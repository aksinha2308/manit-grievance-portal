<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One common department list.
 *
 * Before: every department belonged to exactly one list (student / faculty / non_teaching), so a department
 * such as "Computer Science" had to be entered twice.
 * After:  one row per department name with three flags (for_student, for_faculty, for_non_teaching).
 *
 * Existing same-named departments are merged into the oldest one. People, grievances and post
 * assignments that pointed at a duplicate are re-pointed to it. If a post (e.g. HOD) was held by
 * different people in two duplicates, the holder on the oldest department keeps it and the other
 * assignment is ended (history kept).
 */
class CommonDepartments extends Migration
{
    public function up()
    {
        $db = $this->db;

        $db->query('ALTER TABLE departments
            ADD COLUMN for_student boolean NOT NULL DEFAULT false,
            ADD COLUMN for_faculty boolean NOT NULL DEFAULT false,
            ADD COLUMN for_non_teaching boolean NOT NULL DEFAULT false');
        $db->query("UPDATE departments SET for_student = (role = 'student'), for_faculty = (role = 'faculty'), for_non_teaching = (role = 'non_teaching')");

        // old id -> id that survives
        $map = $db->query('SELECT id AS old_id, min(id) OVER (PARTITION BY lower(name)) AS keep_id FROM departments')->getResultArray();
        $dups = array_filter($map, static fn ($r) => (int) $r['old_id'] !== (int) $r['keep_id']);

        // Flags and active status of the survivor = union of the group
        $db->query('UPDATE departments k SET
                for_student = g.fs, for_faculty = g.ff, for_non_teaching = g.fn, active = g.act
            FROM (SELECT min(id) AS keep_id, bool_or(for_student) AS fs, bool_or(for_faculty) AS ff,
                         bool_or(for_non_teaching) AS fn, bool_or(active) AS act
                    FROM departments GROUP BY lower(name)) g
           WHERE k.id = g.keep_id');

        if ($dups !== []) {
            $keepOf = [];
            foreach ($map as $r) {
                $keepOf[(int) $r['old_id']] = (int) $r['keep_id'];
            }

            // Post assignments: one active holder per (designation, surviving department)
            $rows = $db->query(
                "SELECT a.id, a.designation_id, a.scope_id, a.active FROM designation_assignments a
                   JOIN designations d ON d.id = a.designation_id
                  WHERE d.scope_type = 'department' ORDER BY a.id"
            )->getResultArray();

            $kept = []; // "designation:keep" => assignment id that stays active
            // The assignment already on the surviving department wins; otherwise the lowest id
            foreach ($rows as $r) {
                if (! $this->isTrue($r['active'])) {
                    continue;
                }
                $keep = $keepOf[(int) $r['scope_id']] ?? (int) $r['scope_id'];
                $key  = $r['designation_id'] . ':' . $keep;
                if (! isset($kept[$key]) || (int) $r['scope_id'] === $keep) {
                    $kept[$key] = (int) $r['id'];
                }
            }
            foreach ($rows as $r) {
                if (! $this->isTrue($r['active'])) {
                    continue;
                }
                $keep = $keepOf[(int) $r['scope_id']] ?? (int) $r['scope_id'];
                if ($kept[$r['designation_id'] . ':' . $keep] !== (int) $r['id']) {
                    $db->query('UPDATE designation_assignments SET active = false, ended_at = now() WHERE id = ?', [$r['id']]);
                }
            }

            foreach ($dups as $r) {
                $old = (int) $r['old_id'];
                $new = (int) $r['keep_id'];
                $db->query("UPDATE designation_assignments a SET scope_id = ? FROM designations d
                             WHERE d.id = a.designation_id AND d.scope_type = 'department' AND a.scope_id = ?", [$new, $old]);
                $db->query('UPDATE master_people SET department_id = ? WHERE department_id = ?', [$new, $old]);
                $db->query('UPDATE grievances SET department_id = ? WHERE department_id = ?', [$new, $old]);
                $db->query('DELETE FROM departments WHERE id = ?', [$old]);
            }
        }

        $db->query('DROP INDEX IF EXISTS departments_role_name_uq');
        $db->query('ALTER TABLE departments DROP COLUMN role');
        $db->query('CREATE UNIQUE INDEX departments_name_uq ON departments (lower(name))');
        $db->query('ALTER TABLE departments ADD CONSTRAINT departments_used_by_chk CHECK (for_student OR for_faculty OR for_non_teaching)');
    }

    public function down()
    {
        // Not reversible: merged departments cannot be split again. Restore from a backup instead.
        throw new \RuntimeException('Migration 5 cannot be reversed. Restore the database backup taken before upgrading.');
    }

    private function isTrue(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1';
    }
}
