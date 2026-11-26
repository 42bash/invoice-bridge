<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceEvent extends Model
{
    /** Official French lifecycle statuses and SUPER PDP technical statuses. */
    public const STATUS_LABELS = [
        'api:uploaded' => 'Téléversée',
        'api:invalid' => 'Invalide (avant envoi)',
        'api:validated' => 'Validée',
        'api:sent' => 'Envoyée',
        'api:rejected' => 'Rejetée par le point d\'accès',
        'api:received' => 'Reçue',
        'api:acknowledged' => 'Accusé de réception',
        'api:accepted' => 'Acceptée',
        'fr:200' => 'Déposée',
        'fr:201' => 'Émise',
        'fr:202' => 'Reçue',
        'fr:203' => 'Mise à disposition',
        'fr:204' => 'Prise en charge',
        'fr:205' => 'Approuvée',
        'fr:206' => 'Approuvée partiellement',
        'fr:207' => 'En litige',
        'fr:208' => 'Suspendue',
        'fr:209' => 'Complétée',
        'fr:210' => 'Refusée',
        'fr:211' => 'Paiement transmis',
        'fr:212' => 'Encaissée',
        'fr:213' => 'Rejetée',
        'fr:220' => 'Annulée',
        'fr:501' => 'Irrecevable',
    ];

    public const REJECTION_CODES = ['api:invalid', 'api:rejected', 'fr:213', 'fr:501'];

    public const REFUSAL_CODES = ['fr:210'];

    public const DELIVERY_CODES = ['fr:202', 'fr:203', 'fr:204', 'fr:205', 'fr:206', 'fr:209', 'fr:211', 'fr:212', 'api:received', 'api:accepted', 'api:acknowledged'];

    protected $guarded = ['id'];

    protected $casts = [
        'details' => 'array',
        'event_created_at' => 'datetime',
    ];

    public static function labelFor(string $code, ?string $fallback = null): string
    {
        if (isset(self::STATUS_LABELS[$code])) {
            return self::STATUS_LABELS[$code];
        }

        return $fallback ?: $code;
    }

    /** Human readable reason found in the event details (reason code + notes). */
    public static function reasonFrom(array $event): ?string
    {
        $parts = [];

        if (! empty($event['data']['reason'])) {
            $parts[] = (string) $event['data']['reason'];
        }

        foreach ((array) ($event['details'] ?? []) as $detail) {
            if (! empty($detail['reason'])) {
                $parts[] = 'Motif '.$detail['reason'];
            }

            foreach ((array) ($detail['notes'] ?? []) as $note) {
                if (! is_array($note)) {
                    $parts[] = (string) $note;

                    continue;
                }

                foreach ((array) ($note['contents'] ?? []) as $content) {
                    if (filled($content['content'] ?? null)) {
                        $parts[] = (string) $content['content'];
                    }
                }
            }
        }

        if ($parts === [] && ! empty($event['status_text']) && in_array($event['status_code'] ?? '', array_merge(self::REJECTION_CODES, self::REFUSAL_CODES), true)) {
            $parts[] = $event['status_text'];
        }

        return $parts ? mb_strimwidth(implode(' — ', array_unique($parts)), 0, 2000, '…') : null;
    }
}
