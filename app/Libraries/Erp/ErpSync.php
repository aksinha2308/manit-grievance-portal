<?php

namespace App\Libraries\Erp;

use App\Libraries\AuditLogger;
use App\Libraries\DesignationRoleService;
use App\Libraries\GoogleAuth;
use Config\Database;
use RuntimeException;

/**
 * Admin-triggered sync of people from the ERP (the same code runs from the screen and from
 * php spark portal:erp-sync).
 *
 * scan()  reads the whole ERP and compares it with the identity rows already in the portal. It changes nothing.
 * apply() does what scan() reported: fixes names, takes over old master-list rows, switches on people who are back, and switches OFF
 *         active ERP people who are no longer in the ERP. Nobody is ever deleted (their grievances stay linked).
 *
 * Accounts are NEVER created here: a person's row (with the e-mail they log in with) is created at their first sign-in.
 * People in the ERP without an account yet are only counted ('not_yet').
 * Only identity (role, ID, name, e-mail) is written; department, semester, hostel, rank and mobile stay in the ERP.
 * People the portal cannot safely match (the same roll number or e-mail twice, an e-mail already used by somebody
 * else, an unlisted job title) are not touched and are listed as "conflicts" / "blocked titles".
 */
class ErpSync
{
    /** Refuse to switch off more than this share of the active ERP people in one go, unless the admin confirms. */
    public const MASS_OFF_SHARE = 0.25;

    /**
     * @return array<string,mixed> see the keys below; 'error' is set (and the rest empty) when the ERP cannot be read
     */
    public static function scan(): array
    {
        $out = [
            'error' => null, 'students' => 0, 'staff' => 0,
            'not_yet' => [], 'adopted' => [], 'changed' => [], 'reactivated' => [], 'unchanged' => 0,
            'missing' => [], 'conflicts' => [], 'blocked_titles' => [],
            'unmapped_departments' => [],
            'mass_off' => false, 'active_erp' => 0,
        ];

        $source = ErpDirectory::source();
        try {
            $students = $source->allStudents();
            $staff    = $source->allEmployees();
        } catch (ErpUnavailable $e) {
            $out['error'] = 'The ERP cannot be read right now (' . $e->getMessage() . '). Nothing was changed.';

            return $out;
        }
        $out['students'] = count($students);
        $out['staff']    = count($staff);

        // ----- what the ERP says, one entry per usable person
        $want       = [];   // key => [role, id_code, name, email]
        $deptCounts = [];   // lower code => [code, students, staff]
        $titles     = [];

        $dupe = [];
        foreach ($students as $r) {
            $roll = ErpDirectory::clean($r['roll'] ?? '');
            $name = ErpDirectory::clean($r['name'] ?? '');
            self::countDept($deptCounts, ErpDirectory::clean($r['dept'] ?? ''), 'students');
            if ($roll === '' || $name === '') {
                $out['conflicts'][] = ['what' => 'Student row without roll number or name', 'detail' => 'uid ' . ErpDirectory::clean($r['uid'] ?? '?')];

                continue;
            }
            $dupe['s:' . mb_strtolower($roll)][] = ['role' => 'student', 'id_code' => $roll, 'name' => $name, 'email' => null];   // a student's e-mail is saved at first sign-in only
        }
        foreach ($staff as $r) {
            $code  = ErpDirectory::clean($r['code'] ?? '');
            $name  = ErpDirectory::clean($r['name'] ?? '');
            $email = strtolower(trim((string) ($r['email'] ?? '')));
            self::countDept($deptCounts, ErpDirectory::clean($r['dept'] ?? ''), 'staff');
            if ($code === '' || $name === '') {
                $out['conflicts'][] = ['what' => 'Employee row without employee code or name', 'detail' => $email !== '' ? $email : '(no e-mail)'];

                continue;
            }
            if (GoogleAuth::domainKind($email) !== 'staff') {
                $out['conflicts'][] = ['what' => 'Employee without a usable institute e-mail (cannot sign in)', 'detail' => "{$code} {$name}" . ($email !== '' ? " ({$email})" : '')];

                continue;
            }
            $title = ErpDirectory::clean($r['designation'] ?? '');
            $entry = DesignationRoleService::find($title);
            if ($entry === null) {
                $label = $title === '' ? '(blank)' : $title;
                $titles[$label] = ($titles[$label] ?? 0) + 1;

                continue;
            }
            // LOCAL FIX: find() no longer returns a role. Use the same rule as ErpDirectory (designation 2, 3, 4 = faculty).
            $role = in_array($title, ['2', '3', '4'], true) ? 'faculty' : 'non_teaching';
            $dupe['e:' . $email][] = ['role' => $role, 'id_code' => $code, 'name' => $name, 'email' => $email];
        }
        foreach ($dupe as $key => $list) {
            if (count($list) > 1) {
                $what = $key[0] === 's' ? 'Roll number appears more than once in the ERP' : 'Institute e-mail appears more than once in the ERP';
                $out['conflicts'][] = ['what' => $what, 'detail' => substr($key, 2) . ' (' . count($list) . ' rows)'];

                continue;
            }
            $want[$key] = $list[0];
        }
        arsort($titles);
        $out['blocked_titles'] = $titles;

        // ----- what the portal has
        $db    = Database::connect();
        $have  = $db->query('SELECT id, role, id_code, name, email, active, source FROM master_people')->getResultArray();
        $byId  = [];
        $byMail = [];
        $staffById = [];
        foreach ($have as $h) {
            $byId[$h['role'] . '|' . mb_strtolower($h['id_code'])] = $h;
            if ($h['email'] !== null) {
                $byMail[strtolower($h['email'])] = $h;
            }
            if ($h['role'] !== 'student') {
                $staffById[mb_strtolower($h['id_code'])] = $h;
            }
        }

        $found = [];   // ids of portal rows matched to the ERP
        foreach ($want as $w) {
            $row = $byId[$w['role'] . '|' . mb_strtolower($w['id_code'])] ?? null;
            if ($row === null && $w['role'] !== 'student') {
                $row = $staffById[mb_strtolower($w['id_code'])] ?? null;
                if ($row !== null && ($row['source'] ?? '') !== 'erp') {
                    $row = null;   // a hand-made staff row of the other role is not ours to move
                }
            }
            $clash = $w['email'] === null ? null : ($byMail[$w['email']] ?? null);
            if ($clash !== null && ($row === null || (int) $clash['id'] !== (int) $row['id'])) {
                $out['conflicts'][] = ['what' => 'E-mail already belongs to a different person in the portal', 'detail' => "{$w['id_code']} {$w['name']} ({$w['email']})"];

                continue;
            }
            if ($row === null) {
                $out['not_yet'][] = $w;   // no portal account yet: it is created at the person's first sign-in

                continue;
            }
            $found[(int) $row['id']] = true;
            if (($row['source'] ?? '') !== 'erp') {
                $out['adopted'][] = $w + ['id' => (int) $row['id']];
            } elseif (! self::truthy($row['active'])) {
                $out['reactivated'][] = $w + ['id' => (int) $row['id']];
            } elseif ($row['name'] !== $w['name'] || ($w['email'] !== null && strtolower((string) $row['email']) !== $w['email']) || $row['role'] !== $w['role']) {
                $out['changed'][] = $w + ['id' => (int) $row['id'], 'old_name' => $row['name'], 'old_role' => $row['role']];
            } else {
                $out['unchanged']++;
            }
        }

        // ----- active ERP people who are no longer in the ERP
        foreach ($have as $h) {
            if (($h['source'] ?? '') === 'erp' && self::truthy($h['active'])) {
                $out['active_erp']++;
                if (! isset($found[(int) $h['id']])) {
                    $out['missing'][] = ['id' => (int) $h['id'], 'role' => $h['role'], 'id_code' => $h['id_code'], 'name' => $h['name']];
                }
            }
        }
        $out['mass_off'] = $out['active_erp'] > 0 && count($out['missing']) > $out['active_erp'] * self::MASS_OFF_SHARE;

        // ----- ERP department IDs missing from the authoritative department table
        $mapped = [];
        foreach ($db->query('SELECT dept::text AS c FROM establishment.dpcode')->getResultArray() as $d) {
            $mapped[ErpDirectory::key($d['c'])] = true;
        }
        foreach ($deptCounts as $k => $d) {
            arsort($d['variants']);
            $d['code']     = (string) array_key_first($d['variants']);   // the most used spelling
            $d['variants'] = count($d['variants']) > 1 ? array_keys($d['variants']) : [];
            if (! isset($mapped[$k])) {
                $out['unmapped_departments'][] = $d;
            }
        }
        usort($out['unmapped_departments'], static fn ($a, $b) => strcmp($a['code'], $b['code']));
        return $out;
    }

    /**
     * Do what scan() reports. Returns the counts. Throws RuntimeException (a message for the admin) when it must not.
     *
     * @return array<string,int>
     */
    public static function apply(bool $confirmMassOff = false, string $by = 'admin'): array
    {
        $scan = self::scan();
        if ($scan['error'] !== null) {
            throw new RuntimeException($scan['error']);
        }
        if ($scan['students'] + $scan['staff'] === 0) {
            throw new RuntimeException('The ERP returned no people at all, so nothing was changed (this protects you from switching everybody off by mistake).');
        }
        if ($scan['mass_off'] && ! $confirmMassOff) {
            throw new RuntimeException(count($scan['missing']) . ' of ' . $scan['active_erp'] . ' people would be switched off. Tick the confirmation box if that is really right.');
        }

        $db = Database::connect();
        $db->transStart();
        $n = ['adopted' => 0, 'changed' => 0, 'reactivated' => 0, 'switched_off' => 0, 'skipped' => 0];
        foreach (['adopted', 'changed', 'reactivated'] as $kind) {
            foreach ($scan[$kind] as $w) {
                if (ErpDirectory::upsertIdentity($w, $w['email']) === null) {
                    $n['skipped']++;

                    continue;
                }
                $n[$kind]++;
            }
        }
        foreach ($scan['missing'] as $m) {
            $db->query('UPDATE master_people SET active = false, updated_at = now() WHERE id = ? AND active', [$m['id']]);
            $n['switched_off']++;
        }
        $db->transComplete();
        if (! $db->transStatus()) {
            throw new RuntimeException('Something went wrong and nothing was changed. Please try again.');
        }

        ErpDirectory::forgetMemo();
        AuditLogger::log('erp_sync', 'erp', null, null, $n + ['by' => $by, 'conflicts' => count($scan['conflicts']), 'blocked_titles' => array_sum($scan['blocked_titles'])]);

        return $n;
    }

    /** @return array{error:?string,departments:list<array<string,mixed>>,titles:array<string,int>} */
    public static function unmapped(): array
    {
        $scan = self::scan();

        return [
            'error'       => $scan['error'],
            'departments' => $scan['unmapped_departments'],
            'titles'      => $scan['blocked_titles'],
        ];
    }

    /** @param array<string,array<string,mixed>> $counts */
    private static function countDept(array &$counts, string $code, string $who): void
    {
        if ($code === '') {
            return;
        }
        $k = ErpDirectory::key($code);
        $counts[$k] ??= ['code' => $code, 'students' => 0, 'staff' => 0, 'variants' => []];
        $counts[$k][$who]++;
        $counts[$k]['variants'][$code] = ($counts[$k]['variants'][$code] ?? 0) + 1;
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1';
    }
}
