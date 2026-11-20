<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;

/**
 * Grievance types.
 *
 * Student types are fully configurable. Faculty and Non-Teaching each have exactly one type
 * ("General") in version 1: it can be renamed and its matrix edited, but not deactivated or
 * deleted, and no other type can be added - until the admin switches on
 * "allow_multiple_types_<role>" in the portal settings. After that, the same screens add, modify,
 * deactivate and delete types for that role, with no code change.
 *
 * Whatever the setting, every role keeps at least one active type, so people can always submit.
 */
class TypeService
{
    public static function multipleAllowed(string $role): bool
    {
        return $role === 'student' || Settings::bool("allow_multiple_types_{$role}");
    }

    /** @return list<string> validation errors */
    public static function validate(string $role, string $name, string $code, ?int $ignoreId = null): array
    {
        $errors = [];
        $db     = Database::connect();

        if (! in_array($role, PostService::ROLES, true)) {
            $errors[] = 'Choose who this type is for.';
        }
        if (trim($name) === '' || mb_strlen($name) > 150) {
            $errors[] = 'Type name is required (up to 150 characters).';
        } elseif ($db->query('SELECT 1 FROM grievance_types WHERE role = ? AND lower(name) = lower(?) AND id <> ?', [$role, trim($name), $ignoreId ?? 0])->getRowArray()) {
            $errors[] = "There is already a '{$name}' type for " . role_label($role) . '.';
        }

        if (! preg_match('/^[A-Z]{2,4}$/', $code)) {
            $errors[] = 'The short code must be 2 to 4 capital letters (for example ACD).';
        } elseif ($db->query('SELECT 1 FROM grievance_types WHERE code = ? AND id <> ?', [$code, $ignoreId ?? 0])->getRowArray()) {
            $errors[] = "The short code '{$code}' is already used by another type. Codes must be unique across all types.";
        }

        return $errors;
    }

    /** Normalise what the admin typed into a short code. */
    public static function normaliseCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z]/', '', $code) ?? '');
    }

    /**
     * Create a type with its route set to "Director only". The admin then adds levels in the matrix editor.
     */
    public static function create(string $role, string $name, string $code, bool $requiresHostel, int $sortOrder): int
    {
        if (! self::multipleAllowed($role)) {
            throw new RuntimeException(
                role_label($role) . ' grievances have a single type ("General") in this version. '
                . 'Turn on "allow multiple types" for ' . role_label($role) . ' in Settings to add more.'
            );
        }

        $code     = self::normaliseCode($code);
        $db       = Database::connect();
        $director = $db->query('SELECT id FROM posts WHERE is_director')->getRowArray();
        if ($director === null) {
            throw new RuntimeException('There is no Director post. Create it under Posts first.');
        }

        $db->transStart();
        $db->table('grievance_types')->insert([
            'role'            => $role,
            'name'            => trim($name),
            'code'            => $code,
            'requires_hostel' => pg_flag($role === 'student' && $requiresHostel),
            'sort_order'      => $sortOrder,
        ]);
        $id = (int) $db->insertID();

        $db->table('escalation_levels')->insert([
            'type_id'        => $id,
            'level_no'       => 1,
            'post_id' => $director['id'],
            'overdue_days'   => Settings::int('overdue_default_days', 7),
            'is_final'       => pg_flag(true),
        ]);

        AuditLogger::log('create', 'grievance_type', $id, null, [
            'role' => $role, 'name' => trim($name), 'code' => $code, 'requires_hostel' => $requiresHostel,
        ]);
        $db->transComplete();

        if (! $db->transStatus()) {
            throw new RuntimeException('The type could not be saved.');
        }

        return $id;
    }

    public static function update(int $id, string $name, string $code, bool $requiresHostel, int $sortOrder): void
    {
        $type = self::find($id);
        $code = self::normaliseCode($code);

        // A changed code only affects NEW grievances; reference numbers already issued never change
        Database::connect()->query(
            'UPDATE grievance_types SET name = ?, code = ?, requires_hostel = ?, sort_order = ?, updated_at = now() WHERE id = ?',
            [trim($name), $code, pg_flag($type['role'] === 'student' && $requiresHostel), $sortOrder, $id]
        );

        AuditLogger::log('update', 'grievance_type', $id,
            ['name' => $type['name'], 'code' => $type['code'], 'requires_hostel' => is_true($type['requires_hostel']), 'sort_order' => (int) $type['sort_order']],
            ['name' => trim($name), 'code' => $code, 'requires_hostel' => $requiresHostel, 'sort_order' => $sortOrder]);
    }

    public static function setActive(int $id, bool $active): void
    {
        $type = self::find($id);

        if (! $active) {
            self::assertCanRemove($type, 'deactivated');
        }

        Database::connect()->query('UPDATE grievance_types SET active = ?, updated_at = now() WHERE id = ?', [pg_flag($active), $id]);
        AuditLogger::log($active ? 'activate' : 'deactivate', 'grievance_type', $id,
            ['active' => is_true($type['active'])], ['active' => $active, 'name' => $type['name']]);
    }

    /** Number of grievances filed under a type. */
    public static function grievanceCount(int $id): int
    {
        return (int) Database::connect()->query('SELECT count(*) AS n FROM grievances WHERE type_id = ?', [$id])->getRow('n');
    }

    public static function delete(int $id): void
    {
        $type = self::find($id);
        self::assertCanRemove($type, 'deleted');

        if (self::grievanceCount($id) > 0) {
            throw new RuntimeException('Grievances have been filed under this type, so it can only be deactivated.');
        }

        $db = Database::connect();
        $db->transStart();
        $db->query('DELETE FROM escalation_levels WHERE type_id = ?', [$id]);
        $db->query('DELETE FROM grievance_types WHERE id = ?', [$id]);
        AuditLogger::log('delete', 'grievance_type', $id, ['role' => $type['role'], 'name' => $type['name'], 'code' => $type['code']], null);
        $db->transComplete();
    }

    /** @return array<string,mixed> */
    public static function find(int $id): array
    {
        $row = Database::connect()->query('SELECT * FROM grievance_types WHERE id = ?', [$id])->getRowArray();
        if ($row === null) {
            throw new RuntimeException('That grievance type does not exist.');
        }

        return $row;
    }

    /** @param array<string,mixed> $type */
    private static function assertCanRemove(array $type, string $verb): void
    {
        if (! self::multipleAllowed($type['role'])) {
            throw new RuntimeException(
                "The only " . role_label($type['role']) . " type cannot be {$verb} in this version. It can be renamed and its matrix edited."
            );
        }

        if (is_true($type['active'])) {
            $others = (int) Database::connect()->query(
                'SELECT count(*) AS n FROM grievance_types WHERE role = ? AND active AND id <> ?', [$type['role'], $type['id']]
            )->getRow('n');

            if ($others === 0) {
                throw new RuntimeException('Every group needs at least one active type, so the last active ' . role_label($type['role']) . " type cannot be {$verb}.");
            }
        }
    }
}
