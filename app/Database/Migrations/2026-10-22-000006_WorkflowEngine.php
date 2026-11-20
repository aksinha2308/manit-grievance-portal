<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Module 5 (workflow engine):
 *  - grievance_actions remembers where an escalation / re-route sent the grievance (to_level_no, to_level_label),
 *    so the history reads "Escalated to Dean (Student Welfare)" even after the matrix changes. The append-only
 *    trigger is extended to protect the two new columns as well.
 *  - notification_events gains the 'overdue_reminder' event (written by `php spark portal:reminders`).
 *  - index for "grievances that passed through a post" (authority visibility).
 */
class WorkflowEngine extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
        ALTER TABLE grievance_actions ADD COLUMN to_level_no integer;
        ALTER TABLE grievance_actions ADD COLUMN to_level_label varchar(250);

        CREATE OR REPLACE FUNCTION grievance_actions_immutable() RETURNS trigger AS $$
        BEGIN
            IF TG_OP = 'UPDATE'
               AND NEW.id             = OLD.id
               AND NEW.grievance_id   = OLD.grievance_id
               AND NEW.level_no       IS NOT DISTINCT FROM OLD.level_no
               AND NEW.level_label    IS NOT DISTINCT FROM OLD.level_label
               AND NEW.to_level_no    IS NOT DISTINCT FROM OLD.to_level_no
               AND NEW.to_level_label IS NOT DISTINCT FROM OLD.to_level_label
               AND NEW.actor_user_id  IS NOT DISTINCT FROM OLD.actor_user_id
               AND NEW.actor_name     = OLD.actor_name
               AND NEW.action         = OLD.action
               AND NEW.remark         IS NOT DISTINCT FROM OLD.remark
               AND NEW.created_at     = OLD.created_at
               AND (NEW.level_id       IS NULL OR NEW.level_id       IS NOT DISTINCT FROM OLD.level_id)
               AND (NEW.designation_id IS NULL OR NEW.designation_id IS NOT DISTINCT FROM OLD.designation_id)
            THEN
                RETURN NEW;
            END IF;

            RAISE EXCEPTION 'grievance_actions is append-only';
        END;
        $$ LANGUAGE plpgsql;

        ALTER TABLE notification_events DROP CONSTRAINT notification_events_event_type_check;
        ALTER TABLE notification_events ADD CONSTRAINT notification_events_event_type_check
            CHECK (event_type IN ('submitted','arrived_at_level','escalated','info_requested','submitter_replied',
                                  'disposed','rerouted','overdue_reminder'));
        CREATE INDEX notification_events_reminder_idx ON notification_events (grievance_id, level_id, created_at)
            WHERE event_type = 'overdue_reminder';

        CREATE INDEX grievance_actions_designation_idx ON grievance_actions (designation_id, grievance_id);
        SQL);
    }

    public function down()
    {
        $this->db->query(<<<'SQL'
        DROP INDEX IF EXISTS grievance_actions_designation_idx;
        DROP INDEX IF EXISTS notification_events_reminder_idx;
        DELETE FROM notification_events WHERE event_type = 'overdue_reminder';
        ALTER TABLE notification_events DROP CONSTRAINT notification_events_event_type_check;
        ALTER TABLE notification_events ADD CONSTRAINT notification_events_event_type_check
            CHECK (event_type IN ('submitted','arrived_at_level','escalated','info_requested','submitter_replied','disposed','rerouted'));
        ALTER TABLE grievance_actions DROP COLUMN to_level_label;
        ALTER TABLE grievance_actions DROP COLUMN to_level_no;

        CREATE OR REPLACE FUNCTION grievance_actions_immutable() RETURNS trigger AS $$
        BEGIN
            IF TG_OP = 'UPDATE'
               AND NEW.id             = OLD.id
               AND NEW.grievance_id   = OLD.grievance_id
               AND NEW.level_no       IS NOT DISTINCT FROM OLD.level_no
               AND NEW.level_label    IS NOT DISTINCT FROM OLD.level_label
               AND NEW.actor_user_id  IS NOT DISTINCT FROM OLD.actor_user_id
               AND NEW.actor_name     = OLD.actor_name
               AND NEW.action         = OLD.action
               AND NEW.remark         IS NOT DISTINCT FROM OLD.remark
               AND NEW.created_at     = OLD.created_at
               AND (NEW.level_id       IS NULL OR NEW.level_id       IS NOT DISTINCT FROM OLD.level_id)
               AND (NEW.designation_id IS NULL OR NEW.designation_id IS NOT DISTINCT FROM OLD.designation_id)
            THEN
                RETURN NEW;
            END IF;

            RAISE EXCEPTION 'grievance_actions is append-only';
        END;
        $$ LANGUAGE plpgsql;
        SQL);
    }
}
