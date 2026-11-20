<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;
use Throwable;

/**
 * The grievance workflow engine (module 5).
 *
 * A grievance sits at one level of its type's escalation matrix. The level names a post;  * handles it is that post for the grievance's department (HOD...) or hostel
 * (Warden...). Whoever holds that post right now may act. Access follows the post, never the person.
 *
 * Authority actions (a remark is mandatory for each):
 *   dispose      -> status 'disposed'. Final.
 *   escalate     -> (shown as "Transfer") moves to the NEXT level, or to any later level when the current level has can_skip.
 *                  Not possible at the last (Director) level.
 *   request_info -> status 'awaiting_submitter'; the overdue clock is paused until the submitter replies.
 * While a grievance awaits the submitter, the authority may still dispose or escalate ("transfer") it (e.g. when the
 * submitter never answers); a second information request is not possible until the first is answered.
 *
 * Disposing or escalating while the submitter has not replied needs an explicit confirmation ($confirmWaiting),
 * so the authority knows the question is being withdrawn.
 *
 * Submitter action (the My grievances page calls it after checking the grievance belongs to the signed-in person):
 *   submitter_reply -> back to 'pending' at the same level; the paused time is not counted as overdue.
 *   late_reply      -> the question was withdrawn by an escalation before the answer came. The answer is still
 *                      accepted (once, while the grievance is open) and added to the history as a note for whoever
 *                      handles it now. Status and overdue clock are not changed.
 *
 * Every change locks the grievance row, appends to the history (grievance_actions, append-only) and logs
 * notification events (no e-mail in v1). Forms send the id of the newest history row they were showing
 * ("version"), so a stale page or a double click can never act twice.
 *
 * Errors meant for the user are RuntimeException.
 */
class WorkflowService
{
    public const REMARK_MAX = 2000;
    public const REPLY_MAX  = 5000;

    public const ACTIONS = ['dispose', 'escalate', 'request_info'];

    /** Readable names of history actions. */
    public const ACTION_LABELS = [
        'submit'          => 'Submitted',
        'escalate'        => 'Transferred',
        'dispose'         => 'Disposed',
        'request_info'    => 'Information requested',
        'submitter_reply' => 'Submitter replied',
        'late_reply'      => 'Submitter replied (late)',
        'reroute'         => 'Re-routed',
    ];

    public const STATUS_LABELS = [
        'pending'            => 'Pending',
        'awaiting_submitter' => 'Awaiting submitter',
        'disposed'           => 'Disposed',
    ];

    // ------------------------------------------------------------------ SQL building blocks

    /**
     * SQL condition: user ? currently holds the post of post $post for grievance $g.
     * Needs one bound parameter (the user id).
     */
    public static function holdsPostSql(string $g, string $post): string
    {
        return "EXISTS (SELECT 1 FROM post_holders pa JOIN posts pd ON pd.id = pa.post_id
                         WHERE pa.active AND pd.active AND pa.user_id = ? AND pa.post_id = {$post}
                           AND CASE pd.scope_type
                                   WHEN 'department' THEN pa.scope_id = {$g}.department_id
                                   WHEN 'hostel'     THEN lower(btrim(pa.hostel)) = lower(btrim({$g}.hostel))
                                   ELSE pa.scope_id IS NULL
                               END)";
    }

    /** SQL condition: the post of post $post for grievance $g has an active holder (no parameter). */
    public static function postFilledSql(string $g, string $post): string
    {
        return "EXISTS (SELECT 1 FROM post_holders fa JOIN posts fd ON fd.id = fa.post_id
                         JOIN users fu ON fu.id = fa.user_id AND fu.active
                         WHERE fa.active AND fd.active AND fa.post_id = {$post}
                           AND CASE fd.scope_type
                                   WHEN 'department' THEN fa.scope_id = {$g}.department_id
                                   WHEN 'hostel'     THEN lower(btrim(fa.hostel)) = lower(btrim({$g}.hostel))
                                   ELSE fa.scope_id IS NULL
                               END)";
    }

    /**
     * SQL expression: seconds the grievance has spent at its current level, not counting time spent waiting
     * for the submitter.
     */
    public static function activeSecondsSql(string $g): string
    {
        return "(EXTRACT(EPOCH FROM (now() - {$g}.level_entered_at))::bigint - {$g}.paused_seconds
                 - COALESCE(EXTRACT(EPOCH FROM (now() - {$g}.paused_since))::bigint, 0))";
    }

    /** SQL condition: pending and past the level's overdue days ($l = the current escalation level). */
    public static function overdueSql(string $g, string $l): string
    {
        return "({$g}.status = 'pending' AND " . self::activeSecondsSql($g) . " > {$l}.overdue_days * 86400)";
    }

    // ------------------------------------------------------------------ reading

    /**
     * One grievance with its type and current level (no access check).
     *
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        $row = Database::connect()->query(
            'SELECT g.*, t.name AS type_name, t.code AS type_code,
                    l.level_no, l.overdue_days, l.is_final, l.can_skip, l.post_id, d.name AS post_name, d.scope_type,
                    ' . self::activeSecondsSql('g') . ' AS active_seconds,
                    COALESCE(' . self::overdueSql('g', 'l') . ', false) AS overdue,
                    (SELECT max(id) FROM grievance_actions WHERE grievance_id = g.id) AS version,
                    (SELECT count(*) FROM escalation_levels nl WHERE nl.type_id = g.type_id AND nl.level_no > l.level_no) AS levels_after
               FROM grievances g
               JOIN grievance_types t ON t.id = g.type_id
               LEFT JOIN escalation_levels l ON l.id = g.current_level_id
               LEFT JOIN posts d ON d.id = l.post_id
              WHERE g.id = ?',
            [$id]
        )->getRowArray();

        if ($row === null) {
            return null;
        }

        $row['submitter']   = json_decode((string) $row['submitter'], true) ?: [];
        $row['custom']      = json_decode((string) $row['custom'], true) ?: [];
        $row['overdue']     = is_true($row['overdue']);
        $row['is_final']    = is_true($row['is_final']);
        $row['can_skip']    = is_true($row['can_skip']);
        $row['level_label'] = $row['post_name'] === null ? null
            : PostService::label((string) $row['post_name'], $row['scope_type'], self::scopeValue($row, $row['scope_type']));

        return $row;
    }

    /**
     * May this authority see the grievance? Yes if they now hold the post it is at, or a post it has
     * passed through (any history row at that post, for the grievance's own department / hostel).
     * The Director sees every grievance (view only; acting still needs the grievance to be at the Director).
     */
    public static function canView(int $userId, int $grievanceId): bool
    {
        // The Director has view-only oversight of every grievance
        if (self::isDirector($userId)) {
            return (bool) Database::connect()->query('SELECT 1 FROM grievances WHERE id = ?', [$grievanceId])->getRowArray();
        }

        return (bool) Database::connect()->query(
            'SELECT 1 FROM grievances g LEFT JOIN escalation_levels l ON l.id = g.current_level_id
              WHERE g.id = ? AND (
                    (l.id IS NOT NULL AND ' . self::holdsPostSql('g', 'l.post_id') . ')
                 OR EXISTS (SELECT 1 FROM grievance_actions ga WHERE ga.grievance_id = g.id AND ga.post_id IS NOT NULL
                               AND ' . self::holdsPostSql('g', 'ga.post_id') . '))',
            [$grievanceId, $userId, $userId]
        )->getRowArray();
    }

    /** Does the user currently hold the Director post? */
    public static function isDirector(int $userId): bool
    {
        return (bool) Database::connect()->query(
            'SELECT 1 FROM post_holders a JOIN posts d ON d.id = a.post_id
              WHERE a.user_id = ? AND a.active AND d.active AND d.is_director',
            [$userId]
        )->getRowArray();
    }

    /** May this authority act on the grievance now? (holds the post at its current level, and it is open) */
    public static function canAct(int $userId, int $grievanceId): bool
    {
        return (bool) Database::connect()->query(
            "SELECT 1 FROM grievances g JOIN escalation_levels l ON l.id = g.current_level_id
              WHERE g.id = ? AND g.status <> 'disposed' AND " . self::holdsPostSql('g', 'l.post_id'),
            [$grievanceId, $userId]
        )->getRowArray();
    }

    /**
     * Grievance lists for an authority.
     *   action  : pending at a post they hold
     *   waiting : awaiting the submitter's reply, at a post they hold
     *   earlier : passed through a post they hold and has since moved on or been disposed
     *   all     : every grievance (Director oversight only; the caller checks isDirector)
     * Filters: q (reference number or submitter name, part of it), type_id, overdue (true = overdue only).
     *
     * @param array{q?:string,type_id?:int,overdue?:bool} $filters
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function inbox(int $userId, string $tab, int $limit = 50, int $offset = 0, array $filters = []): array
    {
        [$where, $params] = self::inboxWhere($userId, $tab);
        $db = Database::connect();

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like     = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
            $where   .= " AND (g.reference_no ILIKE ? OR g.submitter->>'name' ILIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }
        if (! empty($filters['type_id'])) {
            $where   .= ' AND g.type_id = ?';
            $params[] = (int) $filters['type_id'];
        }
        if (! empty($filters['overdue'])) {
            $where .= ' AND ' . self::overdueSql('g', 'l');
        }

        $total = (int) $db->query("SELECT count(*) AS n FROM grievances g LEFT JOIN escalation_levels l ON l.id = g.current_level_id WHERE {$where}", $params)->getRow('n');

        $order = in_array($tab, ['earlier', 'all'], true) ? 'g.updated_at DESC, g.id DESC' : 'overdue DESC, g.level_entered_at ASC, g.id ASC';
        $rows  = $db->query(
            "SELECT g.id, g.reference_no, g.subject, g.status, g.role, g.created_at, g.updated_at, g.level_entered_at,
                    g.submitter->>'name' AS submitter_name, t.name AS type_name,
                    (SELECT ga.action FROM grievance_actions ga WHERE ga.grievance_id = g.id ORDER BY ga.id DESC LIMIT 1) AS last_action,
                    l.level_no, l.overdue_days, d.name AS post_name, d.scope_type, g.department_id, g.hostel,
                    " . self::activeSecondsSql('g') . ' AS active_seconds,
                    COALESCE(' . self::overdueSql('g', 'l') . ", false) AS overdue
               FROM grievances g
               JOIN grievance_types t ON t.id = g.type_id
               LEFT JOIN escalation_levels l ON l.id = g.current_level_id
               LEFT JOIN posts d ON d.id = l.post_id
              WHERE {$where}
              ORDER BY {$order}
              LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->getResultArray();

        foreach ($rows as &$r) {
            $r['overdue']     = is_true($r['overdue']);
            $r['level_label'] = $r['post_name'] === null ? '—'
                : PostService::label((string) $r['post_name'], $r['scope_type'], self::scopeValue($r, $r['scope_type']));
        }
        unset($r);

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array{action:int,waiting:int,earlier:int,overdue:int,all?:int} */
    public static function inboxCounts(int $userId): array
    {
        $db   = Database::connect();
        $out  = [];
        $tabs = self::isDirector($userId) ? ['action', 'waiting', 'earlier', 'all'] : ['action', 'waiting', 'earlier'];
        foreach ($tabs as $tab) {
            [$where, $params] = self::inboxWhere($userId, $tab);
            $out[$tab] = (int) $db->query("SELECT count(*) AS n FROM grievances g LEFT JOIN escalation_levels l ON l.id = g.current_level_id WHERE {$where}", $params)->getRow('n');
        }
        [$where, $params] = self::inboxWhere($userId, 'action');
        $out['overdue'] = (int) $db->query('SELECT count(*) AS n FROM grievances g LEFT JOIN escalation_levels l ON l.id = g.current_level_id WHERE ' . $where . ' AND ' . self::overdueSql('g', 'l'), $params)->getRow('n');

        return $out;
    }

    /**
     * History of a grievance, oldest first, with the attachments added by each action.
     *
     * @return list<array<string,mixed>>
     */
    public static function history(int $grievanceId): array
    {
        $db   = Database::connect();
        $rows = $db->query('SELECT * FROM grievance_actions WHERE grievance_id = ? ORDER BY id', [$grievanceId])->getResultArray();
        $att  = $db->query('SELECT id, action_id, original_name, size_bytes FROM attachments WHERE grievance_id = ? AND action_id IS NOT NULL ORDER BY id', [$grievanceId])->getResultArray();

        foreach ($rows as &$r) {
            $r['attachments'] = array_values(array_filter($att, static fn ($a) => (int) $a['action_id'] === (int) $r['id']));
        }
        unset($r);

        return $rows;
    }

    /**
     * Attachments of a grievance (submission ones first, then reply ones).
     *
     * @return list<array<string,mixed>>
     */
    public static function attachments(int $grievanceId): array
    {
        return Database::connect()->query(
            'SELECT id, action_id, original_name, mime_type, size_bytes, created_at FROM attachments WHERE grievance_id = ? ORDER BY action_id NULLS FIRST, id',
            [$grievanceId]
        )->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public static function attachment(int $grievanceId, int $attachmentId): ?array
    {
        return Database::connect()->query('SELECT * FROM attachments WHERE id = ? AND grievance_id = ?', [$attachmentId, $grievanceId])->getRowArray();
    }

    /**
     * The post the grievance is at, as the acting authority sees it: "Head of Department (Computer Science)".
     */
    public static function nextLevelLabel(array $g): ?string
    {
        $next = self::nextLevel((int) $g['type_id'], (int) $g['level_no']);

        return $next === null ? null : PostService::label($next['post_name'], $next['scope_type'], self::scopeValue($g, $next['scope_type']));
    }

    /**
     * The levels this grievance may be transferred to, as [id, level_no, label, is_next]. Always the next level; all later
     * levels too when the current level is allowed to skip ahead.
     *
     * @return list<array{id:int,level_no:int,label:string,is_next:bool}>
     */
    public static function transferTargets(array $g): array
    {
        if ($g['status'] === 'disposed' || $g['level_no'] === null) {
            return [];
        }

        $rows = Database::connect()->query(
            'SELECT l.id, l.level_no, d.name AS post_name, d.scope_type
               FROM escalation_levels l JOIN posts d ON d.id = l.post_id
              WHERE l.type_id = ? AND l.level_no > ? ORDER BY l.level_no',
            [(int) $g['type_id'], (int) $g['level_no']]
        )->getResultArray();

        $out = [];
        foreach ($rows as $i => $r) {
            if ($i > 0 && ! $g['can_skip']) {
                break;
            }
            $out[] = [
                'id'       => (int) $r['id'],
                'level_no' => (int) $r['level_no'],
                'label'    => PostService::label($r['post_name'], $r['scope_type'], self::scopeValue($g, $r['scope_type'])),
                'is_next'  => $i === 0,
            ];
        }

        return $out;
    }

    /**
     * The posts a user holds, each with how many grievances are pending / waiting / overdue there.
     *
     * @return list<array{label:string,pending:int,waiting:int,overdue:int}>
     */
    public static function postCounts(int $userId): array
    {
        $db    = Database::connect();
        $posts = $db->query(
            'SELECT a.post_id, a.scope_id, a.hostel, d.name, d.scope_type FROM post_holders a JOIN posts d ON d.id = a.post_id
              WHERE a.user_id = ? AND a.active AND d.active ORDER BY d.name, a.scope_id, a.hostel',
            [$userId]
        )->getResultArray();

        $out = [];
        foreach ($posts as $p) {
            $scope = match ($p['scope_type']) {
                'department' => 'g.department_id = ?',
                'hostel'     => 'lower(btrim(g.hostel)) = lower(btrim(?))',
                default      => 'g.department_id IS NULL AND g.hostel IS NULL',
            };
            $scopeValue = self::assignmentScope($p);
            $row = $db->query(
                "SELECT count(*) FILTER (WHERE g.status = 'pending') AS pending,
                        count(*) FILTER (WHERE g.status = 'awaiting_submitter') AS waiting,
                        count(*) FILTER (WHERE " . self::overdueSql('g', 'l') . ") AS overdue
                   FROM grievances g JOIN escalation_levels l ON l.id = g.current_level_id
                  WHERE NOT g.archived AND g.status <> 'disposed' AND l.post_id = ? AND {$scope}",
                $p['scope_type'] === null ? [$p['post_id']] : [$p['post_id'], $scopeValue]
            )->getRowArray();
            $out[] = [
                'label'   => PostService::label((string) $p['name'], $p['scope_type'], $scopeValue),
                'pending' => (int) $row['pending'],
                'waiting' => (int) $row['waiting'],
                'overdue' => (int) $row['overdue'],
            ];
        }

        return $out;
    }

    /**
     * Other grievances of the same submitter (same role and ID) from the last 12 months, any type, newest first.
     * can_open tells whether this authority may open each one; the summary itself (reference, type, status, date)
     * is always shown so the authority knows the history exists.
     *
     * @return list<array<string,mixed>>
     */
    public static function related(int $grievanceId, int $userId, int $limit = 20): array
    {
        $rows = Database::connect()->query(
            "SELECT o.id, o.reference_no, o.status, o.created_at, o.subject, t.name AS type_name, (o.type_id = g.type_id) AS same_type
               FROM grievances g
               JOIN grievances o ON o.role = g.role AND lower(o.id_code) = lower(g.id_code) AND o.id <> g.id
               JOIN grievance_types t ON t.id = o.type_id
              WHERE g.id = ? AND o.created_at > now() - interval '12 months'
              ORDER BY o.created_at DESC, o.id DESC
              LIMIT ?",
            [$grievanceId, $limit]
        )->getResultArray();

        foreach ($rows as &$r) {
            $r['same_type'] = is_true($r['same_type']);
            $r['can_open']  = self::canView($userId, (int) $r['id']);
            if (! $r['can_open']) {
                $r['subject'] = null;   // the subject may be sensitive; only those who may open it see it
            }
        }
        unset($r);

        return $rows;
    }

    // ------------------------------------------------------------------ authority actions

    /**
     * Perform dispose / escalate (shown to users as "Transfer") / request_info.
     *
     * @param int|null $version        id of the newest history row the authority was looking at (null = don't check)
     * @param bool     $confirmWaiting the authority confirmed disposing / escalating although the submitter has not
     *                                 replied to the question yet
     * @param int|null $targetLevelId  escalate only: the level to transfer to (null = the next level). Any later level is
     *                                 allowed only when the current level may skip ahead.
     *
     * @return string a confirmation message
     */
    public static function act(int $grievanceId, int $userId, string $action, string $remark, ?int $version = null, bool $confirmWaiting = false, ?int $targetLevelId = null): string
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException('Choose what to do with the grievance.');
        }
        $remark = self::cleanText($remark);
        if ($remark === '') {
            throw new RuntimeException('A remark is required.');
        }
        if (mb_strlen($remark) > self::REMARK_MAX) {
            throw new RuntimeException('The remark is too long (up to ' . self::REMARK_MAX . ' characters).');
        }

        $db = Database::connect();
        $db->transBegin();

        try {
            $g = self::lockForAction($grievanceId, $userId, $version);

            if ($g['status'] === 'awaiting_submitter' && $action !== 'request_info' && ! $confirmWaiting) {
                throw new RuntimeException('The submitter has not replied to your question yet. Tick "I understand the submitter has not replied" to '
                    . ($action === 'escalate' ? 'transfer' : 'dispose') . ' anyway.');
            }

            $actor = $db->query('SELECT full_name FROM users WHERE id = ?', [$userId])->getRow('full_name');
            $here  = [
                'level_id'       => (int) $g['current_level_id'],
                'level_no'       => (int) $g['level_no'],
                'level_label'    => $g['level_label'],
                'post_id' => (int) $g['post_id'],
            ];
            $payload = ['reference_no' => $g['reference_no'], 'level' => $g['level_label'], 'actor' => $actor];

            switch ($action) {
                case 'dispose':
                    $db->query(
                        "UPDATE grievances SET status = 'disposed', disposed_at = now(), paused_since = NULL,
                                paused_seconds = paused_seconds + COALESCE(EXTRACT(EPOCH FROM (now() - paused_since))::integer, 0),
                                updated_at = now()
                          WHERE id = ?",
                        [$grievanceId]
                    );
                    self::record($grievanceId, $here, $userId, $actor, 'dispose', $remark);
                    self::event($grievanceId, 'disposed', $here['level_id'], $payload);
                    $message = "{$g['reference_no']} has been disposed.";
                    break;

                case 'escalate':
                    $next = self::nextLevel((int) $g['type_id'], (int) $g['level_no']);
                    if ($next === null) {
                        throw new RuntimeException('This is the last level of the route. The grievance can be disposed, or more information requested.');
                    }
                    $skipped = 0;
                    if ($targetLevelId !== null && $targetLevelId !== (int) $next['id']) {
                        if (! $g['can_skip']) {
                            throw new RuntimeException('This level can only transfer a grievance to the next level.');
                        }
                        $target = $db->query(
                            'SELECT l.id, l.level_no, l.post_id, d.name AS post_name, d.scope_type
                               FROM escalation_levels l JOIN posts d ON d.id = l.post_id
                              WHERE l.id = ? AND l.type_id = ? AND l.level_no > ?',
                            [$targetLevelId, (int) $g['type_id'], (int) $g['level_no']]
                        )->getRowArray();
                        if ($target === null) {
                            throw new RuntimeException('Choose a level further along this grievance\'s route to transfer to.');
                        }
                        $skipped = (int) $db->query(
                            'SELECT count(*) AS n FROM escalation_levels WHERE type_id = ? AND level_no > ? AND level_no < ?',
                            [(int) $g['type_id'], (int) $g['level_no'], (int) $target['level_no']]
                        )->getRow('n');
                        $next = $target;
                    }
                    $nextLabel = PostService::label($next['post_name'], $next['scope_type'], self::scopeValue($g, $next['scope_type']));

                    // The overdue clock starts afresh at the new level; any open information request is cancelled
                    $db->query(
                        "UPDATE grievances SET current_level_id = ?, status = 'pending', level_entered_at = now(),
                                paused_since = NULL, paused_seconds = 0, updated_at = now()
                          WHERE id = ?",
                        [(int) $next['id'], $grievanceId]
                    );
                    self::record($grievanceId, $here, $userId, $actor, 'escalate', $remark, (int) $next['level_no'], $nextLabel, $skipped);
                    self::event($grievanceId, 'escalated', $here['level_id'], $payload + ['to' => $nextLabel, 'skipped_levels' => $skipped]);
                    self::event($grievanceId, 'arrived_at_level', (int) $next['id'], ['reference_no' => $g['reference_no'], 'level' => $nextLabel]);
                    $message = "{$g['reference_no']} has been transferred " . ($skipped > 0 ? 'directly ' : '') . "to {$nextLabel}.";
                    break;

                default: // request_info
                    if ($g['status'] === 'awaiting_submitter') {
                        throw new RuntimeException('Information has already been requested. Wait for the submitter to reply, or dispose / transfer the grievance.');
                    }
                    $db->query(
                        "UPDATE grievances SET status = 'awaiting_submitter', paused_since = now(), updated_at = now() WHERE id = ?",
                        [$grievanceId]
                    );
                    self::record($grievanceId, $here, $userId, $actor, 'request_info', $remark);
                    self::event($grievanceId, 'info_requested', $here['level_id'], $payload);
                    $message = "Information has been requested for {$g['reference_no']}. It will come back to you when the submitter replies.";
            }

            if (! $db->transCommit()) {
                throw new RuntimeException('The action could not be saved. Please try again.');
            }
        } catch (RuntimeException $e) {
            $db->transRollback();

            throw $e;
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'Workflow action failed: ' . $e);

            throw new RuntimeException('The action could not be saved. Nothing was changed; please try again.');
        }

        return $message;
    }

    // ------------------------------------------------------------------ submitter reply

    /**
     * Can the submitter reply now, and to which question?
     *   mode 'reply' : the grievance is waiting for the submitter (normal reply; resumes the clock)
     *   mode 'late'  : the question was withdrawn by an escalation and is still unanswered; the grievance is open,
     *                  so a late answer is accepted as a note in the history
     *   mode null    : nothing to answer (no open question, already answered, or the grievance is disposed)
     *
     * @return array{mode:?string,question:?array<string,mixed>}
     */
    public static function replyState(int $grievanceId): array
    {
        $db       = Database::connect();
        $status   = $db->query('SELECT status FROM grievances WHERE id = ?', [$grievanceId])->getRow('status');
        $question = $db->query(
            "SELECT * FROM grievance_actions WHERE grievance_id = ? AND action = 'request_info' ORDER BY id DESC LIMIT 1",
            [$grievanceId]
        )->getRowArray();
        $answered = $question !== null && (bool) $db->query(
            "SELECT 1 FROM grievance_actions WHERE grievance_id = ? AND id > ? AND action IN ('submitter_reply','late_reply')",
            [$grievanceId, $question['id']]
        )->getRowArray();

        $mode = match (true) {
            $status === 'awaiting_submitter'                          => 'reply',
            $status === 'pending' && $question !== null && ! $answered => 'late',
            default                                                    => null,
        };

        return ['mode' => $mode, 'question' => $mode === null ? null : $question];
    }

    /**
     * The submitter answers an information request (or, after an escalation withdrew it, sends a late answer).
     * The caller (My grievances) has already checked ownership; this re-checks that the reference number
     * and ID belong together. Whether it counts as a normal or a late reply is decided under the row lock, so an
     * escalation that happens while the submitter is typing never loses the answer.
     *
     * @param list<array<string,mixed>> $files uploads (same rules as at submission)
     *
     * @return string 'reply' or 'late'
     *
     * @throws SubmissionException with the list of problems
     */
    public static function submitterReply(string $referenceNo, string $idCode, string $text, array $files = []): string
    {
        $db = Database::connect();
        $g  = $db->query('SELECT id, id_code FROM grievances WHERE reference_no = ?', [trim($referenceNo)])->getRowArray();
        if ($g === null || strcasecmp(trim((string) $g['id_code']), trim($idCode)) !== 0) {
            throw new SubmissionException(['No grievance matches that reference number and ID.']);
        }

        $text   = self::cleanText($text);
        $errors = [];
        if ($text === '' || mb_strlen($text) > self::REPLY_MAX) {
            $errors[] = 'Your reply is required (up to ' . self::REPLY_MAX . ' characters).';
        }
        [$uploads, $fileErrors] = SubmissionService::checkFiles($files);
        $errors = array_merge($errors, $fileErrors);
        if ($errors !== []) {
            throw new SubmissionException($errors);
        }

        $id     = (int) $g['id'];
        $stored = [];
        $db->transBegin();

        try {
            $g    = $db->query('SELECT * FROM grievances WHERE id = ? FOR UPDATE', [$id])->getRowArray();
            $mode = self::replyState($id)['mode'];
            if ($mode === null) {
                throw new SubmissionException([$g['status'] === 'disposed'
                    ? 'This grievance has been disposed, so a reply can no longer be added.'
                    : 'This grievance is not waiting for a reply from you.']);
            }
            $level = self::levelRow((int) $g['current_level_id']);
            $label = PostService::label($level['post_name'], $level['scope_type'], self::scopeValue($g, $level['scope_type']));

            if ($mode === 'reply') {
                $db->query(
                    "UPDATE grievances SET status = 'pending',
                            paused_seconds = paused_seconds + GREATEST(0, EXTRACT(EPOCH FROM (now() - paused_since))::integer),
                            paused_since = NULL, updated_at = now()
                      WHERE id = ?",
                    [$id]
                );
            } else {
                // A late answer is a note only: status and overdue clock stay as they are
                $db->query('UPDATE grievances SET updated_at = now() WHERE id = ?', [$id]);
            }
            $snapshot = json_decode((string) $g['submitter'], true) ?: [];
            $actionId = self::record($id, [
                'level_id' => (int) $level['id'], 'level_no' => (int) $level['level_no'], 'level_label' => $label,
                'post_id' => (int) $level['post_id'],
            ], null, (string) ($snapshot['name'] ?? 'Submitter'), $mode === 'reply' ? 'submitter_reply' : 'late_reply', $text);

            foreach ($uploads as $u) {
                $storedName = SubmissionService::store($u);
                $stored[]   = $storedName;
                $db->query(
                    'INSERT INTO attachments (grievance_id, action_id, original_name, stored_name, mime_type, size_bytes) VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $actionId, $u['original'], $storedName, $u['mime'], $u['size']]
                );
            }
            self::event($id, 'submitter_replied', (int) $level['id'], ['reference_no' => $g['reference_no'], 'level' => $label, 'late' => $mode === 'late']);

            if (! $db->transCommit()) {
                throw new RuntimeException('commit failed');
            }
        } catch (SubmissionException $e) {
            $db->transRollback();

            throw $e;
        } catch (Throwable $e) {
            $db->transRollback();
            foreach ($stored as $name) {
                @unlink(SubmissionService::path($name));
            }
            log_message('error', 'Submitter reply failed: ' . $e);

            throw new SubmissionException(['Your reply could not be saved. Nothing was sent; please try again.']);
        }

        return $mode;
    }

    // ------------------------------------------------------------------ admin oversight and reminders

    /**
     * Open grievances whose current post has nobody in it (vacant post, deactivated holder, or a department /
     * hostel post that cannot be resolved). They wait until the admin assigns a holder.
     *
     * @return list<array<string,mixed>>
     */
    public static function waitingAtVacantPosts(int $limit = 100): array
    {
        $rows = Database::connect()->query(
            "SELECT g.id, g.reference_no, g.level_entered_at, g.department_id, g.hostel, t.name AS type_name,
                    l.post_id, d.name AS post_name, d.scope_type
               FROM grievances g
               JOIN grievance_types t ON t.id = g.type_id
               JOIN escalation_levels l ON l.id = g.current_level_id
               JOIN posts d ON d.id = l.post_id
              WHERE g.status <> 'disposed' AND NOT g.archived AND NOT " . self::postFilledSql('g', 'l.post_id') . '
              ORDER BY g.level_entered_at, g.id
              LIMIT ?',
            [$limit]
        )->getResultArray();

        foreach ($rows as &$r) {
            $r['post'] = PostService::label((string) $r['post_name'], $r['scope_type'], self::scopeValue($r, $r['scope_type']));
        }
        unset($r);

        return $rows;
    }

    /** @return array{open:int,pending:int,awaiting:int,overdue:int,vacant:int,disposed:int} */
    public static function adminCounts(): array
    {
        $row = Database::connect()->query(
            "SELECT count(*) FILTER (WHERE g.status <> 'disposed') AS open,
                    count(*) FILTER (WHERE g.status = 'pending') AS pending,
                    count(*) FILTER (WHERE g.status = 'awaiting_submitter') AS awaiting,
                    count(*) FILTER (WHERE g.status = 'disposed') AS disposed,
                    count(*) FILTER (WHERE " . self::overdueSql('g', 'l') . ") AS overdue,
                    count(*) FILTER (WHERE g.status <> 'disposed' AND NOT " . self::postFilledSql('g', 'l.post_id') . ') AS vacant
               FROM grievances g LEFT JOIN escalation_levels l ON l.id = g.current_level_id
              WHERE NOT g.archived'
        )->getRowArray();

        return array_map('intval', $row);
    }

    /**
     * Log an 'overdue_reminder' notification event for each overdue grievance: the first when it becomes
     * overdue, then again every <overdue days of that level> while it stays overdue. Safe to run as often as
     * you like (e.g. daily from Task Scheduler / cron): it never logs two reminders inside one interval.
     *
     * @return int reminders logged
     */
    public static function logOverdueReminders(): int
    {
        $db   = Database::connect();
        $rows = $db->query(
            "SELECT g.id, g.reference_no, g.department_id, g.hostel, l.id AS level_id, l.overdue_days, l.post_id,
                    d.name AS post_name, d.scope_type,
                    (" . self::activeSecondsSql('g') . ') / 86400 AS days_at_level
               FROM grievances g
               JOIN escalation_levels l ON l.id = g.current_level_id
               JOIN posts d ON d.id = l.post_id
              WHERE NOT g.archived AND ' . self::overdueSql('g', 'l') . "
                AND NOT EXISTS (SELECT 1 FROM notification_events e
                                 WHERE e.grievance_id = g.id AND e.level_id = l.id AND e.event_type = 'overdue_reminder'
                                   AND e.created_at > GREATEST(g.level_entered_at, now() - make_interval(days => l.overdue_days)))
              ORDER BY g.id"
        )->getResultArray();

        foreach ($rows as $r) {
            $scopeValue = self::scopeValue($r, $r['scope_type']);
            $scopeCondition = match ($r['scope_type']) {
                'department' => 'a.scope_id = ?',
                'hostel' => 'lower(btrim(a.hostel)) = lower(btrim(?))',
                default => 'a.scope_id IS NULL AND a.hostel IS NULL',
            };
            $holder = $db->query(
                "SELECT a.user_id FROM post_holders a JOIN users u ON u.id = a.user_id AND u.active
                  WHERE a.active AND a.post_id = ? AND {$scopeCondition}",
                $r['scope_type'] === null ? [$r['post_id']] : [$r['post_id'], $scopeValue]
            )->getRow('user_id');

            self::event((int) $r['id'], 'overdue_reminder', (int) $r['level_id'], [
                'reference_no'  => $r['reference_no'],
                'level'         => PostService::label((string) $r['post_name'], $r['scope_type'], $scopeValue),
                'days_at_level' => (int) $r['days_at_level'],
                'overdue_days'  => (int) $r['overdue_days'],
                'holder_user_id' => $holder === null ? null : (int) $holder,
            ]);
        }

        return count($rows);
    }

    // ------------------------------------------------------------------ helpers

    /** Department ID or hostel text that a scoped post resolves to for this grievance. */
    public static function scopeValue(array $g, ?string $scopeType): int|string|null
    {
        $v = match ($scopeType) {
            'department' => $g['department_id'] ?? null,
            'hostel'     => $g['hostel'] ?? null,
            default      => null,
        };

        return $v === null ? null : ($scopeType === 'department' ? (int) $v : (string) $v);
    }

    /** @param array<string,mixed> $post_holder */
    private static function assignmentScope(array $post_holder): int|string|null
    {
        return $post_holder['scope_type'] === 'hostel'
            ? (($post_holder['hostel'] ?? '') !== '' ? (string) $post_holder['hostel'] : null)
            : (($post_holder['scope_id'] ?? null) !== null ? (int) $post_holder['scope_id'] : null);
    }

    public static function version(int $grievanceId): int
    {
        return (int) Database::connect()->query('SELECT max(id) AS v FROM grievance_actions WHERE grievance_id = ?', [$grievanceId])->getRow('v');
    }

    /** Lock the grievance and check the user may act on it. Must run inside a transaction. */
    private static function lockForAction(int $grievanceId, int $userId, ?int $version): array
    {
        $db = Database::connect();
        if (! $db->query('SELECT id FROM grievances WHERE id = ? FOR UPDATE', [$grievanceId])->getRowArray()) {
            throw new RuntimeException('That grievance does not exist.');
        }

        $g = self::find($grievanceId);
        if ($g['status'] === 'disposed') {
            throw new RuntimeException("{$g['reference_no']} has already been disposed.");
        }
        if (! self::canAct($userId, $grievanceId)) {
            throw new RuntimeException("{$g['reference_no']} is no longer at a post you hold, so you cannot act on it.");
        }
        if ($version !== null && $version !== (int) $g['version']) {
            throw new RuntimeException("{$g['reference_no']} was updated while you were looking at it. Please read the latest history and try again.");
        }

        return $g;
    }

    /** @return array<string,mixed>|null the level after $levelNo in the type's matrix */
    private static function nextLevel(int $typeId, int $levelNo): ?array
    {
        return Database::connect()->query(
            'SELECT l.id, l.level_no, l.post_id, d.name AS post_name, d.scope_type
               FROM escalation_levels l JOIN posts d ON d.id = l.post_id
              WHERE l.type_id = ? AND l.level_no > ? ORDER BY l.level_no LIMIT 1',
            [$typeId, $levelNo]
        )->getRowArray();
    }

    /** @return array<string,mixed> */
    private static function levelRow(int $levelId): array
    {
        return Database::connect()->query(
            'SELECT l.*, d.name AS post_name, d.scope_type FROM escalation_levels l JOIN posts d ON d.id = l.post_id WHERE l.id = ?',
            [$levelId]
        )->getRowArray();
    }

    /**
     * Append a history row; returns its id.
     *
     * @param array{level_id:int,level_no:int,level_label:?string,post_id:int} $at
     */
    private static function record(int $grievanceId, array $at, ?int $userId, string $actorName, string $action, string $remark, ?int $toNo = null, ?string $toLabel = null, int $skipped = 0): int
    {
        return (int) Database::connect()->query(
            'INSERT INTO grievance_actions (grievance_id, level_id, level_no, level_label, post_id, actor_user_id, actor_name, action, remark, to_level_no, to_level_label, skipped_levels)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$grievanceId, $at['level_id'], $at['level_no'], $at['level_label'], $at['post_id'], $userId, $actorName, $action, $remark, $toNo, $toLabel, $skipped]
        )->getRow('id');
    }

    /** @param array<string,mixed> $payload */
    private static function event(int $grievanceId, string $type, ?int $levelId, array $payload): void
    {
        Database::connect()->query(
            'INSERT INTO notification_events (grievance_id, event_type, level_id, payload) VALUES (?, ?, ?, ?::jsonb)',
            [$grievanceId, $type, $levelId, json_encode($payload, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** Normalise line endings, strip control characters, trim. */
    private static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Control characters only (keeps zero-width joiners that Hindi and other scripts need)
        $text = preg_replace('/[^\P{Cc}\n\t]+/u', '', $text) ?? '';

        return trim($text);
    }

    /**
     * WHERE clause and parameters for an inbox tab ($g = grievances g, $l = escalation_levels l, left-joined).
     *
     * @return array{0:string,1:list<int>}
     */
    private static function inboxWhere(int $userId, string $tab): array
    {
        $atMyPost = 'l.id IS NOT NULL AND ' . self::holdsPostSql('g', 'l.post_id');
        $passed   = 'EXISTS (SELECT 1 FROM grievance_actions ga WHERE ga.grievance_id = g.id AND ga.post_id IS NOT NULL AND '
                  . self::holdsPostSql('g', 'ga.post_id') . ')';

        return match ($tab) {
            'waiting' => ["NOT g.archived AND g.status = 'awaiting_submitter' AND {$atMyPost}", [$userId]],
            'all'     => ['NOT g.archived', []],
            'earlier' => ["NOT g.archived AND {$passed} AND NOT (g.status <> 'disposed' AND {$atMyPost})", [$userId, $userId]],
            default   => ["NOT g.archived AND g.status = 'pending' AND {$atMyPost}", [$userId]],
        };
    }
}
