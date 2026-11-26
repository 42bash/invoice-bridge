<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An Invoice Ninja invoice or credit note, and its life as an e-invoice.
 *
 * States: discovered → pending → ready | invalid → submitted → delivered,
 * or rejected / refused; skipped when the user opted out; error on a
 * technical failure that will be retried.
 */
class OutboundDocument extends Model
{
    public const DISCOVERED = 'discovered';

    public const PENDING = 'pending';

    public const READY = 'ready';

    public const INVALID = 'invalid';

    public const SUBMITTING = 'submitting';

    public const SUBMITTED = 'submitted';

    public const DELIVERED = 'delivered';

    public const REJECTED = 'rejected';

    public const REFUSED = 'refused';

    public const SKIPPED = 'skipped';

    public const ERROR = 'error';

    public const LABELS = [
        self::DISCOVERED => 'Non demandée',
        self::PENDING => 'En attente',
        self::READY => 'Prête (validée)',
        self::INVALID => 'Invalide',
        self::SUBMITTING => 'Envoi en cours',
        self::SUBMITTED => 'Déposée',
        self::DELIVERED => 'Mise à disposition',
        self::REJECTED => 'Rejetée',
        self::REFUSED => 'Refusée',
        self::SKIPPED => 'Non transmise',
        self::ERROR => 'Erreur technique',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'issue_date' => 'date',
        'requested' => 'boolean',
        'deposit' => 'boolean',
        'total' => 'decimal:2',
        'errors' => 'array',
        'warnings' => 'array',
        'files' => 'array',
        'last_status_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'prepared_at' => 'datetime',
        'submitted_at' => 'datetime',
        'payment_reported_at' => 'datetime',
        'in_fields' => 'array',
        'in_synced_at' => 'datetime',
    ];

    public static function externalIdFor(string $entityType, string $inId): string
    {
        return "in-{$entityType}-{$inId}";
    }

    public function label(): string
    {
        return self::LABELS[$this->state] ?? $this->state;
    }

    public function isFinal(): bool
    {
        return in_array($this->state, [self::SUBMITTED, self::DELIVERED, self::REJECTED, self::REFUSED], true)
            || $this->superpdp_id !== null;
    }

    public function file(string $kind): ?array
    {
        return $this->files[$kind] ?? null;
    }

    public function events()
    {
        return $this->hasMany(InvoiceEvent::class, 'superpdp_invoice_id', 'superpdp_id')->orderBy('superpdp_event_id');
    }
}
