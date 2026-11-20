<?php

namespace App\Libraries;

use Config\Database;

/**
 * Summary report for the admin and the Director.
 *
 * Period figures cover grievances SUBMITTED between the two dates (India time, inclusive), archived ones included.
 * "Right now" figures (open and overdue per post) describe the current state, whatever the dates.
 */
class ReportService
{
    /**
     * @return array<string,mixed>
     */
    public static function summary(string $from, string $to): array
    {
        $db = Database::connect();
        // Period boundaries (start of $from, start of the day after $to) computed in PHP as UTC timestamps
        $start = GrievanceSearch::dayStartUtc($from);
        $end   = GrievanceSearch::dayStartUtc($to, 1);
        $off   = GrievanceSearch::offsetSql();
        $in    = 'g.created_at >= ? AND g.created_at < ?';
        $p     = [$start, $end];
        $days = "GREATEST(0, EXTRACT(EPOCH FROM (g.disposed_at - g.created_at))) / 86400.0";

        $totals = $db->query(
            "SELECT count(*) AS submitted,
                    count(*) FILTER (WHERE g.status = 'disposed') AS disposed,
                    count(*) FILTER (WHERE g.status = 'pending') AS pending,
                    count(*) FILTER (WHERE g.status = 'awaiting_submitter') AS awaiting,
                    round(avg({$days}) FILTER (WHERE g.status = 'disposed')::numeric, 1) AS avg_days,
                    round((percentile_cont(0.5) WITHIN GROUP (ORDER BY {$days}) FILTER (WHERE g.status = 'disposed'))::numeric, 1) AS median_days,
                    count(*) FILTER (WHERE EXISTS (SELECT 1 FROM grievance_actions a WHERE a.grievance_id = g.id AND a.action = 'escalate')) AS escalated
               FROM grievances g WHERE {$in}",
            $p
        )->getRowArray();

        $byType = $db->query(
            "SELECT t.name, t.role, count(g.id) AS total,
                    count(g.id) FILTER (WHERE g.status = 'pending') AS pending,
                    count(g.id) FILTER (WHERE g.status = 'awaiting_submitter') AS awaiting,
                    count(g.id) FILTER (WHERE g.status = 'disposed') AS disposed,
                    round(avg({$days}) FILTER (WHERE g.status = 'disposed')::numeric, 1) AS avg_days
               FROM grievances g JOIN grievance_types t ON t.id = g.type_id
              WHERE {$in}
              GROUP BY t.id, t.name, t.role, t.sort_order
              ORDER BY t.role, t.sort_order, t.name",
            $p
        )->getResultArray();

        $byDepartment = $db->query(
            "SELECT COALESCE(d.depname, '(none)') AS name, count(*) AS total,
                    count(*) FILTER (WHERE g.status <> 'disposed') AS open,
                    count(*) FILTER (WHERE g.status = 'disposed') AS disposed
               FROM grievances g LEFT JOIN establishment.dpcode d ON d.dept = g.department_id
              WHERE {$in}
              GROUP BY d.depname ORDER BY count(*) DESC, d.depname",
            $p
        )->getResultArray();

        $byMonth = $db->query(
            "WITH s AS (SELECT to_char(g.created_at AT TIME ZONE {$off}, 'YYYY-MM') AS m, count(*) AS n FROM grievances g WHERE {$in} GROUP BY 1),
                  d AS (SELECT to_char(g.disposed_at AT TIME ZONE {$off}, 'YYYY-MM') AS m, count(*) AS n FROM grievances g
                         WHERE g.disposed_at >= ? AND g.disposed_at < ? GROUP BY 1)
             SELECT COALESCE(s.m, d.m) AS month, COALESCE(s.n, 0) AS submitted, COALESCE(d.n, 0) AS disposed
               FROM s FULL JOIN d ON d.m = s.m ORDER BY 1",
            [$start, $end, $start, $end]
        )->getResultArray();

        // Right now: open and overdue grievances per post (not limited to the period)
        $posts = $db->query(
            "SELECT d.name AS post_name, d.scope_type,
                    CASE d.scope_type WHEN 'department' THEN g.department_id::text WHEN 'hostel' THEN g.hostel END AS scope_value,
                    count(*) AS open,
                    count(*) FILTER (WHERE g.status = 'awaiting_submitter') AS awaiting,
                    count(*) FILTER (WHERE " . WorkflowService::overdueSql('g', 'l') . ') AS overdue,
                    bool_or(NOT ' . WorkflowService::postFilledSql('g', 'l.post_id') . ") AS vacant
               FROM grievances g
               JOIN escalation_levels l ON l.id = g.current_level_id
               JOIN posts d ON d.id = l.post_id
              WHERE g.status <> 'disposed' AND NOT g.archived
              GROUP BY d.name, d.scope_type, 3
              ORDER BY count(*) FILTER (WHERE " . WorkflowService::overdueSql('g', 'l') . ') DESC, count(*) DESC'
        )->getResultArray();
        foreach ($posts as &$r) {
            $scopeValue = $r['scope_value'];
            if ($r['scope_type'] === 'department' && $scopeValue !== null) {
                $scopeValue = (int) $scopeValue;
            }
            $r['post']   = PostService::label((string) $r['post_name'], $r['scope_type'], $scopeValue);
            $r['vacant'] = is_true($r['vacant']);
        }
        unset($r);

        return [
            'from' => $from, 'to' => $to,
            'totals' => array_map(static fn ($v) => $v === null ? null : $v + 0, $totals),
            'byType' => $byType, 'byDepartment' => $byDepartment, 'byMonth' => $byMonth, 'posts' => $posts,
        ];
    }

    /** The report as an Excel workbook (one sheet per table). */
    public static function xlsx(array $r): string
    {
        $t = $r['totals'];
        $num = static fn ($v) => $v === null ? '' : $v + 0;

        return SimpleXlsx::write([
            ['name' => 'Summary', 'widths' => [40, 16], 'rows' => [
                ['Grievances submitted ' . $r['from'] . ' to ' . $r['to'], ''],
                ['Submitted', (int) $t['submitted']], ['Disposed', (int) $t['disposed']], ['Pending', (int) $t['pending']],
                ['Awaiting submitter', (int) $t['awaiting']], ['Transferred at least once', (int) $t['escalated']],
                ['Average days to disposal', $num($t['avg_days'])], ['Median days to disposal', $num($t['median_days'])],
            ]],
            ['name' => 'By type', 'widths' => [30, 14, 10, 10, 12, 10, 14], 'rows' => array_merge(
                [['Type', 'Role', 'Total', 'Pending', 'Awaiting', 'Disposed', 'Avg days']],
                array_map(static fn ($x) => [$x['name'], role_label($x['role']), (int) $x['total'], (int) $x['pending'], (int) $x['awaiting'], (int) $x['disposed'], $num($x['avg_days'])], $r['byType'])
            )],
            ['name' => 'By department', 'widths' => [40, 10, 10, 10], 'rows' => array_merge(
                [['Department', 'Total', 'Open', 'Disposed']],
                array_map(static fn ($x) => [$x['name'], (int) $x['total'], (int) $x['open'], (int) $x['disposed']], $r['byDepartment'])
            )],
            ['name' => 'By month', 'widths' => [12, 12, 12], 'rows' => array_merge(
                [['Month', 'Submitted', 'Disposed']],
                array_map(static fn ($x) => [$x['month'], (int) $x['submitted'], (int) $x['disposed']], $r['byMonth'])
            )],
            ['name' => 'Open by post (now)', 'widths' => [50, 10, 12, 10, 10], 'rows' => array_merge(
                [['Post', 'Open', 'Awaiting', 'Overdue', 'Vacant']],
                array_map(static fn ($x) => [$x['post'], (int) $x['open'], (int) $x['awaiting'], (int) $x['overdue'], $x['vacant'] ? 'Yes' : ''], $r['posts'])
            )],
        ]);
    }
}
