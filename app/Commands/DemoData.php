<?php

namespace App\Commands;

use App\Libraries\DesignationRoleService;
use App\Libraries\Erp\ErpDirectory;
use App\Libraries\Erp\ErpSync;
use App\Libraries\PostService;
use App\Libraries\Settings;
use App\Libraries\SubmissionService;
use App\Libraries\UserService;
use App\Libraries\WorkflowService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * DEMO ONLY. Fills a freshly installed portal with made-up demo data so that every screen has something to show:
 * switches the people source to the (dummy) ERP, verifies departments, creates demo authority
 * accounts, assigns them to their posts and submits 12 sample grievances in different stages.
 *
 *   php spark portal:demo-data
 *
 * It refuses to run when grievances already exist, so it can never be run by accident on a live portal.
 * The demo logins it creates only work with the test sign-in (google.devLogin = true).
 */
class DemoData extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'portal:demo-data';
    protected $description = 'DEMO ONLY: loads demo authorities, direct ERP hostel values and 12 sample grievances into a fresh portal.';
    protected $usage       = 'portal:demo-data';

    /** Departments whose HOD post is left vacant on purpose, to show the "waiting at a vacant post" warning. */
    private const VACANT_HOD = ['Chemical Engineering'];
    private const DEMO_HOSTELS = ['Boys Hostel 1', 'Boys Hostel 2', 'Boys Hostel 3', 'Girls Hostel 1', 'Girls Hostel 2'];

    /** ERP job titles the demo registers. 'Hostel Superintendent' is left out on purpose (shown live on Admin > ERP > Job titles). */
    private const FACULTY_TITLES = ['Professor', 'Associate Professor', 'Assistant Professor', 'Contract Faculty'];
    private const STAFF_TITLES   = ['Clerk', 'Lab Technician', 'Library Assistant', 'Project Assistant', 'Librarian', 'Accountant', 'Accounts Assistant', 'Junior Engineer', 'Assistant Registrar', 'Superintendent'];

    /** @var array<string,int> username => users.id */
    private array $users = [];

    /** @var list<array{0:string,1:string,2:string}> name, e-mail, post label (for the printed list) */
    private array $logins = [];

    public function run(array $params)
    {
        $db = Database::connect();

        try {
            if ((int) $db->query('SELECT count(*) AS n FROM grievances')->getRow('n') > 0) {
                throw new RuntimeException('This portal already has grievances. Demo data is only loaded into a freshly installed portal.');
            }

            CLI::write('1/5  Switching to the ERP people source', 'yellow');
            Settings::set('person_source', 'erp');
            ErpDirectory::forgetMemo();
            $this->titles();

            CLI::write('2/5  Checking departments from the ERP', 'yellow');
            $this->verifyDepartments();

            CLI::write('3/5  Creating demo authorities and assigning posts', 'yellow');
            $this->authorities();

            CLI::write('4/5  Submitting sample grievances', 'yellow');
            $this->grievances();

            CLI::write('5/5  Done. Demo logins are listed in demo/DEMO-LOGINS.txt', 'yellow');
            $this->writeLogins();

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Demo data stopped: ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    private function writeLogins(): void
    {
        $out  = "DEMO LOGINS (made up). Use the TEST SIGN-IN on the sign-in page and type the e-mail address.\n";
        $out .= "Authority accounts exist only in this demo database; they cannot sign in with real Google.\n\n";
        $out .= "ADMINISTRATOR\n  Sign-in page > Administrator login > username: admin\n\n";
        $out .= "STUDENTS (from the demo ERP; the e-mail is the roll number + @stu.manit.ac.in)\n";
        foreach ([
            ['2026cse001@stu.manit.ac.in', 'Komal Lal Mishra', 'CSE, Girls Hostel 2, room 187 (hostel resident)'],
            ['2023ce001@stu.manit.ac.in', 'Sanjay Agrawal', 'Civil Engineering, Boys Hostel 2'],
            ['2023ece002@stu.manit.ac.in', 'Mukesh Raghuwanshi', 'Electronics & Communication, Boys Hostel 1'],
            ['2023che001@stu.manit.ac.in', 'Naveen Prasad Agrawal', 'Chemical Engineering (HOD post vacant)'],
            ['2024ee002@stu.manit.ac.in', 'Yamini Rani Chouhan', 'Electrical Engineering, Girls Hostel 1'],
            ['2025cse001@stu.manit.ac.in', 'Siddharth Lal Malviya', 'CSE, day scholar (no hostel: hostel grievances are blocked)'],
            ['ms25phy001@stu.manit.ac.in', 'Amit Joshi', 'Physics must exist in establishment.dpcode to be routed'],
        ] as [$e, $n, $d]) {
            $out .= '  ' . str_pad($e, 34) . str_pad($n, 28) . $d . "\n";
        }
        $out .= "\nFACULTY AND STAFF (from the demo ERP; the e-mail must match the ERP)\n";
        $out .= '  ' . str_pad('ananya.mehta@manit.ac.in', 34) . str_pad('Ananya Mohan Mehta', 28) . "Professor, CSE (faculty)\n";
        $out .= '  ' . str_pad('mayank.reddy@manit.ac.in', 34) . str_pad('Mayank Devi Reddy', 28) . "Accountant, Accounts Section (non-teaching)\n";
        $out .= "\nCASES LEFT ON PURPOSE (to show the problem handling)\n";
        $out .= "  meera.tiwari@manit.ac.in      job title 'Hostel Superintendent' is not listed yet: sign-in refused until you list it on Admin > ERP > Job titles\n";
        $out .= "  trisha.malviya@manit.ac.in    shares one e-mail with another employee in the ERP: sign-in refused (data error)\n";
        $out .= "  nobody.here@stu.manit.ac.in   not in the ERP at all: 'not registered'\n";
        $out .= "\nAUTHORITIES (e-mail = login on the test sign-in)\n";
        foreach ($this->logins as [$name, $email, $post]) {
            $out .= '  ' . str_pad($email, 44) . $post . "\n";
        }
        $dir = ROOTPATH . 'demo';
        if (is_dir($dir)) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'DEMO-LOGINS.txt', $out);
        } else {
            CLI::write($out);
        }
    }

    private function titles(): void
    {
        foreach (self::FACULTY_TITLES as $t) {
            DesignationRoleService::set($t, 'faculty');
        }
        foreach (self::STAFF_TITLES as $t) {
            DesignationRoleService::set($t, 'non_teaching');
        }
    }

    private function verifyDepartments(): void
    {
        $scan = ErpSync::unmapped();
        if ($scan['error'] !== null) {
            throw new RuntimeException($scan['error']);
        }
        foreach ($scan['departments'] as $d) {
            throw new RuntimeException(
                'ERP department ID "' . $d['code'] . '" is missing from establishment.dpcode. Add or correct it in the source table before loading demo data.'
            );
        }
    }

    /** Short, readable names for the demo e-mail addresses (anything not listed falls back to a plain slug). */
    private const SHORT = [
        'Computer Science & Engineering' => 'cse', 'Electronics & Communication Engineering' => 'ece', 'Electrical Engineering' => 'ee',
        'Mechanical Engineering' => 'me', 'Civil Engineering' => 'ce', 'Chemical Engineering' => 'che',
        'Materials Science & Metallurgical Engineering' => 'msm', 'Mathematics, Bioinformatics & Computer Applications' => 'math',
        'Chemistry' => 'chem', 'Physics' => 'phy', 'Centre for Energy Studies' => 'ces',
        'Boys Hostel 1' => 'bh1', 'Boys Hostel 2' => 'bh2', 'Boys Hostel 3' => 'bh3', 'Girls Hostel 1' => 'gh1', 'Girls Hostel 2' => 'gh2',
        'Accounts Section' => 'accounts', 'Establishment Section' => 'establishment', 'Central Library' => 'library',
        'Academic Section' => 'academic', 'Works & Estate Section' => 'works', 'Student Welfare Office' => 'welfare',
    ];

    private static function slug(string $s): string
    {
        return self::SHORT[$s] ?? trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    }

    private function user(string $username, string $name, string $post): int
    {
        if (! isset($this->users[$username])) {
            $email = $username . '@manit.ac.in';
            $this->users[$username] = UserService::create($username, $name, $email);
            $this->logins[]         = [$name, $email, $post];
        }

        return $this->users[$username];
    }

    private function postId(string $name): int
    {
        $id = Database::connect()->query('SELECT id FROM posts WHERE name = ?', [$name])->getRow('id');
        if ($id === null) {
            throw new RuntimeException("Post '{$name}' not found.");
        }

        return (int) $id;
    }

    private function authorities(): void
    {
        $global = [
            'Director'                                           => ['demo.director', 'Demo Director'],
            'Dean (Academics)'                                   => ['demo.dean.academics', 'Demo Dean (Academics)'],
            'Chairman, Student Grievance Committee (Academic)'   => ['demo.sgc.academic', 'Demo Chairman SGC (Academic)'],
            'Chief Warden'                                       => ['demo.chief.warden', 'Demo Chief Warden'],
            'Chairman (Council of Wardens)'                      => ['demo.council.wardens', 'Demo Chairman (Council of Wardens)'],
            'Chairman, Student Grievance Committee (Hostel)'     => ['demo.sgc.hostel', 'Demo Chairman SGC (Hostel)'],
            'Dean (Student Welfare)'                             => ['demo.dean.welfare', 'Demo Dean (Student Welfare)'],
            'Chairman, Proctorial Committee'                     => ['demo.proctorial', 'Demo Chairman (Proctorial Committee)'],
            'Dean (Faculty Welfare)'                             => ['demo.dean.faculty', 'Demo Dean (Faculty Welfare)'],
            'Chairman, Grievance Committee (Faculty)'            => ['demo.gc.faculty', 'Demo Chairman GC (Faculty)'],
            'Registrar'                                          => ['demo.registrar', 'Demo Registrar'],
            'Chairman, Grievance Committee (Non-Teaching)'       => ['demo.gc.nonteaching', 'Demo Chairman GC (Non-Teaching)'],
        ];
        foreach ($global as $post => [$username, $name]) {
            PostService::assign($this->postId($post), null, $this->user($username, $name, $post));
        }

        $scoped = [
            ['Head of Department', 'demo.hod.', 'Demo HOD'],
            ['Reporting Officer', 'demo.officer.', 'Demo Reporting Officer'],
        ];
        foreach ($scoped as [$post, $prefix, $label]) {
            $postId = $this->postId($post);
            foreach (PostService::seats($postId) as $seat) {
                $scopeName = (string) $seat['scope_name'];
                if ($post === 'Head of Department' && in_array($scopeName, self::VACANT_HOD, true)) {
                    CLI::write("      left vacant on purpose: HOD of {$scopeName}");

                    continue;
                }
                $uid = $this->user($prefix . self::slug($scopeName), "{$label} - {$scopeName}", "{$post} ({$scopeName})");
                PostService::assign($postId, (int) $seat['scope_id'], $uid);
            }
        }

        $wardenId = $this->postId('Warden');
        foreach (self::DEMO_HOSTELS as $hostel) {
            $uid = $this->user('demo.warden.' . self::slug($hostel), 'Demo Warden - ' . $hostel, 'Warden (' . $hostel . ')');
            PostService::assign($wardenId, $hostel, $uid);
        }
    }

    /** The person as the portal's submission code expects it, read from the ERP exactly as a first sign-in would. */
    private function student(string $roll): array
    {
        $res = ErpDirectory::lookupStudent($roll);
        if ($res['status'] !== 'ok') {
            throw new RuntimeException("Student {$roll} is not usable in the ERP ({$res['status']}).");
        }
        $identity = ErpDirectory::upsertIdentity($res['profile'], strtolower($roll) . '@stu.manit.ac.in');

        return $this->person($identity, $res['profile']);
    }

    private function staff(string $email): array
    {
        $res = ErpDirectory::lookupStaff($email);
        if ($res['status'] !== 'ok') {
            throw new RuntimeException("Employee {$email} is not usable in the ERP ({$res['status']}).");
        }
        $identity = ErpDirectory::upsertIdentity($res['profile'], $email);

        return $this->person($identity, $res['profile']);
    }

    private function person(?array $identity, array $profile): array
    {
        if ($identity === null) {
            throw new RuntimeException('Could not create the person record.');
        }
        ErpDirectory::forgetMemo();

        return ErpDirectory::personRow($identity, $profile, false);
    }

    private function typeId(string $code): int
    {
        return (int) Database::connect()->query('SELECT id FROM grievance_types WHERE code = ?', [$code])->getRow('id');
    }

    /** @return array{id:int,reference_no:string} */
    private function submit(array $person, string $type, string $subject, string $text): array
    {
        $r = SubmissionService::submit($person, [
            'type_id'     => $this->typeId($type),
            'contact'     => (string) ($person['contact'] ?? '9876543210'),
            'subject'     => $subject,
            'description' => $text,
        ]);
        CLI::write("      {$r['reference_no']}  {$subject}");

        return $r;
    }

    /** The user who currently holds the post this grievance is waiting at. */
    private function holderOf(int $grievanceId): int
    {
        $row = Database::connect()->query(
            "SELECT h.user_id FROM grievances g
               JOIN escalation_levels l ON l.id = g.current_level_id
               JOIN post_holders h ON h.post_id = l.post_id AND h.active
                AND CASE (SELECT scope_type FROM posts WHERE id = l.post_id)
                      WHEN 'department' THEN h.scope_id = g.department_id
                      WHEN 'hostel' THEN lower(btrim(h.hostel)) = lower(btrim(g.hostel))
                      ELSE h.scope_id IS NULL AND h.hostel IS NULL END
              WHERE g.id = ?",
            [$grievanceId]
        )->getRowArray();
        if ($row === null) {
            throw new RuntimeException('Nobody holds the post this grievance is waiting at.');
        }

        return (int) $row['user_id'];
    }

    private function act(array $g, string $action, string $remark): void
    {
        WorkflowService::act($g['id'], $this->holderOf($g['id']), $action, $remark);
    }

    private function grievances(): void
    {
        $komal  = $this->student('2026CSE001');   // CSE, Girls Hostel 2
        $sanjay = $this->student('2023CE001');    // CE, Boys Hostel 2
        $mukesh = $this->student('2023ECE002');   // ECE, Boys Hostel 1
        $naveen = $this->student('2023CHE001');   // Chemical Engineering (HOD post vacant on purpose)
        $yamini = $this->student('2024EE002');    // EE, Girls Hostel 1
        $sidd   = $this->student('2025CSE001');   // CSE, day scholar
        $anan   = $this->staff('ananya.mehta@manit.ac.in');   // Professor, CSE
        $mayank = $this->staff('mayank.reddy@manit.ac.in');   // Accountant, Accounts Section

        // 1 new, waiting at the HOD (made to look 9 days old at this level, so the overdue banner can be shown)
        $overdue = [];
        $overdue[] = $this->submit($komal, 'ACD', 'Internal marks not uploaded', 'The internal assessment marks of the Data Structures course have not been uploaded on the portal although the exam was held five weeks ago.');

        // 2 escalated once: now with the Dean (Academics)
        $g = $this->submit($sanjay, 'ACD', 'Clash in practical exam schedule', 'Two practical examinations are scheduled in the same slot on the same day. Please advise how to attend both.');
        $this->act($g, 'escalate', 'The clash needs a decision from the academic office. Transferred to the Dean (Academics).');

        // 3 waiting for the submitter's answer
        $g = $this->submit($mukesh, 'HST', 'Water supply problem on the second floor', 'There has been no water supply on the second floor since Monday morning.');
        $this->act($g, 'request_info', 'Please tell us the wing and the room numbers affected.');

        // 4 disposed
        $g = $this->submit($yamini, 'ACD', 'Wrong attendance shown for one lecture', 'My attendance for the lecture of 12 October is shown as absent although I attended.');
        $this->act($g, 'dispose', 'Verified with the faculty and the attendance register. The record has been corrected.');

        // 5 new, waiting at the Dean (Student Welfare)
        $this->submit($sidd, 'DSC', 'Harassment complaint against a senior', 'A senior student has repeatedly used abusive language in the common room. I request an enquiry.');

        // 6 escalated to the Director
        $g = $this->submit($komal, 'OTH', 'Request for a quiet study room in the library', 'There is no place for silent group study in the library. Please consider setting aside a room.');
        $this->act($g, 'escalate', 'This needs the Director\'s decision because it involves allotment of library space.');

        // 7 asked for information, the student replied: back with the Warden ("Replied")
        $g = $this->submit($sanjay, 'HST', 'Broken window in the hostel room', 'The window of my room does not close and rain water comes in.');
        $this->act($g, 'request_info', 'Please confirm the room number and attach a photograph if possible.');
        WorkflowService::submitterReply($g['reference_no'], $sanjay['id_code'], 'The room is 226 in Boys Hostel 2. The window latch is broken.');

        // 8 waiting at a VACANT post (the HOD of Chemical Engineering): shows the admin warning
        $this->submit($naveen, 'ACD', 'Delay in the project evaluation schedule', 'The schedule for the final-year project evaluation has not been announced yet.');

        // 9 faculty grievance, waiting at the HOD (also made to look 9 days old at this level)
        $overdue[] = $this->submit($anan, 'FGEN', 'Shortage of projectors in the department classrooms', 'Two classrooms used by the department have no working projector.');

        // Overdue demo: only the time spent at the current level is moved back (the history keeps its real times).
        // The default overdue limit is 7 days, so these two show as overdue.
        foreach ($overdue as $o) {
            Database::connect()->query("UPDATE grievances SET level_entered_at = now() - interval '9 days' WHERE id = ?", [$o['id']]);
        }

        // 10 non-teaching grievance, waiting at the Reporting Officer
        $this->submit($mayank, 'NGEN', 'Request for a change in office timing', 'I request that the office timing be changed during the examination fortnight.');

        // 11 hostel grievance disposed by the Warden
        $g = $this->submit($komal, 'HST', 'Mess menu not as per the notified schedule', 'The mess has not followed the notified menu for the past week.');
        $this->act($g, 'dispose', 'The mess contractor was informed and the notified menu is being followed from today.');

        // 12 escalated two levels: shows a longer history
        $g = $this->submit($mukesh, 'ACD', 'Delay in the grade card for the last semester', 'The grade card of the previous semester has not been issued although results were declared.');
        $this->act($g, 'escalate', 'Not in the department\'s control. Transferred to the Dean (Academics).');
        $this->act($g, 'escalate', 'Needs the committee\'s review of the pending cases. Transferred to the Chairman, Student Grievance Committee (Academic).');
    }
}
