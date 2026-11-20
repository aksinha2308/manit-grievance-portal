<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Late replies: when an authority escalates a grievance while its question to the submitter is still unanswered,
 * the submitter may still send the answer. It is recorded as 'late_reply' (a note in the history for whoever
 * handles the grievance now) and does not change the status or the overdue clock.
 */
class LateReplies extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
        ALTER TABLE grievance_actions DROP CONSTRAINT grievance_actions_action_check;
        ALTER TABLE grievance_actions ADD CONSTRAINT grievance_actions_action_check
            CHECK (action IN ('submit','escalate','dispose','request_info','submitter_reply','late_reply','reroute'));
        ALTER TABLE grievance_actions DROP CONSTRAINT grievance_actions_remark_chk;
        ALTER TABLE grievance_actions ADD CONSTRAINT grievance_actions_remark_chk
            CHECK (action NOT IN ('escalate','dispose','request_info','reroute','late_reply')
                   OR (remark IS NOT NULL AND length(btrim(remark)) > 0));
        SQL);
    }

    public function down()
    {
        // Late replies stay in the history (it is append-only), so the old rule cannot be restored if any exist
        $this->db->query(<<<'SQL'
        ALTER TABLE grievance_actions DROP CONSTRAINT grievance_actions_remark_chk;
        ALTER TABLE grievance_actions ADD CONSTRAINT grievance_actions_remark_chk
            CHECK (action NOT IN ('escalate','dispose','request_info','reroute')
                   OR (remark IS NOT NULL AND length(btrim(remark)) > 0));
        ALTER TABLE grievance_actions DROP CONSTRAINT grievance_actions_action_check;
        ALTER TABLE grievance_actions ADD CONSTRAINT grievance_actions_action_check
            CHECK (action IN ('submit','escalate','dispose','request_info','submitter_reply','reroute'));
        SQL);
    }
}
