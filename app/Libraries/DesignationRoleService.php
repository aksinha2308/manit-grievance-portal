<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;

/**
 * Which ERP job titles mean "faculty" and which mean "non-teaching staff". The ERP has one staff table and no
 * type column, so the portal keeps this small list. A title that is not on it blocks that person's sign-in until
 * the admin adds it (so nobody is silently put on the wrong route).
 *
 * Titles are compared ignoring capitals, extra spaces and a trailing full stop: "ASSISTANT  Professor " matches
 * "Assistant Professor".
 */
class DesignationRoleService
{
    public const ROLES = ['faculty' => 'Faculty', 'non_teaching' => 'Non-Teaching staff'];

    /** The comparison key of a title. */
    public static function key(string $title): string
    {
        $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? ''));

        return rtrim($t, ". \t");
    }

    /** @return ?string 'faculty' | 'non_teaching' | null when the title is not on the list */
    public static function roleFor(string $title): ?string
    {
        return self::find($title)['role'] ?? null;
    }

    /**
     * The list entry for an ERP title: its role and the tidy spelling to show ("ASSISTANT PROFESSOR " shows as
     * "Assistant Professor"). Null when the title is not on the list.
     *
     * @return array{role:string,text:string}|null
     */
    public static function find(string $title): ?array
    {
        $key = self::key($title);
        if ($key === '') {
            return null;
        }
        //$row = Database::connect()->query('SELECT role, designation_text FROM designation_roles WHERE designation_key = ?', [$key])->getRowArray();
        $row = Database::connect()->query('SELECT long FROM establishment.dgcode WHERE code = ?', [$key])->getRowArray();

        return $row === null ? null : ['text' => (string) $row['long']];
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Database::connect()->query('SELECT * FROM designation_roles ORDER BY role, lower(designation_text)')->getResultArray();
    }

    /** Add a title, or change the role of one already listed. */
    public static function set(string $title, string $role): void
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($title === '' || mb_strlen($title) > 120) {
            throw new RuntimeException('Enter the job title exactly as it appears in the ERP (up to 120 characters).');
        }
        if (! isset(self::ROLES[$role])) {
            throw new RuntimeException('The role must be faculty or non_teaching.');
        }
        $db     = Database::connect();
        $key    = self::key($title);
        $before = $db->query('SELECT designation_text, role FROM designation_roles WHERE designation_key = ?', [$key])->getRowArray();
        $db->query(
            'INSERT INTO designation_roles (designation_key, designation_text, role) VALUES (?, ?, ?)
             ON CONFLICT (designation_key) DO UPDATE SET designation_text = EXCLUDED.designation_text, role = EXCLUDED.role',
            [$key, $title, $role]
        );
        AuditLogger::log($before === null ? 'create' : 'update', 'designation_role', $key, $before, ['designation_text' => $title, 'role' => $role]);
    }

    public static function remove(string $title): void
    {
        $db  = Database::connect();
        $key = self::key($title);
        $before = $db->query('SELECT designation_text, role FROM designation_roles WHERE designation_key = ?', [$key])->getRowArray();
        if ($before === null) {
            throw new RuntimeException('That title is not on the list.');
        }
        $db->query('DELETE FROM designation_roles WHERE designation_key = ?', [$key]);
        AuditLogger::log('delete', 'designation_role', $key, $before, null);
    }
}
