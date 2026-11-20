<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;

/**
 * Transfer matrix editing (the "escalation" levels of a grievance type's route).
 *
 * Rules (from the specification):
 *  - The Director is always the LAST level of every grievance type. The Director level cannot be
 *    removed, moved, or replaced. Every other level can be added, removed or reordered.
 *  - A type may consist of the Director level alone.
 *  - The same post cannot appear twice in one route.
 *  - Matrix changes apply to open grievances too:
 *      * a level ADDED after a grievance's current level is simply passed through when it escalates
 *      * a level added before it is not applied retroactively (the grievance has already passed that point)
 *      * a level REMOVED while grievances sit at it: those grievances move to the next surviving level,
 *        and the history records "re-routed"
 *      * REORDERING does not move a grievance: it stays at the same level (same post); when it is
 *        handled it escalates to whichever level now follows that level
 */
class MatrixService
{
    public const MAX_OVERDUE_DAYS = 365;

    /**
     * Levels of a type, in order, with their post details.
     *
     * @return list<array<string,mixed>>
     */
    public static function levels(int $typeId): array
    {
        return Database::connect()->query(
            'SELECT l.id, l.level_no, l.overdue_days, l.is_final, l.can_skip, l.post_id,
                    d.name AS post_name, d.scope_type, d.is_director, d.active AS post_active,
                    (SELECT count(*) FROM grievances g WHERE g.current_level_id = l.id AND g.status <> \'disposed\') AS open_grievances
               FROM escalation_levels l JOIN posts d ON d.id = l.post_id
              WHERE l.type_id = ? ORDER BY l.level_no',
            [$typeId]
        )->getResultArray();
    }

    /**
     * Insert a level so that it becomes level number $position (existing levels from that number
     * on move down by one). Position can be anywhere up to the Director's current number, so the
     * Director always stays last.
     */
    public static function addLevel(int $typeId, int $postId, int $position, int $overdueDays): void
    {
        $db   = Database::connect();
        $type = self::type($typeId);
        $desc = $db->query('SELECT * FROM posts WHERE id = ?', [$postId])->getRowArray();

        if ($desc === null || ! is_true($desc['active'])) {
            throw new RuntimeException('Choose an active post.');
        }
        if (is_true($desc['is_director'])) {
            throw new RuntimeException('The Director level is added automatically and always stays last.');
        }
        if ($overdueDays < 1 || $overdueDays > self::MAX_OVERDUE_DAYS) {
            throw new RuntimeException('Overdue days must be between 1 and ' . self::MAX_OVERDUE_DAYS . '.');
        }
        if ($db->query('SELECT 1 FROM escalation_levels WHERE type_id = ? AND post_id = ?', [$typeId, $postId])->getRowArray()) {
            throw new RuntimeException("'{$desc['name']}' is already a level in this route.");
        }

        $final = $db->query('SELECT level_no FROM escalation_levels WHERE type_id = ? AND is_final', [$typeId])->getRowArray();
        if ($final === null) {
            throw new RuntimeException('This type has no Director level. Contact support.');
        }
        $position = max(1, min($position, (int) $final['level_no']));

        $db->transStart();
        // The order constraint is deferred, so levels can be shifted before the new one is inserted
        $db->query('UPDATE escalation_levels SET level_no = level_no + 1, updated_at = now() WHERE type_id = ? AND level_no >= ?', [$typeId, $position]);
        $db->table('escalation_levels')->insert([
            'type_id'        => $typeId,
            'level_no'       => $position,
            'post_id' => $postId,
            'overdue_days'   => $overdueDays,
            'is_final'       => pg_flag(false),
        ]);
        $id = (int) $db->insertID();
        AuditLogger::log('add_level', 'escalation_level', $id, null, [
            'type' => $type['name'], 'role' => $type['role'], 'post_name' => $desc['name'],
            'position' => $position, 'overdue_days' => $overdueDays,
        ]);
        $db->transComplete();

        if (! $db->transStatus()) {
            throw new RuntimeException('The level could not be added.');
        }
    }

    /**
     * Remove a level. Open grievances sitting at it move to the next level.
     *
     * @return int number of open grievances that were re-routed
     */
    public static function removeLevel(int $levelId): int
    {
        $db    = Database::connect();
        $level = self::level($levelId);
        $type  = self::type((int) $level['type_id']);

        if (is_true($level['is_final'])) {
            throw new RuntimeException('The Director level is fixed and cannot be removed.');
        }

        $next = $db->query(
            'SELECT l.*, d.name AS post_name, d.scope_type FROM escalation_levels l JOIN posts d ON d.id = l.post_id
              WHERE l.type_id = ? AND l.level_no > ? ORDER BY l.level_no LIMIT 1',
            [$level['type_id'], $level['level_no']]
        )->getRowArray();

        $db->transStart();

        $open = $db->query(
            "SELECT id, department_id, hostel, status FROM grievances WHERE current_level_id = ? AND status <> 'disposed' ORDER BY id FOR UPDATE",
            [$levelId]
        )->getResultArray();

        foreach ($open as $g) {
            $scopeValue = $next['scope_type'] === 'department'
                ? ($g['department_id'] === null ? null : (int) $g['department_id'])
                : ($next['scope_type'] === 'hostel' ? $g['hostel'] : null);
            $label   = PostService::label($next['post_name'], $next['scope_type'], $scopeValue);

            // The overdue clock restarts at the new level; a grievance waiting for the submitter keeps waiting
            $db->query(
                "UPDATE grievances SET current_level_id = ?, level_entered_at = now(), paused_seconds = 0,
                        paused_since = CASE WHEN status = 'awaiting_submitter' THEN now() ELSE NULL END, updated_at = now()
                  WHERE id = ?",
                [$next['id'], $g['id']]
            );
            $db->table('grievance_actions')->insert([
                'grievance_id' => $g['id'],
                'level_id'     => $next['id'],
                // After the removal every later level moves up by one
                'level_no'     => (int) $next['level_no'] - 1,
                'level_label'  => $label,
                'post_id' => $next['post_id'],
                'actor_name'   => 'System',
                'action'       => 'reroute',
                'remark'       => 'Re-routed automatically: the previous level was removed from the transfer matrix.',
                'to_level_no'    => (int) $next['level_no'] - 1,
                'to_level_label' => $label,
            ]);
            $db->table('notification_events')->insert([
                'grievance_id' => $g['id'],
                'event_type'   => 'rerouted',
                'level_id'     => $next['id'],
                'payload'      => json_encode(['reason' => 'level_removed']),
            ]);
        }

        $db->query('DELETE FROM escalation_levels WHERE id = ?', [$levelId]);
        self::renumber((int) $level['type_id']);

        AuditLogger::log('remove_level', 'escalation_level', $levelId, [
            'type' => $type['name'], 'role' => $type['role'], 'post_name' => $level['post_name'],
            'level_no' => (int) $level['level_no'],
        ], ['grievances_rerouted' => count($open), 'rerouted_to' => $next['post_name']]);

        $db->transComplete();

        if (! $db->transStatus()) {
            throw new RuntimeException('The level could not be removed.');
        }

        return count($open);
    }

    /** Swap a level with its neighbour. The Director level can neither move nor be passed. */
    public static function moveLevel(int $levelId, string $direction): void
    {
        $db    = Database::connect();
        $level = self::level($levelId);
        $type  = self::type((int) $level['type_id']);

        if (is_true($level['is_final'])) {
            throw new RuntimeException('The Director level is fixed and always stays last.');
        }

        $targetNo = (int) $level['level_no'] + ($direction === 'up' ? -1 : 1);
        $other    = $db->query('SELECT l.*, d.name AS post_name FROM escalation_levels l JOIN posts d ON d.id = l.post_id
                                 WHERE l.type_id = ? AND l.level_no = ?', [$level['type_id'], $targetNo])->getRowArray();

        if ($other === null) {
            throw new RuntimeException('This level is already at the top.');
        }
        if (is_true($other['is_final'])) {
            throw new RuntimeException('A level cannot be placed after the Director.');
        }

        $db->transStart();
        $db->query(
            'UPDATE escalation_levels SET level_no = CASE id WHEN ? THEN ? WHEN ? THEN ? END, updated_at = now() WHERE id IN (?, ?)',
            [$level['id'], $targetNo, $other['id'], $level['level_no'], $level['id'], $other['id']]
        );
        AuditLogger::log('move_level', 'escalation_level', $levelId,
            ['type' => $type['name'], 'role' => $type['role'], 'post_name' => $level['post_name'], 'level_no' => (int) $level['level_no']],
            ['level_no' => $targetNo, 'swapped_with' => $other['post_name']]);
        $db->transComplete();

        if (! $db->transStatus()) {
            throw new RuntimeException('The level could not be moved.');
        }
    }

    public static function setOverdueDays(int $levelId, int $days): void
    {
        if ($days < 1 || $days > self::MAX_OVERDUE_DAYS) {
            throw new RuntimeException('Overdue days must be between 1 and ' . self::MAX_OVERDUE_DAYS . '.');
        }

        $level = self::level($levelId);
        $type  = self::type((int) $level['type_id']);

        Database::connect()->query('UPDATE escalation_levels SET overdue_days = ?, updated_at = now() WHERE id = ?', [$days, $levelId]);
        AuditLogger::log('update_level', 'escalation_level', $levelId,
            ['type' => $type['name'], 'role' => $type['role'], 'post_name' => $level['post_name'], 'overdue_days' => (int) $level['overdue_days']],
            ['overdue_days' => $days]);
    }

    /** Allow (or forbid) the authority at this level to transfer a grievance directly to any later level. */
    public static function setCanSkip(int $levelId, bool $canSkip): void
    {
        $level = self::level($levelId);
        $type  = self::type((int) $level['type_id']);

        if (is_true($level['is_final'])) {
            throw new RuntimeException('The Director level is the last level; there is nothing to skip to.');
        }

        Database::connect()->query('UPDATE escalation_levels SET can_skip = ?, updated_at = now() WHERE id = ?', [pg_flag($canSkip), $levelId]);
        AuditLogger::log('update_level', 'escalation_level', $levelId,
            ['type' => $type['name'], 'role' => $type['role'], 'post_name' => $level['post_name'], 'can_skip' => is_true($level['can_skip'])],
            ['can_skip' => $canSkip]);
    }

    /** Close any gaps so levels are numbered 1..n in order. */
    public static function renumber(int $typeId): void
    {
        Database::connect()->query(
            'UPDATE escalation_levels l SET level_no = r.rn
               FROM (SELECT id, row_number() OVER (ORDER BY level_no) AS rn FROM escalation_levels WHERE type_id = ?) r
              WHERE l.id = r.id AND l.level_no <> r.rn',
            [$typeId]
        );
    }

    // ---------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function level(int $levelId): array
    {
        $row = Database::connect()->query(
            'SELECT l.*, d.name AS post_name FROM escalation_levels l JOIN posts d ON d.id = l.post_id WHERE l.id = ?',
            [$levelId]
        )->getRowArray();

        if ($row === null) {
            throw new RuntimeException('That level does not exist.');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private static function type(int $typeId): array
    {
        $row = Database::connect()->query('SELECT * FROM grievance_types WHERE id = ?', [$typeId])->getRowArray();
        if ($row === null) {
            throw new RuntimeException('That grievance type does not exist.');
        }

        return $row;
    }
}
