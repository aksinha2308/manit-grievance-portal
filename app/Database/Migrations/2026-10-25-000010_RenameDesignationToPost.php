<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Naming clean-up: "designation" becomes "post" everywhere in the database.
 *
 *   designations             -> posts
 *   designation_assignments  -> post_holders
 *   designation_id (post_holders, escalation_levels, grievance_actions) -> post_id
 *
 * Only names change: no data is touched, so existing rows, ids and history are kept as they are.
 * Sequences, primary keys, foreign keys, the check constraint and all indexes are renamed to match, and the
 * trigger function of the append-only grievance_actions table is replaced because it names the column.
 * down() restores the old names exactly.
 */
class RenameDesignationToPost extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
        ALTER TABLE designations            RENAME TO posts;
        ALTER TABLE designation_assignments RENAME TO post_holders;

        ALTER SEQUENCE designations_id_seq            RENAME TO posts_id_seq;
        ALTER SEQUENCE designation_assignments_id_seq RENAME TO post_holders_id_seq;

        ALTER TABLE post_holders       RENAME COLUMN designation_id TO post_id;
        ALTER TABLE escalation_levels  RENAME COLUMN designation_id TO post_id;
        ALTER TABLE grievance_actions  RENAME COLUMN designation_id TO post_id;

        ALTER TABLE posts             RENAME CONSTRAINT designations_pkey                            TO posts_pkey;
        ALTER TABLE posts             RENAME CONSTRAINT designations_scope_type_check                TO posts_scope_type_check;
        ALTER TABLE post_holders      RENAME CONSTRAINT designation_assignments_pkey                 TO post_holders_pkey;
        ALTER TABLE post_holders      RENAME CONSTRAINT designation_assignments_designation_id_fkey  TO post_holders_post_id_fkey;
        ALTER TABLE post_holders      RENAME CONSTRAINT designation_assignments_user_id_fkey         TO post_holders_user_id_fkey;
        ALTER TABLE escalation_levels RENAME CONSTRAINT escalation_levels_designation_id_fkey        TO escalation_levels_post_id_fkey;
        ALTER TABLE grievance_actions RENAME CONSTRAINT grievance_actions_designation_id_fkey        TO grievance_actions_post_id_fkey;

        ALTER INDEX designations_name_uq                RENAME TO posts_name_uq;
        ALTER INDEX designations_single_director_uq     RENAME TO posts_single_director_uq;
        ALTER INDEX designation_assignments_post_uq     RENAME TO post_holders_post_uq;
        ALTER INDEX designation_assignments_user_idx    RENAME TO post_holders_user_idx;
        ALTER INDEX escalation_levels_designation_idx   RENAME TO escalation_levels_post_idx;
        ALTER INDEX grievance_actions_designation_idx   RENAME TO grievance_actions_post_idx;
        SQL);

        $this->immutableFunction('post_id');
    }

    public function down()
    {
        $this->db->query(<<<'SQL'
        ALTER INDEX grievance_actions_post_idx   RENAME TO grievance_actions_designation_idx;
        ALTER INDEX escalation_levels_post_idx   RENAME TO escalation_levels_designation_idx;
        ALTER INDEX post_holders_user_idx        RENAME TO designation_assignments_user_idx;
        ALTER INDEX post_holders_post_uq         RENAME TO designation_assignments_post_uq;
        ALTER INDEX posts_single_director_uq     RENAME TO designations_single_director_uq;
        ALTER INDEX posts_name_uq                RENAME TO designations_name_uq;

        ALTER TABLE grievance_actions RENAME CONSTRAINT grievance_actions_post_id_fkey  TO grievance_actions_designation_id_fkey;
        ALTER TABLE escalation_levels RENAME CONSTRAINT escalation_levels_post_id_fkey  TO escalation_levels_designation_id_fkey;
        ALTER TABLE post_holders      RENAME CONSTRAINT post_holders_user_id_fkey       TO designation_assignments_user_id_fkey;
        ALTER TABLE post_holders      RENAME CONSTRAINT post_holders_post_id_fkey       TO designation_assignments_designation_id_fkey;
        ALTER TABLE post_holders      RENAME CONSTRAINT post_holders_pkey               TO designation_assignments_pkey;
        ALTER TABLE posts             RENAME CONSTRAINT posts_scope_type_check          TO designations_scope_type_check;
        ALTER TABLE posts             RENAME CONSTRAINT posts_pkey                      TO designations_pkey;

        ALTER TABLE grievance_actions  RENAME COLUMN post_id TO designation_id;
        ALTER TABLE escalation_levels  RENAME COLUMN post_id TO designation_id;
        ALTER TABLE post_holders       RENAME COLUMN post_id TO designation_id;

        ALTER SEQUENCE post_holders_id_seq RENAME TO designation_assignments_id_seq;
        ALTER SEQUENCE posts_id_seq        RENAME TO designations_id_seq;

        ALTER TABLE post_holders RENAME TO designation_assignments;
        ALTER TABLE posts        RENAME TO designations;
        SQL);

        $this->immutableFunction('designation_id');
    }

    /** The append-only guard of grievance_actions compares the post column by name, so it is rewritten for the new name. */
    private function immutableFunction(string $col): void
    {
        $this->db->query(<<<SQL
        CREATE OR REPLACE FUNCTION grievance_actions_immutable() RETURNS trigger AS \$\$
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
               AND (NEW.level_id IS NULL OR NEW.level_id IS NOT DISTINCT FROM OLD.level_id)
               AND (NEW.{$col} IS NULL OR NEW.{$col} IS NOT DISTINCT FROM OLD.{$col})
            THEN
                RETURN NEW;
            END IF;

            RAISE EXCEPTION 'grievance_actions is append-only';
        END;
        \$\$ LANGUAGE plpgsql;
        SQL);
    }
}
