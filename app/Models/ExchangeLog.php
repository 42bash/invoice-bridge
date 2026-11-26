<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/** Technical journal. Archived invoices are kept separately (legal retention), not here. */
class ExchangeLog extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('einvoicing.log_retention_days', 400)));
    }
}
