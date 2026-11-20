<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;

/**
 * Grievance exports as CSV or Excel (.xlsx).
 *
 *   grievances : one row per grievance (submitter details, subject, description, custom fields, status, post, dates,
 *                final decision)
 *   history    : one row per history entry (date, action, post, person, remark, attachments)
 *
 * Every export is written to the audit log by the caller (log()). Times are in India time. Text that a spreadsheet
 * could run as a formula (starting with = + - @) is prefixed with an apostrophe in CSV files; Excel files store
 * everything as plain text cells, which are never run.
 */
class ExportService
{
    public const MAX_ROWS = 50000;
    public const KINDS    = ['grievances', 'history'];
    public const FORMATS  = ['csv', 'xlsx'];

    /**
     * Build the file. Returns [file contents, file name, content type].
     *
     * @param list<int> $ids grievance ids, in the order they should appear
     *
     * @return array{0:string,1:string,2:string}
     */
    public static function build(array $ids, string $kind, string $format): array
    {
        if (! in_array($kind, self::KINDS, true) || ! in_array($format, self::FORMATS, true)) {
            throw new RuntimeException('Choose what to export and the file type.');
        }
        if (count($ids) > self::MAX_ROWS) {
            throw new RuntimeException('More than ' . number_format(self::MAX_ROWS) . ' grievances match. Narrow the filters (for example by date) and export in parts.');
        }

        $rows  = $kind === 'grievances' ? self::grievanceRows($ids) : self::historyRows($ids);
        $stamp = (new \DateTimeImmutable('now', display_timezone()))->format('Y-m-d-His');
        $name  = "{$kind}-{$stamp}.{$format}";

        if ($format === 'csv') {
            return [self::csv($rows), $name, 'text/csv; charset=UTF-8'];
        }

        // Control characters are not allowed in the XML inside an .xlsx file
        $rows = array_map(static fn (array $row) => array_map(
            static fn ($v) => is_string($v) ? (preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? '') : $v,
            $row
        ), $rows);
        $widths = $kind === 'grievances' ? [24, 18, 12, 20, 16, 22, 26, 12, 14, 12, 40, 60] : [24, 20, 22, 36, 36, 22, 60, 30];

        return [SimpleXlsx::write([['name' => ucfirst($kind), 'rows' => $rows, 'header' => true, 'widths' => $widths]]),
            $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    /** Record the export in the audit log (who, what, which filters, how many rows). */
    public static function log(string $scope, string $kind, string $format, int $count, array $filters): void
    {
        AuditLogger::log('export', 'grievances', null, null, [
            'scope' => $scope, 'kind' => $kind, 'format' => $format, 'grievances' => $count, 'filters' => $filters,
        ]);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<list<mixed>>
     */
    public static function grievanceRows(array $ids): array
    {
        $db = Database::connect();

        // Custom fields: one column per field key ever used in these grievances, labelled with the latest label
        $custom = [];
        $data   = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $in   = implode(',', array_map('intval', $chunk));
            $rows = $db->query(
                "SELECT g.*, t.name AS type_name, d.name AS post_name, d.scope_type,
                        " . WorkflowService::activeSecondsSql('g') . ' AS active_seconds,
                        COALESCE(' . WorkflowService::overdueSql('g', 'l') . ", false) AS overdue,
                        (SELECT count(*) FROM grievance_actions a WHERE a.grievance_id = g.id AND a.action = 'escalate') AS escalations,
                        (SELECT a.level_label FROM grievance_actions a WHERE a.grievance_id = g.id AND a.action = 'dispose' ORDER BY a.id DESC LIMIT 1) AS disposed_post,
                        (SELECT a.actor_name  FROM grievance_actions a WHERE a.grievance_id = g.id AND a.action = 'dispose' ORDER BY a.id DESC LIMIT 1) AS disposed_person,
                        (SELECT a.remark      FROM grievance_actions a WHERE a.grievance_id = g.id AND a.action = 'dispose' ORDER BY a.id DESC LIMIT 1) AS disposed_remark
                   FROM grievances g
                   JOIN grievance_types t ON t.id = g.type_id
                   LEFT JOIN escalation_levels l ON l.id = g.current_level_id
                   LEFT JOIN posts d ON d.id = l.post_id
                  WHERE g.id IN ({$in})"
            )->getResultArray();
            foreach ($rows as $r) {
                $r['submitter'] = json_decode((string) $r['submitter'], true) ?: [];
                $r['custom']    = json_decode((string) $r['custom'], true) ?: [];
                foreach ($r['custom'] as $key => $c) {
                    $custom[$key] = (string) ($c['label'] ?? $key);
                }
                $data[(int) $r['id']] = $r;
            }
        }

        $out = [array_merge([
            'Reference no.', 'Type', 'Role', 'Submitted', 'Scholar No. / Employee Code', 'Name', 'Department', 'Semester', 'Hostel',
            'Contact', 'Subject', 'Description',
        ], array_values($custom), [
            'Status', 'Current / last post', 'Level', 'Days at current level', 'Overdue', 'Transfers',
            'Disposed on', 'Disposed by (post)', 'Disposed by (person)', 'Decision remark', 'Archived',
        ])];

        foreach ($ids as $id) {
            if (! isset($data[$id])) {
                continue;
            }
            $r    = $data[$id];
            $s    = $r['submitter'];
            $open = $r['status'] !== 'disposed';
            $post = $r['post_name'] === null ? '' : PostService::label((string) $r['post_name'], $r['scope_type'], WorkflowService::scopeValue($r, $r['scope_type']));
            $row  = [
                $r['reference_no'], $r['type_name'], role_label($r['role']), ist($r['created_at']), $r['id_code'], $s['name'] ?? '',
                $s['department'] ?? '', $s['semester'] ?? '', $s['hostel'] ?? '', $s['contact'] ?? '', $r['subject'], $r['description'],
            ];
            foreach (array_keys($custom) as $key) {
                $row[] = $r['custom'][$key]['value'] ?? '';
            }
            $out[] = array_merge($row, [
                WorkflowService::STATUS_LABELS[$r['status']] ?? $r['status'],
                $open ? $post : (string) ($r['disposed_post'] ?? $post),
                $r['level_no'] ?? '',
                $open ? (int) floor((int) $r['active_seconds'] / 86400) : '',
                $open ? (is_true($r['overdue']) ? 'Yes' : 'No') : '',
                (int) $r['escalations'],
                $open ? '' : ist($r['disposed_at']),
                $open ? '' : (string) ($r['disposed_post'] ?? ''),
                $open ? '' : (string) ($r['disposed_person'] ?? ''),
                $open ? '' : (string) ($r['disposed_remark'] ?? ''),
                is_true($r['archived']) ? 'Yes' : 'No',
            ]);
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<list<mixed>>
     */
    public static function historyRows(array $ids): array
    {
        $db  = Database::connect();
        $out = [['Reference no.', 'Date', 'Action', 'Post', 'To post', 'Person', 'Remark / reply', 'Attachments']];
        $pos = array_flip($ids);
        $all = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            $in   = implode(',', array_map('intval', $chunk));
            $rows = $db->query(
                "SELECT a.*, g.reference_no,
                        (SELECT string_agg(f.original_name, ', ' ORDER BY f.id) FROM attachments f WHERE f.action_id = a.id) AS files
                   FROM grievance_actions a JOIN grievances g ON g.id = a.grievance_id
                  WHERE a.grievance_id IN ({$in})
                  ORDER BY a.grievance_id, a.id"
            )->getResultArray();
            foreach ($rows as $r) {
                $all[] = $r;
            }
        }
        usort($all, static fn ($x, $y) => [$pos[(int) $x['grievance_id']], (int) $x['id']] <=> [$pos[(int) $y['grievance_id']], (int) $y['id']]);

        foreach ($all as $r) {
            $out[] = [
                $r['reference_no'], ist($r['created_at']), WorkflowService::ACTION_LABELS[$r['action']] ?? $r['action'],
                (string) ($r['level_label'] ?? ''), (string) ($r['to_level_label'] ?? ''), $r['actor_name'],
                (string) ($r['remark'] ?? ''), (string) ($r['files'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * CSV with a UTF-8 byte order mark (so Excel shows Hindi and other scripts correctly).
     *
     * @param list<list<mixed>> $rows
     */
    public static function csv(array $rows): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($h, array_map([self::class, 'safeCell'], $row), ',', '"', '');
        }
        rewind($h);
        $csv = (string) stream_get_contents($h);
        fclose($h);

        return $csv;
    }

    /** Neutralise text a spreadsheet would treat as a formula, and drop control characters. */
    public static function safeCell(mixed $value): string
    {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value) ?? '';

        return ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $v : $v;
    }
}
