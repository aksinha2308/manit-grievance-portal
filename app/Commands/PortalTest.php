<?php

namespace App\Commands;

use App\Libraries\BulkUserImporter;
use App\Libraries\PostCatalogService;
use App\Libraries\FormFieldService;
use App\Libraries\ListService;
use App\Libraries\MasterListImporter;
use App\Libraries\MasterListService;
use App\Libraries\MatrixService;
use App\Libraries\PostService;
use App\Libraries\Auth;
use App\Libraries\DesignationRoleService;
use App\Libraries\Erp\ErpDirectory;
use App\Libraries\Erp\FakeErpSource;
use App\Libraries\GoogleAuth;
use App\Libraries\OtpService;
use App\Libraries\Settings;
use App\Libraries\SettingsDefinition;
use App\Libraries\SubmissionException;
use App\Libraries\SubmissionService;
use App\Libraries\SimpleXlsx;
use App\Libraries\TypeService;
use App\Libraries\UserService;
use App\Libraries\WorkflowService;
use App\Libraries\TrackingService;
use App\Libraries\GrievanceSearch;
use App\Libraries\ExportService;
use App\Libraries\ReportService;
use App\Libraries\ArchiveService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Self-test of the portal's rules. Works on Windows, Linux and macOS with nothing but PHP:
 *
 *   php spark portal:test
 *
 * Every check runs inside ONE database transaction that is rolled back at the end, so your data is not
 * changed (database sequence numbers do advance). It uses the services directly, not the web pages.
 * Run it on a development database, never as a routine on a live one.
 */
class PortalTest extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'portal:test';
    protected $description = 'Runs the portal self-test (changes nothing: everything is rolled back).';
    protected $usage       = 'portal:test [--force]';
    protected $options     = ['--force' => 'Run even when CI_ENVIRONMENT is production'];

    private int $pass = 0;
    private int $fail = 0;
    /** @var list<string> */
    private array $tmp = [];
    /** @var array<string, int> */
    private array $testDepartments = [];

    public function run(array $params)
    {
        if (ENVIRONMENT === 'production' && CLI::getOption('force') === null) {
            CLI::error('Refusing to run in production. Use --force if you really mean it.');

            return EXIT_ERROR;
        }

        $db = Database::connect();
        $existing = glob(WRITEPATH . 'imports/*.json') ?: [];
        $db->transBegin();

        try {
            $this->lists();
            $this->postsAndTypes();
            $this->matrix();
            $this->users();
            $this->posts();
            $this->settings();
            $this->formFields();
            $this->masterList();
            $this->masterListUpload();
            $this->submission();
            $this->workflow();
            $this->skipAhead();
            $this->tracking();
            $this->adminTools();
            $this->bulkUsers();
            $this->xlsx();
            $this->googleAndOtp();
            $this->erpPeople();
        } catch (Throwable $e) {
            $this->fail++;
            CLI::error('The self-test stopped with an unexpected error: ' . $e->getMessage());
            CLI::write($e->getTraceAsString());
        } finally {
            // Roll back everything, however deeply nested a failed service left the transaction
            for ($i = 0; $i < 20 && $db->transDepth > 0; $i++) {
                $db->transRollback();
            }
            foreach ($this->tmp as $file) {
                @unlink($file);
            }
            foreach (array_diff(glob(WRITEPATH . 'imports/*.json') ?: [], $existing) as $file) {
                @unlink($file);
            }
        }

        CLI::newLine();
        CLI::write("Passed: {$this->pass}   Failed: {$this->fail}", $this->fail === 0 ? 'green' : 'red');
        CLI::write('Nothing was changed in your database.');

        return $this->fail === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }


    // ------------------------------------------------------------------ people from the ERP (fake ERP)

    private function erpPeople(): void
    {
        CLI::write("\n-- people from the ERP (in-memory fake ERP)", 'yellow');
        helper('portal');
        $db  = Database::connect();
        $stu = config('Google')->studentDomain;
        $stf = config('Google')->staffDomain;
        $session = service('session');
        $fake = new FakeErpSource();
        ErpDirectory::useSource($fake);
        Settings::set('person_source', 'erp');
        Settings::set('erp_cache_minutes', '5');

        try {
            // Clean-up rules
            $this->check('mobile: +91 and spaces removed', '9876543210', ErpDirectory::mobile('+91 98765 43210'));
            $this->check('mobile: 91 prefix removed', '9876543210', ErpDirectory::mobile('91 9876543210'));
            $this->check('mobile: plain 10 digits kept', '9876543210', ErpDirectory::mobile('9876543210'));
            $this->check('mobile: landline with STD code dropped', null, ErpDirectory::mobile('0755-4051234'));
            $this->check('mobile: too short / blank dropped', [null, null], [ErpDirectory::mobile('12345'), ErpDirectory::mobile('')]);
            $this->check('title key ignores case, spaces and a full stop', true, DesignationRoleService::key(' ASSISTANT   Professor. ') === 'assistant professor');
            $this->check('listed title: role and tidy spelling', ['faculty', 'Assistant Professor'], array_values(DesignationRoleService::find(' ASSISTANT  PROFESSOR ') ?? []));
            $this->check('unlisted title is not found', null, DesignationRoleService::find('Visiting Faculty'));
            $this->refused('role must be faculty or non_teaching', fn () => DesignationRoleService::set('Peon', 'student'), 'faculty or non_teaching');

            $dep  = $this->departmentId('erp');
            $depName = $this->departmentName($dep);

            $fake->students = [
                ['uid' => '7001', 'name' => 'Erp  Student', 'roll' => 'ZZE001', 'dept' => (string) $dep, 'department_id' => $dep, 'dept_name' => $depName, 'mobile' => '+91 98765 43210', 'semester' => 3, 'program' => 'B.Tech'],
                ['uid' => '7002', 'name' => 'Day Scholar', 'roll' => 'ZZE002', 'dept' => (string) $dep, 'department_id' => $dep, 'dept_name' => $depName, 'mobile' => null, 'semester' => 1, 'program' => 'M.Tech'],
                ['uid' => '7003', 'name' => 'Odd Hostel', 'roll' => 'ZZE003', 'dept' => (string) $dep, 'department_id' => $dep, 'dept_name' => $depName, 'mobile' => '9000000003', 'semester' => 5, 'program' => 'B.Tech'],
                ['uid' => '7004', 'name' => 'Odd Dept', 'roll' => 'ZZE004', 'dept' => 'NOSUCH', 'department_id' => null, 'dept_name' => null, 'mobile' => '9000000004', 'semester' => 2, 'program' => 'B.Tech'],
                ['uid' => '7005', 'name' => 'Twin One', 'roll' => 'ZZE005', 'dept' => (string) $dep, 'department_id' => $dep, 'dept_name' => $depName, 'mobile' => '9000000005', 'semester' => 2, 'program' => 'B.Tech'],
                ['uid' => '7006', 'name' => 'Twin Two', 'roll' => 'ZZE005', 'dept' => (string) $dep, 'department_id' => $dep, 'dept_name' => $depName, 'mobile' => '9000000006', 'semester' => 2, 'program' => 'B.Tech'],
            ];
            $fake->hostels = [
                '7001' => [['room' => '214', 'hostel' => 'ZZH1', 'hostel_name' => 'ZZ Erp Hostel One']],
                '7003' => [['room' => '9', 'hostel' => 'NOSUCHH', 'hostel_name' => 'Unknown Hostel']],
            ];
            $fake->employees = [
                ['code' => 'ZZ-F100', 'name' => 'Erp Faculty', 'designation' => 'ASSISTANT PROFESSOR ', 'dept' => (string) $dep, 'department_id' => $dep, 'dept_name' => $depName, 'mobile' => '0755-4051234', 'email' => "Erp.Faculty@{$stf} "],
                ['code' => 'ZZ-N100', 'name' => 'Erp Clerk', 'designation' => 'Records Keeper', 'dept' => (string) $dep, 'department_id' => $dep, 'dept_name' => $depName, 'mobile' => '9000000100', 'email' => "erp.clerk@{$stf}"],
                ['code' => 'ZZ-F101', 'name' => 'Dup One', 'designation' => 'Professor', 'dept' => (string) $dep, 'department_id' => $dep, 'dept_name' => $depName, 'mobile' => null, 'email' => "dup@{$stf}"],
                ['code' => 'ZZ-F102', 'name' => 'Dup Two', 'designation' => 'Professor', 'dept' => (string) $dep, 'department_id' => $dep, 'dept_name' => $depName, 'mobile' => null, 'email' => "dup@{$stf}"],
            ];

            // Student sign-in: roll number from the e-mail, any case; identity only is stored
            [$ok] = Auth::loginWithEmail("ZZe001@{$stu}");
            $row = $db->query("SELECT * FROM master_people WHERE lower(id_code) = 'zze001'")->getRowArray();
            $this->check('student found by roll number from the e-mail (any case)', [true, 'student', 'Erp Student'], [$ok, $row['role'] ?? null, $row['name'] ?? null]);
            $this->check('only identity is stored for an ERP person', [null, null, null, null, 'erp'], [$row['department_id'], $row['semester_id'], $row['hostel'], $row['contact'], $row['source']]);
            $this->check('session points at that person', (int) $row['id'], (int) $session->get('person_id'));
            ErpDirectory::forgetMemo();
            $p = Auth::person();
            $this->check('details come from the ERP and dpcode', [$depName, 'Semester 3', 'ZZ Erp Hostel One', '9876543210', '214'], [$p['department'], $p['semester'], $p['hostel'], $p['contact'], $p['erp']['room']]);
            $this->check('ERP program, department ID and hostel value are available', ['B.Tech', $dep, 'ZZ Erp Hostel One'], [$p['erp']['program'], $p['department_id'], $p['hostel']]);
            $this->check('an ERP person has no blocker', [], SubmissionService::erpBlockers($p));

            // The session copy is reused, then read again when it is old
            $calls = $fake->calls;
            ErpDirectory::forgetMemo();
            Auth::person();
            $this->check('session copy reused: the ERP is not asked again', $calls, $fake->calls);
            $fake->students[0]['semester'] = 4;
            $fake->students[0]['dept'] = 'NOSUCH';
            $fake->students[0]['department_id'] = null;
            $fake->students[0]['dept_name'] = null;
            ErpDirectory::forgetMemo();
            $this->check('still the old semester inside the cache window', 'Semester 3', Auth::person()['semester']);
            $cached = $session->get('erp_profile');
            $cached['at'] = time() - 3600;
            $session->set('erp_profile', $cached);
            ErpDirectory::forgetMemo();
            $p = Auth::person();
            $this->check('read again when old: new semester and unmapped department seen', ['Semester 4', null, true], [$p['semester'], $p['department_id'], in_array('department_unmapped', $p['erp']['issues'], true)]);
            $this->has('unmapped department blocks submission with a clear message', SubmissionService::erpBlockers($p), 'has not been set up in the portal');
            $fake->students[0]['dept'] = (string) $dep;
            $fake->students[0]['department_id'] = $dep;
            $fake->students[0]['dept_name'] = $depName;

            // Hostel states
            $this->check('day scholar: no hostel row, status none', ['none', null], [ErpDirectory::lookupStudent('ZZE002')['profile']['hostel_status'], ErpDirectory::lookupStudent('ZZE002')['profile']['hostel']]);
            $this->check('hostel text from ERP does not need portal mapping', ['ok', 'Unknown Hostel'], [ErpDirectory::lookupStudent('ZZE003')['profile']['hostel_status'], ErpDirectory::lookupStudent('ZZE003')['profile']['hostel']]);
            $fake->hostelDown = true;
            [$ok] = Auth::loginWithEmail("zze001@{$stu}");
            $this->check('hostel database down: student can still sign in, hostel unknown', [true, 'unknown'], [$ok, Auth::person()['erp']['hostel_status']]);
            $hostelType = $this->id('grievance_types', "code = 'HST'");
            $good = ['type_id' => $hostelType, 'contact' => '9876543210', 'subject' => 'Water', 'description' => 'No water.'];
            $errs = function (array $person, array $in): array {
                try {
                    SubmissionService::submit($person, $in);
                } catch (SubmissionException $e) {
                    return $e->errors;
                }

                return [];
            };
            $this->has('hostel grievance blocked while the hostel database is down', $errs(Auth::person(), $good), 'hostel records cannot be reached');
            $fake->hostelDown = false;
            Auth::loginWithEmail("zze001@{$stu}");
            $acd = ['type_id' => $this->id('grievance_types', "code = 'ACD'")] + $good;
            $ref = SubmissionService::submit(Auth::person(), $acd);
            $g = $db->query('SELECT g.department_id, g.hostel, g.room, g.id_code, g.submitter FROM grievances g WHERE g.reference_no = ?', [$ref['reference_no'] ?? $ref])->getRowArray();
            $snap = json_decode((string) ($g['submitter'] ?? '{}'), true);
            $this->check('submitted: plain-text hostel and room are stored', [$dep, 'ZZ Erp Hostel One', '214', 'ZZE001', 'B.Tech', '214', $depName], [(int) $g['department_id'], $g['hostel'], $g['room'], $g['id_code'], $snap['program'] ?? null, $snap['hostel_room'] ?? null, $snap['department'] ?? null]);

            // Staff: faculty vs non-teaching by title
            [$ok] = Auth::loginWithEmail("erp.faculty@{$stf}");
            ErpDirectory::forgetMemo();
            $p = Auth::person();
            $this->check('staff e-mail matched ignoring case/space; faculty by title', [true, 'faculty', 'ZZ-F100', 'Assistant Professor'], [$ok, $p['role'] ?? null, $p['id_code'] ?? null, $p['erp']['faculty_rank'] ?? null]);
            $this->check('staff department comes from dpcode lookup', [$dep, $depName], [$p['department_id'], $p['department']]);
            $this->check('landline is not used as a mobile', null, $p['contact']);
            $before = (int) $this->val("SELECT count(*) FROM audit_log WHERE action = 'erp_issue'");
            [$ok, $msg] = Auth::loginWithEmail("erp.clerk@{$stf}");
            $this->check('unlisted job title blocks sign-in with a clear message', [false, true, null], [$ok, str_contains((string) $msg, 'Records Keeper') && str_contains((string) $msg, 'not been set up'), $session->get('person_id')]);
            $this->check('and no identity row was created', 0, $this->val("SELECT count(*) FROM master_people WHERE id_code = 'ZZ-N100'"));
            $this->check('and the admin can see it in the audit log', $before + 1, (int) $this->val("SELECT count(*) FROM audit_log WHERE action = 'erp_issue'"));
            DesignationRoleService::set('Records Keeper', 'non_teaching');
            [$ok] = Auth::loginWithEmail("erp.clerk@{$stf}");
            ErpDirectory::forgetMemo();
            $p = Auth::person();
            $this->check('after the admin lists the title: signs in as non-teaching', [true, 'non_teaching', 'Records Keeper', null], [$ok, $p['role'] ?? null, $p['erp']['job_title'] ?? null, $p['erp']['faculty_rank'] ?? null]);

            // Data errors and people who are not in the ERP
            [$ok, $msg] = Auth::loginWithEmail("zze005@{$stu}");
            $this->check('roll number twice in the ERP: refused, nothing created', [false, true, 0], [$ok, str_contains((string) $msg, 'contact the administration'), (int) $this->val("SELECT count(*) FROM master_people WHERE lower(id_code) = 'zze005'")]);
            [$ok] = Auth::loginWithEmail("dup@{$stf}");
            $this->check('staff e-mail twice in the ERP: refused', false, $ok);
            [$ok, $msg] = Auth::loginWithEmail("nobody@{$stu}");
            $this->check('not in the ERP: not registered', [false, true], [$ok, str_contains((string) $msg, 'not registered')]);
            $db->query("INSERT INTO master_people (role, id_code, name, email, department_id) VALUES ('student', 'ZZ-MANUAL', 'Manual Row', ?, ?)", ["zze004@{$stu}", $dep]);
            [$ok] = Auth::loginWithEmail("zze004@{$stu}");
            $this->check('e-mail already used by another person row: refused', false, $ok);
            $db->query("DELETE FROM master_people WHERE id_code = 'ZZ-MANUAL'");
            [$ok] = Auth::loginWithEmail("zze004@{$stu}");
            ErpDirectory::forgetMemo();
            $this->check('unmapped department: can sign in, but cannot submit', [true, true], [$ok, SubmissionService::erpBlockers(Auth::person()) !== []]);

            // ERP down
            Auth::loginWithEmail("zze001@{$stu}");
            $fake->down = true;
            [$ok] = Auth::loginWithEmail("zze001@{$stu}");
            ErpDirectory::forgetMemo();
            $p = Auth::person();
            $this->check('ERP down: a known person can sign in to read', [true, true], [$ok, $p['erp']['down'] ?? null]);
            $this->has('ERP down: new grievances are blocked', SubmissionService::erpBlockers($p), 'cannot be reached');
            $this->has('ERP down: submit() refuses', $errs($p, ['type_id' => $acd['type_id']] + $good), 'cannot be reached');
            [$ok, $msg] = Auth::loginWithEmail("zze002@{$stu}");
            $this->check('ERP down: a person never seen before cannot sign in', [false, true], [$ok, str_contains((string) $msg, 'cannot be reached')]);
            $authUid = UserService::create('zz.erpauth', 'Erp Authority', "erp.auth@{$stf}");
            [$ok] = Auth::loginWithEmail("erp.auth@{$stf}");
            $this->check('ERP down: an authority account still signs in', [true, $authUid], [$ok, (int) $session->get('user_id')]);
            $fake->down = false;

            // Someone who leaves the ERP while signed in
            Auth::loginWithEmail("zze001@{$stu}");
            $fake->students = array_values(array_filter($fake->students, static fn ($x) => $x['roll'] !== 'ZZE001'));
            $cached = $session->get('erp_profile');
            $cached['at'] = time() - 3600;
            $session->set('erp_profile', $cached);
            ErpDirectory::forgetMemo();
            $this->check('person who left the ERP is signed out of the submitter role', null, Auth::person());
            $this->check('and switched off', false, is_true($this->val("SELECT active FROM master_people WHERE lower(id_code) = 'zze001'")));
            $this->check('ERP source is only for ERP rows: manual rows are ignored while source is erp', null, SubmissionService::lookup('student', 'ZZ-NOPE'));

            // ---- Sync from the ERP (admin screen and portal:erp-sync)
            $db->query("INSERT INTO master_people (role, id_code, name) VALUES ('student', 'ZZ-ADOPT', 'Old Master List Name')");
            $fake->students[] = ['uid' => '7010', 'name' => 'Adopted Student', 'roll' => 'ZZ-ADOPT', 'dept' => (string) $dep, 'mobile' => null, 'semester' => 1, 'program' => 'B.Tech'];
            $fake->students[] = ['uid' => '7001', 'name' => 'Erp  Student', 'roll' => 'ZZE001', 'dept' => (string) $dep, 'mobile' => null, 'semester' => 4, 'program' => 'B.Tech'];   // is back
            $fake->students = array_values(array_filter($fake->students, static fn ($x) => $x['roll'] !== 'ZZE004'));                                                       // has left
            $fake->employees[1]['name'] = 'Erp Clerk Renamed';
            $fake->students[1]['dept'] = 'NOSUCH';   // ZZE002
            $fake->employees[] = ['code' => 'ZZ-G100', 'name' => 'Gardener', 'designation' => 'Gardener', 'dept' => (string) $dep, 'mobile' => null, 'email' => "gardener@{$stf}"];
            $scan = \App\Libraries\Erp\ErpSync::scan();
            $this->check('sync preview: no account yet / adopted / changed / back / to switch off', [2, 1, 1, 1, 1], [count($scan['not_yet']), count($scan['adopted']), count($scan['changed']), count($scan['reactivated']), count($scan['missing'])]);
            $this->check('sync preview: duplicates are conflicts, an unlisted title is reported', [2, ['Gardener' => 1]], [count($scan['conflicts']), $scan['blocked_titles']]);
            $this->check('sync preview: unmapped departments are reported', ['NOSUCH'], array_column($scan['unmapped_departments'], 'code'));
            $this->check('sync preview changes nothing', 0, $this->val("SELECT count(*) FROM master_people WHERE id_code = 'ZZE002'"));
            $this->check('too many people to switch off: needs a confirmation', true, $scan['mass_off']);
            $this->refused('sync refuses a large switch-off without confirmation', fn () => \App\Libraries\Erp\ErpSync::apply(false), 'Tick the confirmation');
            $n = \App\Libraries\Erp\ErpSync::apply(true, 'self-test');
            $this->check('sync applied: counts (it creates no accounts)', [1, 1, 1, 1], [$n['adopted'], $n['changed'], $n['reactivated'], $n['switched_off']]);
            $this->check('the sync did not create an account for people who have not signed in', 0, $this->val("SELECT count(*) FROM master_people WHERE id_code IN ('ZZE002', 'ZZE003')"));
            $this->check('old master-list row taken over (name from the ERP, source erp)', ['Adopted Student', 'erp'], array_values($db->query("SELECT name, source FROM master_people WHERE id_code = 'ZZ-ADOPT'")->getRowArray() ?? []));
            $this->check('renamed in the ERP: renamed here', 'Erp Clerk Renamed', $this->val("SELECT name FROM master_people WHERE id_code = 'ZZ-N100'"));
            $this->check('person who is back is switched on again, the one who left is switched off', [true, false], [is_true($this->val("SELECT active FROM master_people WHERE id_code = 'ZZE001'")), is_true($this->val("SELECT active FROM master_people WHERE id_code = 'ZZE004'"))]);
            $this->check('the taken-over student keeps no invented e-mail', null, $this->val("SELECT email FROM master_people WHERE id_code = 'ZZ-ADOPT'"));
            $this->check('skipped people were not created', 0, $this->val("SELECT count(*) FROM master_people WHERE id_code IN ('ZZE005', 'ZZ-F101', 'ZZ-F102', 'ZZ-G100')"));
            $this->check('the sync is in the audit log', 1, $this->val("SELECT count(*) FROM audit_log WHERE action = 'erp_sync' AND after_data::text LIKE '%self-test%'"));
            $again = \App\Libraries\Erp\ErpSync::scan();
            $this->check('a second preview has nothing left to do (people without an account stay that way)', [2, 0, 0, 0, 0], [count($again['not_yet']), count($again['adopted']), count($again['changed']), count($again['reactivated']), count($again['missing'])]);
            ErpDirectory::forgetMemo();
            $erpPerson = ErpDirectory::lookupStudent('ZZE001')['profile'];
            $this->check('ERP department ID resolves to dpcode ID and name', [$dep, $depName], [$erpPerson['department_id'], $erpPerson['department']]);
            $fake->hostels = ['7001' => [['room' => '1', 'hostel' => 'ZZH99', 'hostel_name' => 'ZZ New Hostel']]];
            $this->check('ERP hostel values need no portal mapping', ['ZZ New Hostel', 'ZZH99'], [
                ErpDirectory::lookupStudent('ZZE001')['profile']['hostel'],
                ErpDirectory::lookupStudent('ZZE001')['profile']['hostel_code'],
            ]);
            $fake->students = [];
            $fake->employees = [];
            $this->refused('sync refuses when the ERP returns nobody', fn () => \App\Libraries\Erp\ErpSync::apply(true), 'no people at all');
            $fake->down = true;
            $this->check('ERP down: the preview says so and changes nothing', true, \App\Libraries\Erp\ErpSync::scan()['error'] !== null);
            $this->refused('ERP down: sync refuses', fn () => \App\Libraries\Erp\ErpSync::apply(true), 'cannot be read');
            $fake->down = false;
        } finally {
            Auth::logout();
            Settings::set('person_source', 'manual');
            ErpDirectory::useSource(null);
        }
        $this->check('switching back to the master list works', false, ErpDirectory::enabled());
    }

    // ------------------------------------------------------------------ helpers

    private function check(string $name, mixed $expected, mixed $actual): void
    {
        if ($expected == $actual) {
            $this->pass++;
            CLI::write("PASS  {$name}", 'green');
        } else {
            $this->fail++;
            CLI::write("FAIL  {$name} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')', 'red');
        }
    }

    /** The action must be refused with a RuntimeException whose message contains $text. */
    private function refused(string $name, callable $action, string $text = ''): void
    {
        try {
            $action();
            $this->fail++;
            CLI::write("FAIL  {$name} (was accepted)", 'red');
        } catch (\RuntimeException $e) {
            $ok = $text === '' || stripos($e->getMessage(), $text) !== false;
            $ok ? $this->pass++ : $this->fail++;
            CLI::write(($ok ? 'PASS  ' : 'FAIL  ') . $name . ($ok ? '' : " (message was: {$e->getMessage()})"), $ok ? 'green' : 'red');
        }
    }

    private function has(string $name, array $errors, string $text): void
    {
        $joined = implode(' | ', $errors);
        $this->check($name, true, stripos($joined, $text) !== false);
    }

    private function val(string $sql, array $args = []): mixed
    {
        $row = Database::connect()->query($sql, $args)->getRowArray();

        return $row === null ? null : array_values($row)[0];
    }

    private function csv(array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pt') . '.csv';
        $out  = '';
        foreach ($rows as $r) {
            $out .= implode(',', array_map(static fn ($c) => '"' . str_replace('"', '""', (string) $c) . '"', $r)) . "\n";
        }
        file_put_contents($file, $out);
        $this->tmp[] = $file;

        return $file;
    }

    private function id(string $table, string $where, array $args = []): int
    {
        return (int) $this->val("SELECT id FROM {$table} WHERE {$where}", $args);
    }

    private function departmentId(string $key): int
    {
        if (isset($this->testDepartments[$key])) {
            return $this->testDepartments[$key];
        }

        $rows = Database::connect()->query('SELECT dept FROM establishment.dpcode ORDER BY dept')->getResultArray();
        $used = array_values($this->testDepartments);
        foreach ($rows as $row) {
            $id = (int) $row['dept'];
            if (! in_array($id, $used, true)) {
                return $this->testDepartments[$key] = $id;
            }
        }

        throw new \RuntimeException('The self-test needs more departments in establishment.dpcode.');
    }

    private function departmentName(int $id): string
    {
        return (string) $this->val('SELECT depname FROM establishment.dpcode WHERE dept = ?', [$id]);
    }

    // ------------------------------------------------------------------ checks

    private function lists(): void
    {
        CLI::write("\n-- lists", 'yellow');
        $cs = $this->departmentId('cs');
        $csName = $this->departmentName($cs);
        $departments = ListService::all('departments');
        $this->check('department list is sourced from dpcode', true, in_array($csName, array_column($departments, 'name'), true));
        $this->has('department list cannot be changed in the portal', ListService::validate('departments', 'ZZ Nothing'), 'maintained in establishment.dpcode');
        $this->refused('department creation is read-only', fn () => ListService::create('departments', 'ZZ Nothing', null), 'maintained in establishment.dpcode');
        $this->refused('department rename is read-only', fn () => ListService::rename('departments', $cs, 'ZZ Nothing'), 'maintained in establishment.dpcode');
        $this->refused('department deletion is read-only', fn () => ListService::delete('departments', $cs), 'maintained in establishment.dpcode');
        $this->check('hostels are not portal-managed lists', false, ListService::isEntity('hostels'));
        Database::connect()->query("INSERT INTO master_people (role, id_code, name, department_id) VALUES ('student', 'ZZ-S0', 'Used', ?)", [$cs]);
        $this->check('dpcode departments are available to all roles', true, in_array($csName, array_column($departments, 'name'), true));
    }

    private function postsAndTypes(): void
    {
        CLI::write("\n-- posts and grievance types", 'yellow');
        $id = PostCatalogService::create('ZZ Librarian', '');
        $this->has('duplicate post rejected', PostCatalogService::validate('zz librarian', ''), 'already');
        PostCatalogService::delete($id);
        $this->check('unused post deleted', 0, $this->val('SELECT count(*) FROM posts WHERE id = ?', [$id]));
        $dir = $this->id('posts', 'is_director');
        $this->refused('Director cannot be deleted', fn () => PostCatalogService::delete($dir));
        $this->refused('Director cannot be deactivated', fn () => PostCatalogService::setActive($dir, false));
        $hod = $this->id('posts', "name = 'Head of Department'");
        $this->refused('a post in a route cannot be deleted', fn () => PostCatalogService::delete($hod));

        $this->refused('extra faculty type refused while the switch is off', fn () => TypeService::create('faculty', 'ZZ Library', 'ZLB', false, 5));
        $this->has('duplicate type code rejected', TypeService::validate('student', 'ZZ Another', 'ACD'), 'code');
        $t = TypeService::create('student', 'ZZ Library', 'zzl', false, 9);
        $this->check('type code stored upper-case', 'ZZL', $this->val('SELECT code FROM grievance_types WHERE id = ?', [$t]));
        $this->check('new type starts with the Director level only', 1, $this->val('SELECT count(*) FROM escalation_levels WHERE type_id = ?', [$t]));
        TypeService::delete($t);
        $this->check('unused type deleted with its matrix', 0, $this->val('SELECT count(*) FROM escalation_levels WHERE type_id = ?', [$t]));
        $gen = $this->id('grievance_types', "code = 'FGEN'");
        $this->refused('the single Faculty type cannot be deactivated', fn () => TypeService::setActive($gen, false));
    }

    private function matrix(): void
    {
        CLI::write("\n-- escalation matrix", 'yellow');
        $db   = Database::connect();
        $acd  = $this->id('grievance_types', "code = 'ACD'");
        $welf = $this->id('posts', "name = 'Dean (Student Welfare)'");
        $dir  = $this->id('posts', 'is_director');
        $names = static fn () => implode(' > ', array_column($db->query('SELECT d.name FROM escalation_levels l JOIN posts d ON d.id = l.post_id WHERE l.type_id = ? ORDER BY l.level_no', [$acd])->getResultArray(), 'name'));

        MatrixService::addLevel($acd, $welf, 2, 5);
        $this->check('level inserted at position 2', 'Head of Department > Dean (Student Welfare) > Dean (Academics) > Chairman, Student Grievance Committee (Academic) > Director', $names());
        $this->check('levels renumbered 1..5', '1,2,3,4,5', implode(',', array_column($db->query('SELECT level_no FROM escalation_levels WHERE type_id = ? ORDER BY level_no', [$acd])->getResultArray(), 'level_no')));
        $this->refused('same post twice in a route refused', fn () => MatrixService::addLevel($acd, $welf, 2, 5));
        $this->refused('Director cannot be added twice', fn () => MatrixService::addLevel($acd, $dir, 1, 5));
        $lv = $this->id('escalation_levels', 'type_id = ? AND post_id = ?', [$acd, $welf]);
        MatrixService::moveLevel($lv, 'down');
        $this->check('level moved down', 'Head of Department > Dean (Academics) > Dean (Student Welfare) > Chairman, Student Grievance Committee (Academic) > Director', $names());
        $final = $this->id('escalation_levels', 'type_id = ? AND is_final', [$acd]);
        $this->refused('Director level cannot be removed', fn () => MatrixService::removeLevel($final));
        $this->refused('Director level cannot be moved', fn () => MatrixService::moveLevel($final, 'up'));
        $this->refused('overdue days of 0 refused', fn () => MatrixService::setOverdueDays($lv, 0));

        // Removal re-routes open grievances
        $dean = $this->id('escalation_levels', "type_id = ? AND post_id = (SELECT id FROM posts WHERE name = 'Dean (Academics)')", [$acd]);
        $dep  = $this->departmentId('cs');
        $mk = static fn (string $ref, string $status, bool $paused = false) => $db->query(
            "INSERT INTO grievances (reference_no, type_id, role, id_code, submitter, department_id, subject, description, current_level_id, status, paused_since)
             VALUES (?, ?, 'student', 'ZZ-S0', '{}'::jsonb, ?, 's', 'd', ?, ?, " . ($paused ? 'now()' : 'NULL') . ') RETURNING id',
            [$ref, $acd, $dep, $dean, $status]
        )->getRow('id');
        $g1 = $mk('ZZ-1', 'pending');
        $g2 = $mk('ZZ-2', 'awaiting_submitter', true);
        $g3 = $mk('ZZ-3', 'pending');
        $db->query("UPDATE grievances SET status = 'disposed', disposed_at = now() WHERE id = ?", [$g3]);
        $db->query("INSERT INTO grievance_actions (grievance_id, level_id, level_no, level_label, actor_name, action) VALUES (?, ?, 2, 'Dean (Academics)', 'Submitter', 'submit')", [$g1, $dean]);

        $moved = MatrixService::removeLevel($dean);
        $this->check('two open grievances re-routed', 2, $moved);
        $next = $this->val('SELECT d.name FROM grievances g JOIN escalation_levels l ON l.id = g.current_level_id JOIN posts d ON d.id = l.post_id WHERE g.id = ?', [$g1]);
        $this->check('moved to the next surviving level', 'Dean (Student Welfare)', $next);
        $this->check('waiting grievance still waiting', 'awaiting_submitter', $this->val('SELECT status FROM grievances WHERE id = ?', [$g2]));
        $this->check('its overdue clock is still paused', true, (bool) $this->val('SELECT paused_since IS NOT NULL FROM grievances WHERE id = ?', [$g2]));
        $this->check('disposed grievance has no re-route entry', 0, $this->val('SELECT count(*) FROM grievance_actions WHERE grievance_id = ?', [$g3]));
        $this->check('re-route is in the history', 1, $this->val("SELECT count(*) FROM grievance_actions WHERE grievance_id = ? AND action = 'reroute'", [$g1]));
        $this->check('older history keeps its level label', 'Dean (Academics)', $this->val("SELECT level_label FROM grievance_actions WHERE grievance_id = ? AND action = 'submit'", [$g1]));
        $this->check('a notification event is queued', 1, $this->val("SELECT count(*) FROM notification_events WHERE grievance_id = ? AND event_type = 'rerouted'", [$g1]));
    }

    private function users(): void
    {
        CLI::write("\n-- authority accounts", 'yellow');
        $staff = config('Google')->staffDomain;
        $this->check('valid staff e-mail accepted', [], UserService::validate('zz.hod', 'ZZ Hod', "zz.hod@{$staff}"));
        $made = ['id' => UserService::create('zz.hod', 'ZZ Hod', "ZZ.Hod@{$staff}")];
        $this->check('e-mail stored in lower case', "zz.hod@{$staff}", $this->val('SELECT email FROM users WHERE id = ?', [$made['id']]));
        $this->check('new account is not asked to change a password', false, is_true($this->val('SELECT must_change_password FROM users WHERE id = ?', [$made['id']])));
        $this->has('duplicate username rejected', UserService::validate('ZZ.HOD', 'X', "x@{$staff}"), 'already');
        $this->has('duplicate email rejected', UserService::validate('zz.other', 'X', "zz.hod@{$staff}"), 'email');
        $this->has('invalid username rejected', UserService::validate('bad name', 'X', "x@{$staff}"), 'Username');
        $this->has('e-mail is required', UserService::validate('zz.other', 'X', ''), 'required');
        $this->has('student e-mail refused for an authority', UserService::validate('zz.other', 'X', 'x@' . config('Google')->studentDomain), 'staff address');
        $this->has('private e-mail refused for an authority', UserService::validate('zz.other', 'X', 'x@gmail.com'), 'staff address');

        $hod = $this->id('posts', "name = 'Head of Department'");
        $dep = $this->departmentId('cs');
        PostService::assign($hod, $dep, $made['id']);
        $this->refused('account holding a post cannot be deleted', fn () => UserService::delete($made['id']));
        $vacated = UserService::setActive($made['id'], false);
        $this->check('deactivating vacates the post', 1, $vacated);
        $this->check('no active post remains', 0, $this->val('SELECT count(*) FROM post_holders WHERE user_id = ? AND active', [$made['id']]));
        $this->refused('an inactive user cannot be assigned', fn () => PostService::assign($hod, $dep, $made['id']), 'active');

        $fresh = UserService::create('zz.fresh', 'Fresh', '');
        UserService::delete($fresh);
        $this->check('account with no history deleted', 0, $this->val('SELECT count(*) FROM users WHERE id = ?', [$fresh]));
    }

    private function posts(): void
    {
        CLI::write("\n-- post holders", 'yellow');
        $hod  = $this->id('posts', "name = 'Head of Department'");
        $ro   = $this->id('posts', "name = 'Reporting Officer'");
        $dep  = $this->departmentId('cs');
        $depName = $this->departmentName($dep);
        $a    = UserService::create('zz.a', 'A', '');
        $b    = UserService::create('zz.b', 'B', '');

        PostService::assign($hod, $dep, $a);
        $this->check('holder set', $a, (int) $this->val('SELECT user_id FROM post_holders WHERE post_id = ? AND scope_id = ? AND active', [$hod, $dep]));
        PostService::assign($hod, $dep, $b);
        $this->check('assigning replaces the holder', $b, (int) $this->val('SELECT user_id FROM post_holders WHERE post_id = ? AND scope_id = ? AND active', [$hod, $dep]));
        $this->check('previous holder record kept and ended', 1, $this->val('SELECT count(*) FROM post_holders WHERE post_id = ? AND scope_id = ? AND user_id = ? AND NOT active AND ended_at IS NOT NULL', [$hod, $dep, $a]));
        $names = array_column(PostService::seats($hod), 'scope_name');
        $this->check('HOD list offers the department', true, in_array($depName, $names, true));
        $this->check('Reporting Officer list does not offer it', false, in_array($depName, array_column(PostService::seats($ro), 'scope_name'), true));
        $this->refused('a department post needs a department', fn () => PostService::assign($hod, null, $a), 'department');
        $this->refused('a fixed post takes no department', fn () => PostService::assign($this->id('posts', "name = 'Registrar'"), $dep, $a));
        PostService::vacate($hod, $dep);
        $this->check('post vacated', 0, $this->val('SELECT count(*) FROM post_holders WHERE post_id = ? AND scope_id = ? AND active', [$hod, $dep]));
        $labels = array_column(PostService::vacancies(), 'label');
        $this->check('vacancy list includes it', true, count(array_filter($labels, static fn ($l) => str_contains($l, $depName))) > 0);
    }

    private function settings(): void
    {
        CLI::write("\n-- settings validation", 'yellow');
        $good = [
            'institute_name' => 'Test', 'ref_prefix' => 'abc', 'ref_seq_digits' => '6', 'attach_allowed_ext' => ['pdf', 'jpg'],
            'attach_max_mb' => '5', 'attach_max_count' => '3', 'contact_pattern' => '^[0-9]{10}$', 'overdue_default_days' => '7',
            'archive_after_years' => '3', 'session_idle_minutes' => '30', 'otp_valid_minutes' => '10', 'otp_max_attempts' => '5', 'otp_resend_seconds' => '60', 'otp_max_per_hour' => '5', 'lockout_attempts' => '5',
            'lockout_minutes' => '15', 'password_min_length' => '8', 'allow_multiple_types_faculty' => '0', 'allow_multiple_types_non_teaching' => '0',
        ];
        [$clean, $errors] = SettingsDefinition::clean($good);
        $this->check('valid settings accepted', [], $errors);
        $this->check('prefix upper-cased', 'ABC', $clean['ref_prefix']);
        $this->check('file types joined', 'pdf,jpg', $clean['attach_allowed_ext']);
        [, $e] = SettingsDefinition::clean(['ref_prefix' => 'a!'] + $good);
        $this->has('bad prefix rejected', $e, 'Reference number prefix');
        [, $e] = SettingsDefinition::clean(['ref_seq_digits' => '99'] + $good);
        $this->has('out-of-range number rejected', $e, 'Digits in the sequence number');
        [, $e] = SettingsDefinition::clean(['contact_pattern' => '('] + $good);
        $this->has('invalid regular expression rejected', $e, 'Contact number pattern');
        [, $e] = SettingsDefinition::clean(['attach_allowed_ext' => ['exe']] + $good);
        $this->has('executable file types cannot be allowed', $e, 'choose at least one');
    }

    private function formFields(): void
    {
        CLI::write("\n-- form fields", 'yellow');
        $before = count(FormFieldService::fields('faculty'));
        $text = FormFieldService::add('faculty', 'ZZ Office room', 'text', '', false);
        $dd   = FormFieldService::add('faculty', 'ZZ Campus', 'dropdown', "North\nSouth\nnorth", true);
        $this->check('two fields added', $before + 2, count(FormFieldService::fields('faculty')));
        $f = FormFieldService::find($dd);
        $this->check('dropdown choices de-duplicated', ['North', 'South'], json_decode($f['options'], true));
        $this->check('custom key generated', true, str_starts_with($f['field_key'], 'cf_zz_campus'));
        $this->has('duplicate label rejected', FormFieldService::validate('faculty', 'zz office ROOM', 'text', ''), 'already exists');
        $this->has('dropdown with one choice rejected', FormFieldService::validate('faculty', 'ZZ Single', 'dropdown', 'Only'), 'at least two');
        $this->has('unknown type rejected', FormFieldService::validate('faculty', 'ZZ Odd', 'bogus', ''), 'type');

        $name = $this->id('form_fields', "role = 'faculty' AND field_key = 'name'");
        FormFieldService::update($name, 'Full name', false, false, '');
        $row = FormFieldService::find($name);
        $this->check('core field renamed', 'Full name', $row['label']);
        $this->check('core field cannot be hidden', true, is_true($row['visible']));
        $this->check('core field mandatory rule is fixed', true, is_true($row['mandatory']));

        $dept = $this->id('form_fields', "role = 'faculty' AND field_key = 'department'");
        FormFieldService::update($dept, 'Department / Section', true, true, '');
        $this->check('built-in dropdown can be renamed', 'Department / Section', FormFieldService::find($dept)['label']);
        $sem = $this->id('form_fields', "role = 'student' AND field_key = 'semester'");
        FormFieldService::update($sem, 'Semester', false, true, '');
        $this->check('built-in non-core field can be made optional', false, is_true(FormFieldService::find($sem)['mandatory']));
        FormFieldService::update($sem, 'Semester', true, true, '');
        FormFieldService::update($text, 'ZZ Office room no.', true, false, '');
        $r = FormFieldService::find($text);
        $this->check('custom field can be hidden', false, is_true($r['visible']));
        $this->check('a hidden field is never mandatory', false, is_true($r['mandatory']));

        $order = fn () => array_column(FormFieldService::fields('faculty'), 'id');
        $ids = $order();
        FormFieldService::move($dd, 'up');
        $now = $order();
        $this->check('field moved up', array_search($dd, $now), array_search($dd, $ids) - 1);

        $this->refused('built-in field cannot be deleted', fn () => FormFieldService::delete($name), 'built-in');
        FormFieldService::delete($text);
        $this->check('unused custom field deleted', 0, $this->val('SELECT count(*) FROM form_fields WHERE id = ?', [$text]));

        // A used custom field can only be hidden
        $db  = Database::connect();
        $lvl = $this->val("SELECT l.id FROM escalation_levels l JOIN grievance_types t ON t.id = l.type_id WHERE t.code = 'FGEN' AND l.level_no = 1");
        $db->query("INSERT INTO grievances (reference_no, type_id, role, id_code, submitter, department_id, subject, description, custom, current_level_id)
                    VALUES ('ZZ-F1', (SELECT id FROM grievance_types WHERE code = 'FGEN'), 'faculty', 'ZZ-E', '{}'::jsonb, ?, 's', 'd', ?::jsonb, ?)",
            [$this->departmentId('cs'), json_encode([$f['field_key'] => 'North']), $lvl]);
        $this->refused('a field used in grievances cannot be deleted', fn () => FormFieldService::delete($dd), 'hidden');
        FormFieldService::update($dd, 'ZZ Campus', true, false, "North\nSouth");
        $this->check('but it can be hidden', false, is_true(FormFieldService::find($dd)['visible']));
    }

    private function masterList(): void
    {
        CLI::write("\n-- master list (single person)", 'yellow');
        $dep  = $this->departmentId('cs');
        $sem  = $this->id('semesters', "name = 'Semester 1'");
        $host = 'ZZ Hostel M';

        $id = MasterListService::create('student', 'ZZ-S1', 'Asha', $dep, $sem, $host, '9876543210');
        $this->has('duplicate id rejected (any case)', MasterListService::validate('student', 'zz-s1', 'X', $dep, $sem, null, ''), 'already on the list');
        $this->check('dpcode department is available to non-teaching staff', [], MasterListService::validate('non_teaching', 'ZZ-N1', 'X', $dep, null, null, ''));
        $this->has('bad contact rejected', MasterListService::validate('student', 'ZZ-S2', 'X', $dep, $sem, null, '123'), 'Contact');
        $this->has('bad id characters rejected', MasterListService::validate('student', '!!', 'X', $dep, $sem, null, ''), 'required');
        MasterListService::update($id, 'Asha K', $dep, $sem, null, '9876543210');
        $this->check('updated; blank hostel means day scholar', null, $this->val('SELECT hostel FROM master_people WHERE id = ?', [$id]));
        MasterListService::setActive($id, false);
        $this->check('deactivated', false, is_true($this->val('SELECT active FROM master_people WHERE id = ?', [$id])));
        MasterListService::delete($id);
        $this->check('person with no grievances can be deleted', 0, $this->val('SELECT count(*) FROM master_people WHERE id = ?', [$id]));
        $used = $this->id('master_people', "id_code = 'ZZ-S0'");
        Database::connect()->query("INSERT INTO grievances (reference_no, type_id, role, id_code, submitter, department_id, subject, description, current_level_id)
            VALUES ('ZZ-M1', (SELECT id FROM grievance_types WHERE code = 'ACD'), 'student', 'ZZ-S0', '{}'::jsonb, ?, 's', 'd',
                    (SELECT l.id FROM escalation_levels l JOIN grievance_types t ON t.id = l.type_id WHERE t.code = 'ACD' AND l.level_no = 1))", [$dep]);
        $this->refused('person with grievances cannot be deleted', fn () => MasterListService::delete($used), 'deactivated');
    }

    private function submission(): void
    {
        CLI::write("\n-- public submission", 'yellow');
        $dep  = $this->departmentId('submit');
        $depName = $this->departmentName($dep);
        $sem  = $this->id('semesters', "name = 'Semester 1'");
        $host = 'ZZ Hostel S';
        MasterListService::create('student', 'ZZ-SUB1', 'Sub One', $dep, $sem, $host, '9876543210');
        MasterListService::create('student', 'ZZ-SUB2', 'Sub Two', $dep, $sem, null, '9876543211');
        $off = MasterListService::create('student', 'ZZ-SUB3', 'Sub Off', $dep, $sem, null, '9876543212');
        MasterListService::create('faculty', 'ZZ-FAC1', 'Fac One', $dep, null, null, '9876543213');
        MasterListService::setActive($off, false);
        $good = ['type_id' => $this->id('grievance_types', "code = 'ACD'"), 'contact' => '9876543210', 'subject' => 'Marks', 'description' => 'Missing marks.'];

        $this->check('lookup finds a person (any case)', 'Sub One', SubmissionService::lookup('student', 'zz-sub1')['name'] ?? null);
        $this->check('lookup ignores a deactivated person', null, SubmissionService::lookup('student', 'ZZ-SUB3'));
        $this->check('lookup is per role', null, SubmissionService::lookup('faculty', 'ZZ-SUB1'));
        $this->check('lookup of blank/over-long ids is null', [null, null], [SubmissionService::lookup('student', '  '), SubmissionService::lookup('student', str_repeat('x', 60))]);
        $this->check('student types offered (4)', 4, count(SubmissionService::types('student')));
        $this->check('slug maps to role', ['non_teaching', null], [SubmissionService::roleFromSlug('non-teaching'), SubmissionService::roleFromSlug('bogus')]);

        $one = SubmissionService::lookup('student', 'ZZ-SUB1');
        $two = SubmissionService::lookup('student', 'ZZ-SUB2');
        $errs = function (array $person, array $in, array $files = []): array {
            try {
                SubmissionService::submit($person, $in, $files);
            } catch (SubmissionException $e) {
                return $e->errors;
            }

            return [];
        };
        $this->has('empty form lists every problem', $errs($one, []), 'Choose the type');
        $this->has('bad contact refused', $errs($one, ['contact' => '12'] + $good), 'contact number');
        $this->has('missing subject refused', $errs($one, ['subject' => ' '] + $good), 'Subject');
        $this->has('over-long description refused', $errs($one, ['description' => str_repeat('x', 5001)] + $good), 'Description');
        $hostelType = $this->id('grievance_types', "code = 'HST'");
        $this->has('hostel type refused for a day scholar', $errs($two, ['type_id' => $hostelType] + $good), 'hostel residents');
        $this->has('inactive/unknown type refused', $errs($one, ['type_id' => 999999] + $good), 'Choose the type');
        $this->check('invalid submissions saved nothing', 0, $this->val('SELECT count(*) FROM grievances WHERE id_code LIKE ?', ['ZZ-SUB%']));

        $first = SubmissionService::submit($one, $good + ['name' => 'Hacker', 'department_id' => 1]);
        $year  = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y');
        $this->check('reference number format', 1, preg_match('/^MANIT-' . $year . '-ACD-\d{6}$/', $first['reference_no']));
        $row = Database::connect()->query('SELECT * FROM grievances WHERE id = ?', [$first['id']])->getRowArray();
        $snap = json_decode($row['submitter'], true);
        $this->check('snapshot name comes from the master list', 'Sub One', $snap['name']);
        $this->check('snapshot department and hostel', [$depName, 'ZZ Hostel S'], [$snap['department'], $snap['hostel']]);
        $this->check('routing keys captured', [$dep, $host], [(int) $row['department_id'], $row['hostel']]);
        $this->check('starts pending at level 1', ['pending', 1], [$row['status'], (int) $this->val('SELECT level_no FROM escalation_levels WHERE id = ?', [$row['current_level_id']])]);
        $this->check('one submit action by the submitter', ['submit', 'Sub One', null], array_values(Database::connect()->query('SELECT action, actor_name, actor_user_id FROM grievance_actions WHERE grievance_id = ?', [$first['id']])->getRowArray()));
        $this->check('level label names the scoped post', 'Head of Department (' . $depName . ')', $first['level']);
        $this->check('two events logged', 2, $this->val('SELECT count(*) FROM notification_events WHERE grievance_id = ?', [$first['id']]));
        $this->check('master list contact untouched by an edited contact', '9876543210', $this->val("SELECT contact FROM master_people WHERE id_code = 'ZZ-SUB1'"));

        $this->has('duplicate pending grievance is warned about', $errs($one, $good), 'already have');
        $second = SubmissionService::submit($one, $good + ['confirm_duplicate' => '1']);
        $this->check('confirmed duplicate accepted; sequence advances', (int) substr($first['reference_no'], -6) + 1, (int) substr($second['reference_no'], -6));
        $hst = SubmissionService::submit($one, ['type_id' => $hostelType] + $good);
        $this->check('separate sequence per type', 1, preg_match('/-HST-\d{6}$/', $hst['reference_no']));
        $this->check('hostel grievance goes to the hostel Warden', 'Warden (ZZ Hostel S)', $hst['level']);

        $fac = SubmissionService::lookup('faculty', 'ZZ-FAC1');
        $f   = SubmissionService::submit($fac, ['contact' => '9876543213', 'subject' => 'Lab', 'description' => 'Access.']);
        $this->check('single type is chosen automatically (faculty)', 1, preg_match('/-FGEN-\d{6}$/', $f['reference_no']));

        // Custom fields
        $mess = FormFieldService::add('student', 'ZZ Mess no', 'text', '', true);
        $blk  = FormFieldService::add('student', 'ZZ Block', 'dropdown', "North\nSouth", false);
        $mk   = FormFieldService::find($mess)['field_key'];
        $bk   = FormFieldService::find($blk)['field_key'];
        $disc = ['type_id' => $this->id('grievance_types', "code = 'DSC'")] + $good;
        $this->has('mandatory custom field enforced', $errs($two, $disc), 'ZZ Mess no is required');
        $this->has('dropdown value outside the options refused', $errs($two, $disc + ['cf' => [$mk => 'M1', $bk => 'East']]), 'ZZ Block is not valid');
        $c = SubmissionService::submit($two, $disc + ['cf' => [$mk => 'M1', $bk => 'South', 'cf_zz_unknown' => 'x']]);
        $custom = json_decode((string) $this->val('SELECT custom FROM grievances WHERE id = ?', [$c['id']]), true);
        $this->check('custom values stored with their labels', ['M1', 'ZZ Mess no', 'South'], [$custom[$mk]['value'], $custom[$mk]['label'], $custom[$bk]['value']]);
        $this->check('unknown custom keys ignored', false, isset($custom['cf_zz_unknown']));

        // Attachments
        $pdf = tempnam(sys_get_temp_dir(), 'pt') . '.pdf';
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
        $fake = tempnam(sys_get_temp_dir(), 'pt') . '.pdf';
        file_put_contents($fake, 'plain text');
        $exe = tempnam(sys_get_temp_dir(), 'pt') . '.exe';
        file_put_contents($exe, 'MZ');
        array_push($this->tmp, $pdf, $fake, $exe);
        $file = static fn (string $path, string $name): array => ['name' => $name, 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK];
        $three = $disc + ['cf' => [$mk => 'M2'], 'confirm_duplicate' => '1'];
        $this->has('fake pdf refused', $errs($two, $three, [$file($fake, 'a.pdf')]), 'real pdf');
        $this->has('disallowed type refused', $errs($two, $three, [$file($exe, 'a.exe')]), 'not allowed');
        $this->has('too many files refused', $errs($two, $three, array_fill(0, 4, $file($pdf, 'a.pdf'))), 'at most');
        $this->has('no-file entries are ignored', $errs($two, $three, [['name' => '', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE]]), '');
        $a = SubmissionService::submit($two, $three, [$file($pdf, '../../x<y>.pdf')]);
        $att = Database::connect()->query('SELECT * FROM attachments WHERE grievance_id = ?', [$a['id']])->getRowArray();
        $this->tmp[] = SubmissionService::path($att['stored_name']);
        $this->check('attachment stored privately under a random name', [1, 1], [preg_match('/^[0-9a-f]{32}\.pdf$/', $att['stored_name']), (int) is_file(SubmissionService::path($att['stored_name']))]);
        $this->check('file name cleaned', 0, preg_match('~[/<>]~', $att['original_name']));
        $this->check('stored path is outside the web root', true, ! str_contains(SubmissionService::path($att['stored_name']), 'public'));
    }

    private function workflow(): void
    {
        CLI::write("\n-- workflow engine (module 5)", 'yellow');
        $db   = Database::connect();
        // Earlier sections add mandatory custom fields in the same (rolled-back) transaction
        $db->query("UPDATE form_fields SET mandatory = false WHERE role = 'student' AND NOT is_core");
        $dep  = $this->departmentId('flow');
        $dep2 = $this->departmentId('flow-other');
        $depName = $this->departmentName($dep);
        $sem  = $this->id('semesters', "name = 'Semester 1'");
        $hod  = $this->id('posts', "name = 'Head of Department'");
        $dean = $this->id('posts', "name = 'Dean (Academics)'");
        $dir  = $this->id('posts', 'is_director');
        $type = TypeService::create('student', 'ZZ Workflow', 'ZZW', false, 9);
        MatrixService::addLevel($type, $hod, 1, 7);
        MatrixService::addLevel($type, $dean, 2, 7);
        $u = static fn (string $name) => UserService::create('zz.wf.' . strtolower($name), $name, '');
        $hodU = $u('Hod'); $otherHod = $u('OtherHod'); $deanU = $u('Dean'); $dirU = $u('Director'); $newHod = $u('NewHod'); $nobody = $u('Nobody');
        PostService::assign($hod, $dep, $hodU);
        PostService::assign($hod, $dep2, $otherHod);
        PostService::assign($dean, null, $deanU);
        PostService::assign($dir, null, $dirU);
        MasterListService::create('student', 'ZZ-WF1', 'Flow Student', $dep, $sem, null, '9876500001');
        $who  = SubmissionService::lookup('student', 'ZZ-WF1');
        $sub  = static fn (string $subject) => SubmissionService::submit($who, ['type_id' => $type, 'contact' => '9876500001', 'subject' => $subject, 'description' => 'd', 'confirm_duplicate' => '1']);
        $g    = $sub('Flow one');
        $id   = $g['id'];
        $ref  = $g['reference_no'];
        $status = fn () => $this->val('SELECT status FROM grievances WHERE id = ?', [$id]);
        $last   = fn () => $db->query('SELECT * FROM grievance_actions WHERE grievance_id = ? ORDER BY id DESC LIMIT 1', [$id])->getRowArray();

        // Who can see and act
        $this->check('HOD of the department can act', true, WorkflowService::canAct($hodU, $id));
        $this->check('HOD of another department cannot see it', [false, false], [WorkflowService::canView($otherHod, $id), WorkflowService::canAct($otherHod, $id)]);
        $this->check('a later level cannot see it yet', false, WorkflowService::canView($deanU, $id));
        $this->check('someone with no post cannot see it', false, WorkflowService::canView($nobody, $id));
        $this->check('it is in the HOD\'s "needs action" list', true, in_array($ref, array_column(WorkflowService::inbox($hodU, 'action')['rows'], 'reference_no'), true));
        $this->check('not in the other HOD\'s list', 0, WorkflowService::inboxCounts($otherHod)['action']);
        $this->check('find() names the current post', 'Head of Department (' . $depName . ')', WorkflowService::find($id)['level_label']);

        // Validation
        $v = WorkflowService::version($id);
        $this->refused('remark is mandatory', fn () => WorkflowService::act($id, $hodU, 'dispose', "  \n "), 'remark');
        $this->refused('over-long remark refused', fn () => WorkflowService::act($id, $hodU, 'dispose', str_repeat('x', 2001)), 'too long');
        $this->refused('unknown action refused', fn () => WorkflowService::act($id, $hodU, 'reassign', 'x'));
        $this->refused('an authority without the post cannot act', fn () => WorkflowService::act($id, $otherHod, 'dispose', 'x'), 'post you hold');
        $this->refused('a stale page (old version) is refused', fn () => WorkflowService::act($id, $hodU, 'dispose', 'x', $v - 1), 'updated while');
        $this->check('refused actions changed nothing', ['pending', 1], [$status(), (int) $this->val('SELECT count(*) FROM grievance_actions WHERE grievance_id = ?', [$id])]);

        // Request information, then the submitter replies
        WorkflowService::act($id, $hodU, 'request_info', 'Please attach the mark sheet.', $v);
        $this->check('request info: awaiting the submitter, clock paused', ['awaiting_submitter', true], [$status(), is_true($this->val('SELECT paused_since IS NOT NULL FROM grievances WHERE id = ?', [$id]))]);
        $this->check('request info recorded with remark and actor', ['request_info', 'Please attach the mark sheet.', $hodU, 'Head of Department (' . $depName . ')'], [$last()['action'], $last()['remark'], (int) $last()['actor_user_id'], $last()['level_label']]);
        $this->check('info_requested event logged', 1, $this->val("SELECT count(*) FROM notification_events WHERE grievance_id = ? AND event_type = 'info_requested'", [$id]));
        $this->check('listed under "waiting for submitter"', [0, 1], [WorkflowService::inboxCounts($hodU)['action'], WorkflowService::inboxCounts($hodU)['waiting']]);
        $this->refused('a second request while waiting is refused', fn () => WorkflowService::act($id, $hodU, 'request_info', 'again'), 'already been requested');
        $this->check('a waiting grievance is never overdue', false, WorkflowService::find($id)['overdue']);
        $this->check('reply state: normal reply to the question', ['reply', 'Please attach the mark sheet.'], [WorkflowService::replyState($id)['mode'], WorkflowService::replyState($id)['question']['remark'] ?? null]);

        $replyErrs = function (string $r, string $code, string $text, array $files = []): array {
            try {
                WorkflowService::submitterReply($r, $code, $text, $files);
            } catch (SubmissionException $e) {
                return $e->errors;
            }

            return [];
        };
        $this->has('reply with the wrong ID refused', $replyErrs($ref, 'ZZ-OTHER', 'hello'), 'No grievance matches');
        $this->has('empty reply refused', $replyErrs($ref, 'ZZ-WF1', '   '), 'reply is required');
        $pdf = tempnam(sys_get_temp_dir(), 'pt') . '.pdf';
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
        $this->tmp[] = $pdf;
        $this->check('valid reply accepted (ID any case)', [], $replyErrs($ref, 'zz-wf1', "Attached.\r\nThanks", [['name' => 'marks.pdf', 'tmp_name' => $pdf, 'size' => filesize($pdf), 'error' => UPLOAD_ERR_OK]]));
        $reply = $last();
        $this->check('reply: back to pending, clock running again', ['pending', false], [$status(), is_true($this->val('SELECT paused_since IS NOT NULL FROM grievances WHERE id = ?', [$id]))]);
        $this->check('reply recorded as the submitter', ['submitter_reply', 'Flow Student', null, "Attached.\nThanks"], [$reply['action'], $reply['actor_name'], $reply['actor_user_id'], $reply['remark']]);
        $att = $db->query('SELECT * FROM attachments WHERE action_id = ?', [$reply['id']])->getRowArray();
        $this->tmp[] = SubmissionService::path($att['stored_name'] ?? 'x');
        $this->check('reply attachment linked to the reply', 'marks.pdf', $att['original_name'] ?? null);
        $this->check('history lists the reply attachment', 1, count(WorkflowService::history($id)[2]['attachments'] ?? []));
        $this->has('a second reply is refused', $replyErrs($ref, 'ZZ-WF1', 'more'), 'not waiting');
        $this->check('answered question: nothing to reply to', null, WorkflowService::replyState($id)['mode']);

        // Escalate to the next level only
        $msg = WorkflowService::act($id, $hodU, 'escalate', 'Needs the Dean.');
        $this->check('escalate message names the next post', true, str_contains($msg, 'Dean (Academics)'));
        $esc = $last();
        $this->check('escalation recorded at the acting level with its destination', ['escalate', 'Head of Department (' . $depName . ')', 'Dean (Academics)', 2], [$esc['action'], $esc['level_label'], $esc['to_level_label'], (int) $esc['to_level_no']]);
        $this->check('now at level 2, clock restarted', [2, 0], [(int) WorkflowService::find($id)['level_no'], (int) $this->val('SELECT paused_seconds FROM grievances WHERE id = ?', [$id])]);
        $this->check('escalated event + arrival at the new level logged', [1, 2], [(int) $this->val("SELECT count(*) FROM notification_events WHERE grievance_id = ? AND event_type = 'escalated'", [$id]), (int) $this->val("SELECT count(*) FROM notification_events WHERE grievance_id = ? AND event_type = 'arrived_at_level'", [$id])]);
        $this->check('the HOD can no longer act but can still see it', [false, true], [WorkflowService::canAct($hodU, $id), WorkflowService::canView($hodU, $id)]);
        $this->check('it moved to the HOD\'s "earlier" list', [0, 1], [WorkflowService::inboxCounts($hodU)['action'], WorkflowService::inboxCounts($hodU)['earlier']]);
        $this->check('the Dean can act now', true, WorkflowService::canAct($deanU, $id));
        $this->refused('the HOD cannot act a second time', fn () => WorkflowService::act($id, $hodU, 'dispose', 'x'), 'post you hold');

        // Waiting at the Dean; escalating anyway needs a confirmation and withdraws the question
        WorkflowService::act($id, $deanU, 'request_info', 'Which course?');
        $this->refused('escalating while waiting needs a confirmation', fn () => WorkflowService::act($id, $deanU, 'escalate', 'No reply.'), 'not replied');
        $this->refused('so does disposing', fn () => WorkflowService::act($id, $deanU, 'dispose', 'No reply.'), 'not replied');
        $this->check('refusal changed nothing', 'awaiting_submitter', $status());
        WorkflowService::act($id, $deanU, 'escalate', 'No reply; sending up.', null, true);
        $this->check('confirmed escalation while waiting resumes the clock', ['pending', false], [$status(), is_true($this->val('SELECT paused_since IS NOT NULL FROM grievances WHERE id = ?', [$id]))]);
        $this->check('reached the Director (last level)', [true, 0], [WorkflowService::find($id)['is_final'], (int) WorkflowService::find($id)['levels_after']]);

        // The withdrawn question can still be answered late, as a note for the Director
        $this->check('reply state: late answer to the Dean\'s question', ['late', 'Which course?'], [WorkflowService::replyState($id)['mode'], WorkflowService::replyState($id)['question']['remark'] ?? null]);
        $before = $this->val('SELECT paused_seconds FROM grievances WHERE id = ?', [$id]);
        $mode   = WorkflowService::submitterReply($ref, 'ZZ-WF1', 'It is CS-301.');
        $late   = $last();
        $this->check('late answer accepted', 'late', $mode);
        $this->check('recorded as a late reply at the Director', ['late_reply', 'Director', 'It is CS-301.', null], [$late['action'], $late['level_label'], $late['remark'], $late['actor_user_id']]);
        $this->check('late answer changes neither status nor clock', ['pending', $before], [$status(), $this->val('SELECT paused_seconds FROM grievances WHERE id = ?', [$id])]);
        $this->check('the Director can still act normally', true, WorkflowService::canAct($dirU, $id));
        $this->has('only one late answer per question', $replyErrs($ref, 'ZZ-WF1', 'again'), 'not waiting');
        $this->check('late reply shown in the Director\'s list', 'late_reply', WorkflowService::inbox($dirU, 'action')['rows'][0]['last_action'] ?? null);
        $this->refused('the Director cannot escalate further', fn () => WorkflowService::act($id, $dirU, 'escalate', 'x'), 'last level');

        WorkflowService::act($id, $dirU, 'dispose', 'Resolved: marks corrected.');
        $f = WorkflowService::find($id);
        $this->check('disposed, with time and level kept', ['disposed', true, 'Director'], [$f['status'], $f['disposed_at'] !== null, $f['post_name']]);
        $this->refused('a disposed grievance cannot be acted on', fn () => WorkflowService::act($id, $dirU, 'request_info', 'x'), 'already been disposed');
        $this->check('full history in order', 'submit,request_info,submitter_reply,escalate,request_info,escalate,late_reply,dispose', implode(',', array_column(WorkflowService::history($id), 'action')));

        // Asked, then disposed without a reply: closed, no late answer
        $g5 = $sub('Flow five');
        WorkflowService::act($g5['id'], $hodU, 'request_info', 'Proof please.');
        WorkflowService::act($g5['id'], $hodU, 'dispose', 'No reply.', null, true);
        $this->has('no reply after disposal', $replyErrs($g5['reference_no'], 'ZZ-WF1', 'here it is'), 'disposed');
        $this->check('every authority action names its actor', 0, $this->val("SELECT count(*) FROM grievance_actions WHERE grievance_id = ? AND action IN ('escalate','dispose','request_info') AND actor_user_id IS NULL", [$id]));

        // Access follows the post
        $g2 = $sub('Flow two')['id'];
        PostService::assign($hod, $dep, $newHod);
        $this->check('the new HOD gets the pending grievance', true, WorkflowService::canAct($newHod, $g2));
        $this->check('and sees the earlier grievance handled at the post', true, WorkflowService::canView($newHod, $id));
        $this->check('the former HOD loses access', [false, false], [WorkflowService::canAct($hodU, $g2), WorkflowService::canView($hodU, $id)]);

        // Vacant post: waits, admin is warned, authorities see nothing
        PostService::vacate($hod, $dep);
        $this->check('waiting at a vacant post is reported to the admin', true, in_array((int) $g2, array_map('intval', array_column(WorkflowService::waitingAtVacantPosts(), 'id')), true));
        $this->check('vacant count in the admin summary', true, WorkflowService::adminCounts()['vacant'] >= 1);
        $this->check('nobody can act on it meanwhile', [false, false], [WorkflowService::canAct($newHod, $g2), WorkflowService::canAct($otherHod, $g2)]);
        PostService::assign($hod, $dep, $newHod);
        $this->check('filled again: no longer reported', false, in_array((int) $g2, array_map('intval', array_column(WorkflowService::waitingAtVacantPosts(), 'id')), true));

        // Overdue: days at the level, not counting time waiting for the submitter
        $db->query("UPDATE grievances SET level_entered_at = now() - interval '10 days' WHERE id = ?", [$g2]);
        $this->check('pending 10 days at a 7-day level is overdue', true, WorkflowService::find((int) $g2)['overdue']);
        $g3 = $sub('Flow three')['id'];
        $db->query("UPDATE grievances SET level_entered_at = now() - interval '10 days', paused_seconds = 5 * 86400 WHERE id = ?", [$g3]);
        $this->check('5 of those days waiting for the submitter: not overdue', false, WorkflowService::find((int) $g3)['overdue']);
        $this->check('overdue grievances come first in the inbox', (int) $g2, (int) WorkflowService::inbox($newHod, 'action')['rows'][0]['id']);
        $this->check('overdue counted for the authority', 1, WorkflowService::inboxCounts($newHod)['overdue']);
        WorkflowService::logOverdueReminders();
        $rem = fn () => (int) $this->val("SELECT count(*) FROM notification_events WHERE grievance_id = ? AND event_type = 'overdue_reminder'", [$g2]);
        $this->check('one reminder logged for it', 1, $rem());
        $this->check('reminder names the holder', $newHod, (int) $this->val("SELECT payload->>'holder_user_id' FROM notification_events WHERE grievance_id = ? AND event_type = 'overdue_reminder'", [$g2]));
        WorkflowService::logOverdueReminders();
        $this->check('running again does not repeat it', 1, $rem());
        $this->check('no reminder for the not-overdue one', 0, (int) $this->val("SELECT count(*) FROM notification_events WHERE grievance_id = ? AND event_type = 'overdue_reminder'", [$g3]));

        // Matrix change: removing the level re-routes and records the destination
        $deanLevel = $this->id('escalation_levels', 'type_id = ? AND post_id = ?', [$type, $dean]);
        WorkflowService::act((int) $g2, $newHod, 'escalate', 'To the Dean.');
        MatrixService::removeLevel($deanLevel);
        $rr = $db->query("SELECT * FROM grievance_actions WHERE grievance_id = ? AND action = 'reroute'", [$g2])->getRowArray();
        $this->check('re-routed to the Director with the destination recorded', ['Director', 'Director'], [$rr['level_label'] ?? null, $rr['to_level_label'] ?? null]);
        $this->check('the Director can act on the re-routed grievance', true, WorkflowService::canAct($dirU, (int) $g2));
        $this->check('the old escalation row keeps its readable destination', 'Dean (Academics)', $this->val("SELECT to_level_label FROM grievance_actions WHERE grievance_id = ? AND action = 'escalate'", [$g2]));
    }

    private function skipAhead(): void
    {
        CLI::write("\n-- transfer with skip-ahead", 'yellow');
        $db   = Database::connect();
        $db->query("UPDATE form_fields SET mandatory = false WHERE role = 'student' AND NOT is_core");
        $dep  = $this->departmentId('skip');
        $sem  = $this->id('semesters', "name = 'Semester 1'");
        $hod  = $this->id('posts', "name = 'Head of Department'");
        $dean = $this->id('posts', "name = 'Dean (Academics)'");
        $sgc  = $this->id('posts', "name = 'Chairman, Student Grievance Committee (Academic)'");
        $dir  = $this->id('posts', 'is_director');
        $type = TypeService::create('student', 'ZZ Skip', 'ZZK', false, 9);
        MatrixService::addLevel($type, $hod, 1, 7);
        MatrixService::addLevel($type, $dean, 2, 7);
        MatrixService::addLevel($type, $sgc, 3, 7);
        $lvl = fn (int $d) => $this->id('escalation_levels', 'type_id = ? AND post_id = ?', [$type, $d]);
        $u = static fn (string $name) => UserService::create('zz.sk.' . strtolower($name), $name, '');
        $hodU = $u('Hod'); $deanU = $u('Dean'); $sgcU = $u('Sgc'); $dirU = $u('Director');
        PostService::assign($hod, $dep, $hodU);
        PostService::assign($dean, null, $deanU);
        PostService::assign($sgc, null, $sgcU);
        PostService::assign($dir, null, $dirU);
        MasterListService::create('student', 'ZZ-SK1', 'Skip Student', $dep, $sem, null, '9876500002');
        $who = SubmissionService::lookup('student', 'ZZ-SK1');
        $sub = static fn (string $subject) => SubmissionService::submit($who, ['type_id' => $type, 'contact' => '9876500002', 'subject' => $subject, 'description' => 'd', 'confirm_duplicate' => '1']);
        $a   = $sub('Skip one')['id'];

        $this->check('skipping is off by default', false, WorkflowService::find($a)['can_skip']);
        $this->check('without skip permission only the next level is offered', ['Dean (Academics)'], array_column(WorkflowService::transferTargets(WorkflowService::find($a)), 'label'));
        $this->refused('a later level is refused without permission', fn () => WorkflowService::act($a, $hodU, 'escalate', 'Straight up.', null, false, $lvl($sgc)), 'next level');
        $this->check('refusal changed nothing', 1, (int) WorkflowService::find($a)['level_no']);

        MatrixService::setCanSkip($lvl($hod), true);
        $this->check('with permission every later level is offered, next first', ['Dean (Academics)', 'Chairman, Student Grievance Committee (Academic)', 'Director'], array_column(WorkflowService::transferTargets(WorkflowService::find($a)), 'label'));
        $this->refused('the Director level cannot be given skip permission', fn () => MatrixService::setCanSkip($this->id('escalation_levels', 'type_id = ? AND is_final', [$type]), true), 'last level');
        $this->refused('a level of another route is refused', fn () => WorkflowService::act($a, $hodU, 'escalate', 'x', null, false, $this->id('escalation_levels', "post_id = ? AND type_id <> ?", [$sgc, $type])), 'further along');
        $this->refused('the current level is refused as a target', fn () => WorkflowService::act($a, $hodU, 'escalate', 'x', null, false, $lvl($hod)), 'further along');

        $msg = WorkflowService::act($a, $hodU, 'escalate', 'Dean is on leave; straight to the Chairman.', null, false, $lvl($sgc));
        $h   = $db->query("SELECT * FROM grievance_actions WHERE grievance_id = ? AND action = 'escalate'", [$a])->getRowArray();
        $this->check('transferred directly, message says so', true, str_contains($msg, 'directly') && str_contains($msg, 'Chairman, Student Grievance Committee (Academic)'));
        $this->check('history records the destination and 1 skipped level', [3, 1], [(int) $h['to_level_no'], (int) $h['skipped_levels']]);
        $this->check('grievance now at level 3', 3, (int) WorkflowService::find($a)['level_no']);
        $this->check('the skipped Dean never sees it', [false, false], [WorkflowService::canView($deanU, $a), WorkflowService::canAct($deanU, $a)]);
        $this->check('the Chairman can act', true, WorkflowService::canAct($sgcU, $a));
        $this->check('the submitter view says "directly"', true, str_starts_with(TrackingService::view($a)['history'][1]['title'], 'Transferred directly to '));
        $this->check('the Chairman (no skip permission) is offered only the Director', ['Director'], array_column(WorkflowService::transferTargets(WorkflowService::find($a)), 'label'));

        // Default (no target) and explicit next level are both an ordinary transfer
        $b = $sub('Skip two')['id'];
        WorkflowService::act($b, $hodU, 'escalate', 'Normal.');
        WorkflowService::act($b, $deanU, 'escalate', 'Normal too.', null, false, $lvl($sgc));
        $this->check('ordinary transfers record 0 skipped levels', [0, 0], array_map('intval', array_column($db->query("SELECT skipped_levels FROM grievance_actions WHERE grievance_id = ? AND action = 'escalate' ORDER BY id", [$b])->getResultArray(), 'skipped_levels')));

        // Skip permission can be switched off again; audit trail
        MatrixService::setCanSkip($lvl($hod), false);
        $c = $sub('Skip three')['id'];
        $this->refused('permission withdrawn: skipping refused again', fn () => WorkflowService::act($c, $hodU, 'escalate', 'x', null, false, $lvl($dir)), 'next level');
        $this->check('changing skip permission is audited', true, (int) $this->val("SELECT count(*) FROM audit_log WHERE entity = 'escalation_level' AND entity_id = ? AND after_data::text LIKE '%can_skip%'", [(string) $lvl($hod)]) >= 2);
    }

    private function tracking(): void
    {
        CLI::write("\n-- tracking, oversight, related grievances (module 6)", 'yellow');
        $db   = Database::connect();
        $dep  = $this->departmentId('track');
        $dep2 = $this->departmentId('track-other');
        $depName = $this->departmentName($dep);
        $dep2Name = $this->departmentName($dep2);
        $sem  = $this->id('semesters', "name = 'Semester 1'");
        $hod  = $this->id('posts', "name = 'Head of Department'");
        $dir  = $this->id('posts', 'is_director');
        $type = TypeService::create('student', 'ZZ Tracked', 'ZZT', false, 9);
        $typ2 = TypeService::create('student', 'ZZ Tracked Two', 'ZZU', false, 9);
        MatrixService::addLevel($type, $hod, 1, 7);
        MatrixService::addLevel($typ2, $hod, 1, 7);
        $u = static fn (string $name) => UserService::create('zz.m6.' . strtolower($name), $name, '');
        $hodU = $u('Hod Person'); $hod2 = $u('Other Hod'); $dirU = $u('Director Person');
        PostService::assign($hod, $dep, $hodU);
        PostService::assign($hod, $dep2, $hodU);     // one person, two posts
        PostService::assign($dir, null, $dirU);
        $hod2Post = $this->departmentId('track-third');
        PostService::assign($hod, $hod2Post, $hod2);
        MasterListService::create('student', 'ZZ-TR1', 'Tara Track', $dep, $sem, null, '9876500021');
        MasterListService::create('student', 'ZZ-TR2', 'Other Person', $dep2, $sem, null, '9876500022');
        MasterListService::create('student', 'ZZ-TR3', 'Third Person', $hod2Post, $sem, null, '9876500023');
        $sub = static fn (string $id, int $t, string $subject) => SubmissionService::submit(SubmissionService::lookup('student', $id),
            ['type_id' => $t, 'contact' => '9876500021', 'subject' => $subject, 'description' => 'd', 'confirm_duplicate' => '1']);
        $a = $sub('ZZ-TR1', $type, 'Track alpha');
        $b = $sub('ZZ-TR1', $typ2, 'Track beta (other type)');
        $c = $sub('ZZ-TR2', $type, 'Track gamma');
        $d = $sub('ZZ-TR3', $type, 'Private third');

        // Finding a grievance to track
        $this->check('track: reference + ID found (any case)', $a['id'], TrackingService::find(strtolower($a['reference_no']), 'zz-tr1'));
        $this->check('track: right reference, wrong ID', null, TrackingService::find($a['reference_no'], 'ZZ-TR2'));
        $this->check('track: blank or over-long input', [null, null], [TrackingService::find('', 'ZZ-TR1'), TrackingService::find(str_repeat('X', 41), 'ZZ-TR1')]);

        // What the submitter sees
        WorkflowService::act($a['id'], $hodU, 'request_info', 'Which lab?');
        $v = TrackingService::view($a['id']);
        $this->check('track: status text while waiting', 'Waiting for your reply', $v['status_text']);
        $this->check('track: reply form offered with the question', ['reply', 'Which lab?'], [$v['reply']['mode'], $v['reply']['question']['remark']]);
        $joined = json_encode($v['history']);
        $this->check('track: authority shown by post, never by name', [true, false], [str_contains($joined, 'Head of Department (' . $depName . ')'), str_contains($joined, 'Hod Person')]);
        $this->check('track: own actions shown as "You"', true, str_starts_with($v['history'][0]['by'], 'You'));
        $this->check('track: remarks are visible', 'Which lab?', $v['history'][1]['remark']);
        WorkflowService::submitterReply($a['reference_no'], 'ZZ-TR1', 'Chemistry lab.');
        WorkflowService::act($a['id'], $hodU, 'escalate', 'Needs the Director.');
        $v = TrackingService::view($a['id']);
        $this->check('track: escalation shows the destination and its remark', ['Transferred to Director', 'Needs the Director.'], [$v['history'][3]['title'], $v['history'][3]['remark']]);
        $this->check('track: status text under review, no reply form', ['Under review', null], [$v['status_text'], $v['reply']['mode']]);

        // Director oversight (view only)
        $this->check('Director recognised; HOD is not', [true, false], [WorkflowService::isDirector($dirU), WorkflowService::isDirector($hodU)]);
        $this->check('Director can view a grievance still at an HOD', [true, false], [WorkflowService::canView($dirU, $c['id']), WorkflowService::canAct($dirU, $c['id'])]);
        $this->check('Director can act once it reaches the Director', true, WorkflowService::canAct($dirU, $a['id']));
        $all = array_column(WorkflowService::inbox($dirU, 'all', 500)['rows'], 'reference_no');
        $this->check('"All grievances" list for the Director', true, in_array($c['reference_no'], $all, true) && in_array($d['reference_no'], $all, true));
        $this->check('only the Director gets that tab', [true, false], [isset(WorkflowService::inboxCounts($dirU)['all']), isset(WorkflowService::inboxCounts($hodU)['all'])]);
        $this->check('another HOD still cannot view it', false, WorkflowService::canView($hod2, $c['id']));

        // Filters
        $refs = static fn (array $f) => array_column(WorkflowService::inbox($hodU, 'action', 100, 0, $f)['rows'], 'reference_no');
        $this->check('search by part of the submitter name', [$c['reference_no']], $refs(['q' => 'other pers']));
        $this->check('search by reference number', [$b['reference_no']], $refs(['q' => substr($b['reference_no'], -8)]));
        $this->check('search wildcards are taken literally', [], $refs(['q' => '%']));
        $this->check('filter by type', [$b['reference_no']], $refs(['type_id' => $typ2]));
        $db->query("UPDATE grievances SET level_entered_at = now() - interval '9 days' WHERE id = ?", [$c['id']]);
        $this->check('filter overdue only', [$c['reference_no']], $refs(['overdue' => true]));

        // Counts per post (one person holding two HOD posts)
        $pc = WorkflowService::postCounts($hodU);
        $byLabel = array_column($pc, null, 'label');
        $this->check('per-post counts', [1, 1, 0, 1], [
            $byLabel['Head of Department (' . $depName . ')']['pending'] ?? -1, $byLabel['Head of Department (' . $dep2Name . ')']['pending'] ?? -1,
            $byLabel['Head of Department (' . $depName . ')']['overdue'] ?? -1, $byLabel['Head of Department (' . $dep2Name . ')']['overdue'] ?? -1,
        ]);

        // Related grievances of the same person
        $rel = WorkflowService::related($b['id'], $hodU);
        $this->check('related: the other grievance of the same person', [$a['reference_no']], array_column($rel, 'reference_no'));
        $this->check('related: marked as a different type', false, $rel[0]['same_type']);
        $this->check('related: HOD who passed it on can still open it', true, $rel[0]['can_open']);
        $this->check('related: nothing for someone with a single grievance', [], WorkflowService::related($c['id'], $hodU));
        $sub('ZZ-TR3', $type, 'Private fourth');
        $rel = WorkflowService::related($d['id'], $hodU);
        $this->check('related seen by someone without access: summary only, no subject', [false, null], [$rel[0]['can_open'] ?? null, array_key_exists('subject', $rel[0] ?? []) ? $rel[0]['subject'] : 'missing']);
        $db->query("UPDATE grievances SET created_at = now() - interval '13 months' WHERE id = ?", [$a['id']]);
        $this->check('related: older than 12 months left out', [], WorkflowService::related($b['id'], $hodU));
    }

    private function adminTools(): void
    {
        CLI::write("\n-- admin search, export, report, archive (module 7)", 'yellow');
        $db   = Database::connect();
        $dep  = $this->departmentId('report');
        $depName = $this->departmentName($dep);
        $sem  = $this->id('semesters', "name = 'Semester 1'");
        $hod  = $this->id('posts', "name = 'Head of Department'");
        $type = TypeService::create('student', 'ZZ Reported', 'ZZR', false, 9);
        MatrixService::addLevel($type, $hod, 1, 7);
        $hodU = UserService::create('zz.m7.hod', 'Report Hod', '');
        PostService::assign($hod, $dep, $hodU);
        MasterListService::create('student', 'ZZ-RP1', 'Rita Report', $dep, $sem, null, '9876500031');
        $who = SubmissionService::lookup('student', 'ZZ-RP1');
        $sub = static fn (string $subject) => SubmissionService::submit($who, ['type_id' => $type, 'contact' => '9876500031', 'subject' => $subject, 'description' => "=HYPERLINK(\"x\")\nline two\x0B", 'confirm_duplicate' => '1']);
        $a = $sub('Report one');
        $b = $sub('+Report two');
        $c = $sub('Report three');
        WorkflowService::act($a['id'], $hodU, 'dispose', 'Done, thanks.');
        WorkflowService::act($b['id'], $hodU, 'escalate', 'Up.');

        // Filters
        $f = GrievanceSearch::clean(['q' => 'rita', 'type' => (string) $type, 'status' => 'bogus', 'from' => '2026-02-30', 'archived' => 'x', 'overdue' => '1x']);
        $this->check('filter cleaning drops invalid values', ['q' => 'rita', 'type' => (string) $type, 'archived' => 'no'], $f);
        $refs = static fn (array $in) => array_column(GrievanceSearch::search(GrievanceSearch::clean($in), 100)['rows'], 'reference_no');
        $this->check('search by name finds all three', 3, count($refs(['q' => 'rita report'])));
        $this->check('search by Scholar Number', 3, count($refs(['q' => 'zz-rp1'])));
        $this->check('status disposed', [$a['reference_no']], $refs(['q' => 'zz-rp1', 'status' => 'disposed']));
        $this->check('status open', 2, count($refs(['q' => 'zz-rp1', 'status' => 'open'])));
        $this->check('current post filter', [$c['reference_no']], $refs(['q' => 'zz-rp1', 'post' => (string) $hod, 'status' => 'open']));
        $today = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        $this->check('date range including today', 3, count($refs(['q' => 'zz-rp1', 'from' => $today, 'to' => $today])));
        $this->check('date range in the past', 0, count($refs(['q' => 'zz-rp1', 'to' => '2020-01-01'])));

        // Exports
        $ids  = [$a['id'], $b['id'], $c['id']];
        $rows = ExportService::grievanceRows($ids);
        $head = $rows[0];
        $col  = static fn (string $name) => array_search($name, $head, true);
        $this->check('grievance export: header + one row each', 4, count($rows));
        $this->check('export has submitter contact and description', ['9876500031', true], [$rows[1][$col('Contact')], str_contains((string) $rows[1][$col('Description')], 'line two')]);
        $this->check('export shows the decision for a disposed grievance', ['Disposed', 'Done, thanks.', 'Report Hod'], [$rows[1][$col('Status')], $rows[1][$col('Decision remark')], $rows[1][$col('Disposed by (person)')]]);
        $this->check('export counts transfers', 1, $rows[2][$col('Transfers')]);
        $hist = ExportService::historyRows($ids);
        $this->check('history export: one row per action', 1 + 2 + 2 + 1, count($hist));
        $csv = ExportService::csv($rows);
        $this->check('CSV starts with a UTF-8 marker', "\xEF\xBB\xBF", substr($csv, 0, 3));
        $this->check('formulas neutralised in CSV', [true, true, false], [str_contains($csv, "'=HYPERLINK"), str_contains($csv, "'+Report two"), str_contains($csv, "\x0B")]);
        $this->check('safe cell keeps normal text', ['abc', "'-5", ''], [ExportService::safeCell('abc'), ExportService::safeCell('-5'), ExportService::safeCell(null)]);
        [$xlsx, $name] = ExportService::build($ids, 'grievances', 'xlsx');
        $file = tempnam(sys_get_temp_dir(), 'pt') . '.xlsx';
        file_put_contents($file, $xlsx);
        $this->tmp[] = $file;
        $back = array_values(SimpleXlsx::read($file));
        $this->check('Excel export opens and has the rows', [4, $a['reference_no']], [count($back), $back[1][0] ?? null]);
        $this->check('export file name', 1, preg_match('/^grievances-\d{4}-\d{2}-\d{2}-\d{6}\.xlsx$/', $name));
        $this->refused('unknown export kind refused', fn () => ExportService::build($ids, 'everything', 'csv'));
        $before = (int) $this->val("SELECT count(*) FROM audit_log WHERE action = 'export'");
        ExportService::log('admin', 'grievances', 'csv', 3, ['q' => 'x']);
        $this->check('export is written to the audit log', $before + 1, (int) $this->val("SELECT count(*) FROM audit_log WHERE action = 'export'"));

        // Report
        $r = ReportService::summary($today, $today);
        $mine = array_values(array_filter($r['byType'], static fn ($x) => $x['name'] === 'ZZ Reported'))[0] ?? [];
        $this->check('report by type', [3, 1, 2], [(int) ($mine['total'] ?? 0), (int) ($mine['disposed'] ?? 0), (int) ($mine['pending'] ?? 0)]);
        $this->check('report counts escalated grievances', true, (int) $r['totals']['escalated'] >= 1);
        $dept = array_values(array_filter($r['byDepartment'], static fn ($x) => $x['name'] === $depName))[0] ?? [];
        $this->check('report by department', [3, 2], [(int) ($dept['total'] ?? 0), (int) ($dept['open'] ?? 0)]);
        $this->check('report by month has this month', true, in_array(substr($today, 0, 7), array_column($r['byMonth'], 'month'), true));
        $post = array_values(array_filter($r['posts'], static fn ($x) => $x['post'] === 'Head of Department (' . $depName . ')'))[0] ?? [];
        $this->check('report: open per post right now', 1, (int) ($post['open'] ?? 0));
        $file = tempnam(sys_get_temp_dir(), 'pt') . '.xlsx';
        file_put_contents($file, ReportService::xlsx($r));
        $this->tmp[] = $file;
        $this->check('report workbook opens', 'Submitted', array_values(SimpleXlsx::read($file, 'Summary'))[1][0] ?? null);

        // Archive
        $this->refused('an open grievance cannot be archived', fn () => ArchiveService::archive((int) $c['id']), 'still open');
        $this->refused('a recently disposed one cannot be archived yet', fn () => ArchiveService::archive((int) $a['id']), 'less than');
        $db->query("UPDATE grievances SET disposed_at = now() - interval '4 years' WHERE id = ?", [$a['id']]);
        $n = ArchiveService::eligibleCount();
        $this->check('old disposed grievance is eligible', true, $n >= 1 && in_array($a['reference_no'], array_column(ArchiveService::eligible(5000), 'reference_no'), true));
        $this->refused('bulk archive refuses when more became eligible than shown', fn () => ArchiveService::archiveEligible($n - 1), 'check the list again');
        $this->check('bulk archive', $n, ArchiveService::archiveEligible($n));
        $this->check('archived flag set, nothing deleted', [true, 4], [is_true($this->val('SELECT archived FROM grievances WHERE id = ?', [$a['id']])), (int) $this->val('SELECT count(*) FROM grievance_actions WHERE grievance_id = ?', [$a['id']]) + 2]);
        $this->check('archived: left out of the authority\'s lists', false, in_array($a['reference_no'], array_column(WorkflowService::inbox($hodU, 'earlier', 500)['rows'], 'reference_no'), true));
        $this->check('archived: hidden from the default admin search, found with the filter', [0, 1], [count($refs(['q' => $a['reference_no']])), count($refs(['q' => $a['reference_no'], 'archived' => 'yes']))]);
        $this->check('archived: submitter can still track it', $a['id'], TrackingService::find($a['reference_no'], 'ZZ-RP1'));
        $this->refused('archiving twice refused', fn () => ArchiveService::archive((int) $a['id']), 'already');
        ArchiveService::restore((int) $a['id']);
        $this->check('restore', false, is_true($this->val('SELECT archived FROM grievances WHERE id = ?', [$a['id']])));
        $this->refused('restoring a grievance that is not archived refused', fn () => ArchiveService::restore((int) $a['id']), 'not archived');
        $this->check('archive and restore are audited', [true, true], [
            (int) $this->val("SELECT count(*) FROM audit_log WHERE action = 'archive' AND created_at = now()") >= 1,
            (int) $this->val("SELECT count(*) FROM audit_log WHERE action = 'restore' AND entity_id = ? AND created_at = now()", [(string) $a['id']]) === 1,
        ]);
        ArchiveService::archive((int) $a['id']);
        $this->check('single archive of an eligible grievance', true, is_true($this->val('SELECT archived FROM grievances WHERE id = ?', [$a['id']])));
    }

    private function masterListUpload(): void
    {
        CLI::write("\n-- master list upload", 'yellow');
        $db   = Database::connect();
        $dep  = $this->departmentName($this->departmentId('cs'));
        $host = 'ZZ Hostel U';
        $H    = ['Scholar Number', 'Name', 'Department', 'Semester', 'Hostel', 'Contact'];

        $file = $this->csv([$H,
            ['ZZ-U1', 'One', $dep, 'Semester 2', 'ZZ Hostel U', '9000000001'],
            ['zz-u2', 'Two', strtolower($dep), '3', '', ''],
            ['ZZ-U3', 'Three', $dep, '4', '', '']]);
        $r = MasterListImporter::parse($file, 'csv', 'student', 1);
        $this->check('preview: three new people', 3, $r['new']);
        $this->check('preview: no errors', 0, count($r['errors']));
        $this->check('preview wrote nothing', 0, $this->val("SELECT count(*) FROM master_people WHERE id_code LIKE 'ZZ-U%' OR id_code LIKE 'zz-u%'"));
        $done = MasterListImporter::commit($r['token'], 1);
        $this->check('three rows saved', 3, $done['rows']);
        $this->check('semester matched by number', 'Semester 3', $this->val("SELECT s.name FROM master_people p JOIN semesters s ON s.id = p.semester_id WHERE lower(p.id_code) = 'zz-u2'"));
        $this->check('blank hostel = day scholar', null, $this->val("SELECT hostel FROM master_people WHERE id_code = 'ZZ-U3'"));

        // Update + add, nobody removed, blank contact preserved, id match ignores case
        $file = $this->csv([$H,
            ['ZZ-U1', 'One Renamed', $dep, 'Semester 2', '', ''],
            ['ZZ-U2', 'Two', $dep, 'Semester 3', '', ''],
            ['ZZ-U4', 'Four', $dep, 'Semester 1', '', '9000000004']]);
        $r = MasterListImporter::parse($file, 'csv', 'student', 1);
        $this->check('preview: one new', 1, $r['new']);
        $this->check('preview: one changed (U1: name and hostel)', 1, $r['changed']);
        $this->check('preview: one unchanged', 1, $r['same']);
        MasterListImporter::commit($r['token'], 1);
        $this->check('existing person updated', 'One Renamed', $this->val("SELECT name FROM master_people WHERE id_code = 'ZZ-U1'"));
        $this->check('blank contact kept the stored number', '9000000001', $this->val("SELECT contact FROM master_people WHERE id_code = 'ZZ-U1'"));
        $this->check('person missing from the file was not removed', 1, $this->val("SELECT count(*) FROM master_people WHERE id_code = 'ZZ-U3'"));
        $this->check('no duplicate created for different id case', 1, $this->val("SELECT count(*) FROM master_people WHERE lower(id_code) = 'zz-u2'"));
        $this->check('hostel cleared by blank', null, $this->val("SELECT hostel FROM master_people WHERE id_code = 'ZZ-U1'"));

        // Errors block everything
        $file = $this->csv([$H,
            ['ZZ-E1', 'Ok', $dep, 'Semester 1', '', ''],
            ['ZZ-E1', 'Dup', $dep, 'Semester 1', '', ''],
            ['ZZ-E3', '', $dep, 'Semester 1', '', ''],
            ['ZZ-E4', 'Four', 'Nowhere', 'Semester 1', '', ''],
            ['ZZ-E5', 'Five', $dep, 'Semester 99', '', ''],
            ['ZZ-E6', 'Six', $dep, 'Semester 1', 'No Such Hostel', ''],
            ['ZZ-E7', 'Seven', $dep, 'Semester 1', '', 'abc'],
            ['!!', 'Eight', $dep, 'Semester 1', '', ''],
            ['ZZ-E9', 'Nine', $dep, '', '', '']]);
        $r = MasterListImporter::parse($file, 'csv', 'student', 1);
        $msgs = array_column($r['errors'], 'message');
        $this->has('repeated id reported', $msgs, 'repeated');
        $this->has('missing name reported', $msgs, 'Name is required');
        $this->has('unknown department reported', $msgs, 'Nowhere');
        $this->has('unknown semester reported', $msgs, 'Semester "Semester 99"');
        $this->check('free-text hostel is accepted without portal mapping', false, count(array_filter($msgs, static fn ($m) => str_contains($m, 'No Such Hostel'))) > 0);
        $this->has('bad contact reported', $msgs, 'Contact');
        $this->has('bad id reported', $msgs, 'Scholar Number is missing or invalid');
        $this->has('missing semester reported (required on the form)', $msgs, 'Semester is required');
        $this->refused('a file with errors cannot be committed', fn () => MasterListImporter::commit($r['token'], 1), 'errors');
        $this->check('nothing from the bad file was saved', 0, $this->val("SELECT count(*) FROM master_people WHERE id_code LIKE 'ZZ-E%'"));

        // Semester becomes optional when the form field is not required
        $db->query("UPDATE form_fields SET mandatory = false WHERE role = 'student' AND field_key = 'semester'");
        $r = MasterListImporter::parse($this->csv([$H, ['ZZ-O1', 'Opt', $dep, '', '', '']]), 'csv', 'student', 1);
        $this->check('semester optional when the form does not require it', 0, count($r['errors']));

        // Wrong columns, faculty file, tokens
        $this->refused('wrong columns refused', fn () => MasterListImporter::parse($this->csv([['a', 'b'], ['1', '2']]), 'csv', 'student', 1), 'do not match');
        $r = MasterListImporter::parse($this->csv([['Employee Code', 'Name', 'Department', 'Contact'], ['ZZ-F1', 'Fac', $dep, '9111111111']]), 'csv', 'faculty', 1);
        MasterListImporter::commit($r['token'], 1);
        $this->check('faculty row saved without semester or hostel', 1, $this->val("SELECT count(*) FROM master_people WHERE role = 'faculty' AND id_code = 'ZZ-F1' AND semester_id IS NULL AND hostel IS NULL"));
        $r = MasterListImporter::parse($this->csv([['Employee Code', 'Name', 'Department', 'Contact'], ['ZZ-F2', 'Fac2', 'ZZ Library Missing', '']]), 'csv', 'non_teaching', 1);
        $this->check('non-teaching file rejects an unknown department', 1, count($r['errors']));
        $this->refused('another admin cannot use the token', fn () => MasterListImporter::commit($r['token'], 2));
        $this->refused('malformed token refused', fn () => MasterListImporter::commit('../../etc/passwd', 1), 'no longer available');
    }

    private function bulkUsers(): void
    {
        CLI::write("\n-- authority bulk upload", 'yellow');
        $H = ['Username', 'Full Name', 'Email', 'Post', 'Department or Hostel'];
        $st = config('Google')->staffDomain;
        $department = $this->departmentName($this->departmentId('cs'));
        $r = BulkUserImporter::parse($this->csv([$H,
            ['zz.bulk1', 'Bulk One', "zz.bulk1@{$st}", 'Head of Department', $department],
            ['zz.bulk1', 'Bulk One', '', 'Registrar', ''],
            ['zz.bulk2', 'Bulk Two', "zz.bulk2@{$st}", '', '']]), 'csv', 1);
        $this->check('preview: two accounts, two posts', [2, 2, 0], [$r['users'], $r['posts'], count($r['errors'])]);
        $done = BulkUserImporter::commit($r['token'], 1);
        $this->check('accounts created', 2, $done['created']);
        $this->check('multi-row user holds two posts', 2, $this->val("SELECT count(*) FROM post_holders a JOIN users u ON u.id = a.user_id WHERE u.username = 'zz.bulk1' AND a.active"));
        $this->check('bulk accounts are not asked for a password change', 0, $this->val("SELECT count(*) FROM users WHERE username IN ('zz.bulk1','zz.bulk2') AND must_change_password"));

        $r = BulkUserImporter::parse($this->csv([$H,
            ['zz.bulk1', 'Dup', "zz.dup@{$st}", '', ''],
            ['zz.n3', 'Three', 'bad-email', 'Nonexistent', ''],
            ['zz.n4', 'Four', "zz.n4@{$st}", 'Head of Department', 'Nowhere'],
            ['zz.n5', 'Five', "zz.n5@{$st}", 'Registrar', 'x'],
            ['zz.n6', 'Six', "zz.n6@{$st}", 'Registrar', ''],
            ['zz.n7', 'Seven', '', '', ''],
            ['zz.n8', 'Eight', 'zz.n8@gmail.com', '', '']]), 'csv', 1);
        $msgs = array_column($r['errors'], 'message');
        $this->has('existing username reported', $msgs, 'already exists');
        $this->has('bad email reported', $msgs, 'Email is not valid');
        $this->has('missing email reported', $msgs, 'Email is required');
        $this->has('non-institute email reported', $msgs, 'staff address');
        $this->has('unknown post reported', $msgs, 'Nonexistent');
        $this->has('unknown department reported', $msgs, 'Nowhere');
        $this->has('post already held reported', $msgs, 'already held');
        $this->has('single post with a department reported', $msgs, 'single post');
        $this->refused('a master-list token cannot be used here', fn () => BulkUserImporter::commit(\App\Libraries\ImportStore::save(['role' => 'student', 'records' => [], 'ok' => true], 1), 1), 'not an authority upload');
    }

    private function xlsx(): void
    {
        CLI::write("\n-- Excel files", 'yellow');
        $file = tempnam(sys_get_temp_dir(), 'px') . '.xlsx';
        file_put_contents($file, SimpleXlsx::write([['name' => 'People', 'header' => true, 'rows' => [['A', 'B'], ['1', 'Ünïcode & <tags>'], ['0007', 'x']]]]));
        $this->tmp[] = $file;
        $rows = array_values(SimpleXlsx::read($file, 'People'));
        $this->check('workbook round-trips with special characters', 'Ünïcode & <tags>', $rows[1][1]);
        $this->check('leading zeros are kept', '0007', $rows[2][0]);

        foreach (['student', 'faculty', 'non_teaching'] as $role) {
            $f = tempnam(sys_get_temp_dir(), 'pt') . '.xlsx';
            file_put_contents($f, MasterListImporter::template($role));
            $this->tmp[] = $f;
            $first = array_values(SimpleXlsx::read($f, 'People'))[0];
            $this->check("{$role} template header", MasterListImporter::headers($role), $first);
        }
        $f = tempnam(sys_get_temp_dir(), 'pt') . '.xlsx';
        file_put_contents($f, BulkUserImporter::template());
        $this->tmp[] = $f;
        $this->check('authority template header', BulkUserImporter::HEADERS, array_values(SimpleXlsx::read($f, 'Users'))[0]);
    }

    private function googleAndOtp(): void
    {
        CLI::write("\n-- Google sign-in and OTP", 'yellow');
        helper('portal');
        $g   = config('Google');
        $stu = $g->studentDomain;
        $stf = $g->staffDomain;
        $db  = Database::connect();

        // Which e-mails count as institute accounts
        $this->check('student domain recognised', 'student', GoogleAuth::domainKind("A.B@" . strtoupper($stu)));
        $this->check('staff domain recognised', 'staff', GoogleAuth::domainKind("x@{$stf}"));
        $this->check('private Gmail refused', null, GoogleAuth::domainKind('x@gmail.com'));
        $this->check('look-alike sub-domain refused', null, GoogleAuth::domainKind("x@evil.{$stu}"));
        $this->check('look-alike suffix refused', null, GoogleAuth::domainKind("x@{$stf}.evil.com"));
        $this->check('not an e-mail refused', null, GoogleAuth::domainKind("@{$stf}"));

        // ID-token claim checks
        $oldClient   = $g->clientId;
        $g->clientId = 'test-client';
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'test-client', 'exp' => time() + 300, 'nonce' => 'n1',
            'email' => "Asha@{$stu}", 'email_verified' => true, 'hd' => $stu];
        $this->check('valid Google token accepted', "asha@{$stu}", GoogleAuth::checkClaims($claims, 'n1', time()));
        $this->refused('token for another app refused', fn () => GoogleAuth::checkClaims(['aud' => 'other'] + $claims, 'n1', time()), 'verified');
        $this->refused('expired token refused', fn () => GoogleAuth::checkClaims(['exp' => time() - 600] + $claims, 'n1', time()), 'verified');
        $this->refused('wrong nonce refused', fn () => GoogleAuth::checkClaims($claims, 'n2', time()), 'verified');
        $this->refused('wrong issuer refused', fn () => GoogleAuth::checkClaims(['iss' => 'evil.com'] + $claims, 'n1', time()), 'verified');
        $this->refused('unverified e-mail refused', fn () => GoogleAuth::checkClaims(['email_verified' => false] + $claims, 'n1', time()), 'institute');
        $this->refused('personal account (no hd) refused', fn () => GoogleAuth::checkClaims(array_diff_key($claims, ['hd' => 1]), 'n1', time()), 'institute');
        $this->refused('other Workspace refused', fn () => GoogleAuth::checkClaims(['hd' => 'other.edu', 'email' => 'x@other.edu'] + $claims, 'n1', time()), 'institute');
        $this->refused('institute hd with outside e-mail refused', fn () => GoogleAuth::checkClaims(['email' => 'x@gmail.com'] + $claims, 'n1', time()), 'institute');
        $g->clientId = $oldClient;
        $url = (function () use ($g) { $g->clientId = 'test-client'; $u = GoogleAuth::authUrl(); $g->clientId = ''; return $u; })();
        $this->check('sign-in link carries state, nonce and the openid scope', [1, 1, 1],
            [(int) str_contains($url, 'state='), (int) str_contains($url, 'nonce='), (int) str_contains($url, 'scope=openid+email')]);
        GoogleAuth::authUrl('admin');
        $this->check('administrator Google sign-in is marked as a separate flow', 'admin', GoogleAuth::pendingFlow());
        $this->refused('callback with a forged state refused', fn () => GoogleAuth::emailFromCallback('forged', 'code', null), 'expired');
        $this->check('invalid callback consumes the pending Google flow', 'user', GoogleAuth::pendingFlow());
        $this->refused('cancelled sign-in reported', fn () => GoogleAuth::emailFromCallback(null, null, 'access_denied'), 'cancelled');
        $this->check('test login is off unless switched on', false, (function () use ($g) { $o = $g->devLogin; $g->devLogin = false; $r = GoogleAuth::devLoginEnabled(); $g->devLogin = $o; return $r; })());

        // Master list e-mails
        $dep = $this->departmentId('cs');
        $depName = $this->departmentName($dep);
        $sid = MasterListService::create('student', 'ZZ-G1', 'Gita Student', $dep, null, null, '9876500011', "Gita@{$stu}");
        $this->check('student e-mail saved in lower case', "gita@{$stu}", $this->val('SELECT email FROM master_people WHERE id = ?', [$sid]));
        $this->check('staff e-mail refused for a student', true, MasterListService::emailError('student', "x@{$stf}") !== null);
        $this->check('student e-mail refused for faculty', true, MasterListService::emailError('faculty', "x@{$stu}") !== null);
        $this->check('duplicate e-mail refused', true, str_contains((string) MasterListService::emailError('student', "GITA@{$stu}"), 'ZZ-G1'));
        $this->check('own e-mail is not a duplicate', null, MasterListService::emailError('student', "gita@{$stu}", $sid));
        $this->check('blank e-mail allowed', null, MasterListService::emailError('student', ''));
        MasterListService::update($sid, 'Gita Student', $dep, null, null, '9876500011');
        $this->check('update without an e-mail keeps the stored one', "gita@{$stu}", $this->val('SELECT email FROM master_people WHERE id = ?', [$sid]));

        // Upload with and without the Email column
        $r = MasterListImporter::parse($this->csv([['Scholar Number', 'Name', 'Department', 'Semester', 'Hostel', 'Contact', 'Email'],
            ['ZZ-G2', 'Two', $depName, '', '', '', "zz.g2@{$stu}"],
            ['ZZ-G3', 'Three', $depName, '', '', '', "gita@{$stu}"],
            ['ZZ-G4', 'Four', $depName, '', '', '', "zz.g4@{$stf}"],
            ['ZZ-G5', 'Five', $depName, '', '', '', "zz.g2@{$stu}"]]), 'csv', 'student', 1);
        $msgs = array_column($r['errors'], 'message');
        $this->has('upload: e-mail of another person reported', $msgs, 'already belongs to ZZ-G1');
        $this->has('upload: staff e-mail for a student reported', $msgs, '@' . $stu);
        $this->has('upload: repeated e-mail reported', $msgs, 'repeated');
        $r = MasterListImporter::parse($this->csv([['Scholar Number', 'Name', 'Department', 'Semester', 'Hostel', 'Contact'],
            ['ZZ-G6', 'Six', $depName, '', '', '']]), 'csv', 'student', 1);
        $this->check('upload: older template without Email still accepted', 0, count($r['errors']));
        $r = MasterListImporter::parse($this->csv([['Scholar Number', 'Name', 'Department', 'Semester', 'Hostel', 'Contact', 'Email'],
            ['ZZ-G1', 'Gita Student', $depName, '', '', '', ''],
            ['ZZ-G7', 'Seven', $depName, '', '', '', "zz.g7@{$stu}"]]), 'csv', 'student', 1);
        MasterListImporter::commit($r['token'], 1);
        $this->check('upload: blank e-mail keeps the stored one', "gita@{$stu}", $this->val("SELECT email FROM master_people WHERE id_code = 'ZZ-G1'"));
        $this->check('upload: new e-mail saved', "zz.g7@{$stu}", $this->val("SELECT email FROM master_people WHERE id_code = 'ZZ-G7'"));

        // Sign-in by e-mail (what happens after Google confirms the address)
        $fac = MasterListService::create('faculty', 'ZZ-GF', 'Dr. Both Roles', $dep, null, null, '9876500012', "both@{$stf}");
        $uid = UserService::create('zz.both', 'Dr. Both Roles', "both@{$stf}");
        $onlyAuth = UserService::create('zz.onlyauth', 'Only Authority', "only.auth@{$stf}");
        $session = service('session');

        [$ok] = Auth::loginWithEmail("GITA@{$stu}");
        $this->check('student signs in', [true, $sid, null], [$ok, (int) $session->get('person_id'), $session->get('user_id')]);
        $this->check('student identity is the master list person', 'ZZ-G1', Auth::person()['id_code'] ?? null);
        [$ok] = Auth::loginWithEmail("both@{$stf}");
        $this->check('staff member who is also an authority gets both', [true, $fac, $uid], [$ok, (int) $session->get('person_id'), (int) $session->get('user_id')]);
        $this->check('and is not asked to change a password', false, Auth::user()['must_change_password']);
        [$ok] = Auth::loginWithEmail("only.auth@{$stf}");
        $this->check('authority without a master list entry signs in', [true, null, $onlyAuth], [$ok, $session->get('person_id'), (int) $session->get('user_id')]);
        [$ok, $msg] = Auth::loginWithEmail("nobody@{$stu}");
        $this->check('unknown e-mail refused', [false, true], [$ok, str_contains((string) $msg, 'not registered')]);
        $this->check('refused sign-in leaves nobody signed in', [null, null], [$session->get('person_id'), $session->get('user_id')]);
        [$ok] = Auth::loginWithEmail("x@gmail.com");
        $this->check('private e-mail refused', false, $ok);
        $db->query("UPDATE master_people SET active = false WHERE id = ?", [$sid]);
        [$ok] = Auth::loginWithEmail("gita@{$stu}");
        $this->check('deactivated person cannot sign in', false, $ok);
        $db->query("UPDATE master_people SET active = true WHERE id = ?", [$sid]);
        $db->query("UPDATE users SET password_hash = ? WHERE id = ?", [password_hash('Known#Pass1', PASSWORD_DEFAULT), $uid]);
        [$ok] = Auth::attempt('zz.both', 'Known#Pass1');
        $this->check('authorities cannot use a password any more', false, $ok);
        Auth::logout();
        $this->check('logout clears the submitter too', [null, null], [$session->get('person_id'), $session->get('user_id')]);

        // OTP
        Settings::set('otp_resend_seconds', '60');
        Settings::set('otp_max_per_hour', '5');
        Settings::set('otp_max_attempts', '3');
        $person = MasterListService::find($sid);
        // Test OTPs go to a temporary file, never into the real writable/logs/otp-*.log
        $otpLog = tempnam(sys_get_temp_dir(), 'otp');
        $this->tmp[] = $otpLog;
        OtpService::$logFileOverride = $otpLog;
        $lastCode = static function () use ($otpLog): string {
            $lines = file($otpLog) ?: [];
            preg_match('/: (\d{6}) is your OTP/', (string) end($lines), $m);

            return $m[1] ?? '';
        };
        $to = OtpService::send($person);
        $this->check('OTP sent to masked e-mail and mobile', ['g' . str_repeat('*', 3) . "@{$stu}", '******0011'], [$to['email'], $to['mobile']]);
        $code = $lastCode();
        $this->check('OTP is 6 digits and written to the log (e-mail and SMS)', 1, preg_match('/^\d{6}$/', $code));
        $this->check('only a hash of the OTP is stored', false, $this->val('SELECT code_hash FROM otp_codes WHERE person_id = ? ORDER BY id DESC LIMIT 1', [$sid]) === $code);
        $this->refused('a new OTP must wait for the resend time', fn () => OtpService::send($person), 'wait');
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->refused('wrong OTP refused, tries left shown', fn () => OtpService::consume($sid, $wrong), '2 tries left');
        $this->refused('OTP must be 6 digits', fn () => OtpService::consume($sid, '12'), '6-digit');
        $otpId = OtpService::consume($sid, " {$code} ");
        $this->check('right OTP accepted', true, $otpId > 0);
        $this->refused('OTP works only once', fn () => OtpService::consume($sid, $code), 'Send OTP');
        OtpService::release($otpId);
        $this->check('released OTP (grievance not saved) can be used again', $otpId, OtpService::consume($sid, $code));
        OtpService::release($otpId);
        $db->query("UPDATE otp_codes SET created_at = created_at - interval '2 minutes' WHERE person_id = ?", [$sid]);
        OtpService::send($person);
        $code2 = $lastCode();
        $this->refused('a new OTP replaces the old one', fn () => OtpService::consume($sid, $code === $code2 ? '999999' : $code), 'not correct');
        $this->refused('second wrong entry', fn () => OtpService::consume($sid, $wrong === $code2 ? '222222' : $wrong), '1 try left');
        $this->refused('too many wrong entries lock the OTP', fn () => OtpService::consume($sid, $wrong === $code2 ? '222222' : $wrong), 'too many');
        $this->refused('even the right OTP is refused after that', fn () => OtpService::consume($sid, $code2), 'too many');
        $db->query("UPDATE otp_codes SET created_at = created_at - interval '2 minutes' WHERE person_id = ?", [$sid]);
        OtpService::send($person);
        $code3 = $lastCode();
        $db->query("UPDATE otp_codes SET expires_at = now() - interval '1 second' WHERE person_id = ? AND used_at IS NULL", [$sid]);
        $this->refused('expired OTP refused', fn () => OtpService::consume($sid, $code3), 'expired');
        Settings::set('otp_resend_seconds', '0');
        for ($i = 0; $i < 2; $i++) {
            OtpService::send($person);
        }
        $this->refused('OTPs per hour are limited', fn () => OtpService::send($person), 'last hour');
        $this->refused('no e-mail and no mobile: no OTP', fn () => OtpService::send(['id' => $fac, 'email' => '', 'contact' => '']), 'contact the administration');
        $this->check('masks', ['a***@x.in', '******3210'], [OtpService::maskEmail('ab@x.in'), OtpService::maskMobile('98765 43210')]);
        Settings::set('otp_resend_seconds', '60');
        Settings::set('otp_max_attempts', '5');
        OtpService::$logFileOverride = null;
    }
}
