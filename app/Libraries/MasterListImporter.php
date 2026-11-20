<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;

/**
 * Bulk load of the master list from an Excel/CSV file, one role at a time.
 *
 * Rules (as agreed):
 *  - existing people (matched on Scholar Number / Employee Code, ignoring case) are UPDATED;
 *  - new people are ADDED;
 *  - nobody is ever removed because they are missing from the file;
 *  - an optional column left blank leaves the stored value unchanged, except Hostel and Room, where blank clears them;
 *  - the whole file is validated first and nothing is saved unless every row is valid.
 */
class MasterListImporter
{
    public const SAMPLE_LIMIT = 40;

    /** @return list<string> */
    public static function headers(string $role): array
    {
        return $role === 'student'
            ? ['Scholar Number', 'Name', 'Department', 'Semester', 'Hostel', 'Room', 'Contact', 'Email']
            : ['Employee Code', 'Name', 'Department', 'Contact', 'Email'];
    }

    public static function template(string $role): string
    {
        MasterListService::assertRole($role);
        $db    = Database::connect();
        $deps  = array_column($db->query('SELECT depname FROM establishment.dpcode ORDER BY lower(depname)')->getResultArray(), 'depname');
        $sems  = array_column($db->query('SELECT name FROM semesters WHERE active ORDER BY sort_order')->getResultArray(), 'name');

        $lists = [$role === 'student' ? ['Departments', 'Semesters'] : ['Departments']];
        $max   = max(count($deps), $role === 'student' ? count($sems) : 0, 1);
        for ($i = 0; $i < $max; $i++) {
            $lists[] = $role === 'student' ? [$deps[$i] ?? '', $sems[$i] ?? ''] : [$deps[$i] ?? ''];
        }

        $idLabel = $role === 'student' ? 'Scholar Number' : 'Employee Code';
        $notes   = [
            ['How to fill the People sheet'],
            ["One row per person. {$idLabel}, Name and Department are required."],
            ["Format the {$idLabel} column as Text before typing, so Excel keeps leading zeros (select the column, Format Cells, Text)."],
            ['Department must be one of the names on the Lists sheet from establishment.dpcode (spelling is not case-sensitive).'],
        ];
        if ($role === 'student') {
            $notes[] = 'Semester: the semester name (or just its number). Required only if the submission form makes Semester a required field.';
            $notes[] = 'Hostel and Room: free-text ERP values. Leave Hostel blank for a day scholar; students without a hostel cannot raise hostel-only grievances.';
        }
        $notes[] = 'Contact: the mobile number the submission OTP is sent to. Optional here. A blank Contact never erases a number already stored.';
        $notes[] = 'Email: the institute Google account the person signs in with (@' . ($role === 'student' ? config('Google')->studentDomain : config('Google')->staffDomain) . '). Without it the person cannot sign in. A blank Email never erases one already stored.';
        $notes[] = "People already on the list are updated; new ones are added; people missing from the file are NOT removed.";
        $notes[] = 'Nothing is saved until you review the preview and confirm.';

        $example = $role === 'student'
            ? ['0901CS211001', 'Example Student', $deps[0] ?? '', $sems[0] ?? '', '', '', '', '0901cs211001@' . config('Google')->studentDomain]
            : ['E1001', 'Example Person', $deps[0] ?? '', '', 'example.person@' . config('Google')->staffDomain];

        $validations = [['sqref' => 'C2:C25000', 'formula' => 'Lists!$A$2:$A$' . max(2, count($deps) + 1)]];
        if ($role === 'student') {
            $validations[] = ['sqref' => 'D2:D25000', 'formula' => 'Lists!$B$2:$B$' . max(2, count($sems) + 1)];
        }

        return SimpleXlsx::write([
            ['name' => 'People', 'header' => true, 'rows' => [self::headers($role), $example], 'widths' => $role === 'student' ? [20, 28, 30, 18, 24, 16, 16, 34] : [20, 28, 30, 16, 34], 'validations' => $validations],
            ['name' => 'Lists', 'header' => true, 'rows' => $lists, 'widths' => [32, 20]],
            ['name' => 'Instructions', 'header' => false, 'rows' => array_map(static fn ($n) => is_array($n) ? $n : [$n], $notes), 'widths' => [140]],
        ]);
    }

    /**
     * Validate a file and store the preview. Nothing is written to the master list.
     *
     * @return array<string,mixed> summary for the preview screen
     */
    public static function parse(string $path, string $extension, string $role, int $adminId): array
    {
        MasterListService::assertRole($role);
        $rows = strtolower($extension) === 'csv' ? SimpleXlsx::readCsv($path) : SimpleXlsx::read($path, 'People');
        if ($rows === []) {
            throw new RuntimeException('The file is empty.');
        }

        $headers = self::headers($role);
        $first   = array_key_first($rows);
        $got     = array_map(static fn ($h) => strtolower(trim((string) $h)), array_slice($rows[$first], 0, count($headers)));
        // Files made from the older template (no Email column) are still accepted
        $withoutEmail = array_slice($headers, 0, -1);
        $legacyStudentHeaders = ['Scholar Number', 'Name', 'Department', 'Semester', 'Hostel', 'Contact'];
        $legacyStudent = $role === 'student' && array_slice($got, 0, count($legacyStudentHeaders)) === array_map('strtolower', $legacyStudentHeaders);
        if ($legacyStudent) {
            $got = array_map('strtolower', $legacyStudentHeaders);
        } elseif (array_slice($got, 0, count($withoutEmail)) === array_map('strtolower', $withoutEmail) && trim((string) ($got[count($withoutEmail)] ?? '')) === '') {
            $got = array_merge($got, ['email']);
        }
        if (! $legacyStudent && array_slice($got, 0, count($headers)) !== array_map('strtolower', $headers)) {
            throw new RuntimeException('The columns do not match the template. Download the template again and keep its first row: ' . implode(', ', $headers) . '.');
        }
        unset($rows[$first]);

        $db      = Database::connect();
        $student = $role === 'student';
        $needSem = $student && MasterListService::semesterRequired();

        $deps = [];
        foreach ($db->query('SELECT dept AS id, depname AS name FROM establishment.dpcode')->getResultArray() as $d) {
            $deps[mb_strtolower($d['name'])] = $d;
        }
        $sems = [];
        $semByNo = [];
        foreach ($db->query('SELECT id, name, sort_order, active FROM semesters')->getResultArray() as $s) {
            $sems[mb_strtolower($s['name'])] = $s;
            $semByNo[(int) $s['sort_order']] = $s;
        }
        $emailsTaken = [];   // lower(email) => id_code, over the whole master list
        foreach ($db->query('SELECT lower(email) AS email, role, id_code FROM master_people WHERE email IS NOT NULL')->getResultArray() as $r) {
            $emailsTaken[$r['email']] = ['role' => $r['role'], 'id_code' => $r['id_code']];
        }
        $emailsInFile = [];
        $existing = [];
        foreach ($db->query(
            'SELECT p.*, d.depname AS department, s.name AS semester FROM master_people p
               JOIN establishment.dpcode d ON d.dept = p.department_id
               LEFT JOIN semesters s ON s.id = p.semester_id WHERE p.role = ?',
            [$role]
        )->getResultArray() as $p) {
            $existing[mb_strtolower($p['id_code'])] = $p;
        }

        $errors  = [];
        $seen    = [];
        $records = [];
        $new = $changed = $same = $inactive = 0;
        $changes = [];
        $total   = 0;

        foreach ($rows as $rowNo => $cells) {
            $cells = array_map(static fn ($c) => trim((string) $c), $cells);
            if ($legacyStudent) {
                $cells = [$cells[0] ?? '', $cells[1] ?? '', $cells[2] ?? '', $cells[3] ?? '', $cells[4] ?? '', '', $cells[5] ?? '', ''];
            }
            $cells = array_pad(array_slice($cells, 0, count($headers)), count($headers), '');
            if (implode('', $cells) === '') {
                continue;
            }
            $total++;
            if ($student) {
                [$idCode, $name, $depName, $semName, $hostName, $roomName, $contact, $email] = $cells;
            } else {
                [$idCode, $name, $depName, $contact, $email] = $cells;
                $semName = $hostName = $roomName = '';
            }
            $rowErrors = [];
            $label     = $student ? 'Scholar Number' : 'Employee Code';

            if (! preg_match(MasterListService::ID_PATTERN, $idCode)) {
                $rowErrors[] = "{$label} is missing or invalid.";
            } elseif (isset($seen[mb_strtolower($idCode)])) {
                $rowErrors[] = "{$label} {$idCode} is repeated: first on row " . $seen[mb_strtolower($idCode)] . '.';
            }
            if ($name === '' || mb_strlen($name) > 150) {
                $rowErrors[] = 'Name is required (up to 150 characters).';
            }

            $dep = $deps[mb_strtolower($depName)] ?? null;
            if ($depName === '') {
                $rowErrors[] = 'Department is required.';
            } elseif ($dep === null) {
                $rowErrors[] = "Department \"{$depName}\" is not in the department list.";
            }

            $semId = null;
            if ($student && $semName !== '') {
                $sem = $sems[mb_strtolower($semName)] ?? (ctype_digit($semName) ? ($semByNo[(int) $semName] ?? null) : null);
                if ($sem === null || ! is_true($sem['active'])) {
                    $rowErrors[] = "Semester \"{$semName}\" is not in the semester list.";
                } else {
                    $semId = (int) $sem['id'];
                }
            } elseif ($needSem) {
                $rowErrors[] = 'Semester is required.';
            }

            if ($student && (mb_strlen($hostName) > 150 || mb_strlen($roomName) > 150)) {
                $rowErrors[] = 'Hostel and Room must each be 150 characters or fewer.';
            } elseif ($student && $roomName !== '' && $hostName === '') {
                $rowErrors[] = 'Enter a Hostel when providing a Room.';
            }

            if ($contact !== '' && ! MasterListService::contactValid($contact)) {
                $rowErrors[] = "Contact \"{$contact}\" does not match the required format.";
            }

            $email = strtolower($email);
            if ($email !== '') {
                $dom = $student ? config('Google')->studentDomain : config('Google')->staffDomain;
                $taken = $emailsTaken[$email] ?? null;
                if (mb_strlen($email) > 190 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || GoogleAuth::domainKind($email) !== ($student ? 'student' : 'staff')) {
                    $rowErrors[] = "Email \"{$email}\" must be an institute address ending in @{$dom}.";
                } elseif (isset($emailsInFile[$email])) {
                    $rowErrors[] = "Email {$email} is repeated: first on row {$emailsInFile[$email]}.";
                } elseif ($taken !== null && ($taken['role'] !== $role || mb_strtolower($taken['id_code']) !== mb_strtolower($idCode))) {
                    $rowErrors[] = "Email {$email} already belongs to {$taken['id_code']}.";
                } else {
                    $emailsInFile[$email] = $rowNo;
                }
            }

            foreach ($rowErrors as $m) {
                $errors[] = ['row' => (int) $rowNo, 'message' => $m];
            }
            if ($rowErrors !== []) {
                continue;
            }
            $seen[mb_strtolower($idCode)] = $rowNo;

            $rec = ['id_code' => $idCode, 'name' => $name, 'department_id' => (int) $dep['id'], 'semester_id' => $semId,
                'hostel' => $student && $hostName !== '' ? $hostName : null, 'room' => $student && $hostName !== '' && $roomName !== '' ? $roomName : null,
                'contact' => $contact === '' ? null : $contact, 'email' => $email === '' ? null : $email];
            $old = $existing[mb_strtolower($idCode)] ?? null;

            if ($old === null) {
                $new++;
            } else {
                $diff = [];
                if ($old['name'] !== $name) {
                    $diff[] = "name: {$old['name']} → {$name}";
                }
                if ((int) $old['department_id'] !== (int) $dep['id']) {
                    $diff[] = "department: {$old['department']} → {$dep['name']}";
                }
                if ($student) {
                    if ($semId !== null && (int) $old['semester_id'] !== $semId) {
                        $diff[] = 'semester: ' . ($old['semester'] ?? 'none') . ' → ' . ($semName);
                    }
                    if (($old['hostel'] ?? null) !== ($rec['hostel'] ?? null) || ($old['room'] ?? null) !== ($rec['room'] ?? null)) {
                        $oldHostel = $old['hostel'] ?? 'day scholar';
                        $newHostel = $rec['hostel'] ?? 'day scholar';
                        $diff[] = "hostel: {$oldHostel}" . (($old['room'] ?? '') !== '' ? ', room ' . $old['room'] : '') .
                            " → {$newHostel}" . (($rec['room'] ?? '') !== '' ? ', room ' . $rec['room'] : '');
                    }
                }
                if ($contact !== '' && $old['contact'] !== $contact) {
                    $diff[] = 'contact changed';
                }
                if ($email !== '' && strtolower((string) $old['email']) !== $email) {
                    $diff[] = 'e-mail: ' . ($old['email'] ?? 'none') . " → {$email}";
                }
                if ($diff === []) {
                    $same++;
                } else {
                    $changed++;
                    if (count($changes) < self::SAMPLE_LIMIT) {
                        $changes[] = ['row' => (int) $rowNo, 'id_code' => $idCode, 'diff' => implode('; ', $diff)];
                    }
                }
                if (! is_true($old['active'])) {
                    $inactive++;
                }
            }
            $records[] = $rec;
        }

        if ($total === 0) {
            throw new RuntimeException('The file has no data rows.');
        }

        $token = ImportStore::save(['role' => $role, 'records' => $errors === [] ? $records : [], 'ok' => $errors === []], $adminId);

        return [
            'token' => $token, 'role' => $role, 'rows' => $total, 'new' => $new, 'changed' => $changed, 'same' => $same,
            'inactive' => $inactive, 'errors' => $errors, 'changes' => $changes,
        ];
    }

    /**
     * Save a previewed file: inserts new people and updates existing ones in one transaction.
     *
     * @return array{role:string,rows:int}
     */
    public static function commit(string $token, int $adminId): array
    {
        $data = ImportStore::load($token, $adminId);
        if (! isset($data['role'], $data['records'])) {
            throw new RuntimeException('This upload is not a master list upload. Please upload the file again.');
        }
        if (empty($data['ok'])) {
            throw new RuntimeException('The file still has errors. Fix them and upload again.');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $role = $data['role'];
        $db   = Database::connect();
        $sql  = 'INSERT INTO master_people (role, id_code, name, department_id, semester_id, hostel, room, contact, email)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (role, lower(id_code)) DO UPDATE SET
                    name = EXCLUDED.name, department_id = EXCLUDED.department_id,
                    semester_id = CASE WHEN ? THEN COALESCE(EXCLUDED.semester_id, master_people.semester_id) ELSE NULL END,
                    hostel = EXCLUDED.hostel, room = EXCLUDED.room,
                    contact = COALESCE(EXCLUDED.contact, master_people.contact),
                    email = COALESCE(EXCLUDED.email, master_people.email), updated_at = now()';
        $student = $role === 'student';

        $db->transStart();
        foreach ($data['records'] as $r) {
            $db->query($sql, [
                $role, $r['id_code'], $r['name'], $r['department_id'],
                $student ? $r['semester_id'] : null, $student ? $r['hostel'] : null, $student ? $r['room'] : null, $r['contact'],
                $r['email'] ?? null, pg_flag($student),
            ]);
        }
        AuditLogger::log('bulk_import', 'master_list', $role, null, ['rows' => count($data['records'])]);
        $db->transComplete();

        if (! $db->transStatus()) {
            throw new RuntimeException('The upload could not be saved and nothing was changed.');
        }
        ImportStore::discard($token);

        return ['role' => $role, 'rows' => count($data['records'])];
    }
}
