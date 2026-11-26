<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Write-once storage for every file exchanged with SUPER PDP (generated
 * Factur-X, validation reports, received originals). Files are never
 * overwritten: a second write with different content gets a new name, and
 * each entry records its SHA-256 so integrity can be checked later.
 */
class Archive
{
    public const DISK = 'archive';

    /** @return array{path: string, sha256: string, size: int, mime: string, name: string, stored_at: string} */
    public static function put(string $folder, string $name, string $content, string $mime): array
    {
        $disk = Storage::disk(self::DISK);
        $name = self::safeName($name);
        $hash = hash('sha256', $content);
        $path = trim($folder, '/').'/'.$name;

        if ($disk->exists($path)) {
            if (hash('sha256', $disk->get($path)) === $hash) {
                return self::entry($path, $name, $hash, strlen($content), $mime);
            }

            $info = pathinfo($name);
            $name = $info['filename'].'-'.substr($hash, 0, 8).(isset($info['extension']) ? '.'.$info['extension'] : '');
            $path = trim($folder, '/').'/'.$name;
        }

        if (! $disk->put($path, $content)) {
            throw new RuntimeException("Impossible d'archiver {$path}");
        }

        return self::entry($path, $name, $hash, strlen($content), $mime);
    }

    public static function get(array $entry): string
    {
        return Storage::disk(self::DISK)->get($entry['path']);
    }

    public static function verify(array $entry): bool
    {
        $disk = Storage::disk(self::DISK);

        return $disk->exists($entry['path']) && hash('sha256', $disk->get($entry['path'])) === $entry['sha256'];
    }

    private static function entry(string $path, string $name, string $hash, int $size, string $mime): array
    {
        return [
            'path' => $path,
            'name' => $name,
            'sha256' => $hash,
            'size' => $size,
            'mime' => $mime,
            'stored_at' => now()->toIso8601String(),
        ];
    }

    private static function safeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);

        return trim($name, '._') ?: 'document';
    }
}
