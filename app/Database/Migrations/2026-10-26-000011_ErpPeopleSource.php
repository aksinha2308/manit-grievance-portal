<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * People can now come from the institute ERP instead of the portal's own master list.
 *
 *  - settings.person_source: 'manual' (master list inside the portal, the old way; default) or 'erp'
 *  - settings.erp_cache_minutes: how long the details read from the ERP are reused inside one session
 *  - master_people.source / erp_synced_at: where a row came from. An 'erp' row holds IDENTITY ONLY (role, ID, name,
 *    e-mail); department, semester, hostel and contact are NOT stored for it: they are read from the ERP.
 *    department_id may therefore be empty.
 *  - departments.erp_code / hostels.erp_code: the ERP's code for each, so an ERP row can be matched to the portal's
 *    own department / hostel (which carry the posts and routes). Unique, ignoring case.
 *  - designation_roles: tells faculty from non-teaching staff by the ERP's job-title text. An unlisted title
 *    blocks that person's sign-in until the admin adds it.
 */
class ErpPeopleSource extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
        ALTER TABLE master_people ALTER COLUMN department_id DROP NOT NULL;
        ALTER TABLE master_people ADD COLUMN source varchar(10) NOT NULL DEFAULT 'manual' CHECK (source IN ('manual','erp'));
        ALTER TABLE master_people ADD COLUMN erp_synced_at timestamptz;

        ALTER TABLE departments ADD COLUMN erp_code varchar(30);
        CREATE UNIQUE INDEX departments_erp_code_uq ON departments (lower(btrim(erp_code))) WHERE erp_code IS NOT NULL;
        ALTER TABLE hostels ADD COLUMN erp_code varchar(30);
        CREATE UNIQUE INDEX hostels_erp_code_uq ON hostels (lower(btrim(erp_code))) WHERE erp_code IS NOT NULL;

        CREATE TABLE designation_roles (
            designation_key  varchar(120) PRIMARY KEY,
            designation_text varchar(120) NOT NULL,
            role             varchar(20)  NOT NULL CHECK (role IN ('faculty','non_teaching')),
            created_at       timestamptz  NOT NULL DEFAULT now()
        );
        INSERT INTO designation_roles (designation_key, designation_text, role) VALUES
            ('professor', 'Professor', 'faculty'),
            ('associate professor', 'Associate Professor', 'faculty'),
            ('assistant professor', 'Assistant Professor', 'faculty'),
            ('contract faculty', 'Contract Faculty', 'faculty')
        ON CONFLICT (designation_key) DO NOTHING;

        INSERT INTO settings (key, value) VALUES ('person_source', 'manual'), ('erp_cache_minutes', '5')
        ON CONFLICT (key) DO NOTHING;
        SQL);
    }

    public function down()
    {
        // Rows created from the ERP hold identity only and cannot satisfy the old NOT NULL rule: they are removed
        // (grievances keep their own copy of the submitter, so nothing is lost there).
        $this->db->query(<<<'SQL'
        DELETE FROM settings WHERE key IN ('person_source', 'erp_cache_minutes');
        DROP TABLE IF EXISTS designation_roles;
        DROP INDEX IF EXISTS hostels_erp_code_uq;
        ALTER TABLE hostels DROP COLUMN IF EXISTS erp_code;
        DROP INDEX IF EXISTS departments_erp_code_uq;
        ALTER TABLE departments DROP COLUMN IF EXISTS erp_code;
        DELETE FROM master_people WHERE source = 'erp';
        ALTER TABLE master_people DROP COLUMN IF EXISTS erp_synced_at;
        ALTER TABLE master_people DROP COLUMN IF EXISTS source;
        ALTER TABLE master_people ALTER COLUMN department_id SET NOT NULL;
        SQL);
    }
}
