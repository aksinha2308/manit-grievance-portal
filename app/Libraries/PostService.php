<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;

/**
 * Posts and who holds them.
 *
 * A post (Head of Department, Warden...) is held per scope (a department ID or hostel text), or has no scope:
 *   - Dean (Academics)       -> one holder, no scope
 *   - Head of Department     -> one holder per department
 *   - Warden                 -> one holder per hostel
 * Each post-and-scope has at most one ACTIVE holder (enforced by a database index). Access to grievances
 * follows the post, not the person.
 */
class PostService
{
    public const ROLES = ['student', 'faculty', 'non_teaching'];

    /** Human-readable post name, e.g. "Head of Department (Computer Science)". */
    public static function label(string $postName, ?string $scopeType, int|string|null $scopeValue): string
    {
        if ($scopeType === null || $scopeValue === null || $scopeValue === '') {
            return $postName;
        }

        $name = $scopeType === 'department'
            ? Database::connect()->query('SELECT depname FROM establishment.dpcode WHERE dept = ?', [(int) $scopeValue])->getRow('depname')
            : trim((string) $scopeValue);

        return $name ? "{$postName} ({$name})" : $postName;
    }

    /**
     * Roles (student / faculty / non_teaching) whose grievance routes include this post.
     * A post not used in any route yet applies to all roles.
     *
     * @return list<string>
     */
    public static function applicableRoles(int $postId, bool $activeTypesOnly = false): array
    {
        $sql = 'SELECT DISTINCT t.role FROM escalation_levels l JOIN grievance_types t ON t.id = l.type_id
                 WHERE l.post_id = ?' . ($activeTypesOnly ? ' AND t.active' : '') . ' ORDER BY t.role';
        $rows = Database::connect()->query($sql, [$postId])->getResultArray();
        $roles = array_column($rows, 'role');

        return $roles === [] && ! $activeTypesOnly ? self::ROLES : $roles;
    }

    /**
     * Every post of a post with its current holder.
     *
     * @return list<array{scope_id:int|string|null,scope_name:?string,group:string,used_by:?string,user_id:?int,holder:?string}>
     */
    public static function seats(int $postId, bool $activeTypesOnly = false): array
    {
        $db   = Database::connect();
        $desc = $db->query('SELECT * FROM posts WHERE id = ?', [$postId])->getRowArray();
        if ($desc === null) {
            return [];
        }

        $scope = $desc['scope_type'];

        if ($scope === null) {
            $row = $db->query(
                'SELECT a.user_id, u.full_name FROM post_holders a JOIN users u ON u.id = a.user_id
                  WHERE a.post_id = ? AND a.scope_id IS NULL AND a.active',
                [$postId]
            )->getRowArray();

            return [[
                'scope_id' => null, 'scope_name' => null, 'group' => '', 'used_by' => null,
                'user_id'  => $row ? (int) $row['user_id'] : null,
                'holder'   => $row['full_name'] ?? null,
            ]];
        }

        if ($scope === 'hostel') {
            $rows = $db->query(
                "SELECT h.hostel AS scope_id, h.hostel AS scope_name, NULL AS used_by, a.user_id, u.full_name
                   FROM (
                       SELECT DISTINCT btrim(hostel) AS hostel
                         FROM (
                             SELECT hostel FROM master_people
                             UNION ALL SELECT hostel FROM grievances
                             UNION ALL SELECT hostel FROM post_holders
                         ) all_hostels
                        WHERE nullif(btrim(hostel), '') IS NOT NULL
                   ) h
                   LEFT JOIN post_holders a ON a.post_id = ? AND lower(btrim(a.hostel)) = lower(btrim(h.hostel)) AND a.active
                   LEFT JOIN users u ON u.id = a.user_id
                  ORDER BY lower(h.hostel)",
                [$postId]
            )->getResultArray();
        } else {
            $roles = self::applicableRoles($postId, $activeTypesOnly);
            if ($roles === []) {
                return [];
            }
            $rows = $db->query(
                "SELECT d.dept AS scope_id, d.depname AS scope_name, a.user_id, u.full_name
                   FROM establishment.dpcode d
                   LEFT JOIN post_holders a ON a.post_id = ? AND a.scope_id = d.dept AND a.active
                   LEFT JOIN users u ON u.id = a.user_id
                  ORDER BY lower(d.depname)",
                [$postId]
            )->getResultArray();
            foreach ($rows as &$row) {
                $row['used_by'] = implode(', ', array_map('role_label', PostService::ROLES));
            }
            unset($row);
        }

        return array_map(static fn (array $r) => [
            'scope_id'   => $scope === 'hostel' ? (string) $r['scope_id'] : (int) $r['scope_id'],
            'scope_name' => $r['scope_name'],
            'group'      => $scope === 'hostel' ? 'Hostels' : 'Departments',
            'used_by'    => $r['used_by'],
            'user_id'    => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'holder'     => $r['full_name'],
        ], $rows);
    }

    /**
     * Put a user in a post (replacing the current holder).
     *
     * @return list<string> messages describing what was done
     */
    public static function assign(int $postId, int|string|null $scopeValue, int $userId): array
    {
        $db   = Database::connect();
        $desc = $db->query('SELECT * FROM posts WHERE id = ?', [$postId])->getRowArray();

        if ($desc === null || ! is_true($desc['active'])) {
            throw new RuntimeException('That post is not available.');
        }

        $user = $db->query('SELECT id, full_name, active, is_admin FROM users WHERE id = ?', [$userId])->getRowArray();
        if ($user === null || ! is_true($user['active']) || is_true($user['is_admin'])) {
            throw new RuntimeException('Choose an active authority account.');
        }

        $scopeValue = self::assertScope($desc, $scopeValue);

        $messages = [];
        $db->transStart();
        $messages[] = self::assignOne($desc, $scopeValue, $user);

        $db->transComplete();

        if (! $db->transStatus()) {
            throw new RuntimeException('The change could not be saved.');
        }

        return array_values(array_filter($messages));
    }

    /** Make a post vacant. */
    public static function vacate(int $postId, int|string|null $scopeValue): void
    {
        $db   = Database::connect();
        $desc = $db->query('SELECT * FROM posts WHERE id = ?', [$postId])->getRowArray();
        if ($desc === null) {
            throw new RuntimeException('That post does not exist.');
        }

        $scopeValue = self::assertScope($desc, $scopeValue);
        $current = self::currentHolder($postId, $desc['scope_type'], $scopeValue);
        if ($current === null) {
            return;
        }

        $db->transStart();
        $db->query('UPDATE post_holders SET active = false, ended_at = now() WHERE id = ?', [$current['assignment_id']]);
        AuditLogger::log('vacate', 'post_holder', "{$postId}:" . ($scopeValue ?? '-'),
            ['post' => self::label($desc['name'], $desc['scope_type'], $scopeValue), 'holder' => $current['full_name']],
            ['holder' => null]);
        $db->transComplete();
    }

    /** End every active post of a user (used when the account is deactivated). Returns how many. */
    public static function endAllForUser(int $userId, string $reason): int
    {
        $db   = Database::connect();
        $rows = $db->query(
            'SELECT a.id, a.post_id, a.scope_id, a.hostel, d.name, d.scope_type
               FROM post_holders a JOIN posts d ON d.id = a.post_id
              WHERE a.user_id = ? AND a.active',
            [$userId]
        )->getResultArray();

        foreach ($rows as $row) {
            $scopeValue = self::assignmentScope($row);
            $db->query('UPDATE post_holders SET active = false, ended_at = now() WHERE id = ?', [$row['id']]);
            AuditLogger::log('vacate', 'post_holder', "{$row['post_id']}:" . ($scopeValue ?? '-'),
                ['post' => self::label($row['name'], $row['scope_type'], $scopeValue), 'user_id' => $userId],
                ['holder' => null, 'reason' => $reason]);
        }

        return count($rows);
    }

    /**
     * Posts that are used in the route of an active grievance type but have no active holder.
     * Grievances arriving at these posts wait until the admin assigns someone.
     *
     * @return list<array{post_id:int,post_name:string,scope_id:int|string|null,label:string}>
     */
    public static function vacancies(): array
    {
        $db   = Database::connect();
        $used = $db->query(
            'SELECT DISTINCT d.id, d.name, d.scope_type
               FROM posts d
               JOIN escalation_levels l ON l.post_id = d.id
               JOIN grievance_types t ON t.id = l.type_id AND t.active
              WHERE d.active ORDER BY d.name'
        )->getResultArray();

        $vacant = [];
        foreach ($used as $d) {
            $id = (int) $d['id'];
            foreach (self::seats($id, true) as $post) {
                if ($post['user_id'] === null) {
                    $vacant[] = [
                        'post_id' => $id,
                        'post_name'    => $d['name'],
                        'scope_id'       => $post['scope_id'],
                        'label'          => $post['scope_name'] === null ? $d['name'] : "{$d['name']} ({$post['group']}: {$post['scope_name']})",
                    ];
                }
            }
        }

        return $vacant;
    }

    // ---------------------------------------------------------------------

    private static function currentHolder(int $postId, ?string $scopeType, int|string|null $scopeValue): ?array
    {
        $db = Database::connect();
        $condition = match ($scopeType) {
            'department' => 'a.scope_id = ?',
            'hostel' => 'lower(btrim(a.hostel)) = lower(btrim(?))',
            default => 'a.scope_id IS NULL AND a.hostel IS NULL',
        };
        $sql = 'SELECT a.id AS assignment_id, a.user_id, u.full_name FROM post_holders a
                  JOIN users u ON u.id = a.user_id
                 WHERE a.post_id = ? AND a.active AND ' . $condition;
        $params = $scopeType === null ? [$postId] : [$postId, $scopeValue];

        return $db->query($sql, $params)->getRowArray();
    }

    /** @param array<string,mixed> $desc */
    private static function assertScope(array $desc, int|string|null $scopeValue): int|string|null
    {
        if ($desc['scope_type'] === null) {
            if ($scopeValue !== null) {
                throw new RuntimeException('This post is not tied to a department or hostel.');
            }

            return null;
        }

        if ($scopeValue === null || trim((string) $scopeValue) === '') {
            throw new RuntimeException('Choose a ' . $desc['scope_type'] . '.');
        }

        if ($desc['scope_type'] === 'department') {
            if (! ctype_digit((string) $scopeValue) || Database::connect()->query('SELECT 1 FROM establishment.dpcode WHERE dept = ?', [(int) $scopeValue])->getRowArray() === null) {
                throw new RuntimeException('That department is not available.');
            }

            return (int) $scopeValue;
        }

        $hostel = trim((string) $scopeValue);
        if (mb_strlen($hostel) > 150) {
            throw new RuntimeException('Hostel name or code must be 150 characters or fewer.');
        }

        return $hostel;
    }

    /** @return string|null a message, or null if nothing changed */
    private static function assignOne(array $desc, int|string|null $scopeValue, array $user): ?string
    {
        $db      = Database::connect();
        $current = self::currentHolder((int) $desc['id'], $desc['scope_type'], $scopeValue);
        $post    = self::label($desc['name'], $desc['scope_type'], $scopeValue);

        if ($current !== null && (int) $current['user_id'] === (int) $user['id']) {
            return null;
        }

        if ($current !== null) {
            $db->query('UPDATE post_holders SET active = false, ended_at = now() WHERE id = ?', [$current['assignment_id']]);
        }

        $db->table('post_holders')->insert([
            'post_id' => $desc['id'],
            'scope_id' => $desc['scope_type'] === 'department' ? $scopeValue : null,
            'hostel' => $desc['scope_type'] === 'hostel' ? $scopeValue : null,
            'user_id' => $user['id'],
        ]);

        AuditLogger::log('assign', 'post', "{$desc['id']}:" . ($scopeValue ?? '-'),
            ['post' => $post, 'holder' => $current['full_name'] ?? null],
            ['post' => $post, 'holder' => $user['full_name']]);

        return $post . ' is now held by ' . $user['full_name'] . '.';
    }

    /** @param array<string,mixed> $row */
    private static function assignmentScope(array $row): int|string|null
    {
        return $row['scope_type'] === 'hostel'
            ? (($row['hostel'] ?? '') !== '' ? (string) $row['hostel'] : null)
            : (($row['scope_id'] ?? null) !== null ? (int) $row['scope_id'] : null);
    }
}
