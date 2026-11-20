<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;

/**
 * The department list is read-only and comes from establishment.dpcode. Semesters remain portal-managed.
 *
 * Portal-managed list items that have been used can only be deactivated. External departments are read-only.
 */
class ListService
{
    /** Definition of each list. */
    public const ENTITIES = [
        'departments' => ['table' => 'establishment.dpcode', 'title' => 'Departments', 'singular' => 'department'],
        'semesters'   => ['table' => 'semesters',   'title' => 'Semesters',   'singular' => 'semester'],
    ];

    public static function isEntity(string $entity): bool
    {
        return isset(self::ENTITIES[$entity]);
    }

    /**
     * Items of a list with a usage count each.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(string $entity): array
    {
        if ($entity === 'departments') {
            $rows = Database::connect()->query(
                'SELECT dept AS id, depname AS name FROM establishment.dpcode ORDER BY lower(depname)'
            )->getResultArray();
            foreach ($rows as &$row) {
                $row['active'] = true;
                $row['usage']  = self::usage($entity, (int) $row['id']);
            }
            unset($row);

            return $rows;
        }

        $table = self::table($entity);
        $order = match ($entity) {
            'semesters'   => 'sort_order, lower(name)',
            default       => 'lower(name)',
        };

        $rows = Database::connect()->query("SELECT * FROM {$table} ORDER BY {$order}")->getResultArray();
        foreach ($rows as &$row) {
            $row['usage'] = self::usage($entity, (int) $row['id']);
        }

        return $rows;
    }

    /** How many records refer to this item. */
    public static function usage(string $entity, int $id): int
    {
        $db = Database::connect();

        return match ($entity) {
            'departments' =>
                (int) $db->query('SELECT count(*) AS n FROM master_people WHERE department_id = ?', [$id])->getRow('n')
                + (int) $db->query('SELECT count(*) AS n FROM grievances WHERE department_id = ?', [$id])->getRow('n')
                + self::assignmentUsage('department', $id),
            'semesters' =>
                (int) $db->query('SELECT count(*) AS n FROM master_people WHERE semester_id = ?', [$id])->getRow('n'),
            default => 0,
        };
    }

    /** @return list<string> validation errors */
    public static function validate(string $entity, string $name, ?array $roles = null, ?int $ignoreId = null): array
    {
        if ($entity === 'departments') {
            return ['Departments are maintained in establishment.dpcode and cannot be changed here.'];
        }

        $errors = [];
        $table  = self::table($entity);
        $name   = trim($name);
        $db     = Database::connect();

        if ($name === '' || mb_strlen($name) > 150) {
            return ['Name is required (up to 150 characters).'];
        }

        $dup = $db->query("SELECT 1 FROM {$table} WHERE lower(name) = lower(?) AND id <> ?", [$name, $ignoreId ?? 0])->getRowArray();
        if ($dup) {
            $errors[] = "'{$name}' already exists.";
        }

        return $errors;
    }

    /** @param list<string>|null $roles retained for callers that share the list form */
    public static function create(string $entity, string $name, ?array $roles, int $sortOrder = 0): int
    {
        if ($entity === 'departments') {
            throw new RuntimeException('Departments are maintained in establishment.dpcode and cannot be changed here.');
        }

        $table = self::table($entity);
        $name  = trim($name);
        $data  = ['name' => $name];

        if ($entity === 'semesters') {
            $data['sort_order'] = $sortOrder;
        }

        $db = Database::connect();
        $db->table($table)->insert($data);
        $id = (int) $db->insertID();
        AuditLogger::log('create', rtrim($entity, 's'), $id, null, $data);

        return $id;
    }

    /** Rename a portal-managed list item. */
    public static function rename(string $entity, int $id, string $name, int $sortOrder = 0, ?array $roles = null): void
    {
        if ($entity === 'departments') {
            throw new RuntimeException('Department names are maintained in establishment.dpcode.');
        }

        $before = self::find($entity, $id);
        $table  = self::table($entity);
        $name   = trim($name);
        $db     = Database::connect();

        if ($entity === 'semesters') {
            $db->query("UPDATE {$table} SET name = ?, sort_order = ?, updated_at = now() WHERE id = ?", [$name, $sortOrder, $id]);
            $after = ['name' => $name, 'sort_order' => $sortOrder];
        } else {
            $db->query("UPDATE {$table} SET name = ?, updated_at = now() WHERE id = ?", [$name, $id]);
            $after = ['name' => $name];
        }

        AuditLogger::log('update', rtrim($entity, 's'), $id, array_intersect_key($before, $after), $after);
    }

    /** "Student, Faculty" style text for legacy department flags. */
    public static function usedBy(array $flags): string
    {
        $out = [];
        foreach (PostService::ROLES as $role) {
            if (! empty($flags["for_{$role}"])) {
                $out[] = role_label($role);
            }
        }

        return implode(', ', $out);
    }

    /**
     * @param list<string> $roles
     *
     * @return array{for_student:bool,for_faculty:bool,for_non_teaching:bool}
     */
    public static function roleFlags(array $roles): array
    {
        return [
            'for_student'      => in_array('student', $roles, true),
            'for_faculty'      => in_array('faculty', $roles, true),
            'for_non_teaching' => in_array('non_teaching', $roles, true),
        ];
    }

    public static function setActive(string $entity, int $id, bool $active): void
    {
        if ($entity === 'departments') {
            throw new RuntimeException('Departments are maintained in establishment.dpcode and cannot be changed here.');
        }

        $before = self::find($entity, $id);
        $table  = self::table($entity);

        Database::connect()->query("UPDATE {$table} SET active = ?, updated_at = now() WHERE id = ?", [pg_flag($active), $id]);
        AuditLogger::log($active ? 'activate' : 'deactivate', rtrim($entity, 's'), $id,
            ['name' => $before['name'], 'active' => is_true($before['active'])], ['active' => $active]);
    }

    public static function delete(string $entity, int $id): void
    {
        if ($entity === 'departments') {
            throw new RuntimeException('Departments are maintained in establishment.dpcode and cannot be deleted here.');
        }

        $before = self::find($entity, $id);
        if (self::usage($entity, $id) > 0) {
            throw new RuntimeException("'{$before['name']}' has been used, so it can only be deactivated.");
        }

        Database::connect()->query('DELETE FROM ' . self::table($entity) . ' WHERE id = ?', [$id]);
        AuditLogger::log('delete', rtrim($entity, 's'), $id, ['name' => $before['name']], null);
    }

    /** @return array<string,mixed> */
    public static function find(string $entity, int $id): array
    {
        $row = $entity === 'departments'
            ? Database::connect()->query('SELECT dept AS id, depname AS name, true AS active FROM establishment.dpcode WHERE dept = ?', [$id])->getRowArray()
            : Database::connect()->query('SELECT * FROM ' . self::table($entity) . ' WHERE id = ?', [$id])->getRowArray();
        if ($row === null) {
            throw new RuntimeException('That item does not exist.');
        }

        return $row;
    }

    // ---------------------------------------------------------------------

    private static function table(string $entity): string
    {
        if (! self::isEntity($entity)) {
            throw new RuntimeException('Unknown list.');
        }

        return self::ENTITIES[$entity]['table'];
    }

    /** Post mappings (active or historical) that point at this department / hostel. */
    private static function assignmentUsage(string $scopeType, int $id): int
    {
        return (int) Database::connect()->query(
            'SELECT count(*) AS n FROM post_holders a JOIN posts d ON d.id = a.post_id
              WHERE d.scope_type = ? AND a.scope_id = ?',
            [$scopeType, $id]
        )->getRow('n');
    }
}
