<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncCursor extends Model
{
    public const CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        $stored = static::query()->whereKey($key)->value('value');

        return $stored ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value]);
    }
}
