<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Short-lived storage for upload previews. A validated upload is kept as a private JSON file under
 * writable/imports/<token>.json (never inside the web root) until the admin confirms or discards it.
 * A token only works for the admin who created it and expires after one hour.
 */
class ImportStore
{
    private const TTL = 3600;

    /** @param array<string,mixed> $data */
    public static function save(array $data, int $adminId): string
    {
        $dir = self::dir();
        self::sweep($dir);

        $token = bin2hex(random_bytes(16));
        file_put_contents("{$dir}/{$token}.json", json_encode(['admin_id' => $adminId, 'created' => time()] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        @chmod("{$dir}/{$token}.json", 0600);

        return $token;
    }

    /** @return array<string,mixed> */
    public static function load(string $token, int $adminId): array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new RuntimeException('This upload is no longer available. Please upload the file again.');
        }

        $file = self::dir() . "/{$token}.json";
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (! is_array($data) || (int) $data['admin_id'] !== $adminId || time() - (int) $data['created'] > self::TTL) {
            self::discard($token);

            throw new RuntimeException('This upload has expired. Please upload the file again.');
        }

        return $data;
    }

    public static function discard(string $token): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token)) {
            @unlink(self::dir() . "/{$token}.json");
        }
    }

    private static function dir(): string
    {
        $dir = WRITEPATH . 'imports';
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    private static function sweep(string $dir): void
    {
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (filemtime($file) < time() - self::TTL * 2) {
                @unlink($file);
            }
        }
    }
}
