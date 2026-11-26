<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A supplier invoice received on SUPER PDP.
 *
 * States: received → imported (expense created in Invoice Ninja),
 * needs_review (vendor or data needs a human), duplicate, error, ignored.
 */
class InboundDocument extends Model
{
    public const RECEIVED = 'received';

    public const IMPORTED = 'imported';

    public const NEEDS_REVIEW = 'needs_review';

    public const DUPLICATE = 'duplicate';

    public const ERROR = 'error';

    public const IGNORED = 'ignored';

    public const LABELS = [
        self::RECEIVED => 'Reçue',
        self::IMPORTED => 'Importée',
        self::NEEDS_REVIEW => 'À vérifier',
        self::DUPLICATE => 'Doublon',
        self::ERROR => 'Erreur',
        self::IGNORED => 'Ignorée',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'total_without_vat' => 'decimal:2',
        'total_vat' => 'decimal:2',
        'total_with_vat' => 'decimal:2',
        'amount_due' => 'decimal:2',
        'errors' => 'array',
        'files' => 'array',
        'summary' => 'array',
        'last_status_at' => 'datetime',
        'received_at' => 'datetime',
        'imported_at' => 'datetime',
    ];

    public static function fingerprintFor(?string $sellerKey, ?string $number, ?string $issueDate): ?string
    {
        if (blank($sellerKey) || blank($number)) {
            return null;
        }

        return hash('sha256', mb_strtolower(trim($sellerKey)).'|'.mb_strtolower(trim($number)).'|'.$issueDate);
    }

    public function label(): string
    {
        return self::LABELS[$this->state] ?? $this->state;
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
