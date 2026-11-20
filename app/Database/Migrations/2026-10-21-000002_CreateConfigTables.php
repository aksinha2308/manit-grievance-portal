<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Configuration tables: master list, grievance types, escalation matrix, form fields.
 */
class CreateConfigTables extends Migration
{
    public function up()
    {
        $sql = <<<'SQL'
        -- ---------------------------------------------------------------
        -- Master list: everyone who may submit a grievance
        -- ---------------------------------------------------------------
        CREATE TABLE master_people (
            id             bigserial PRIMARY KEY,
            role           varchar(20)  NOT NULL CHECK (role IN ('student','faculty','non_teaching')),
            id_code        varchar(50)  NOT NULL,          -- Scholar Number / Employee Code
            name           varchar(150) NOT NULL,
            department_id  bigint       NOT NULL REFERENCES departments(id),
            semester_id    bigint       REFERENCES semesters(id),   -- students only
            hostel_id      bigint       REFERENCES hostels(id),     -- students only; NULL = day scholar
            contact        varchar(20),
            active         boolean      NOT NULL DEFAULT true,
            extra          jsonb        NOT NULL DEFAULT '{}'::jsonb,  -- admin-defined custom fields
            created_at     timestamptz  NOT NULL DEFAULT now(),
            updated_at     timestamptz  NOT NULL DEFAULT now()
        );
        CREATE UNIQUE INDEX master_people_role_id_uq ON master_people (role, lower(id_code));
        CREATE INDEX master_people_department_idx ON master_people (department_id);
        CREATE INDEX master_people_hostel_idx ON master_people (hostel_id) WHERE hostel_id IS NOT NULL;

        -- ---------------------------------------------------------------
        -- Grievance types (per role). Short code is used in the reference number.
        -- ---------------------------------------------------------------
        CREATE TABLE grievance_types (
            id          bigserial PRIMARY KEY,
            role        varchar(20)  NOT NULL CHECK (role IN ('student','faculty','non_teaching')),
            name        varchar(150) NOT NULL,
            code        varchar(4)   NOT NULL CHECK (code ~ '^[A-Z]{2,4}$'),
            -- Hostel Related grievances need the student's hostel to route to a Warden
            requires_hostel boolean  NOT NULL DEFAULT false,
            sort_order  integer      NOT NULL DEFAULT 0,
            active      boolean      NOT NULL DEFAULT true,
            created_at  timestamptz  NOT NULL DEFAULT now(),
            updated_at  timestamptz  NOT NULL DEFAULT now()
        );
        CREATE UNIQUE INDEX grievance_types_code_uq ON grievance_types (code);
        CREATE UNIQUE INDEX grievance_types_role_name_uq ON grievance_types (role, lower(name));

        -- ---------------------------------------------------------------
        -- Escalation matrix: ordered levels per grievance type.
        -- A level points at a designation. If the designation is scoped
        -- (HOD, Warden...), the actual holder is resolved from the
        -- submitter's department / hostel via designation_assignments.
        -- ---------------------------------------------------------------
        CREATE TABLE escalation_levels (
            id              bigserial PRIMARY KEY,
            type_id         bigint   NOT NULL REFERENCES grievance_types(id),
            level_no        integer  NOT NULL CHECK (level_no >= 1),
            designation_id  bigint   NOT NULL REFERENCES designations(id),
            overdue_days    integer  NOT NULL DEFAULT 7 CHECK (overdue_days >= 1),
            is_final        boolean  NOT NULL DEFAULT false,   -- the Director level
            created_at      timestamptz NOT NULL DEFAULT now(),
            updated_at      timestamptz NOT NULL DEFAULT now(),
            -- Deferrable so levels can be re-numbered inside one transaction
            CONSTRAINT escalation_levels_order_uq UNIQUE (type_id, level_no) DEFERRABLE INITIALLY DEFERRED
        );
        -- Exactly one final level per type
        CREATE UNIQUE INDEX escalation_levels_one_final_uq ON escalation_levels (type_id) WHERE is_final;
        CREATE INDEX escalation_levels_designation_idx ON escalation_levels (designation_id);

        -- ---------------------------------------------------------------
        -- Configurable submission form fields (per role)
        -- ---------------------------------------------------------------
        CREATE TABLE form_fields (
            id          bigserial PRIMARY KEY,
            role        varchar(20)  NOT NULL CHECK (role IN ('student','faculty','non_teaching')),
            field_key   varchar(60)  NOT NULL,
            label       varchar(150) NOT NULL,
            field_type  varchar(20)  NOT NULL CHECK (field_type IN ('text','number','dropdown','date')),
            options     jsonb        NOT NULL DEFAULT '[]'::jsonb,   -- dropdown choices
            mandatory   boolean      NOT NULL DEFAULT false,
            visible     boolean      NOT NULL DEFAULT true,
            sort_order  integer      NOT NULL DEFAULT 0,
            is_core     boolean      NOT NULL DEFAULT false,         -- core fields cannot be removed or made optional
            created_at  timestamptz  NOT NULL DEFAULT now(),
            updated_at  timestamptz  NOT NULL DEFAULT now(),
            -- Core fields can never be hidden (mandatory-ness of each is enforced by the application,
            -- because e.g. Hostel Number is core but empty for day scholars)
            CONSTRAINT form_fields_core_chk CHECK (NOT is_core OR visible)
        );
        CREATE UNIQUE INDEX form_fields_role_key_uq ON form_fields (role, field_key);
        SQL;

        $this->db->query($sql);
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS form_fields, escalation_levels, grievance_types, master_people CASCADE');
    }
}
