<?php

namespace App\Services;

use App\Models\SyncCursor;
use App\Support\Journal;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Alerts for the business owner. Every alert is written to the journal (shown
 * on the dashboard); it is also e-mailed and/or pushed to a webhook (ntfy,
 * Gotify, Slack-compatible) when those channels are configured. Alerts carry
 * no secret and no full invoice: who, which number, how much, what to do.
 */
class Notifier
{
    public const KINDS = [
        'received' => 'Nouvelle facture fournisseur',
        'rejected' => 'Facture électronique rejetée ou bloquée',
        'sync_failed' => 'Échec de synchronisation',
        'credentials' => 'Identifiants API invalides',
        'outage' => 'SUPER PDP indisponible',
        'recovered' => 'Retour à la normale',
        'test' => 'Test de notification',
    ];

    /**
     * @param  string|null  $dedupeKey  same key within $cooldownMinutes: sent once
     * @return bool whether the alert was sent (false when muted or deduplicated)
     */
    public function notify(string $kind, string $subject, string $body, ?string $dedupeKey = null, int $cooldownMinutes = 0): bool
    {
        if (! $this->enabled($kind)) {
            return false;
        }

        if ($dedupeKey !== null) {
            $key = 'notify.'.$kind.'.'.md5($dedupeKey);
            $last = SyncCursor::get($key);

            if ($last !== null && ($cooldownMinutes === 0 || now()->diffInMinutes(Carbon::parse($last), true) < $cooldownMinutes)) {
                return false;
            }

            SyncCursor::put($key, now()->toIso8601String());
        }

        $environment = config('einvoicing.superpdp.environment');
        $subject = '[Facturation électronique'.($environment === 'production' ? '' : ' — '.strtoupper((string) $environment)).'] '.$subject;
        $body = (string) Journal::mask($body);
        $dashboard = rtrim((string) config('einvoicing.notify.dashboard_url'), '/');
        $text = $body.($dashboard !== '' ? "\n\nTableau de bord : {$dashboard}" : '');
        $channels = [];

        if (filled($to = config('einvoicing.notify.mail_to'))) {
            try {
                if (filled(config('einvoicing.notify.mailtrap_token'))) {
                    $this->mailtrap($to, $subject, $text);
                } else {
                    Mail::raw($text, fn ($m) => $m->to(array_map('trim', explode(',', (string) $to)))->subject($subject));
                }
                $channels[] = 'e-mail';
            } catch (Throwable $e) {
                Journal::exception('notify', 'mail_failed', $e);
            }
        }

        if (filled($url = config('einvoicing.notify.webhook_url'))) {
            try {
                Http::timeout(10)
                    ->withHeaders(['Title' => $this->ascii($subject), 'Tags' => $kind, 'Priority' => in_array($kind, ['credentials', 'outage', 'rejected'], true) ? 'high' : 'default'])
                    ->withBody($text, 'text/plain; charset=utf-8')
                    ->post($url)
                    ->throw();
                $channels[] = 'webhook';
            } catch (Throwable $e) {
                Journal::exception('notify', 'webhook_failed', $e);
            }
        }

        $level = in_array($kind, ['received', 'recovered', 'test'], true) ? 'info' : 'warning';
        Journal::{$level}('notify', $kind, $subject.' — '.mb_strimwidth(str_replace("\n", ' ', $body), 0, 600, '…'), null, [
            'channels' => $channels ?: ['tableau de bord'],
        ]);

        return true;
    }

    public function enabled(string $kind): bool
    {
        $kinds = array_filter(array_map('trim', explode(',', (string) config('einvoicing.notify.events'))));

        return $kind === 'test' || $kind === 'recovered' || in_array($kind, $kinds, true);
    }

    public function channels(): array
    {
        $mail = match (true) {
            ! filled(config('einvoicing.notify.mail_to')) => null,
            filled(config('einvoicing.notify.mailtrap_token')) => 'e-mail (Mailtrap)',
            default => 'e-mail ('.config('mail.default').')',
        };

        return array_values(array_filter([
            $mail,
            filled(config('einvoicing.notify.webhook_url')) ? 'webhook' : null,
        ]));
    }

    private function mailtrap(string $to, string $subject, string $text): void
    {
        $from = (string) (config('einvoicing.notify.mailtrap_from') ?: config('mail.from.address') ?: 'noreply@localhost');
        $host = rtrim((string) config('einvoicing.notify.mailtrap_host', 'send.api.mailtrap.io'), '/');
        $recipients = array_map(fn ($e) => ['email' => trim($e)], explode(',', $to));

        Http::timeout(15)
            ->withToken((string) config('einvoicing.notify.mailtrap_token'))
            ->acceptJson()
            ->post("https://{$host}/api/send", [
                'from' => ['email' => $from, 'name' => 'Facturation électronique 42bash'],
                'to' => $recipients,
                'subject' => $subject,
                'text' => $text,
                'category' => 'einvoicing',
            ])
            ->throw();
    }

    /** HTTP header values must be ASCII. */
    private function ascii(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return preg_replace('/[^\x20-\x7E]/', '', $converted !== false ? $converted : $value);
    }
}
