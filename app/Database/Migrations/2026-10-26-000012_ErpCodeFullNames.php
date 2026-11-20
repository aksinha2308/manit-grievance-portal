<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The real ERP holds the FULL department name (not a short code) in its department columns, so departments.erp_code
 * and hostels.erp_code now hold up to 150 characters, and "the same text" means the same ignoring case, extra
 * spaces and "&" versus "and". The unique indexes use that same comparison.
 */
class ErpCodeFullNames extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
        DROP INDEX IF EXISTS departments_erp_code_uq;
        DROP INDEX IF EXISTS hostels_erp_code_uq;
        ALTER TABLE departments ALTER COLUMN erp_code TYPE varchar(150);
        ALTER TABLE hostels ALTER COLUMN erp_code TYPE varchar(150);
        CREATE UNIQUE INDEX departments_erp_code_uq ON departments (lower(regexp_replace(replace(btrim(erp_code), '&', 'and'), '\s+', ' ', 'g'))) WHERE erp_code IS NOT NULL;
        CREATE UNIQUE INDEX hostels_erp_code_uq ON hostels (lower(regexp_replace(replace(btrim(erp_code), '&', 'and'), '\s+', ' ', 'g'))) WHERE erp_code IS NOT NULL;
        SQL);
    }

    public function down()
    {
        $this->db->query(<<<'SQL'
        DROP INDEX IF EXISTS departments_erp_code_uq;
        DROP INDEX IF EXISTS hostels_erp_code_uq;
        UPDATE departments SET erp_code = left(erp_code, 30) WHERE length(erp_code) > 30;
        UPDATE hostels SET erp_code = left(erp_code, 30) WHERE length(erp_code) > 30;
        ALTER TABLE departments ALTER COLUMN erp_code TYPE varchar(30);
        ALTER TABLE hostels ALTER COLUMN erp_code TYPE varchar(30);
        CREATE UNIQUE INDEX departments_erp_code_uq ON departments (lower(btrim(erp_code))) WHERE erp_code IS NOT NULL;
        CREATE UNIQUE INDEX hostels_erp_code_uq ON hostels (lower(btrim(erp_code))) WHERE erp_code IS NOT NULL;
        SQL);
    }
}
