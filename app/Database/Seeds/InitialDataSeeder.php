<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use RuntimeException;

/**
 * Seeds the defaults agreed in the specification:
 *  - portal settings
 *  - semesters 1-8
 *  - posts (Head of Department, Warden, Dean, ...)
 *  - grievance types with short codes, and their escalation matrices
 *  - submission form fields per role
 *
 * Departments are sourced from establishment.dpcode. People and post holders are institute-specific;
 * hostel scopes are stored as text. The admin account is created with `php spark portal:create-admin`.
 *
 * Safe to run once on an empty database; refuses to run twice.
 */
class InitialDataSeeder extends Seeder
{
    public function run()
    {
        if ($this->db->table('posts')->countAllResults() > 0) {
            throw new RuntimeException('Initial data is already present. Refusing to seed twice.');
        }

        $this->db->transStart();

        $this->seedSettings();
        $this->seedSemesters();
        $d = $this->seedPosts();
        $this->seedTypesAndMatrices($d);
        $this->seedFormFields();

        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            throw new RuntimeException('Seeding failed; nothing was saved.');
        }
    }

    private function seedSettings(): void
    {
        $settings = [
            'institute_name'              => 'MANIT',
            'institute_logo'              => '',
            'ref_prefix'                  => 'MANIT',
            'ref_seq_digits'              => '6',
            'attach_allowed_ext'          => 'pdf,jpg,jpeg,png',
            'attach_max_mb'               => '5',
            'attach_max_count'            => '3',
            'otp_valid_minutes'           => '10',
            'otp_max_attempts'            => '5',
            'otp_resend_seconds'          => '60',
            'otp_max_per_hour'            => '5',
            'archive_after_years'         => '3',
            'contact_pattern'             => '^[0-9]{10}$',
            'overdue_default_days'        => '7',
            'session_idle_minutes'        => '30',
            'lockout_attempts'            => '5',
            'lockout_minutes'             => '15',
            'password_min_length'         => '8',
            // Faculty / Non-Teaching: exactly one type ("General") in v1. Flip to 1 later to allow more.
            'allow_multiple_types_faculty'      => '0',
            'allow_multiple_types_non_teaching' => '0',
        ];

        foreach ($settings as $key => $value) {
            // Some keys (OTP rules) are also created by a migration: keep what is there
            $this->db->query('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO NOTHING', [$key, $value]);
        }
    }

    private function seedSemesters(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->db->table('semesters')->insert(['name' => "Semester {$i}", 'sort_order' => $i]);
        }
    }

    /** @return array<string,int> post key => id */
    private function seedPosts(): array
    {
        $rows = [
            // key              => [name, scope_type, is_director]
            'hod'               => ['Head of Department', 'department', false],
            'warden'            => ['Warden', 'hostel', false],
            'reporting_officer' => ['Reporting Officer', 'department', false],
            'dean_academics'    => ['Dean (Academics)', null, false],
            'chair_sgc_acad'    => ['Chairman, Student Grievance Committee (Academic)', null, false],
            'chief_warden'      => ['Chief Warden', null, false],
            'chair_wardens'     => ['Chairman (Council of Wardens)', null, false],
            'chair_sgc_hostel'  => ['Chairman, Student Grievance Committee (Hostel)', null, false],
            'dean_welfare'      => ['Dean (Student Welfare)', null, false],
            'chair_proctorial'  => ['Chairman, Proctorial Committee', null, false],
            'dean_faculty'      => ['Dean (Faculty Welfare)', null, false],
            'chair_gc_faculty'  => ['Chairman, Grievance Committee (Faculty)', null, false],
            'registrar'         => ['Registrar', null, false],
            'chair_gc_nt'       => ['Chairman, Grievance Committee (Non-Teaching)', null, false],
            'director'          => ['Director', null, true],
        ];

        $ids = [];
        foreach ($rows as $key => [$name, $scope, $isDirector]) {
            $this->db->table('posts')->insert([
                'name'        => $name,
                'scope_type'  => $scope,
                'is_director' => $isDirector ? 'true' : 'false',
            ]);
            $ids[$key] = (int) $this->db->insertID();
        }

        return $ids;
    }

    /** @param array<string,int> $d */
    private function seedTypesAndMatrices(array $d): void
    {
        $types = [
            // [role, name, code, requires_hostel, sort, [post keys in order, ending with director]]
            ['student', 'Academic', 'ACD', false, 1,
                ['hod', 'dean_academics', 'chair_sgc_acad', 'director']],
            ['student', 'Hostel Related', 'HST', true, 2,
                ['warden', 'chief_warden', 'chair_wardens', 'chair_sgc_hostel', 'director']],
            ['student', 'Discipline', 'DSC', false, 3,
                ['dean_welfare', 'chair_proctorial', 'director']],
            ['student', 'Others', 'OTH', false, 4,
                ['dean_welfare', 'director']],
            ['faculty', 'General', 'FGEN', false, 1,
                ['hod', 'dean_faculty', 'chair_gc_faculty', 'director']],
            ['non_teaching', 'General', 'NGEN', false, 1,
                ['reporting_officer', 'registrar', 'chair_gc_nt', 'director']],
        ];

        foreach ($types as [$role, $name, $code, $requiresHostel, $sort, $route]) {
            $this->db->table('grievance_types')->insert([
                'role'            => $role,
                'name'            => $name,
                'code'            => $code,
                'requires_hostel' => $requiresHostel ? 'true' : 'false',
                'sort_order'      => $sort,
            ]);
            $typeId = (int) $this->db->insertID();

            $last = count($route);
            foreach ($route as $i => $key) {
                $this->db->table('escalation_levels')->insert([
                    'type_id'        => $typeId,
                    'level_no'       => $i + 1,
                    'post_id' => $d[$key],
                    'overdue_days'   => 7,
                    'is_final'       => ($i + 1 === $last) ? 'true' : 'false',
                ]);
            }
        }
    }

    private function seedFormFields(): void
    {
        // [role, key, label, type, mandatory, visible, sort, core]
        $fields = [
            // Student
            ['student', 'scholar_number', 'Scholar Number', 'text',     true,  true, 1, true],
            ['student', 'name',           'Name',           'text',     true,  true, 2, true],
            ['student', 'contact',        'Contact No.',    'text',     true,  true, 3, true],
            ['student', 'department',     'Department',     'dropdown', true,  true, 4, true],
            ['student', 'semester',       'Semester',       'dropdown', true,  true, 5, false],
            ['student', 'hostel',         'Hostel Number',  'dropdown', false, true, 6, true],
            // Faculty
            ['faculty', 'employee_code',  'Employee Code',  'text',     true,  true, 1, true],
            ['faculty', 'name',           'Name',           'text',     true,  true, 2, true],
            ['faculty', 'contact',        'Contact No.',    'text',     true,  true, 3, true],
            ['faculty', 'department',     'Department',     'dropdown', true,  true, 4, true],
            // Non-Teaching (Department = Section)
            ['non_teaching', 'employee_code', 'Employee Code', 'text',     true, true, 1, true],
            ['non_teaching', 'name',          'Name',          'text',     true, true, 2, true],
            ['non_teaching', 'contact',       'Contact No.',   'text',     true, true, 3, true],
            ['non_teaching', 'department',    'Department',    'dropdown', true, true, 4, true],
        ];

        foreach ($fields as [$role, $key, $label, $type, $mandatory, $visible, $sort, $core]) {
            $this->db->table('form_fields')->insert([
                'role'       => $role,
                'field_key'  => $key,
                'label'      => $label,
                'field_type' => $type,
                'mandatory'  => $mandatory ? 'true' : 'false',
                'visible'    => $visible ? 'true' : 'false',
                'sort_order' => $sort,
                'is_core'    => $core ? 'true' : 'false',
            ]);
        }
    }
}
