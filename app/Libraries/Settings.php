<?php

namespace App\Libraries;

use Config\Database;

/**
 * Portal settings stored in the `settings` table (key/value text).
 * Read once per request and cached in memory.
 */
class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            $rows = Database::connect()->table('settings')->get()->getResultArray();
            foreach ($rows as $row) {
                self::$cache[$row['key']] = $row['value'];
            }
        }

        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::all()[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return $value === null ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /** Insert or update a setting and drop the cache. Callers audit the change. */
    public static function set(string $key, string $value): void
    {
        Database::connect()->query(
            'INSERT INTO settings ("key", value, updated_at) VALUES (?, ?, now())
             ON CONFLICT ("key") DO UPDATE SET value = EXCLUDED.value, updated_at = now()',
            [$key, $value]
        );
        self::$cache = null;
    }
}
