<?php

use App\Libraries\Settings;

if (! function_exists('display_timezone')) {
    /**
     * The time zone dates are shown in (portal.displayTimezone in .env, IST by default). Tolerates the usual typos in
     * .env, such as a missing closing quote ('Asia/Kolkata) or spaces, and falls back to Asia/Kolkata (with a warning
     * in the log) if the name is still not a valid time zone, so a typo can never break pages or reports.
     */
    function display_timezone(): DateTimeZone
    {
        static $zone = null;
        if ($zone !== null) {
            return $zone;
        }

        $name = trim((string) env('portal.displayTimezone', 'Asia/Kolkata'), " \t\n\r\0\x0B'\"");
        try {
            $zone = new DateTimeZone($name === '' ? 'Asia/Kolkata' : $name);
        } catch (Exception) {
            log_message('warning', "portal.displayTimezone '{$name}' in .env is not a valid time zone; using Asia/Kolkata.");
            $zone = new DateTimeZone('Asia/Kolkata');
        }

        return $zone;
    }
}

if (! function_exists('ist')) {
    /**
     * Format a stored (UTC) timestamp for display in the portal's display timezone (IST by default),
     * with date, time and seconds, e.g. "21 Oct 2026, 18:03:45 IST".
     */
    function ist(?string $timestamp, bool $withSeconds = true): string
    {
        if ($timestamp === null || $timestamp === '') {
            return '';
        }

        $zone = display_timezone();
        $dt   = (new DateTimeImmutable($timestamp))->setTimezone($zone);

        return $dt->format($withSeconds ? 'd M Y, H:i:s T' : 'd M Y, H:i T');
    }
}

if (! function_exists('institute_name')) {
    function institute_name(): string
    {
        return Settings::get('institute_name', 'Grievance Portal') ?? 'Grievance Portal';
    }
}

if (! function_exists('institute_name_hi')) {
    /** Full institute name in Hindi (shown first in the page header). */
    function institute_name_hi(): string
    {
        return 'मौलाना आज़ाद राष्ट्रीय प्रौद्योगिकी संस्थान भोपाल';
    }
}

if (! function_exists('institute_name_en')) {
    /** Full institute name in English (shown below the Hindi name in the page header). */
    function institute_name_en(): string
    {
        return 'Maulana Azad National Institute of Technology Bhopal';
    }
}

if (! function_exists('bootstrap_asset')) {
    /**
     * Use locally hosted Bootstrap if the files have been placed in public/assets/bootstrap/,
     * otherwise fall back to the jsDelivr CDN.
     */
    function bootstrap_asset(string $type): string
    {
        $local = [
            'css' => ['assets/bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'],
            'js'  => ['assets/bootstrap/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'],
        ][$type];

        return is_file(FCPATH . $local[0]) ? base_url($local[0]) : $local[1];
    }
}

if (! function_exists('is_true')) {
    /** PostgreSQL booleans arrive as true/false, 't'/'f' or '1'/'0' depending on the code path. */
    function is_true(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }
}

if (! function_exists('role_label')) {
    function role_label(string $role): string
    {
        return [
            'student'      => 'Student',
            'faculty'      => 'Faculty',
            'non_teaching' => 'Non-Teaching',
        ][$role] ?? $role;
    }
}

if (! function_exists('pg_flag')) {
    /** Value for writing a boolean column through the query builder. */
    function pg_flag(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}

if (! function_exists('duration_text')) {
    /** "3 days 4 h", "5 h 10 min", "12 min" for a number of seconds. */
    function duration_text(int|string|null $seconds): string
    {
        $s = max(0, (int) $seconds);
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);

        if ($d > 0) {
            return $d . ($d === 1 ? ' day' : ' days') . ($h > 0 ? " {$h} h" : '');
        }
        if ($h > 0) {
            return "{$h} h" . ($m > 0 ? " {$m} min" : '');
        }

        return max(1, $m) . ' min';
    }
}

if (! function_exists('file_size_text')) {
    function file_size_text(int|string $bytes): string
    {
        $b = (int) $bytes;

        return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) ceil($b / 1024)) . ' KB';
    }
}
