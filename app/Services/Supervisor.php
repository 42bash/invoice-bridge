<?php

namespace App\Services;

use App\Models\InboundDocument;
use App\Models\OutboundDocument;
use App\Models\SyncCursor;
use App\Services\InvoiceNinja\InvoiceNinjaException;
use App\Services\SuperPdp\SuperPdpException;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Turns synchronisation outcomes into alerts. State lives in sync_cursors
 * (health.*) so that it survives restarts: one alert per incident, one
 * "back to normal" message when it ends.
 */
class Supervisor
{
    public function __construct(private readonly Notifier $notifier) {}

    public static function classify(Throwable $e): string
    {
        $superPdpHost = (string) parse_url((string) config('einvoicing.superpdp.api_url'), PHP_URL_HOST);

        return match (true) {
            $e instanceof SuperPdpException && $e->authentication => 'credentials',
            $e instanceof InvoiceNinjaException && in_array($e->getCode(), [401, 403], true) => 'credentials',
            $e instanceof SuperPdpException && $e->isUnavailable() => 'outage',
            $e instanceof ConnectionException && $superPdpHost !== '' && str_contains($e->getMessage(), $superPdpHost) => 'outage',
            default => 'sync_failed',
        };
    }

    /** @param  list<array{step: string, error: Throwable}>  $failures */
    public function runFinished(array $failures): void
    {
        $byKind = collect($failures)->groupBy(fn ($f) => self::classify($f['error']));
        $describe = fn (Collection $items) => $items->map(fn ($f) => "- {$f['step']} : ".mb_strimwidth($f['error']->getMessage(), 0, 300, '…'))->implode("\n");

        if ($byKind->has('credentials')) {
            $this->notifier->notify('credentials', 'Identifiants API refusés',
                "La synchronisation ne peut plus s'authentifier :\n".$describe($byKind['credentials'])
                ."\n\nÀ faire : vérifier SUPERPDP_CLIENT_ID / SUPERPDP_CLIENT_SECRET (application SUPER PDP supprimée ou secret renouvelé ?) ou le jeton Invoice Ninja du connecteur, dans le .env du connecteur, puis redémarrer le conteneur. Aucune facture n'est perdue : la synchronisation reprendra là où elle s'est arrêtée.",
                'credentials', 1440);
            SyncCursor::put('health.alerted.credentials', now()->toIso8601String());
        } else {
            $this->recovered('credentials', 'Les identifiants API sont de nouveau acceptés.');
        }

        if ($byKind->has('outage')) {
            $since = SyncCursor::get('health.superpdp_down_since') ?? now()->toIso8601String();
            SyncCursor::put('health.superpdp_down_since', $since);
            $minutes = (int) Carbon::parse($since)->diffInMinutes(now(), true);

            if ($minutes >= (int) config('einvoicing.notify.outage_minutes', 60)) {
                $this->notifier->notify('outage', "SUPER PDP indisponible depuis {$minutes} min",
                    'SUPER PDP ne répond pas (ou renvoie des erreurs serveur) depuis le '.Carbon::parse($since)->timezone('Europe/Paris')->format('d/m/Y H:i').".\n".$describe($byKind['outage'])
                    ."\n\nRien à faire de votre côté : les envois et réceptions reprendront automatiquement. Les factures reçues pendant la coupure restent chez SUPER PDP et seront importées ensuite.",
                    'outage '.$since);
                SyncCursor::put('health.alerted.outage', now()->toIso8601String());
            }
        } else {
            $since = SyncCursor::get('health.superpdp_down_since');
            SyncCursor::put('health.superpdp_down_since', null);
            $this->recovered('outage', 'SUPER PDP répond de nouveau'.($since ? ' (coupure depuis le '.Carbon::parse($since)->timezone('Europe/Paris')->format('d/m/Y H:i').')' : '').'. La synchronisation a repris.');
        }

        if ($byKind->has('sync_failed')) {
            $count = (int) SyncCursor::get('health.failed_runs', 0) + 1;
            SyncCursor::put('health.failed_runs', $count);

            if ($count >= (int) config('einvoicing.notify.failures_after', 2)) {
                $this->notifier->notify('sync_failed', "Échec de synchronisation ({$count} passages de suite)",
                    "Étapes en échec :\n".$describe($byKind['sync_failed'])
                    ."\n\nLe détail est dans le journal du tableau de bord. La synchronisation réessaie toutes les ".config('einvoicing.schedule.sync_every_minutes', 10).' minutes.',
                    'sync_failed', 360);
                SyncCursor::put('health.alerted.sync_failed', now()->toIso8601String());
            }
        } else {
            SyncCursor::put('health.failed_runs', 0);
            $this->recovered('sync_failed', 'La synchronisation fonctionne de nouveau.');
        }

        SyncCursor::put('health.last_run', json_encode([
            'at' => now()->toIso8601String(),
            'failures' => $byKind->map->count()->all(),
        ]));
    }

    /** @param  Collection<int, InboundDocument>  $documents */
    public function received(Collection $documents): void
    {
        $documents = $documents->filter(fn (InboundDocument $d) => $d->state !== InboundDocument::DUPLICATE)->values();

        if ($documents->isEmpty()) {
            return;
        }

        $lines = $documents->map(function (InboundDocument $d) {
            $amount = number_format((float) $d->total_with_vat, 2, ',', ' ').' '.($d->currency ?: 'EUR');
            $where = match ($d->state) {
                InboundDocument::IMPORTED => 'importée dans Invoice Ninja (Dépenses)',
                InboundDocument::NEEDS_REVIEW => 'à vérifier dans le tableau de bord avant import',
                InboundDocument::ERROR => 'import en échec, voir le tableau de bord',
                default => 'archivée, import manuel depuis le tableau de bord',
            };

            return "- {$d->seller_name}, n° {$d->number} du ".($d->issue_date?->format('d/m/Y') ?? '?')." : {$amount}, {$where}.";
        })->implode("\n");

        $count = $documents->count();
        $this->notifier->notify('received', $count === 1 ? "Facture fournisseur reçue : {$documents[0]->seller_name}" : "{$count} factures fournisseurs reçues",
            "{$lines}\n\nL'original est archivé tel que reçu et joint à la dépense.");
    }

    public function rejected(OutboundDocument $document): void
    {
        $what = $document->state === OutboundDocument::REFUSED ? 'refusée par le client' : 'rejetée';
        $this->notifier->notify('rejected', "Facture {$document->number} {$what}",
            "Facture {$document->number} ({$document->client_name}, ".number_format((float) $document->total, 2, ',', ' ')." €) : {$document->last_status_code} {$document->last_status_text}.\n"
            .'Motif : '.($document->rejection_reason ?: 'non précisé')."\n\n"
            .'À faire : corriger dans Invoice Ninja puis émettre un avoir et une nouvelle facture si nécessaire (une facture rejetée n\'est pas valable fiscalement).',
            "rejected {$document->id} {$document->last_status_code}");
    }

    public function blocked(OutboundDocument $document, array $errors, string $where): void
    {
        if (! $document->requested) {
            return;
        }

        $this->notifier->notify('rejected', "Facture {$document->number} non transmise ({$where})",
            "Facture {$document->number} ({$document->client_name}) :\n- ".implode("\n- ", array_slice($errors, 0, 5))
            ."\n\nÀ faire : corriger dans Invoice Ninja, puis choisir « ".config('einvoicing.invoice_ninja.action_retry').' » dans le champ « Facture électronique ».',
            "blocked {$document->id} ".md5(json_encode($errors)));
    }

    private function recovered(string $kind, string $message): void
    {
        if (SyncCursor::get("health.alerted.{$kind}") === null) {
            return;
        }

        SyncCursor::put("health.alerted.{$kind}", null);
        SyncCursor::put("notify.{$kind}.".md5($kind), null);
        $this->notifier->notify('recovered', Notifier::KINDS[$kind].' : résolu', $message);
    }
}
