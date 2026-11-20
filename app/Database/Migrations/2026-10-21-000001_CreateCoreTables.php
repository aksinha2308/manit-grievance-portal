<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Core tables: sessions, users, designations, assignments, lists, settings, audit log.
 *
 * Posts and holders:
 *  - A designation (HOD, Warden, Dean...) may be "scoped" to a department or a hostel.
 *  - designation_assignments links a user to a post = (designation, scope).
 *    This single table is both "who holds the designation" and the HOD-by-department /
 *    Warden-by-hostel / Reporting-Officer-by-section mapping table.
 *  - Exactly one ACTIVE holder per post (partial unique index).
 */
class CreateCoreTables extends Migration
{
    public function up()
    {
        $sql = <<<'SQL'
        -- ---------------------------------------------------------------
        -- Sessions (CodeIgniter database session handler, PostgreSQL form)
        -- ---------------------------------------------------------------
        CREATE TABLE ci_sessions (
            id          varchar(128) NOT NULL,
            ip_address  inet         NOT NULL,
            timestamp   timestamptz  NOT NULL DEFAULT now(),
            data        bytea        NOT NULL DEFAULT ''
        );
        CREATE INDEX ci_sessions_timestamp ON ci_sessions (timestamp);
        ALTER TABLE ci_sessions ADD PRIMARY KEY (id);

        -- ---------------------------------------------------------------
        -- Users: authorities and the single admin (submitters never log in)
        -- ---------------------------------------------------------------
        CREATE TABLE users (
            id                    bigserial PRIMARY KEY,
            username              varchar(60)  NOT NULL,
            full_name             varchar(150) NOT NULL,
            email                 varchar(190),
            password_hash         varchar(255) NOT NULL,
            is_admin              boolean      NOT NULL DEFAULT false,
            active                boolean      NOT NULL DEFAULT true,
            must_change_password  boolean      NOT NULL DEFAULT true,
            failed_attempts       integer      NOT NULL DEFAULT 0,
            locked_until          timestamptz,
            last_login_at         timestamptz,
            created_at            timestamptz  NOT NULL DEFAULT now(),
            updated_at            timestamptz  NOT NULL DEFAULT now()
        );
        CREATE UNIQUE INDEX users_username_uq ON users (lower(username));
        CREATE UNIQUE INDEX users_email_uq    ON users (lower(email)) WHERE email IS NOT NULL;
        -- Only one admin account may exist
        CREATE UNIQUE INDEX users_single_admin_uq ON users (is_admin) WHERE is_admin;

        -- ---------------------------------------------------------------
        -- Lists maintained by admin
        -- ---------------------------------------------------------------
        CREATE TABLE departments (
            id          bigserial PRIMARY KEY,
            role        varchar(20)  NOT NULL CHECK (role IN ('student','faculty','non_teaching')),
            name        varchar(150) NOT NULL,
            active      boolean      NOT NULL DEFAULT true,
            created_at  timestamptz  NOT NULL DEFAULT now(),
            updated_at  timestamptz  NOT NULL DEFAULT now()
        );
        CREATE UNIQUE INDEX departments_role_name_uq ON departments (role, lower(name));

        CREATE TABLE hostels (
            id          bigserial PRIMARY KEY,
            name        varchar(150) NOT NULL,
            active      boolean      NOT NULL DEFAULT true,
            created_at  timestamptz  NOT NULL DEFAULT now(),
            updated_at  timestamptz  NOT NULL DEFAULT now()
        );
        CREATE UNIQUE INDEX hostels_name_uq ON hostels (lower(name));

        CREATE TABLE semesters (
            id          bigserial PRIMARY KEY,
            name        varchar(60)  NOT NULL,
            sort_order  integer      NOT NULL DEFAULT 0,
            active      boolean      NOT NULL DEFAULT true,
            created_at  timestamptz  NOT NULL DEFAULT now(),
            updated_at  timestamptz  NOT NULL DEFAULT now()
        );
        CREATE UNIQUE INDEX semesters_name_uq ON semesters (lower(name));

        -- ---------------------------------------------------------------
        -- Designations (posts) and who holds them
        -- ---------------------------------------------------------------
        CREATE TABLE designations (
            id           bigserial PRIMARY KEY,
            name         varchar(150) NOT NULL,
            -- NULL: a single fixed post (Dean, Chairman, Director...).
            -- 'department' / 'hostel': one post per department / hostel (HOD, Warden...).
            -- Department rows already belong to a role list (student / faculty / non_teaching),
            -- so one HOD designation serves both the student and the faculty department lists.
            scope_type   varchar(20) CHECK (scope_type IN ('department','hostel')),
            is_director  boolean     NOT NULL DEFAULT false,
            active       boolean     NOT NULL DEFAULT true,
            created_at   timestamptz NOT NULL DEFAULT now(),
            updated_at   timestamptz NOT NULL DEFAULT now()
        );
        CREATE UNIQUE INDEX designations_name_uq ON designations (lower(name));
        CREATE UNIQUE INDEX designations_single_director_uq ON designations (is_director) WHERE is_director;

        CREATE TABLE designation_assignments (
            id              bigserial PRIMARY KEY,
            designation_id  bigint  NOT NULL REFERENCES designations(id),
            -- scope_id points to departments.id or hostels.id depending on the designation; NULL for fixed posts
            scope_id        bigint,
            user_id         bigint  NOT NULL REFERENCES users(id),
            active          boolean NOT NULL DEFAULT true,
            assigned_at     timestamptz NOT NULL DEFAULT now(),
            ended_at        timestamptz
        );
        -- One active holder per post
        CREATE UNIQUE INDEX designation_assignments_post_uq
            ON designation_assignments (designation_id, COALESCE(scope_id, 0)) WHERE active;
        CREATE INDEX designation_assignments_user_idx ON designation_assignments (user_id) WHERE active;

        -- ---------------------------------------------------------------
        -- Portal settings (key/value)
        -- ---------------------------------------------------------------
        CREATE TABLE settings (
            key         varchar(100) PRIMARY KEY,
            value       text         NOT NULL,
            updated_at  timestamptz  NOT NULL DEFAULT now()
        );

        -- ---------------------------------------------------------------
        -- Audit log: append-only (UPDATE and DELETE are blocked by trigger)
        -- ---------------------------------------------------------------
        CREATE TABLE audit_log (
            id          bigserial PRIMARY KEY,
            user_id     bigint,
            username    varchar(60),
            action      varchar(40)  NOT NULL,
            entity      varchar(60)  NOT NULL,
            entity_id   varchar(60),
            before_data jsonb,
            after_data  jsonb,
            ip_address  varchar(45),
            created_at  timestamptz  NOT NULL DEFAULT now()
        );
        CREATE INDEX audit_log_created_idx ON audit_log (created_at DESC);
        CREATE INDEX audit_log_entity_idx  ON audit_log (entity, entity_id);

        CREATE FUNCTION audit_log_immutable() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'audit_log is append-only';
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER audit_log_no_update_delete
            BEFORE UPDATE OR DELETE ON audit_log
            FOR EACH ROW EXECUTE FUNCTION audit_log_immutable();
        CREATE TRIGGER audit_log_no_truncate
            BEFORE TRUNCATE ON audit_log
            FOR EACH STATEMENT EXECUTE FUNCTION audit_log_immutable();
        SQL;

        $this->db->query($sql);
    }

    public function down()
    {
        $this->db->query(<<<'SQL'
        DROP TABLE IF EXISTS audit_log CASCADE;
        DROP FUNCTION IF EXISTS audit_log_immutable();
        DROP TABLE IF EXISTS settings, designation_assignments, designations,
            semesters, hostels, departments, users, ci_sessions CASCADE;
        SQL);
    }
}
