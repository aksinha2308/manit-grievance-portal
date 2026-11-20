<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A disposed grievance remembers the level it was disposed at. If the admin later removes that
 * level from the matrix, the reference is cleared (the action trail keeps the level's readable
 * name). A grievance that is still open must always have a current level: the matrix editor
 * re-routes open grievances before it removes a level, and this constraint guards that rule.
 */
class AllowNullLevelOnDisposed extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
        ALTER TABLE grievances ALTER COLUMN current_level_id DROP NOT NULL;
        ALTER TABLE grievances DROP CONSTRAINT grievances_current_level_id_fkey;
        ALTER TABLE grievances ADD CONSTRAINT grievances_current_level_id_fkey
            FOREIGN KEY (current_level_id) REFERENCES escalation_levels(id) ON DELETE SET NULL;
        ALTER TABLE grievances ADD CONSTRAINT grievances_open_has_level_chk
            CHECK (status = 'disposed' OR current_level_id IS NOT NULL);
        SQL);
    }

    public function down()
    {
        $this->db->query(<<<'SQL'
        ALTER TABLE grievances DROP CONSTRAINT grievances_open_has_level_chk;
        ALTER TABLE grievances DROP CONSTRAINT grievances_current_level_id_fkey;
        ALTER TABLE grievances ADD CONSTRAINT grievances_current_level_id_fkey
            FOREIGN KEY (current_level_id) REFERENCES escalation_levels(id);
        ALTER TABLE grievances ALTER COLUMN current_level_id SET NOT NULL;
        SQL);
    }
}
