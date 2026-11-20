<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * How the portal reads people (students, faculty, staff) from the institute ERP.
 *
 * Which source is used is the setting "person_source" (Admin > Settings; command: php spark portal:erp-enable):
 *   manual = the master list kept inside the portal (the old way)
 *   erp    = the ERP is the source; department, semester, hostel, rank and mobile are read fresh at every
 *            sign-in and again at submission when the session copy is older than "erp_cache_minutes".
 *
 * The ERP connections themselves are in .env (database.erp.* and database.erp_hostel.*).
 */
class Erp extends BaseConfig
{
    /** Query the real ERP tables. Only the self-test replaces this with an in-memory fake. */
    public string $driver = 'postgres';

    /** Schema names of the ERP (DUMMY ERP uses the same names as the real one, as far as they are known). */
    public string $academicSchema = 'academic';
    public string $hostelSchema   = 'manit_hostel_management';

    /**
     * Table and column names in the ERP. Edit the right-hand names when the ERP layout differs; set a column to
     * null if the ERP does not have it (the portal then treats that detail as missing). The student-by-roll
     * sign-in query is defined directly in App\Libraries\Erp\ErpDirectory.
     *
     * Known from the existing ERP code: studentmas (studentuid, full_name, roll_no, curr_dept), empmas (emp_code,
     * empname, empdesg, empdept, empmob, inst_email), hostel_allot (allot_student_id, allot_room_no, allot_status,
     * allot_final_status, delete_status).
     * DUMMY ERP: these are GUESSES until checked against the real ERP: student mobile / semester / program, the
     * hostel_id column of hostel_allot, and the whole hostel_master table. Department IDs and names come from
     * establishment.dpcode.
     */
    public array $tables = [
        'student'      => 'studentmas',
        'employee'     => 'empmas',
        'hostel_allot' => 'hostel_allot',
        'hostel_master' => 'hostel_master',   // DUMMY ERP: guessed table. null = no hostel names (only the room number)
    ];

    public array $student = [
        'uid'      => 'studentuid',
        'name'     => 'full_name',
        'roll'     => 'roll_no',
        'dept'     => 'curr_dept',
        'mobile'   => 'mobile_no',     // DUMMY ERP: guessed column (the real ERP may have none: use null)
        'semester' => 'curr_sem',      // DUMMY ERP: guessed column
        'program'  => 'course_name',   // DUMMY ERP: guessed column
    ];

    public array $employee = [
        'code'        => 'emp_code',
        'name'        => 'empname',
        'designation' => 'empdesg',
        'dept'        => 'empdept',
        'mobile'      => 'empmob',
        'email'       => 'inst_email',
    ];

    public array $hostel = [
        'student'     => 'allot_student_id',   // DUMMY ERP: assumed to hold the studentuid (as text)
        'room'        => 'allot_room_no',
        'hostel'      => 'hostel_id',          // DUMMY ERP: guessed column: which hostel (null = unknown)
        'status'      => 'allot_status',
        'final'       => 'allot_final_status',
        'deleted'     => 'delete_status',
        'master_id'   => 'hostel_id',          // DUMMY ERP: guessed hostel_master columns
        'master_name' => 'hostel_name',
    ];
}
