<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;

/**
 * The master list: every student (Scholar Number), faculty member and non-teaching employee (Employee Code)
 * who may submit a grievance. Submitters are identified only by this list; their name, department, semester,
 * hostel and room are filled in from it.
 *
 * People are never removed by an upload. An individual can be deactivated (they can no longer submit) and
 * can be deleted only if they have never submitted a grievance.
 */
class MasterListService
{
    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._\/-]{0,49}$/';
    public const PER_PAGE   = 50;

    /** @return array{rows:list<array<string,mixed>>,total:int,pages:int,page:int} */
    public static function search(string $role, string $q = '', int $departmentId = 0, string $status = '', int $page = 1): array
    {
        self::assertRole($role);
        $db    = Database::connect();
        $where = ['p.role = ?'];
        $args  = [$role];

        if ($q !== '') {
            $like    = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = '(p.id_code ILIKE ? OR p.name ILIKE ? OR p.email ILIKE ?)';
            array_push($args, $like, $like, $like);
        }
        if ($departmentId > 0) {
            $where[] = 'p.department_id = ?';
            $args[]  = $departmentId;
        }
        if ($status === 'active') {
            $where[] = 'p.active';
        } elseif ($status === 'inactive') {
            $where[] = 'NOT p.active';
        }

        $w     = implode(' AND ', $where);
        $total = (int) $db->query("SELECT count(*) AS n FROM master_people p WHERE {$w}", $args)->getRow('n');
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = max(1, min($page, $pages));

        $rows = $db->query(
            "SELECT p.*, d.depname AS department, s.name AS semester
               FROM master_people p
               JOIN establishment.dpcode d ON d.dept = p.department_id
               LEFT JOIN semesters s ON s.id = p.semester_id
              WHERE {$w} ORDER BY lower(p.id_code) LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $args
        )->getResultArray();

        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** @return array{total:int,active:int} per role */
    public static function counts(): array
    {
        $out = [];
        foreach (PostService::ROLES as $role) {
            $r = Database::connect()->query('SELECT count(*) AS total, count(*) FILTER (WHERE active) AS active FROM master_people WHERE role = ?', [$role])->getRowArray();
            $out[$role] = ['total' => (int) $r['total'], 'active' => (int) $r['active']];
        }

        return $out;
    }

    public static function contactValid(string $contact): bool
    {
        $pattern = Settings::get('contact_pattern', '^[0-9]{10}$');

        return @preg_match('~' . str_replace('~', '\~', (string) $pattern) . '~u', $contact) === 1;
    }

    /**
     * @return list<string> errors
     */
    public static function validate(string $role, string $idCode, string $name, int $departmentId, ?int $semesterId, ?string $hostel, string $contact, ?int $ignoreId = null, string $email = '', ?string $room = null): array
    {
        self::assertRole($role);
        $db     = Database::connect();
        $errors = [];
        $idCode = trim($idCode);
        $label  = $role === 'student' ? 'Scholar Number' : 'Employee Code';

        if (! preg_match(self::ID_PATTERN, $idCode)) {
            $errors[] = "{$label} is required (letters, digits, space, dot, dash, slash, up to 50 characters).";
        } elseif ($db->query('SELECT 1 FROM master_people WHERE role = ? AND lower(id_code) = lower(?) AND id <> ?', [$role, $idCode, $ignoreId ?? 0])->getRowArray()) {
            $errors[] = "{$label} {$idCode} is already on the list.";
        }

        if (trim($name) === '' || mb_strlen(trim($name)) > 150) {
            $errors[] = 'Name is required (up to 150 characters).';
        }

        $dep = $db->query('SELECT 1 FROM establishment.dpcode WHERE dept = ?', [$departmentId])->getRowArray();
        if ($dep === null) {
            $errors[] = 'Choose a valid department from the establishment department list.';
        }

        if ($role === 'student') {
            if ($semesterId === null) {
                if (self::semesterRequired()) {
                    $errors[] = 'Semester is required.';
                }
            } elseif ($db->query('SELECT 1 FROM semesters WHERE id = ? AND active', [$semesterId])->getRowArray() === null) {
                $errors[] = 'Choose a valid semester.';
            }
            if (mb_strlen((string) $hostel) > 150 || mb_strlen((string) $room) > 150) {
                $errors[] = 'Hostel and room must each be 150 characters or fewer.';
            }
            if (trim((string) $room) !== '' && trim((string) $hostel) === '') {
                $errors[] = 'Enter a hostel when providing a room.';
            }
        }

        if ($contact !== '' && ! self::contactValid($contact)) {
            $errors[] = 'Contact number does not match the required format (' . (Settings::get('contact_pattern') ?? '') . ').';
        }

        $emailError = self::emailError($role, $email, $ignoreId);
        if ($emailError !== null) {
            $errors[] = $emailError;
        }

        return $errors;
    }

    /**
     * Problem with an institute e-mail for a person of this role, or null when it is fine (blank is fine).
     * Students need @<studentDomain>, faculty and non-teaching staff @<staffDomain>; e-mails are unique.
     */
    public static function emailError(string $role, string $email, ?int $ignoreId = null): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        $g    = config('Google');
        $need = $role === 'student' ? 'student' : 'staff';
        if (mb_strlen($email) > 190 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || GoogleAuth::domainKind($email) !== $need) {
            return "E-mail \"{$email}\" must be an institute address ending in @" . ($need === 'student' ? $g->studentDomain : $g->staffDomain) . '.';
        }
        $other = Database::connect()->query('SELECT id_code FROM master_people WHERE lower(email) = ? AND id <> ?', [$email, $ignoreId ?? 0])->getRow('id_code');
        if ($other !== null) {
            return "E-mail {$email} is already used by {$other}.";
        }

        return null;
    }

    /** True when the student form shows Semester as a required field. */
    public static function semesterRequired(): bool
    {
        $f = Database::connect()->query("SELECT mandatory, visible FROM form_fields WHERE role = 'student' AND field_key = 'semester'")->getRowArray();

        return $f !== null && is_true($f['visible']) && is_true($f['mandatory']);
    }

    public static function create(string $role, string $idCode, string $name, int $departmentId, ?int $semesterId, ?string $hostel, string $contact, string $email = '', ?string $room = null): int
    {
        $errors = self::validate($role, $idCode, $name, $departmentId, $semesterId, $hostel, $contact, null, $email, $room);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $db   = Database::connect();
        $data = [
            'role' => $role, 'id_code' => trim($idCode), 'name' => trim($name), 'department_id' => $departmentId,
            'semester_id' => $role === 'student' ? $semesterId : null,
            'hostel' => $role === 'student' && trim((string) $hostel) !== '' ? trim((string) $hostel) : null,
            'room' => $role === 'student' && trim((string) $hostel) !== '' && trim((string) $room) !== '' ? trim((string) $room) : null,
            'contact' => $contact === '' ? null : $contact,
            'email'   => trim($email) === '' ? null : strtolower(trim($email)),
        ];
        $db->table('master_people')->insert($data);
        $id = (int) $db->insertID();
        AuditLogger::log('create', 'master_person', $id, null, $data);

        return $id;
    }

    public static function update(int $id, string $name, int $departmentId, ?int $semesterId, ?string $hostel, string $contact, ?string $email = null, ?string $room = null): void
    {
        $p      = self::find($id);
        $email  = $email === null ? (string) $p['email'] : strtolower(trim($email));
        $errors = self::validate($p['role'], $p['id_code'], $name, $departmentId, $semesterId, $hostel, $contact, $id, $email, $room);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $student = $p['role'] === 'student';
        Database::connect()->query(
            'UPDATE master_people SET name = ?, department_id = ?, semester_id = ?, hostel = ?, room = ?, contact = ?, email = ?, updated_at = now() WHERE id = ?',
            [trim($name), $departmentId, $student ? $semesterId : null,
                $student && trim((string) $hostel) !== '' ? trim((string) $hostel) : null,
                $student && trim((string) $hostel) !== '' && trim((string) $room) !== '' ? trim((string) $room) : null,
                $contact === '' ? null : $contact, $email === '' ? null : $email, $id]
        );
        AuditLogger::log('update', 'master_person', $id,
            ['name' => $p['name'], 'department_id' => (int) $p['department_id'], 'semester_id' => $p['semester_id'], 'hostel' => $p['hostel'], 'room' => $p['room'], 'contact' => $p['contact'], 'email' => $p['email']],
            ['name' => trim($name), 'department_id' => $departmentId, 'semester_id' => $semesterId,
                'hostel' => $student && trim((string) $hostel) !== '' ? trim((string) $hostel) : null,
                'room' => $student && trim((string) $hostel) !== '' && trim((string) $room) !== '' ? trim((string) $room) : null,
                'contact' => $contact, 'email' => $email]);
    }

    public static function setActive(int $id, bool $active): void
    {
        $p = self::find($id);
        Database::connect()->query('UPDATE master_people SET active = ?, updated_at = now() WHERE id = ?', [pg_flag($active), $id]);
        AuditLogger::log($active ? 'activate' : 'deactivate', 'master_person', $id, ['id_code' => $p['id_code'], 'role' => $p['role']], ['active' => $active]);
    }

    public static function isDeletable(array $person): bool
    {
        return self::grievanceCount($person) === 0;
    }

    public static function grievanceCount(array $person): int
    {
        return (int) Database::connect()->query('SELECT count(*) AS n FROM grievances WHERE role = ? AND lower(id_code) = lower(?)', [$person['role'], $person['id_code']])->getRow('n');
    }

    public static function delete(int $id): void
    {
        $p = self::find($id);
        if (! self::isDeletable($p)) {
            throw new RuntimeException("{$p['id_code']} has submitted grievances, so the record can only be deactivated.");
        }
        Database::connect()->query('DELETE FROM master_people WHERE id = ?', [$id]);
        AuditLogger::log('delete', 'master_person', $id, ['id_code' => $p['id_code'], 'name' => $p['name'], 'role' => $p['role']], null);
    }

    /** @return array<string,mixed> */
    public static function find(int $id): array
    {
        $row = Database::connect()->query('SELECT * FROM master_people WHERE id = ?', [$id])->getRowArray();
        if ($row === null) {
            throw new RuntimeException('That person is not on the list.');
        }

        return $row;
    }

    public static function assertRole(string $role): void
    {
        if (! in_array($role, PostService::ROLES, true)) {
            throw new RuntimeException('Unknown list.');
        }
    }
}
