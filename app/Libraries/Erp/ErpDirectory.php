<?php

namespace App\Libraries\Erp;

use App\Libraries\AuditLogger;
use App\Libraries\DesignationRoleService;
use App\Libraries\MasterListService;
use App\Libraries\Settings;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;
use Throwable;

/**
 * People from the institute ERP (setting person_source = erp).
 * Also provides the PostgreSQL queries; tests can inject FakeErpSource instead.
 *
 *  - signIn():       who owns this Google e-mail? Reads the ERP, then keeps a small IDENTITY row in master_people
 *                    (role, ID, name, e-mail). Nothing else about the person is stored.
 *  - currentPerson(): the signed-in person with department, semester, hostel, rank and mobile, read from the ERP and
 *                    reused inside the session for erp_cache_minutes, then read again.
 *
 * Matching rules (as in the institute's existing ERP code):
 *   student   roll number = the part of the Google e-mail before the @ ; the ERP e-mail column is not used
 *   staff     the ERP institute e-mail = the Google e-mail; faculty / non-teaching comes from the job title via
 *             DesignationRoleService (an unlisted title blocks sign-in until the admin adds it)
 * A person who is not in the ERP (or has left it) is "not registered". Two ERP rows for one person is a data
 * error: sign-in is refused and the audit log says why.
 */
class ErpDirectory
{
    private static ErpDirectory|FakeErpSource|null $source = null;

    /** @var array<int,?array<string,mixed>> */
    private static array $memo = [];

    public static function source(): ErpDirectory|FakeErpSource
    {
        return self::$source ??= new self();
    }

    /** Self-test only: use a fake ERP (null restores the real one). */
    public static function useSource(ErpDirectory|FakeErpSource|null $source): void
    {
        self::$source = $source;
        self::$memo   = [];
    }

    public function studentsByRoll(string $roll): array
    {
        //$db = $this->db('erp');
        $sql = "SELECT s.studentuid AS uid, s.full_name AS name,
                       s.fname, s.mname, s.lname, s.roll_no AS roll,
                       s.curr_dept AS dept, d.dept AS department_id, d.depname AS dept_name,
                       NULL AS mobile, x.semester_term_no_id_code AS semester,
                       NULL AS program
                  FROM academic.studentmas s
                    LEFT JOIN establishment.dpcode d ON d.dept = s.curr_dept
                  INNER JOIN academic.student_program_enrollment_versions x
                          ON x.studentuid = s.studentuid
                         AND x.semester_reg_completion_status = 'R'
                         AND x.program_master_id NOT IN (
                             SELECT pm.program_master_id
                               FROM academic.program_master pm
                              WHERE pm.program_master_id = x.program_master_id
                                AND pm.degree_level_id_code = 82
                         )
                 WHERE upper(btrim(s.roll_no::text)) = upper(btrim(?))
                 ORDER BY x.semester_term_no_id_code DESC
                 LIMIT 1";

        return $this->rows('erp', $sql, [$roll]);
    }

    public function employeesByEmail(string $email): array
    {
        $sql = "SELECT  e.emp_code AS code, e.empname AS name,
                       e.empdesg AS designation, e.empdept AS dept,
                       d.dept AS department_id, d.depname AS dept_name, e.mobile_no AS mobile,
                       e.inst_email AS email
                  FROM establishment.empmas e
                  LEFT JOIN establishment.dpcode d ON d.dept = e.empdept
                 WHERE lower(btrim(e.inst_email::text)) = lower(btrim(?))
                 LIMIT 5";

        return $this->rows('erp', $sql, [$email]);
    }

    public function hostelFor(string $studentUid): array
    {
        $sql = "SELECT ha.allot_room_no AS room,
                       CAST(hm.hostal_abber AS CHAR) AS hostel,
                       hm.hostal_hostal_name AS hostel_name
                  FROM manit_hostel_management.hostel_allot ha
                  INNER JOIN manit_hostel_management.hostel_rooms hr
                          ON hr.room_no = ha.allot_room_no
                  INNER JOIN manit_hostel_management.hostal_master hm
                          ON hr.hostel_id = hm.id_hostal_master
                 WHERE CAST(ha.allot_student_id AS CHAR) = ?
                   AND ha.allot_status = '1'
                   AND ha.allot_final_status = '1'
                   AND ha.delete_status = '0'
                 LIMIT 5";

        return $this->rows('erp_hostel', $sql, [$studentUid]);
    }

    public function allStudents(): array
    {
        $c  = config('Erp');
        $s  = $c->student;
        $db = $this->db('erp');
        $sql = 'SELECT ' . $this->cols($db, $s, ['uid', 'name', 'roll', 'dept', 'mobile', 'semester', 'program'])
             . ' FROM ' . $this->table($db, $c->academicSchema, $c->tables['student']);

        return $this->rows('erp', $sql, []);
    }

    public function allEmployees(): array
    {
        $c  = config('Erp');
        $e  = $c->employee;
        $db = $this->db('erp');
        $sql = 'SELECT ' . $this->cols($db, $e, ['code', 'name', 'designation', 'dept', 'mobile', 'email'])
             . ' FROM ' . $this->table($db, $c->academicSchema, $c->tables['employee']);

        return $this->rows('erp', $sql, []);
    }

    public function ping(): array
    {
        $out = [];
        $c   = config('Erp');
        foreach (['main' => ['erp', $c->academicSchema, $c->tables['student']], 'hostel' => ['erp_hostel', $c->hostelSchema, $c->tables['hostel_allot']]] as $key => [$group, $schema, $table]) {
            try {
                $db  = $this->db($group);
                $res = $this->rows($group, 'SELECT count(*) AS n FROM ' . $this->table($db, $schema, $table), []);
                $out[$key] = ['ok' => true, 'detail' => "connected; {$schema}.{$table} has " . (int) ($res[0]['n'] ?? 0) . ' rows'];
            } catch (ErpUnavailable $e) {
                $out[$key] = ['ok' => false, 'detail' => $e->getMessage()];
            }
        }

        return $out;
    }

    private function db(string $group): BaseConnection
    {
        try {
            $db = Database::connect($group);
            $db->initialize();
        } catch (Throwable $e) {
            log_message('error', "ERP connection '{$group}' failed: " . $e->getMessage());
            throw new ErpUnavailable("cannot connect ({$group})", 0, $e);
        }
        if (! $db->connID) {
            log_message('error', "ERP connection '{$group}' failed: " . ($db->error()['message'] ?? 'unknown'));
            throw new ErpUnavailable("cannot connect ({$group})");
        }

        return $db;
    }

    /**
     * @param array<string,mixed> $binds
     *
     * @return list<array<string,mixed>>
     */
    private function rows(string $group, string $sql, array $binds): array
    {
        $db = $this->db($group);
        try {
            $res = $db->query($sql, $binds);
        } catch (Throwable $e) {
            log_message('error', "ERP query on '{$group}' failed: " . $e->getMessage());
            throw new ErpUnavailable("query failed ({$group})", 0, $e);
        }
        if ($res === false) {
            log_message('error', "ERP query on '{$group}' failed: " . ($db->error()['message'] ?? 'unknown'));
            throw new ErpUnavailable("query failed ({$group})");
        }

        return $res->getResultArray();
    }

    private function table(BaseConnection $db, string $schema, string $table): string
    {
        return $db->escapeIdentifiers($schema) . '.' . $db->escapeIdentifiers($table);
    }

    /**
     * @param array<string,?string> $map
     * @param list<string>          $keys
     */
    private function cols(BaseConnection $db, array $map, array $keys): string
    {
        $parts = [];
        foreach ($keys as $k) {
            $parts[] = ($map[$k] ?? null) ? $db->escapeIdentifiers($map[$k]) . ' AS ' . $k : 'NULL AS ' . $k;
        }

        return implode(', ', $parts);
    }

    public static function enabled(): bool
    {
        return Settings::get('person_source', 'manual') === 'erp';
    }

    /**
     * The comparison key of an ERP department / hostel text: ignoring case, extra spaces and "&" versus "and".
     * (The SQL twin is keySql(); both must stay the same.)
     */
    public static function key(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace('&', 'and', $text)) ?? ''));
    }

    /** SQL expression that gives key() of a column (also used by the unique indexes). */
    public static function keySql(string $column): string
    {
        return "lower(regexp_replace(replace(btrim({$column}), '&', 'and'), '\\s+', ' ', 'g'))";
    }

    public static function forgetMemo(): void
    {
        self::$memo = [];
    }

    // ---------------------------------------------------------------------
    // Sign-in
    // ---------------------------------------------------------------------

    /**
     * @param string $kind 'student' | 'staff' (from the e-mail domain)
     *
     * @return array{status:string,person:?array<string,mixed>,message:?string}
     *         status: ok | not_found | unmapped_title | conflict | unavailable | unavailable_known
     *         (unavailable_known: the ERP is down but this person signed in before: they may sign in to read only)
     */
    public static function signIn(string $email, string $kind): array
    {
        $email = strtolower(trim($email));
        $res   = $kind === 'student'
            ? self::lookupStudent((string) strstr($email, '@', true))
            : self::lookupStaff($email);

        if ($res['status'] === 'ok') {
            $identity = self::upsertIdentity($res['profile'], $email);
            if ($identity === null) {
                self::issue('identity_conflict', $email, ['id_code' => $res['profile']['id_code']]);

                return ['status' => 'conflict', 'person' => null, 'message' => self::conflictMessage()];
            }
            self::keep((int) $identity['id'], $res['profile']);

            return ['status' => 'ok', 'person' => $identity, 'message' => null];
        }

        if ($res['status'] === 'unavailable') {
            $known = self::knownIdentity($email, $kind);
            if ($known !== null) {
                return ['status' => 'unavailable_known', 'person' => $known, 'message' => null];
            }

            return ['status' => 'unavailable', 'person' => null, 'message' => 'The institute records system cannot be reached right now, so we cannot confirm who you are. Please try again in a few minutes.'];
        }

        if ($res['status'] === 'unmapped_title') {
            self::issue('unmapped_designation', $email, ['designation' => $res['detail']]);

            return ['status' => 'unmapped_title', 'person' => null, 'message' => "Your job title (\"{$res['detail']}\") has not been set up in the portal yet. Please contact the administration."];
        }

        if ($res['status'] === 'conflict') {
            self::issue('duplicate_in_erp', $email, ['detail' => $res['detail']]);

            return ['status' => 'conflict', 'person' => null, 'message' => self::conflictMessage()];
        }

        // not_found: whoever is not in the ERP (any more) cannot sign in. A row we created earlier is switched off.
        self::deactivateLeaver($email, $kind);

        return ['status' => 'not_found', 'person' => null, 'message' => null];
    }

    // ---------------------------------------------------------------------
    // The signed-in person
    // ---------------------------------------------------------------------

    /**
     * The person in the shape the submission code expects (same keys as the master list person) plus an 'erp' part.
     * Null when the person is gone, switched off or no longer matches the ERP.
     *
     * @return array<string,mixed>|null
     */
    public static function currentPerson(string $role, int $id): ?array
    {
        if (array_key_exists($id, self::$memo)) {
            return self::$memo[$id];
        }

        $db       = Database::connect();
        $identity = $db->query("SELECT * FROM master_people WHERE id = ? AND role = ? AND active AND source = 'erp'", [$id, $role])->getRowArray();
        if ($identity === null) {
            return self::$memo[$id] = null;
        }

        $session = Services::session();
        $cached  = $session->get('erp_profile');
        $cached  = is_array($cached) && (int) ($cached['person_id'] ?? 0) === $id ? $cached : null;
        $fresh   = $cached !== null && (time() - (int) $cached['at']) <= self::cacheSeconds();

        $down = false;
        if ($fresh) {
            $profile = $cached['profile'];
        } else {
            $res = $role === 'student'
                ? self::lookupStudent((string) $identity['id_code'])
                : self::lookupStaff((string) $identity['email']);
            if ($res['status'] === 'ok') {
                $profile = $res['profile'];
                self::keep($id, $profile);
            } elseif ($res['status'] === 'unavailable') {
                $profile = $cached['profile'] ?? null;
                $down    = true;
            } else {
                if ($res['status'] === 'not_found') {
                    self::deactivateLeaver((string) $identity['email'], $role === 'student' ? 'student' : 'staff');
                }

                return self::$memo[$id] = null;
            }
        }

        return self::$memo[$id] = self::personRow($identity, $profile, $down);
    }

    /**
     * @param array<string,mixed>      $identity master_people row
     * @param array<string,mixed>|null $profile  null when the ERP could not be read
     *
     * @return array<string,mixed>
     */
    public static function personRow(array $identity, ?array $profile, bool $down): array
    {
        log_message('debug', 'inside personRow() - ERP student profile: ' . json_encode($profile, JSON_UNESCAPED_UNICODE));
        $issues = [];
        if ($profile === null) {
            $issues[] = 'erp_down';
        } elseif ($profile['department_id'] === null) {
            $issues[] = 'department_unmapped';
        }

        return [
            'id'            => (int) $identity['id'],
            'role'          => $identity['role'],
            'id_code'       => $identity['id_code'],
            'name'          => $profile['name'] ?? $identity['name'],
            'email'         => $identity['email'],
            'contact'       => $profile['mobile'] ?? null,
            'department_id' => $profile['department_id'] ?? null,
            'department'    => $profile['department'] ?? null,
            'semester'      => $profile['semester'] ?? null,
            'room'          => $profile['room'] ?? null,
            'hostel_code'   => $profile['hostel_code'] ?? null,
            'hostel'        => $profile['hostel'] ?? null,
            'active'        => true,
            'source'        => 'erp',
            'erp'           => [
                'down'          => $down || $profile === null,
                'issues'        => $issues,
                'erp_department' => $profile['erp_department'] ?? null,
                'hostel_status' => $profile['hostel_status'] ?? 'unknown',
                'hostel_code'   => $profile['hostel_code'] ?? null,
                'room'          => $profile['room'] ?? null,
                'program'       => $profile['program'] ?? null,
                'faculty_rank'  => $profile['faculty_rank'] ?? null,
                'job_title'     => $profile['job_title'] ?? null,
                'fetched_at'    => $profile['fetched_at'] ?? null,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // ERP lookups and clean-up
    // ---------------------------------------------------------------------

    /** @return array{status:string,profile:?array<string,mixed>,detail:?string} */
    public static function lookupStudent(string $roll): array
    {
        $roll = trim($roll);
        if ($roll === '' || mb_strlen($roll) > 50) {
            return ['status' => 'not_found', 'profile' => null, 'detail' => null];
        }
        try {
            $rows = self::source()->studentsByRoll($roll);
        } catch (ErpUnavailable) {
            return ['status' => 'unavailable', 'profile' => null, 'detail' => null];
        }
        if ($rows === []) {
            return ['status' => 'not_found', 'profile' => null, 'detail' => null];
        }
        if (count($rows) > 1) {
            return ['status' => 'conflict', 'profile' => null, 'detail' => "roll number {$roll} appears " . count($rows) . ' times in the ERP'];
        }

        $r  = $rows[0];
        $p  = [
            'role'           => 'student',
            'id_code'        => self::clean($r['roll'] ?? $roll),
            'name'           => self::clean($r['name'] ?? ''),
            'mobile'         => self::mobile($r['mobile'] ?? null),
            'erp_department' => self::clean($r['dept'] ?? ''),
            'department_id'  => isset($r['department_id']) ? (int) $r['department_id'] : null,
            'department'     => self::clean($r['dept_name'] ?? '') ?: null,
            'program'        => self::clean($r['program'] ?? '') ?: null,
            'faculty_rank'   => null,
            'job_title'      => null,
            'fetched_at'     => time(),
            'semester_no'      => self::clean($r['semester']-3 ?? null),
            'semester'      => 'Semester '.self::clean($r['semester']-3 ?? null),
            'hostel_code'   => null,
            'hostel'        => null,
            'room'          => null,
            'hostel_status' => 'unknown',
        ];
        //$p += self::semesterPart($db, $r['semester'] ?? null);
        $uid    = (string) ($r['uid'] ?? '');
        if ($uid === '') {
            $p['hostel_status'] = 'none';
        } else {
            try {
                $hostelRows = self::source()->hostelFor($uid);
            } catch (ErpUnavailable) {
                $hostelRows = null;
            }

            if ($hostelRows === []) {
                $p['hostel_status'] = 'none';
            } elseif (is_array($hostelRows) && count($hostelRows) > 1) {
                $p['hostel_status'] = 'conflict';
            } elseif (is_array($hostelRows)) {
                $room = self::clean($hostelRows[0]['room'] ?? '') ?: null;
                $code = self::clean($hostelRows[0]['hostel'] ?? '');
                $hostel = self::clean($hostelRows[0]['hostel_name'] ?? '') ?: null;

                $p['room']          = $room;
                $p['hostel_code']   = $code !== '' ? $code : null;
                $p['hostel']        = $hostel ?? ($code !== '' ? $code : null);
                $p['hostel_status'] = $p['hostel'] === null ? 'none' : 'ok';
            }
        }
        log_message('debug', 'ERP student profile: ' . json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['status' => 'ok', 'profile' => $p, 'detail' => null];
    }

    /** @return array{status:string,profile:?array<string,mixed>,detail:?string} */
    public static function lookupStaff(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return ['status' => 'not_found', 'profile' => null, 'detail' => null];
        }
        try {
            $rows = self::source()->employeesByEmail($email);
        } catch (ErpUnavailable) {
            return ['status' => 'unavailable', 'profile' => null, 'detail' => null];
        }
        if ($rows === []) {
            return ['status' => 'not_found', 'profile' => null, 'detail' => null];
        }
        if (count($rows) > 1) {
            return ['status' => 'conflict', 'profile' => null, 'detail' => "e-mail {$email} appears " . count($rows) . ' times in the ERP'];
        }

        $r     = $rows[0];
        $title = self::clean($r['designation'] ?? '');
        $entry = DesignationRoleService::find($title);
        if ($entry === null) {
            return ['status' => 'unmapped_title', 'profile' => null, 'detail' => $title === '' ? '(blank)' : $title];
        }
        //$role  = $entry['role'];
        if($title == '2' or $title == '3' or $title == '4'){
            $role = 'faculty';
        } else{
            $role = 'non_teaching';
        }
        $title = $entry['text'];   // the tidy spelling from the list

        $p  = [
            'role'           => $role,
            'id_code'        => self::clean($r['code'] ?? ''),
            'name'           => self::clean($r['name'] ?? ''),
            'mobile'         => self::mobile($r['mobile'] ?? null),
            'erp_department' => self::clean($r['dept'] ?? ''),
            'department_id'  => isset($r['department_id']) ? (int) $r['department_id'] : null,
            'department'     => self::clean($r['dept_name'] ?? '') ?: null,
            'program'        => null,
            'faculty_rank'   => $role === 'faculty' ? $title : null,
            'job_title'      => $role === 'non_teaching' ? $title : null,
            'semester'       => null,
            'semester_no'    => null,
            'hostel'         => null,
            'hostel_status'  => 'none',
            'room'           => null,
            'fetched_at'     => time(),
        ];
        if ($p['id_code'] === '' || $p['name'] === '') {
            return ['status' => 'conflict', 'profile' => null, 'detail' => 'the ERP row has no employee code or name'];
        }

        return ['status' => 'ok', 'profile' => $p, 'detail' => null];
    }

    /** Trim and collapse white space. */
    public static function clean(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    /**
     * A usable 10-digit mobile number or null. "+91 98765 43210" and "91 9876543210" become 9876543210; a landline
     * with an STD code ("0755-4051234") or anything that does not match the contact pattern is dropped.
     */
    public static function mobile(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) !== 10 || $digits[0] === '0') {
            return null;
        }

        return MasterListService::contactValid($digits) ? $digits : null;
    }

    // ---------------------------------------------------------------------

    /** @return array{semester:?string,semester_no:?int} */
    private static function semesterPart(BaseConnection $db, mixed $value): array
    {
        $n = (int) preg_replace('/\D+/', '', (string) $value);
        if ($n < 1) {
            return ['semester' => null, 'semester_no' => null];
        }
        $name = $db->query('SELECT name FROM semesters WHERE sort_order = ? ORDER BY id LIMIT 1', [$n])->getRow('name');

        return ['semester' => $name !== null ? (string) $name : "Semester {$n}", 'semester_no' => $n];
    }

    // ---------------------------------------------------------------------

    /**
     * Keep the person's identity (and only that) in master_people. Null when the e-mail or ID already belongs to a
     * different person row.
     *
     * @param array<string,mixed> $p profile
     *
     * @return array<string,mixed>|null the identity row
     */
    public static function upsertIdentity(array $p, ?string $email): ?array
    {
        $db       = Database::connect();
        $existing = $db->query('SELECT * FROM master_people WHERE role = ? AND lower(id_code) = lower(?)', [$p['role'], $p['id_code']])->getRowArray();
        if ($existing === null && $p['role'] !== 'student') {
            // A staff member whose job title moved them between faculty and non-teaching keeps the same row
            $existing = $db->query("SELECT * FROM master_people WHERE role IN ('faculty','non_teaching') AND lower(id_code) = lower(?) AND source = 'erp'", [$p['id_code']])->getRowArray();
        }
        $other    = $email === null ? null : $db->query('SELECT id FROM master_people WHERE lower(email) = lower(?) AND id <> ?', [$email, $existing['id'] ?? 0])->getRowArray();
        if ($other !== null) {
            return null;
        }

        if ($existing !== null) {
            $db->query(
                "UPDATE master_people SET role = ?, name = ?, email = COALESCE(?, email), active = true, source = 'erp', erp_synced_at = now(), updated_at = now() WHERE id = ?",
                [$p['role'], $p['name'], $email, $existing['id']]
            );
            $id = (int) $existing['id'];
        } else {
            if ($email === null) {
                return null;   // a new person is only ever created from a real sign-in (which always has the e-mail)
            }
            $id = (int) $db->query(
                "INSERT INTO master_people (role, id_code, name, email, active, source, erp_synced_at) VALUES (?, ?, ?, ?, true, 'erp', now()) RETURNING id",
                [$p['role'], $p['id_code'], $p['name'], $email]
            )->getRow('id');
        }

        return $db->query('SELECT * FROM master_people WHERE id = ?', [$id])->getRowArray();
    }

    /** @return array<string,mixed>|null a person who signed in before (so may still read while the ERP is down) */
    private static function knownIdentity(string $email, string $kind): ?array
    {
        $roles = $kind === 'student' ? ['student'] : ['faculty', 'non_teaching'];

        return Database::connect()->query(
            "SELECT * FROM master_people WHERE lower(email) = ? AND active AND source = 'erp' AND role IN ? LIMIT 1",
            [$email, $roles]
        )->getRowArray();
    }

    private static function deactivateLeaver(string $email, string $kind): void
    {
        $roles = $kind === 'student' ? ['student'] : ['faculty', 'non_teaching'];
        $db    = Database::connect();
        $row   = $db->query("SELECT id, id_code FROM master_people WHERE lower(email) = lower(?) AND active AND source = 'erp' AND role IN ?", [$email, $roles])->getRowArray();
        if ($row !== null) {
            $db->query('UPDATE master_people SET active = false, updated_at = now() WHERE id = ?', [$row['id']]);
            AuditLogger::log('erp_left', 'master_person', $row['id'], ['active' => true], ['active' => false, 'reason' => 'not found in the ERP']);
            self::$memo = [];
        }
    }

    /** Remember the details read from the ERP for this session. */
    private static function keep(int $personId, array $profile): void
    {
        Services::session()->set('erp_profile', ['person_id' => $personId, 'at' => time(), 'profile' => $profile]);
        self::$memo = [];
    }

    private static function cacheSeconds(): int
    {
        return max(60, Settings::int('erp_cache_minutes', 5) * 60);
    }

    /** Problems the admin should see (shown on the audit log; the ERP mapping page in the next step lists them). */
    private static function issue(string $issue, string $email, array $detail): void
    {
        AuditLogger::log('erp_issue', 'erp', null, null, ['issue' => $issue, 'email' => $email] + $detail);
    }

    private static function conflictMessage(): string
    {
        return 'Your record in the institute system could not be matched to a single person. Please contact the administration.';
    }
}
