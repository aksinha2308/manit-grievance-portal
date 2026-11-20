<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Replace portal-owned department IDs with establishment.dpcode.dept IDs.
 */
class DpcodeDepartments extends Migration
{
    public function up()
    {
        $type = $this->db->query(
            "SELECT data_type FROM information_schema.columns
              WHERE table_schema = 'establishment' AND table_name = 'dpcode' AND column_name = 'dept'"
        )->getRow('data_type');

        if (! in_array($type, ['smallint', 'integer', 'bigint'], true)) {
            throw new RuntimeException('establishment.dpcode.dept must be an integer department ID before this migration can run.');
        }

        $unique = $this->db->query(
            "SELECT 1
               FROM pg_index i
               JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
              WHERE i.indrelid = 'establishment.dpcode'::regclass
                AND i.indisunique AND i.indisvalid AND i.indnatts = 1 AND a.attname = 'dept'
              LIMIT 1"
        )->getRowArray();
        if ($unique === null) {
            throw new RuntimeException('establishment.dpcode.dept must have a unique constraint or unique index before this migration can add the department foreign keys.');
        }

        if ($this->db->query('SELECT 1 FROM establishment.dpcode WHERE dept IS NULL LIMIT 1')->getRowArray() !== null
            || $this->db->query('SELECT dept FROM establishment.dpcode GROUP BY dept HAVING count(*) > 1 LIMIT 1')->getRowArray() !== null) {
            throw new RuntimeException('establishment.dpcode.dept must contain unique, non-null department IDs before this migration can run.');
        }

        $this->db->query('DROP TABLE IF EXISTS pg_temp.dpcode_department_map');
        $this->db->query(<<<'SQL'
        CREATE TEMP TABLE dpcode_department_map AS
        WITH used_departments AS (
            SELECT department_id AS old_id FROM master_people WHERE department_id IS NOT NULL
            UNION
            SELECT department_id FROM grievances WHERE department_id IS NOT NULL
            UNION
            SELECT a.scope_id
              FROM post_holders a
              JOIN posts p ON p.id = a.post_id
             WHERE p.scope_type = 'department' AND a.scope_id IS NOT NULL
        ),
        candidates AS (
            SELECT DISTINCT u.old_id, dp.dept::bigint AS new_id
              FROM used_departments u
              JOIN departments d ON d.id = u.old_id
              JOIN establishment.dpcode dp
                ON btrim(dp.dept::text) = btrim(COALESCE(d.erp_code, ''))
                OR lower(regexp_replace(replace(btrim(dp.depname), '&', 'and'), '\s+', ' ', 'g'))
                   = lower(regexp_replace(replace(btrim(d.name), '&', 'and'), '\s+', ' ', 'g'))
                OR (d.erp_code IS NOT NULL AND
                    lower(regexp_replace(replace(btrim(dp.depname), '&', 'and'), '\s+', ' ', 'g'))
                    = lower(regexp_replace(replace(btrim(d.erp_code), '&', 'and'), '\s+', ' ', 'g')))
        )
        SELECT u.old_id, count(c.new_id)::integer AS match_count, min(c.new_id) AS new_id
          FROM used_departments u
          LEFT JOIN candidates c ON c.old_id = u.old_id
         GROUP BY u.old_id
        SQL);

        $unmatched = $this->db->query(
            'SELECT old_id, match_count FROM dpcode_department_map WHERE match_count <> 1 ORDER BY old_id'
        )->getResultArray();
        if ($unmatched !== []) {
            $details = array_map(
                static fn (array $row): string => $row['old_id'] . ' (' . ($row['match_count'] === '0' || $row['match_count'] === 0 ? 'no match' : 'ambiguous match') . ')',
                $unmatched
            );
            throw new RuntimeException('Cannot migrate department references; match each portal department to exactly one establishment.dpcode row first: ' . implode(', ', $details));
        }

        $conflicts = $this->db->query(
            "SELECT a.post_id, m.new_id
               FROM post_holders a
               JOIN posts p ON p.id = a.post_id AND p.scope_type = 'department'
               JOIN dpcode_department_map m ON m.old_id = a.scope_id
              WHERE a.active
              GROUP BY a.post_id, m.new_id
             HAVING count(*) > 1
              LIMIT 10"
        )->getResultArray();
        if ($conflicts !== []) {
            throw new RuntimeException('Cannot migrate department post assignments: multiple active holders would map to the same post and dpcode department. Resolve those assignments, then rerun the migration.');
        }

        $this->db->query('ALTER TABLE master_people DROP CONSTRAINT IF EXISTS master_people_department_id_fkey');
        $this->db->query('ALTER TABLE grievances DROP CONSTRAINT IF EXISTS grievances_department_id_fkey');
        $this->db->query('UPDATE master_people p SET department_id = m.new_id FROM dpcode_department_map m WHERE p.department_id = m.old_id');
        $this->db->query('UPDATE grievances g SET department_id = m.new_id FROM dpcode_department_map m WHERE g.department_id = m.old_id');
        $this->db->query(
            "UPDATE post_holders a SET scope_id = m.new_id
               FROM dpcode_department_map m
               JOIN posts p ON p.scope_type = 'department'
              WHERE a.post_id = p.id AND a.scope_id = m.old_id"
        );
        $this->db->query(
            'ALTER TABLE master_people ADD CONSTRAINT master_people_department_dpcode_fkey FOREIGN KEY (department_id) REFERENCES establishment.dpcode (dept)'
        );
        $this->db->query(
            'ALTER TABLE grievances ADD CONSTRAINT grievances_department_dpcode_fkey FOREIGN KEY (department_id) REFERENCES establishment.dpcode (dept)'
        );
        $this->db->query('DROP TABLE departments');
        $this->db->query('DROP TABLE dpcode_department_map');
    }

    public function down()
    {
        throw new RuntimeException('This migration cannot be reversed automatically. Restore the database backup taken before switching departments to establishment.dpcode.');
    }
}
