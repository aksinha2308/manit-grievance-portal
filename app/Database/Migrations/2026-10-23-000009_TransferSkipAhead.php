<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Transfer with skip-ahead: a level may be allowed ("can_skip") to transfer a grievance directly to any LATER level
 * of its type's route. The history records how many levels were skipped.
 */
class TransferSkipAhead extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
        ALTER TABLE escalation_levels ADD COLUMN can_skip boolean NOT NULL DEFAULT false;
        ALTER TABLE grievance_actions ADD COLUMN skipped_levels smallint NOT NULL DEFAULT 0 CHECK (skipped_levels >= 0);
        SQL);
    }

    public function down()
    {
        $this->db->query(<<<'SQL'
        ALTER TABLE grievance_actions DROP COLUMN skipped_levels;
        ALTER TABLE escalation_levels DROP COLUMN can_skip;
        SQL);
    }
}
