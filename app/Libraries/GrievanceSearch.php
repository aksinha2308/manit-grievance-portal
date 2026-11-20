<?php

namespace App\Libraries;

use Config\Database;
use DateTime;

/**
 * Searching all grievances (admin list, admin exports, Director exports).
 *
 * Filters (all optional, as they arrive from the query string):
 *   q          reference number, Scholar Number / Employee Code, or submitter name (part of it)
 *   type       grievance type id            role        student | faculty | non_teaching
 *   status     pending | awaiting_submitter | disposed | open (= not disposed)
 * department department id hostel hostel name/code
 *   post       post id of the current level
 *   overdue    '1' = overdue only
 *   from, to   submitted between these dates (YYYY-MM-DD, India time, both inclusive)
 *   archived   'no' (default) | 'yes' | 'all'
 *
 * SQL aliases: g = grievances, l = escalation_levels (current level, LEFT JOIN).
 */
class GrievanceSearch
{
    public const STATUSES = ['open' => 'Open (not disposed)', 'pending' => 'Pending', 'awaiting_submitter' => 'Awaiting submitter', 'disposed' => 'Disposed'];

    /**
     * Clean the raw filter values; unknown or invalid values are dropped.
     *
     * @param array<string,mixed> $in
     *
     * @return array<string,string>
     */
    public static function clean(array $in): array
    {
        $f = [];
        $q = trim((string) ($in['q'] ?? ''));
        if ($q !== '') {
            $f['q'] = mb_substr($q, 0, 100);
        }
        foreach (['type', 'department', 'post'] as $k) {
            if (ctype_digit((string) ($in[$k] ?? '')) && (int) $in[$k] > 0) {
                $f[$k] = (string) (int) $in[$k];
            }
        }
        $hostel = trim((string) ($in['hostel'] ?? ''));
        if ($hostel !== '') {
            $f['hostel'] = mb_substr($hostel, 0, 150);
        }
        if (in_array($in['role'] ?? '', PostService::ROLES, true)) {
            $f['role'] = (string) $in['role'];
        }
        if (array_key_exists((string) ($in['status'] ?? ''), self::STATUSES)) {
            $f['status'] = (string) $in['status'];
        }
        if (($in['overdue'] ?? '') === '1') {
            $f['overdue'] = '1';
        }
        foreach (['from', 'to'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            $d = DateTime::createFromFormat('!Y-m-d', $v);
            if ($d !== false && $d->format('Y-m-d') === $v) {
                $f[$k] = $v;
            }
        }
        $f['archived'] = in_array($in['archived'] ?? '', ['yes', 'all'], true) ? (string) $in['archived'] : 'no';

        return $f;
    }

    /**
     * WHERE clause + parameters for cleaned filters.
     *
     * @param array<string,string> $f
     *
     * @return array{0:string,1:list<mixed>}
     */
    public static function where(array $f): array
    {
        $w = ['TRUE'];
        $p = [];

        if (isset($f['q'])) {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $w[]  = "(g.reference_no ILIKE ? OR g.id_code ILIKE ? OR g.submitter->>'name' ILIKE ?)";
            array_push($p, $like, $like, $like);
        }
        $map = ['type' => 'g.type_id', 'department' => 'g.department_id', 'post' => 'l.post_id', 'role' => 'g.role'];
        foreach ($map as $k => $col) {
            if (isset($f[$k])) {
                $w[] = "{$col} = ?";
                $p[] = $k === 'role' ? $f[$k] : (int) $f[$k];
            }
        }
        if (isset($f['hostel'])) {
            $w[] = 'lower(btrim(g.hostel)) = lower(btrim(?))';
            $p[] = $f['hostel'];
        }
        if (isset($f['status'])) {
            if ($f['status'] === 'open') {
                $w[] = "g.status <> 'disposed'";
            } else {
                $w[] = 'g.status = ?';
                $p[] = $f['status'];
            }
        }
        if (isset($f['overdue'])) {
            $w[] = WorkflowService::overdueSql('g', 'l');
        }
        if (isset($f['from'])) {
            $w[] = 'g.created_at >= ?';
            $p[] = self::dayStartUtc($f['from']);
        }
        if (isset($f['to'])) {
            $w[] = 'g.created_at < ?';
            $p[] = self::dayStartUtc($f['to'], 1);
        }
        $archived = $f['archived'] ?? 'no';
        if ($archived !== 'all') {
            $w[] = $archived === 'yes' ? 'g.archived' : 'NOT g.archived';
        }

        return [implode(' AND ', $w), $p];
    }

    /**
     * The moment a calendar day (YYYY-MM-DD, in the display time zone, IST by default) starts, as a UTC timestamp
     * string for PostgreSQL. $addDays = 1 gives the start of the following day (an exclusive end boundary).
     * Computed in PHP so the database never needs its own time-zone name list.
     */
    public static function dayStartUtc(string $date, int $addDays = 0): string
    {
        $d = new \DateTimeImmutable($date . ' 00:00:00', display_timezone());
        if ($addDays !== 0) {
            $d = $d->modify("+{$addDays} day");
        }

        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    }

    /**
     * SQL interval literal for the display time zone's current UTC offset, e.g. INTERVAL '+05:30' for IST
     * (India has no daylight saving). Used to group timestamps by local month inside PostgreSQL.
     */
    public static function offsetSql(): string
    {
        $offset = (new \DateTimeImmutable('now', display_timezone()))->format('P');

        return preg_match('/^[+-]\d{2}:\d{2}$/', $offset) === 1 ? "INTERVAL '{$offset}'" : "INTERVAL '+00:00'";
    }

    /**
     * One page of matching grievances, newest first.
     *
     * @param array<string,string> $f cleaned filters
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function search(array $f, int $limit = 25, int $offset = 0): array
    {
        [$where, $params] = self::where($f);
        $db    = Database::connect();
        $total = (int) $db->query("SELECT count(*) AS n FROM grievances g LEFT JOIN escalation_levels l ON l.id = g.current_level_id WHERE {$where}", $params)->getRow('n');

        $rows = $db->query(
            "SELECT g.id, g.reference_no, g.subject, g.status, g.role, g.id_code, g.created_at, g.archived, g.department_id, g.hostel, g.room,
                    g.submitter->>'name' AS submitter_name, t.name AS type_name, d.name AS post_name, d.scope_type,
                    COALESCE(" . WorkflowService::overdueSql('g', 'l') . ", false) AS overdue
               FROM grievances g
               JOIN grievance_types t ON t.id = g.type_id
               LEFT JOIN escalation_levels l ON l.id = g.current_level_id
               LEFT JOIN posts d ON d.id = l.post_id
              WHERE {$where}
              ORDER BY g.created_at DESC, g.id DESC
              LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->getResultArray();

        foreach ($rows as &$r) {
            $r['overdue']  = is_true($r['overdue']);
            $r['archived'] = is_true($r['archived']);
            $r['post']     = $r['post_name'] === null ? '—'
                : PostService::label((string) $r['post_name'], $r['scope_type'], WorkflowService::scopeValue($r, $r['scope_type']));
        }
        unset($r);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Ids of all matching grievances (for export), oldest first.
     *
     * @param array<string,string> $f
     *
     * @return list<int>
     */
    public static function ids(array $f, int $max): array
    {
        [$where, $params] = self::where($f);

        return array_map('intval', array_column(Database::connect()->query(
            "SELECT g.id FROM grievances g LEFT JOIN escalation_levels l ON l.id = g.current_level_id WHERE {$where} ORDER BY g.created_at, g.id LIMIT ?",
            array_merge($params, [$max + 1])
        )->getResultArray(), 'id'));
    }

    /**
     * Options for the filter drop-downs.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public static function options(): array
    {
        $db = Database::connect();

        return [
            'types'       => $db->query('SELECT id, name, role FROM grievance_types ORDER BY role, sort_order, name')->getResultArray(),
            'departments' => $db->query('SELECT dept AS id, depname AS name FROM establishment.dpcode ORDER BY lower(depname)')->getResultArray(),
            'hostels'     => $db->query(
                " SELECT name FROM (
                SELECT DISTINCT btrim(hostel) AS name FROM (
                    SELECT hostel FROM master_people
                    UNION ALL SELECT hostel FROM grievances
                    UNION ALL SELECT hostel FROM post_holders
                 ) h WHERE nullif(btrim(hostel), '') IS NOT NULL 
                 ) x
                 ORDER BY lower(name)"
            )->getResultArray(),
            'posts'       => $db->query('SELECT DISTINCT d.id, d.name FROM posts d JOIN escalation_levels l ON l.post_id = d.id ORDER BY d.name')->getResultArray(),
        ];
    }
}
