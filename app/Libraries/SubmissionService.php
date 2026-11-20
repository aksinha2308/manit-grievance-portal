<?php

namespace App\Libraries;

use App\Libraries\Erp\ErpDirectory;

use Config\Database;
use DateTime;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Public submission of a grievance.
 *
 * Flow: the submitter is looked up on the master list (lookup), then fills the form (submit). Nothing is
 * trusted from the browser except the choices the submitter is entitled to make: grievance type, editable
 * contact number, custom field values, subject, description and attachments. Name, department, semester and
 * hostel always come from the master list and are copied into the grievance as a snapshot.
 *
 * submit() is all-or-nothing: the grievance, its first action, the notification events and the attachment
 * rows are saved in one transaction, and any stored files are removed again if it fails.
 */
class SubmissionService
{
    public const SUBJECT_MAX     = 250;
    public const DESCRIPTION_MAX = 5000;
    public const TEXT_MAX        = 500;

    /** extension => [stored mime type, magic-byte prefixes in hex] */
    private const FILE_TYPES = [
        'pdf'  => ['application/pdf', ['255044462d']],
        'jpg'  => ['image/jpeg', ['ffd8ff']],
        'jpeg' => ['image/jpeg', ['ffd8ff']],
        'png'  => ['image/png', ['89504e470d0a1a0a']],
        'doc'  => ['application/msword', ['d0cf11e0a1b11ae1']],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ['504b0304']],
        'xls'  => ['application/vnd.ms-excel', ['d0cf11e0a1b11ae1']],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ['504b0304']],
    ];

    /** URL segment => role */
    public const SLUGS = ['student' => 'student', 'faculty' => 'faculty', 'non-teaching' => 'non_teaching'];

    public static function roleFromSlug(string $slug): ?string
    {
        return self::SLUGS[$slug] ?? null;
    }

    public static function slug(string $role): string
    {
        return str_replace('_', '-', $role);
    }

    /**
     * Grievance types a role can currently submit: active, and with at least one level in the matrix.
     *
     * @return list<array<string,mixed>>
     */
    public static function types(string $role): array
    {
        $rows = Database::connect()->query(
            'SELECT t.id, t.name, t.code, t.requires_hostel
               FROM grievance_types t
              WHERE t.role = ? AND t.active AND EXISTS (SELECT 1 FROM escalation_levels l WHERE l.type_id = t.id)
              ORDER BY t.sort_order, t.id',
            [$role]
        )->getResultArray();

        foreach ($rows as &$r) {
            $r['requires_hostel'] = is_true($r['requires_hostel']);
        }

        return $rows;
    }

    /**
     * Find an active person on the master list by Scholar Number / Employee Code (case-insensitive).
     *
     * @return array<string,mixed>|null
     */
    public static function lookup(string $role, string $idCode): ?array
    {
        $idCode = trim($idCode);
        if ($idCode === '' || mb_strlen($idCode) > 50 || ! in_array($role, PostService::ROLES, true)) {
            return null;
        }
        if (ErpDirectory::enabled()) {
            $id = Database::connect()->query(
                "SELECT id FROM master_people WHERE role = ? AND lower(id_code) = lower(?) AND active AND source = 'erp'",
                [$role, $idCode]
            )->getRow('id');

            return $id === null ? null : ErpDirectory::currentPerson($role, (int) $id);
        }

        return self::person($role, $idCode, null);
    }

    /** @return array<string,mixed>|null */
    public static function personById(string $role, int $id): ?array
    {
        if (ErpDirectory::enabled()) {
            return ErpDirectory::currentPerson($role, $id);
        }

        return self::person($role, null, $id);
    }

    /**
     * The fields shown on the form for a role, in the admin's order (hidden ones left out).
     *
     * @return list<array<string,mixed>>
     */
    public static function formFields(string $role): array
    {
        return array_values(array_filter(FormFieldService::fields($role), static fn ($f) => is_true($f['visible'])));
    }

    /**
     * Pending (not disposed) grievances this person has of one type.
     *
     * @return list<array{reference_no:string,created_at:string}>
     */
    public static function pendingOfType(string $role, string $idCode, int $typeId): array
    {
        return Database::connect()->query(
            "SELECT reference_no, created_at FROM grievances
              WHERE role = ? AND lower(id_code) = lower(?) AND type_id = ? AND status <> 'disposed'
              ORDER BY id DESC",
            [$role, $idCode, $typeId]
        )->getResultArray();
    }

    /**
     * Validate and save a grievance.
     *
     * @param array<string,mixed>        $person person row from lookup()/personById()
     * @param array<string,mixed>        $in     type_id, contact, subject, description, cf[key], confirm_duplicate
     * @param list<array<string,mixed>>  $files  entries with name, tmp_name, size, error
     *
     * @return array{id:int,reference_no:string,type:string,created_at:string,level:string}
     *
     * @throws SubmissionException
     */
    public static function submit(array $person, array $in, array $files = []): array
    {
        $db   = Database::connect();
        $role = (string) $person['role'];

        // Re-read the person: they may have been deactivated since the lookup
        $person = self::personById($role, (int) $person['id']);
        if ($person === null) {
            throw new SubmissionException(['You are no longer eligible to submit a grievance. Please contact the administration.']);
        }

        $errors = [];
        foreach (self::erpBlockers($person) as $msg) {
            $errors[] = $msg;
        }
        $types  = self::types($role);
        $typeId = (int) ($in['type_id'] ?? 0);
        if ($typeId === 0 && count($types) === 1) {
            $typeId = (int) $types[0]['id'];
        }
        $type = null;
        foreach ($types as $t) {
            if ((int) $t['id'] === $typeId) {
                $type = $t;
            }
        }
        if ($type === null) {
            $errors[] = 'Choose the type of grievance.';
        } elseif ($type['requires_hostel'] && trim((string) ($person['hostel'] ?? '')) === '') {
            $errors[] = self::hostelBlocker($person, (string) $type['name']);
        }

        $contact = trim((string) ($in['contact'] ?? ''));
        if ($contact === '' || ! MasterListService::contactValid($contact)) {
            $errors[] = 'Enter a valid contact number.';
        }

        $subject = trim((string) ($in['subject'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > self::SUBJECT_MAX) {
            $errors[] = 'Subject is required (up to ' . self::SUBJECT_MAX . ' characters).';
        }
        $description = trim(str_replace("\r\n", "\n", (string) ($in['description'] ?? '')));
        if ($description === '' || mb_strlen($description) > self::DESCRIPTION_MAX) {
            $errors[] = 'Description is required (up to ' . self::DESCRIPTION_MAX . ' characters).';
        }

        [$custom, $customErrors] = self::customValues($role, (array) ($in['cf'] ?? []));
        $errors = array_merge($errors, $customErrors);

        [$uploads, $fileErrors] = self::checkFiles($files);
        $errors = array_merge($errors, $fileErrors);

        if ($errors !== []) {
            throw new SubmissionException($errors);
        }

        // Duplicate pending grievance: warn, but allow when the submitter confirms
        $dups = self::pendingOfType($role, (string) $person['id_code'], (int) $type['id']);
        if ($dups !== [] && empty($in['confirm_duplicate'])) {
            throw new SubmissionException(
                ['You already have ' . count($dups) . " pending \"{$type['name']}\" grievance(s). Tick the box below if you still want to submit another one."],
                $dups
            );
        }

        $level = $db->query(
            'SELECT l.id, l.level_no, l.post_id, d.name AS post_name, d.scope_type
               FROM escalation_levels l JOIN posts d ON d.id = l.post_id
              WHERE l.type_id = ? ORDER BY l.level_no LIMIT 1',
            [(int) $type['id']]
        )->getRowArray();
        if ($level === null) {
            throw new SubmissionException(['This grievance type is not ready to receive grievances yet.']);
        }
        $scopeValue = $level['scope_type'] === 'department' ? $person['department_id'] : ($level['scope_type'] === 'hostel' ? $person['hostel'] : null);
        $levelLabel = PostService::label($level['post_name'], $level['scope_type'], $scopeValue);

        $snapshot = self::snapshot($role, $person, $contact);
        $stored   = [];

        $db->transBegin();
        try {
            $ref = self::nextReference((string) $type['code']);

            $grievanceId = (int) $db->query(
                'INSERT INTO grievances (reference_no, type_id, role, id_code, submitter, department_id, subject, description,
                                         custom, current_level_id, status, level_entered_at, hostel, room)
                 VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?::jsonb, ?, \'pending\', now(), ?, ?)
                 RETURNING id',
                [
                    $ref, (int) $type['id'], $role, $person['id_code'], json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                    (int) $person['department_id'],
                    $subject, $description, json_encode($custom === [] ? new \stdClass() : $custom, JSON_UNESCAPED_UNICODE), (int) $level['id'],
                    $person['hostel'] ?? null, $person['room'] ?? null
                ]
            )->getRow('id');

            $db->query(
                'INSERT INTO grievance_actions (grievance_id, level_id, level_no, level_label, post_id, actor_user_id, actor_name, action, remark)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, \'submit\', NULL)',
                [$grievanceId, (int) $level['id'], (int) $level['level_no'], $levelLabel, (int) $level['post_id'], $person['name']]
            );

            foreach ($uploads as $u) {
                $storedName = self::store($u);
                $stored[]   = $storedName;
                $db->query(
                    'INSERT INTO attachments (grievance_id, original_name, stored_name, mime_type, size_bytes) VALUES (?, ?, ?, ?, ?)',
                    [$grievanceId, $u['original'], $storedName, $u['mime'], $u['size']]
                );
            }

            $db->query(
                "INSERT INTO notification_events (grievance_id, event_type, level_id, payload) VALUES (?, 'submitted', ?, ?::jsonb), (?, 'arrived_at_level', ?, ?::jsonb)",
                [
                    $grievanceId, (int) $level['id'], json_encode(['reference_no' => $ref]),
                    $grievanceId, (int) $level['id'], json_encode(['reference_no' => $ref, 'level' => $levelLabel]),
                ]
            );

            if (! $db->transCommit()) {
                throw new RuntimeException('Could not save the grievance.');
            }
        } catch (Throwable $e) {
            $db->transRollback();
            foreach ($stored as $name) {
                @unlink(self::path($name));
            }
            log_message('error', 'Grievance submission failed: ' . $e->getMessage());

            throw new SubmissionException(['Your grievance could not be saved. Nothing was submitted; please try again.']);
        }

        $createdAt = (string) $db->query('SELECT created_at FROM grievances WHERE id = ?', [$grievanceId])->getRow('created_at');

        return ['id' => $grievanceId, 'reference_no' => $ref, 'type' => (string) $type['name'], 'created_at' => $createdAt, 'level' => $levelLabel];
    }

    /** Absolute path of a stored attachment (never inside the web root). */
    public static function path(string $storedName): string
    {
        return WRITEPATH . 'attachments/' . substr($storedName, 0, 2) . '/' . $storedName;
    }

    // ---------------------------------------------------------------------

    /**
     * Reasons a person from the ERP cannot submit at all right now (empty for everyone else).
     *
     * @param array<string,mixed> $person
     *
     * @return list<string>
     */
    public static function erpBlockers(array $person): array
    {
        $erp = $person['erp'] ?? null;
        if ($erp === null) {
            return [];
        }
        if ($erp['down']) {
            return ['The institute records system cannot be reached right now, so a new grievance cannot be submitted. You can still read your earlier grievances. Please try again in a few minutes.'];
        }
        if ($person['department_id'] === null) {
            $dept = $erp['erp_department'] ?? '';

            return ['Your department' . ($dept !== '' ? " (\"{$dept}\")" : '') . ' has not been set up in the portal yet, so a grievance cannot be routed. Please contact the administration.'];
        }

        return [];
    }

    /** Why a hostel grievance cannot be submitted when the person has no usable hostel. */
    private static function hostelBlocker(array $person, string $typeName): string
    {
        return match ($person['erp']['hostel_status'] ?? 'none') {
            'unknown'  => "The hostel records cannot be reached right now, so \"{$typeName}\" grievances cannot be submitted at the moment. Please try again later, or choose another type.",
            'conflict' => "The hostel records show more than one current room for you, so \"{$typeName}\" grievances cannot be routed. Please contact the administration.",
            default    => "\"{$typeName}\" grievances are for hostel residents. No hostel is recorded for you; contact the administration if this is wrong.",
        };
    }

    /** @return array<string,mixed>|null */
    private static function person(string $role, ?string $idCode, ?int $id): ?array
    {
        $sql = 'SELECT p.*, d.depname AS department, s.name AS semester
                  FROM master_people p
                  JOIN establishment.dpcode d ON d.dept = p.department_id
                  LEFT JOIN semesters s ON s.id = p.semester_id
                 WHERE p.role = ? AND p.active AND ' . ($id !== null ? 'p.id = ?' : 'lower(p.id_code) = lower(?)');

        return Database::connect()->query($sql, [$role, $id ?? $idCode])->getRowArray();
    }

    /**
     * Validate admin-defined fields. Stored as key => {label, value} so the grievance keeps the label it was
     * answered under even if the admin renames the field later.
     *
     * @param array<string,mixed> $input
     *
     * @return array{0:array<string,array{label:string,value:string}>,1:list<string>}
     */
    private static function customValues(string $role, array $input): array
    {
        $out    = [];
        $errors = [];

        foreach (self::formFields($role) as $f) {
            if (! FormFieldService::isCustom($f)) {
                continue;
            }
            $label = (string) $f['label'];
            $raw   = $input[$f['field_key']] ?? '';
            $v     = is_string($raw) ? trim($raw) : '';

            if ($v === '') {
                if (is_true($f['mandatory'])) {
                    $errors[] = "{$label} is required.";
                }
                continue;
            }

            $ok = match ($f['field_type']) {
                'number'   => preg_match('/^-?\d{1,15}(\.\d{1,6})?$/', $v) === 1,
                'dropdown' => in_array($v, $f['options'], true),
                'date'     => (($d = DateTime::createFromFormat('Y-m-d', $v)) !== false) && $d->format('Y-m-d') === $v,
                default    => mb_strlen($v) <= self::TEXT_MAX,
            };
            if (! $ok) {
                $errors[] = "{$label} is not valid.";
                continue;
            }
            $out[$f['field_key']] = ['label' => $label, 'value' => $v];
        }

        return [$out, $errors];
    }

    /**
     * The submitter's details as they were at submission time.
     *
     * @param array<string,mixed> $person
     *
     * @return array<string,mixed>
     */
    private static function snapshot(string $role, array $person, string $contact): array
    {
        $labels = [];
        foreach (FormFieldService::fields($role) as $f) {
            $labels[$f['field_key']] = $f['label'];
        }
        $idKey = $role === 'student' ? 'scholar_number' : 'employee_code';
        $semesterVisible = isset($labels['semester']) && ! empty(array_filter(self::formFields($role), static fn ($f) => $f['field_key'] === 'semester'));

        return [
            'id_label'   => $labels[$idKey] ?? 'ID',
            'id_code'    => $person['id_code'],
            'name'       => $person['name'],
            'contact'    => $contact,
            'department' => $person['department'],
            'semester'   => $semesterVisible ? $person['semester'] : null,
            'hostel'     => $person['hostel'],
            // Details read from the ERP (null when people come from the portal's own master list)
            'hostel_room'  => $person['room'] ?? null,
            'program'      => $person['erp']['program'] ?? null,
            'faculty_rank' => $person['erp']['faculty_rank'] ?? null,
            'job_title'    => $person['erp']['job_title'] ?? null,
            'labels'     => $labels,
        ];
    }

    /**
     * Next reference number: PREFIX-YEAR-CODE-000001. The counter row is locked by the upsert, so two
     * simultaneous submissions can never receive the same number.
     */
    private static function nextReference(string $typeCode): string
    {
        $year = (int) (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y');
        $seq  = (int) Database::connect()->query(
            'INSERT INTO reference_counters (year, type_code, last_seq) VALUES (?, ?, 1)
             ON CONFLICT (year, type_code) DO UPDATE SET last_seq = reference_counters.last_seq + 1
             RETURNING last_seq',
            [$year, $typeCode]
        )->getRow('last_seq');

        $digits = max(4, Settings::int('ref_seq_digits', 6));

        return sprintf('%s-%d-%s-%0' . $digits . 'd', Settings::get('ref_prefix', 'MANIT'), $year, $typeCode, $seq);
    }

    /**
     * Check uploads against the settings. Returns accepted files and the problems found.
     *
     * @param list<array<string,mixed>> $files
     *
     * @return array{0:list<array<string,mixed>>,1:list<string>}
     */
    public static function checkFiles(array $files): array
    {
        $accepted = [];
        $errors   = [];
        $max      = Settings::int('attach_max_count', 3);
        $maxBytes = Settings::int('attach_max_mb', 5) * 1024 * 1024;
        $allowed  = array_filter(explode(',', (string) Settings::get('attach_allowed_ext', 'pdf,jpg,jpeg,png')));

        $given = array_values(array_filter($files, static fn ($f) => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if ($given !== [] && $max === 0) {
            return [[], ['Attachments are not accepted at the moment.']];
        }
        if (count($given) > $max) {
            $errors[] = "You can attach at most {$max} file(s).";
        }

        foreach ($given as $f) {
            $name = self::cleanName((string) ($f['name'] ?? ''));
            $err  = (int) $f['error'];

            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = "\"{$name}\" is too large (limit " . Settings::int('attach_max_mb', 5) . ' MB per file).';
                continue;
            }
            if ($err !== UPLOAD_ERR_OK) {
                $errors[] = "\"{$name}\" could not be uploaded. Please attach it again.";
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (! in_array($ext, $allowed, true) || ! isset(self::FILE_TYPES[$ext])) {
                $errors[] = "\"{$name}\": this file type is not allowed. Allowed: " . strtoupper(implode(', ', $allowed)) . '.';
                continue;
            }
            $size = (int) ($f['size'] ?? 0);
            if ($size <= 0) {
                $errors[] = "\"{$name}\" is empty.";
                continue;
            }
            if ($size > $maxBytes) {
                $errors[] = "\"{$name}\" is too large (limit " . Settings::int('attach_max_mb', 5) . ' MB per file).';
                continue;
            }

            // The content must really be what the extension says
            $head = bin2hex((string) file_get_contents((string) $f['tmp_name'], false, null, 0, 8));
            $ok   = false;
            foreach (self::FILE_TYPES[$ext][1] as $magic) {
                $ok = $ok || str_starts_with($head, $magic);
            }
            if (! $ok) {
                $errors[] = "\"{$name}\" does not look like a real {$ext} file.";
                continue;
            }

            $accepted[] = ['tmp' => (string) $f['tmp_name'], 'original' => $name, 'ext' => $ext, 'mime' => self::FILE_TYPES[$ext][0], 'size' => $size];
        }

        return [$accepted, $errors];
    }

    /** Keep a readable file name but remove anything that could be a path or markup. */
    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', $name) ?? '';
        $name = trim(preg_replace('/\.{2,}/', '.', $name) ?? '', " .");
        if (mb_strlen($name) > 150) {
            $ext  = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 140) . ($ext !== '' ? ".{$ext}" : '');
        }

        return $name === '' ? 'file' : $name;
    }

    /** Move an accepted upload into private storage under a random name; returns the stored name. */
    public static function store(array $u): string
    {
        $name = bin2hex(random_bytes(16)) . '.' . $u['ext'];
        $path = self::path($name);
        $dir  = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create the attachment folder.');
        }
        $moved = is_uploaded_file($u['tmp']) ? move_uploaded_file($u['tmp'], $path) : copy($u['tmp'], $path);
        if (! $moved) {
            throw new RuntimeException('Could not store an attachment.');
        }
        @chmod($path, 0640);

        return $name;
    }
}
