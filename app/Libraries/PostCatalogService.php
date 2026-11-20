<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;

/**
 * Posts (posts such as Head of Department, Warden, Dean, Chairman, Registrar, Director).
 *
 * Rules:
 *  - exactly one Director post exists; it cannot be renamed into something else's name,
 *    deactivated, deleted, or have a scope
 *  - the scope (none / department / hostel) cannot change once the post is in a route or has had a holder
 *  - a post used in a route cannot be deactivated or deleted: remove it from the matrices first
 *  - a post that has ever had a holder cannot be deleted, only deactivated
 */
class PostCatalogService
{
    public const SCOPES = ['' => 'Single post (no scope)', 'department' => 'One per department', 'hostel' => 'One per hostel'];

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Database::connect()->query(
            'SELECT d.*,
                    (SELECT count(*) FROM escalation_levels l WHERE l.post_id = d.id) AS route_uses,
                    (SELECT count(*) FROM post_holders a WHERE a.post_id = d.id) AS ever_held,
                    (SELECT count(*) FROM post_holders a WHERE a.post_id = d.id AND a.active) AS holders
               FROM posts d ORDER BY d.is_director, lower(d.name)'
        )->getResultArray();
    }

    /** @return list<string> validation errors */
    public static function validate(string $name, string $scope, ?int $ignoreId = null): array
    {
        $errors = [];
        $name   = trim($name);

        if ($name === '' || mb_strlen($name) > 150) {
            $errors[] = 'Name is required (up to 150 characters).';
        } elseif (Database::connect()->query('SELECT 1 FROM posts WHERE lower(name) = lower(?) AND id <> ?', [$name, $ignoreId ?? 0])->getRowArray()) {
            $errors[] = "A post named '{$name}' already exists.";
        }

        if (! array_key_exists($scope, self::SCOPES)) {
            $errors[] = 'Choose a valid scope.';
        }

        return $errors;
    }

    public static function create(string $name, string $scope): int
    {
        $db = Database::connect();
        $db->table('posts')->insert([
            'name'        => trim($name),
            'scope_type'  => $scope === '' ? null : $scope,
            'is_director' => pg_flag(false),
        ]);
        $id = (int) $db->insertID();
        AuditLogger::log('create', 'post', $id, null, ['name' => trim($name), 'scope' => $scope ?: 'none']);

        return $id;
    }

    public static function update(int $id, string $name, string $scope): void
    {
        $before = self::find($id);
        $scopeValue = $scope === '' ? null : $scope;

        if ($scopeValue !== $before['scope_type']) {
            if (is_true($before['is_director'])) {
                throw new RuntimeException('The Director post cannot have a scope.');
            }
            if (self::routeUses($id) > 0 || self::everHeld($id) > 0) {
                throw new RuntimeException('The scope cannot be changed while the post is in a route or has had holders.');
            }
        }

        Database::connect()->query('UPDATE posts SET name = ?, scope_type = ?, updated_at = now() WHERE id = ?', [trim($name), $scopeValue, $id]);
        AuditLogger::log('update', 'post', $id,
            ['name' => $before['name'], 'scope' => $before['scope_type'] ?? 'none'],
            ['name' => trim($name), 'scope' => $scopeValue ?? 'none']);
    }

    public static function setActive(int $id, bool $active): void
    {
        $before = self::find($id);

        if (! $active) {
            if (is_true($before['is_director'])) {
                throw new RuntimeException('The Director post cannot be deactivated.');
            }
            if (self::routeUses($id) > 0) {
                throw new RuntimeException('This post is a level in at least one route. Remove it from those matrices first.');
            }
        }

        Database::connect()->query('UPDATE posts SET active = ?, updated_at = now() WHERE id = ?', [pg_flag($active), $id]);
        AuditLogger::log($active ? 'activate' : 'deactivate', 'post', $id, ['name' => $before['name']], ['active' => $active]);
    }

    public static function delete(int $id): void
    {
        $before = self::find($id);

        if (is_true($before['is_director'])) {
            throw new RuntimeException('The Director post cannot be deleted.');
        }
        if (self::routeUses($id) > 0) {
            throw new RuntimeException('This post is a level in at least one route. Remove it from those matrices first.');
        }
        if (self::everHeld($id) > 0) {
            throw new RuntimeException('This post has had holders, so it can only be deactivated.');
        }

        Database::connect()->query('DELETE FROM posts WHERE id = ?', [$id]);
        AuditLogger::log('delete', 'post', $id, ['name' => $before['name']], null);
    }

    /** @return array<string,mixed> */
    public static function find(int $id): array
    {
        $row = Database::connect()->query('SELECT * FROM posts WHERE id = ?', [$id])->getRowArray();
        if ($row === null) {
            throw new RuntimeException('That post does not exist.');
        }

        return $row;
    }

    private static function routeUses(int $id): int
    {
        return (int) Database::connect()->query('SELECT count(*) AS n FROM escalation_levels WHERE post_id = ?', [$id])->getRow('n');
    }

    private static function everHeld(int $id): int
    {
        return (int) Database::connect()->query('SELECT count(*) AS n FROM post_holders WHERE post_id = ?', [$id])->getRow('n');
    }
}
