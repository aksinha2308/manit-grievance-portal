<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Google login + OTP before submission.
 *  - master_people.email: the institute e-mail used to sign in (unique, optional until the admin fills it)
 *  - otp_codes: one-time passwords sent before a grievance is submitted
 *  - authorities no longer use passwords (only the admin keeps one, as an emergency login)
 *  - the captcha setting is gone (everyone is signed in now); OTP settings added
 */
class GoogleLoginOtp extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
        ALTER TABLE master_people ADD COLUMN email varchar(190);
        CREATE UNIQUE INDEX master_people_email_uq ON master_people (lower(email)) WHERE email IS NOT NULL;

        CREATE TABLE otp_codes (
            id          bigserial PRIMARY KEY,
            person_id   bigint       NOT NULL REFERENCES master_people(id) ON DELETE CASCADE,
            code_hash   varchar(255) NOT NULL,
            sent_email  varchar(190),
            sent_mobile varchar(20),
            attempts    integer      NOT NULL DEFAULT 0,
            expires_at  timestamptz  NOT NULL,
            used_at     timestamptz,
            created_at  timestamptz  NOT NULL DEFAULT now()
        );
        CREATE INDEX otp_codes_person_idx ON otp_codes (person_id, created_at DESC);

        UPDATE users SET must_change_password = false WHERE NOT is_admin;

        DELETE FROM settings WHERE key = 'captcha_enabled';
        INSERT INTO settings (key, value) VALUES
            ('otp_valid_minutes', '10'), ('otp_max_attempts', '5'), ('otp_resend_seconds', '60'), ('otp_max_per_hour', '5')
        ON CONFLICT (key) DO NOTHING;
        SQL);
    }

    public function down()
    {
        $this->db->query(<<<'SQL'
        DROP TABLE IF EXISTS otp_codes;
        DROP INDEX IF EXISTS master_people_email_uq;
        ALTER TABLE master_people DROP COLUMN IF EXISTS email;
        DELETE FROM settings WHERE key IN ('otp_valid_minutes', 'otp_max_attempts', 'otp_resend_seconds', 'otp_max_per_hour');
        INSERT INTO settings (key, value) VALUES ('captcha_enabled', '1') ON CONFLICT (key) DO NOTHING;
        SQL);
    }
}
