<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Grievances, their action trail, attachments, reference counters, notification events.
 */
class CreateGrievanceTables extends Migration
{
    public function up()
    {
        $sql = <<<'SQL'
        -- ---------------------------------------------------------------
        -- Reference-number counters: one sequence per (year, type code).
        -- Incremented under a row lock inside the submission transaction.
        -- ---------------------------------------------------------------
        CREATE TABLE reference_counters (
            year       integer     NOT NULL,
            type_code  varchar(4)  NOT NULL,
            last_seq   integer     NOT NULL DEFAULT 0,
            PRIMARY KEY (year, type_code)
        );

        -- ---------------------------------------------------------------
        -- Grievances
        -- ---------------------------------------------------------------
        CREATE TABLE grievances (
            id                bigserial PRIMARY KEY,
            reference_no      varchar(40)  NOT NULL,
            type_id           bigint       NOT NULL REFERENCES grievance_types(id),
            role              varchar(20)  NOT NULL CHECK (role IN ('student','faculty','non_teaching')),
            id_code           varchar(50)  NOT NULL,          -- Scholar Number / Employee Code
            -- Snapshot of submitter details at submission (name, department, semester, hostel, contact,
            -- and labels), so later master-list changes never alter the record
            submitter         jsonb        NOT NULL,
            -- Routing keys captured at submission (used to resolve scoped posts: HOD, Warden...)
            department_id     bigint       REFERENCES departments(id),
            hostel_id         bigint       REFERENCES hostels(id),
            subject           varchar(250) NOT NULL,
            description       text         NOT NULL,
            custom            jsonb        NOT NULL DEFAULT '{}'::jsonb,  -- admin-defined field values (snapshot)
            current_level_id  bigint       NOT NULL REFERENCES escalation_levels(id),
            status            varchar(20)  NOT NULL DEFAULT 'pending'
                              CHECK (status IN ('pending','awaiting_submitter','disposed')),
            -- Overdue tracking: when the grievance entered the current level, and time spent
            -- waiting for the submitter at this level (the overdue clock is paused while waiting)
            level_entered_at  timestamptz  NOT NULL DEFAULT now(),
            paused_since      timestamptz,
            paused_seconds    integer      NOT NULL DEFAULT 0,
            disposed_at       timestamptz,
            archived          boolean      NOT NULL DEFAULT false,
            archived_at       timestamptz,
            created_at        timestamptz  NOT NULL DEFAULT now(),
            updated_at        timestamptz  NOT NULL DEFAULT now(),
            CONSTRAINT grievances_paused_chk CHECK ((status = 'awaiting_submitter') = (paused_since IS NOT NULL))
        );
        CREATE UNIQUE INDEX grievances_reference_uq ON grievances (reference_no);
        CREATE INDEX grievances_submitter_idx ON grievances (role, lower(id_code));
        CREATE INDEX grievances_type_idx      ON grievances (type_id);
        CREATE INDEX grievances_level_status_idx ON grievances (current_level_id, status);
        CREATE INDEX grievances_status_idx    ON grievances (status) WHERE NOT archived;
        CREATE INDEX grievances_created_idx   ON grievances (created_at DESC);
        CREATE INDEX grievances_department_idx ON grievances (department_id);
        CREATE INDEX grievances_hostel_idx    ON grievances (hostel_id) WHERE hostel_id IS NOT NULL;
        -- Related-grievance lookup (same submitter + same type + still pending)
        CREATE INDEX grievances_related_idx ON grievances (role, lower(id_code), type_id)
            WHERE status <> 'disposed';

        -- ---------------------------------------------------------------
        -- Action trail: every step, in order. Never updated or deleted.
        -- level_id can become NULL if the level is later removed from the matrix;
        -- level_label keeps the readable name so history stays accurate.
        -- ---------------------------------------------------------------
        CREATE TABLE grievance_actions (
            id              bigserial PRIMARY KEY,
            grievance_id    bigint       NOT NULL REFERENCES grievances(id),
            level_id        bigint       REFERENCES escalation_levels(id) ON DELETE SET NULL,
            level_no        integer,
            level_label     varchar(250),                 -- e.g. "Head of Department (Computer Science)"
            designation_id  bigint       REFERENCES designations(id) ON DELETE SET NULL,
            actor_user_id   bigint       REFERENCES users(id),   -- NULL when the actor is the submitter or the system
            actor_name      varchar(150) NOT NULL,        -- the person who actually acted, as at that time
            action          varchar(20)  NOT NULL
                            CHECK (action IN ('submit','escalate','dispose','request_info','submitter_reply','reroute')),
            remark          text,
            created_at      timestamptz  NOT NULL DEFAULT now(),
            -- Remark is mandatory for every action an authority takes
            CONSTRAINT grievance_actions_remark_chk
                CHECK (action NOT IN ('escalate','dispose','request_info','reroute')
                       OR (remark IS NOT NULL AND length(btrim(remark)) > 0))
        );
        CREATE INDEX grievance_actions_grievance_idx ON grievance_actions (grievance_id, id);
        CREATE INDEX grievance_actions_actor_idx ON grievance_actions (actor_user_id, action);

        -- Rows can never be deleted or edited. The single permitted change is the foreign-key
        -- clean-up that sets level_id / designation_id to NULL when the admin removes a level or
        -- designation from the configuration; level_no and level_label keep the history readable.
        CREATE FUNCTION grievance_actions_immutable() RETURNS trigger AS $$
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
        CREATE TRIGGER grievance_actions_no_update
            BEFORE UPDATE OR DELETE ON grievance_actions
            FOR EACH ROW EXECUTE FUNCTION grievance_actions_immutable();

        -- ---------------------------------------------------------------
        -- Attachments (files live outside the web root; only metadata here)
        -- ---------------------------------------------------------------
        CREATE TABLE attachments (
            id             bigserial PRIMARY KEY,
            grievance_id   bigint       NOT NULL REFERENCES grievances(id),
            action_id      bigint       REFERENCES grievance_actions(id),  -- set for submitter-reply attachments
            original_name  varchar(255) NOT NULL,
            stored_name    varchar(120) NOT NULL,
            mime_type      varchar(100) NOT NULL,
            size_bytes     bigint       NOT NULL CHECK (size_bytes >= 0),
            created_at     timestamptz  NOT NULL DEFAULT now()
        );
        CREATE UNIQUE INDEX attachments_stored_uq ON attachments (stored_name);
        CREATE INDEX attachments_grievance_idx ON attachments (grievance_id);

        -- ---------------------------------------------------------------
        -- Notification events: logged now so email can be attached later (no sending in v1)
        -- ---------------------------------------------------------------
        CREATE TABLE notification_events (
            id            bigserial PRIMARY KEY,
            grievance_id  bigint       NOT NULL REFERENCES grievances(id),
            event_type    varchar(30)  NOT NULL
                          CHECK (event_type IN ('submitted','arrived_at_level','escalated','info_requested','submitter_replied','disposed','rerouted')),
            level_id      bigint       REFERENCES escalation_levels(id) ON DELETE SET NULL,
            payload       jsonb        NOT NULL DEFAULT '{}'::jsonb,
            processed     boolean      NOT NULL DEFAULT false,
            created_at    timestamptz  NOT NULL DEFAULT now()
        );
        CREATE INDEX notification_events_pending_idx ON notification_events (id) WHERE NOT processed;
        SQL;

        $this->db->query($sql);
    }

    public function down()
    {
        $this->db->query(<<<'SQL'
        DROP TABLE IF EXISTS notification_events, attachments, grievance_actions, grievances, reference_counters CASCADE;
        DROP FUNCTION IF EXISTS grievance_actions_immutable();
        SQL);
    }
}
