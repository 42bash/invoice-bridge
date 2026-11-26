<?php

namespace App\Support;

use App\Models\ExchangeLog;
use App\Models\InboundDocument;
use App\Models\OutboundDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every exchange is written twice: in the `exchange_logs` table (shown in the
 * dashboard, attached to a document) and in the structured application log.
 * Secrets and personal data are masked before being written anywhere.
 */
class Journal
{
    private const SECRET_KEYS = '/(token|secret|password|authorization|client_secret|access_token|refresh_token|api[-_]?key|iban|bic)/i';

    public static function info(string $channel, string $action, string $message, ?Model $document = null, array $context = []): void
    {
        self::write('info', $channel, $action, $message, $document, $context);
    }

    public static function warning(string $channel, string $action, string $message, ?Model $document = null, array $context = []): void
    {
        self::write('warning', $channel, $action, $message, $document, $context);
    }

    public static function error(string $channel, string $action, string $message, ?Model $document = null, array $context = []): void
    {
        self::write('error', $channel, $action, $message, $document, $context);
    }

    public static function exception(string $channel, string $action, Throwable $e, ?Model $document = null, array $context = []): void
    {
        self::write('error', $channel, $action, self::mask($e->getMessage()), $document, $context + ['exception' => $e::class]);
    }

    public static function mask(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $k => $v) {
                $out[$k] = is_string($k) && preg_match(self::SECRET_KEYS, $k) ? '***' : self::mask($v);
            }

            return $out;
        }

        if (! is_string($value)) {
            return $value;
        }

        $value = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-~+\/=]+/i', '$1***', $value);
        $value = preg_replace('/\b(FR\d{2})[ ]?(\d{4}[ ]?){5}\d{3}\b/', '$1 **** (IBAN masqué)', $value);
        $value = preg_replace('/([?&](?:secret|token|client_secret)=)[^&\s]+/i', '$1***', $value);

        return $value;
    }

    private static function write(string $level, string $channel, string $action, string $message, ?Model $document, array $context): void
    {
        $message = (string) self::mask($message);
        $context = self::mask($context);

        [$type, $id] = match (true) {
            $document instanceof OutboundDocument => ['outbound', $document->getKey()],
            $document instanceof InboundDocument => ['inbound', $document->getKey()],
            default => [null, null],
        };

        try {
            ExchangeLog::query()->create([
                'level' => $level,
                'channel' => $channel,
                'action' => $action,
                'message' => mb_strimwidth($message, 0, 4000, '…'),
                'document_type' => $type,
                'document_id' => $id,
                'context' => $context ?: null,
            ]);
        } catch (Throwable $e) {
            Log::error('journal write failed', ['error' => $e->getMessage()]);
        }

        Log::log($level, "[{$channel}] {$action}: {$message}", array_filter([
            'channel' => $channel,
            'action' => $action,
            'document_type' => $type,
            'document_id' => $id,
        ]) + $context);
    }
}
