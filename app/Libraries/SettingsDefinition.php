<?php

namespace App\Libraries;

use Config\Database;

/**
 * What the admin may configure on the Settings screen, and how each value is validated.
 */
class SettingsDefinition
{
    /** File types an admin may allow for attachments (no executables or scripts, ever). */
    public const ATTACHMENT_TYPES = [
        'pdf' => 'PDF', 'jpg' => 'JPG', 'jpeg' => 'JPEG', 'png' => 'PNG',
        'doc' => 'Word (.doc)', 'docx' => 'Word (.docx)', 'xls' => 'Excel (.xls)', 'xlsx' => 'Excel (.xlsx)',
    ];

    /**
     * group => [ key => definition ]
     * type: text | int | bool | regex | filetypes
     *
     * @return array<string,array<string,array<string,mixed>>>
     */
    public static function groups(): array
    {
        return [
            'Institute' => [
                'institute_name' => ['label' => 'Institute name', 'type' => 'text', 'max' => 150, 'help' => 'Shown at the top of every page.'],
                'ref_prefix'     => ['label' => 'Reference number prefix', 'type' => 'prefix', 'help' => 'Reference numbers look like PREFIX-2026-ACD-000001. 2 to 12 capital letters or digits. Changing it affects new grievances only.'],
                'ref_seq_digits' => ['label' => 'Digits in the sequence number', 'type' => 'int', 'min' => 4, 'max' => 9, 'help' => 'The sequence restarts every year and is counted separately for each grievance type.'],
            ],
            'Attachments' => [
                'attach_allowed_ext' => ['label' => 'Allowed file types', 'type' => 'filetypes'],
                'attach_max_mb'      => ['label' => 'Maximum size per file (MB)', 'type' => 'int', 'min' => 1, 'max' => 25],
                'attach_max_count'   => ['label' => 'Maximum files per submission', 'type' => 'int', 'min' => 0, 'max' => 10, 'help' => '0 switches attachments off.'],
            ],
            'Form and workflow' => [
                'contact_pattern'      => ['label' => 'Contact number pattern', 'type' => 'regex', 'help' => 'A regular expression the contact number must match. Default: exactly 10 digits.'],
                'overdue_default_days' => ['label' => 'Default overdue days for a new level', 'type' => 'int', 'min' => 1, 'max' => 365],
                'archive_after_years'  => ['label' => 'Archive disposed grievances after (years)', 'type' => 'int', 'min' => 1, 'max' => 50, 'help' => 'Archiving is a manual admin action; nothing is ever deleted.'],
            ],
            'Security' => [
                'session_idle_minutes' => ['label' => 'Log out after inactivity (minutes)', 'type' => 'int', 'min' => 5, 'max' => 480],
                'lockout_attempts'     => ['label' => 'Failed admin password logins before the account locks', 'type' => 'int', 'min' => 3, 'max' => 20],
                'lockout_minutes'      => ['label' => 'Lock duration (minutes)', 'type' => 'int', 'min' => 1, 'max' => 1440],
                'password_min_length'  => ['label' => 'Minimum admin password length', 'type' => 'int', 'min' => 8, 'max' => 64, 'help' => 'Only the admin has a password (emergency login). Everyone else signs in with Google.'],
                'otp_valid_minutes'    => ['label' => 'OTP valid for (minutes)', 'type' => 'int', 'min' => 2, 'max' => 60, 'help' => 'The OTP sent before a grievance is submitted.'],
                'otp_max_attempts'     => ['label' => 'Wrong OTP entries allowed', 'type' => 'int', 'min' => 1, 'max' => 10],
                'otp_resend_seconds'   => ['label' => 'Wait before a new OTP can be sent (seconds)', 'type' => 'int', 'min' => 10, 'max' => 600],
                'otp_max_per_hour'     => ['label' => 'OTPs per person per hour', 'type' => 'int', 'min' => 1, 'max' => 20],
            ],
            'Grievance types' => [
                'allow_multiple_types_faculty'      => ['label' => 'Allow several Faculty grievance types', 'type' => 'bool', 'help' => 'Off: Faculty have a single "General" type. On: you can add, modify and deactivate Faculty types.'],
                'allow_multiple_types_non_teaching' => ['label' => 'Allow several Non-Teaching grievance types', 'type' => 'bool', 'help' => 'Off: Non-Teaching staff have a single "General" type. On: you can add, modify and deactivate their types.'],
            ],
        ];
    }

    /** Flat list of definitions keyed by setting name. */
    public static function flat(): array
    {
        $flat = [];
        foreach (self::groups() as $fields) {
            $flat += $fields;
        }

        return $flat;
    }

    /**
     * Validate submitted values and return [cleanValues, errors].
     *
     * @param array<string,mixed> $input
     *
     * @return array{0:array<string,string>,1:list<string>}
     */
    public static function clean(array $input): array
    {
        $clean  = [];
        $errors = [];

        foreach (self::flat() as $key => $def) {
            $label = $def['label'];
            $raw   = $input[$key] ?? null;

            switch ($def['type']) {
                case 'text':
                    $v = trim((string) $raw);
                    if ($v === '' || mb_strlen($v) > $def['max']) {
                        $errors[] = "{$label} is required (up to {$def['max']} characters).";
                    }
                    $clean[$key] = $v;
                    break;

                case 'prefix':
                    $v = strtoupper(trim((string) $raw));
                    if (! preg_match('/^[A-Z0-9]{2,12}$/', $v)) {
                        $errors[] = "{$label} must be 2 to 12 capital letters or digits.";
                    }
                    $clean[$key] = $v;
                    break;

                case 'int':
                    $v = trim((string) $raw);
                    if (! preg_match('/^\d+$/', $v) || (int) $v < $def['min'] || (int) $v > $def['max']) {
                        $errors[] = "{$label} must be a whole number from {$def['min']} to {$def['max']}.";
                    }
                    $clean[$key] = (string) (int) $v;
                    break;

                case 'bool':
                    $clean[$key] = ($raw !== null && $raw !== '' && $raw !== '0') ? '1' : '0';
                    break;

                case 'regex':
                    $v = trim((string) $raw);
                    if ($v === '' || mb_strlen($v) > 200 || @preg_match('~' . str_replace('~', '\~', $v) . '~u', '') === false) {
                        $errors[] = "{$label} is not a valid pattern.";
                    }
                    $clean[$key] = $v;
                    break;

                case 'filetypes':
                    $picked = array_values(array_intersect(array_keys(self::ATTACHMENT_TYPES), array_map('strval', (array) $raw)));
                    if ($picked === []) {
                        $errors[] = "{$label}: choose at least one file type (or set the maximum number of files to 0).";
                    }
                    $clean[$key] = implode(',', $picked);
                    break;
            }
        }

        // The single-type rule for a role can only be switched back on if just one type is left
        foreach (['faculty', 'non_teaching'] as $role) {
            $key = "allow_multiple_types_{$role}";
            if (($clean[$key] ?? '0') === '0' && Settings::bool($key)) {
                $n = (int) Database::connect()->query('SELECT count(*) AS n FROM grievance_types WHERE role = ?', [$role])->getRow('n');
                if ($n > 1) {
                    $errors[] = role_label($role) . " has {$n} grievance types. Delete the extra ones before switching back to a single type.";
                }
            }
        }

        return [$clean, $errors];
    }
}
