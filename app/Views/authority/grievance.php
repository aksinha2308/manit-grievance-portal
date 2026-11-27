<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?><?= esc($g['reference_no']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
use App\Libraries\WorkflowService;

$s       = $g['submitter'];
$labels  = $s['labels'] ?? [];
$badge   = ['pending' => 'text-bg-primary', 'awaiting_submitter' => 'text-bg-warning', 'disposed' => 'text-bg-success'][$g['status']] ?? 'text-bg-secondary';
$icons   = ['submit' => 'secondary', 'escalate' => 'info', 'dispose' => 'success', 'request_info' => 'warning', 'submitter_reply' => 'primary', 'late_reply' => 'primary', 'reroute' => 'dark'];
$picked  = old('action', null, false) ?? '';
$adminView = $adminView ?? false;                 // the admin's read-only view reuses this page
$base      = $base ?? 'authority/grievances';
$back      = $back ?? 'authority';
?>
<p class="mb-2"><a href="<?= site_url($back) ?>">&larr; Back to <?= $adminView ? 'all grievances' : 'my grievances' ?></a></p>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0" id="ref"><?= esc($g['reference_no']) ?></h1>
    <span class="badge <?= $badge ?>" id="status"><?= esc(WorkflowService::STATUS_LABELS[$g['status']] ?? $g['status']) ?></span>
    <?php if ($g['overdue']): ?><span class="badge text-bg-danger">Overdue</span><?php endif; ?>
    <?php if (is_true($g['archived'])): ?><span class="badge text-bg-dark" id="archived-badge">Archived <?= esc(ist($g['archived_at'], false)) ?></span><?php endif; ?>
</div>

<?php if ($related !== []): ?>
    <?php $sameType = count(array_filter($related, static fn ($r) => $r['same_type'])); $open = count(array_filter($related, static fn ($r) => $r['status'] !== 'disposed')); ?>
    <div class="alert alert-info" id="related">
        <div class="mb-1"><strong>This person has <?= count($related) ?> other grievance(s) in the last 12 months</strong>
            (<?= (int) $open ?> still open<?= $sameType > 0 ? ", {$sameType} of the same type" : '' ?>).</div>
        <ul class="mb-0 small ps-3">
            <?php foreach ($related as $r): ?>
                <li>
                    <?php if ($r['can_open']): ?>
                        <a href="<?= site_url($base . '/' . (int) $r['id']) ?>"><?= esc($r['reference_no']) ?></a>
                    <?php else: ?>
                        <?= esc($r['reference_no']) ?>
                    <?php endif; ?>
                    · <?= esc($r['type_name']) ?><?= $r['same_type'] ? ' <span class="badge text-bg-secondary">same type</span>' : '' ?>
                    · <?= esc(WorkflowService::STATUS_LABELS[$r['status']] ?? $r['status']) ?>
                    · <?= esc(ist($r['created_at'], false)) ?>
                    <?php if ($r['subject'] !== null): ?>· <span class="text-muted"><?= esc(mb_strimwidth((string) $r['subject'], 0, 60, '…')) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-body">
                <div class="small text-muted mb-1"><?= esc($g['type_name']) ?> · <?= esc(role_label($g['role'])) ?> · submitted <?= esc(ist($g['created_at'])) ?></div>
                <h2 class="h5"><?= esc($g['subject']) ?></h2>
                <div class="mb-0" style="white-space: pre-wrap"><?= esc($g['description']) ?></div>
                <?php if ($g['custom'] !== []): ?>
                    <table class="table table-sm mt-3 mb-0">
                        <?php foreach ($g['custom'] as $c): ?>
                            <tr><th class="w-50 fw-normal text-muted"><?= esc($c['label'] ?? '') ?></th><td><?= esc($c['value'] ?? '') ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
                <?php if ($attachments !== []): ?>
                    <div class="mt-3">
                        <div class="small text-muted mb-1">Attachments</div>
                        <?php foreach ($attachments as $a): ?>
                            <a class="btn btn-sm btn-outline-secondary me-1 mb-1" href="<?= site_url("{$base}/{$g['id']}/attachments/{$a['id']}") ?>">
                                <?= esc($a['original_name']) ?> <span class="text-muted">(<?= esc(file_size_text($a['size_bytes'])) ?>)</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-white"><strong>History</strong></div>
            <ul class="list-group list-group-flush" id="history">
                <?php foreach ($history as $h): ?>
                    <li class="list-group-item">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div>
                                <span class="badge text-bg-<?= $icons[$h['action']] ?? 'secondary' ?>"><?= esc(WorkflowService::ACTION_LABELS[$h['action']] ?? $h['action']) ?></span>
                                <?php if ($h['action'] === 'escalate' || $h['action'] === 'reroute'): ?>
                                    <span class="small"><?= $h['action'] === 'escalate' && (int) $h['skipped_levels'] > 0 ? 'directly ' : '' ?>to <strong><?= esc($h['to_level_label'] ?? $h['level_label'] ?? '') ?></strong></span>
                                    <?php if ($h['action'] === 'escalate' && (int) $h['skipped_levels'] > 0): ?>
                                        <span class="badge text-bg-light border">skipped <?= (int) $h['skipped_levels'] ?> level<?= (int) $h['skipped_levels'] === 1 ? '' : 's' ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <span class="small text-muted"><?= esc(ist($h['created_at'])) ?></span>
                        </div>
                        <div class="small mt-1">
                            <?= esc($h['actor_name']) ?>
                            <?php if ($h['level_label'] && $h['action'] !== 'reroute'): ?>
                                <span class="text-muted">· <?= $h['action'] === 'submit' ? 'sent to ' : (in_array($h['action'], ['submitter_reply', 'late_reply'], true) ? 'to ' : 'as ') ?><?= esc($h['level_label']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($h['action'] === 'late_reply'): ?>
                            <div class="small text-muted mt-1">Answer to a question asked at an earlier level, received after the grievance was transferred.</div>
                        <?php endif; ?>
                        <?php if ($h['remark'] !== null && $h['remark'] !== ''): ?>
                            <div class="mt-1 p-2 bg-light rounded small" style="white-space: pre-wrap"><?= esc($h['remark']) ?></div>
                        <?php endif; ?>
                        <?php foreach ($h['attachments'] as $a): ?>
                            <a class="btn btn-sm btn-outline-secondary mt-1 me-1" href="<?= site_url("{$base}/{$g['id']}/attachments/{$a['id']}") ?>"><?= esc($a['original_name']) ?></a>
                        <?php endforeach; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-white"><strong>Submitted by</strong></div>
            <table class="table table-sm mb-0">
                <tr><th class="ps-3 fw-normal text-muted w-40"><?= esc($s['id_label'] ?? 'ID') ?></th><td><?= esc($s['id_code'] ?? $g['id_code']) ?></td></tr>
                <tr><th class="ps-3 fw-normal text-muted"><?= esc($labels['name'] ?? 'Name') ?></th><td><?= esc($s['name'] ?? '') ?></td></tr>
                <tr><th class="ps-3 fw-normal text-muted"><?= esc($labels['contact'] ?? 'Contact No.') ?></th><td><?= esc($s['contact'] ?? '') ?></td></tr>
                <tr><th class="ps-3 fw-normal text-muted"><?= esc($labels['department'] ?? 'Department') ?></th><td><?= esc($s['department'] ?? '') ?></td></tr>
                <?php if (! empty($s['semester'])): ?>
                    <tr><th class="ps-3 fw-normal text-muted"><?= esc($labels['semester'] ?? 'Semester') ?></th><td><?= esc($s['semester']) ?></td></tr>
                <?php endif; ?>
                <?php if ($g['role'] === 'student'): ?>
                    <tr><th class="ps-3 fw-normal text-muted"><?= esc($labels['hostel'] ?? 'Hostel') ?></th><td><?= esc($s['hostel'] ?? 'Day scholar') ?></td></tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="card mb-3">
            <div class="card-body small">
                <?php if ($g['status'] === 'disposed'): ?>
                    Disposed on <?= esc(ist($g['disposed_at'])) ?><?= $g['level_label'] ? ' by ' . esc($g['level_label']) : '' ?>.
                <?php else: ?>
                    <div>Now at: <strong><?= esc($g['level_label']) ?></strong> (level <?= (int) $g['level_no'] ?>)</div>
                    <div>Time at this level: <?= esc(duration_text($g['active_seconds'])) ?> of <?= (int) $g['overdue_days'] ?> days
                        <?= $g['status'] === 'awaiting_submitter' ? '<span class="text-muted">(paused while waiting for the submitter)</span>' : '' ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canAct): ?>
            <div class="card border-primary" id="action-panel">
                <div class="card-header bg-primary-subtle"><strong>Take action</strong>
                    <span class="small text-muted d-block">as <?= esc($g['level_label']) ?></span></div>
                <div class="card-body">
                    <?php if ($g['status'] === 'awaiting_submitter'): ?>
                        <div class="alert alert-warning small py-2" id="waiting-note">
                            Waiting for the submitter's reply to your question (asked <?= esc(duration_text(time() - strtotime((string) $g['paused_since']))) ?> ago).
                            You can still act without the reply:
                            <ul class="mb-0 mt-1 ps-3">
                                <li><strong>Transfer</strong>: the question is withdrawn. If the submitter answers later, the answer is still added to the history for the next level.</li>
                                <li><strong>Dispose</strong>: the grievance is closed and no reply can be added afterwards.</li>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <form method="post" action="<?= site_url("authority/grievances/{$g['id']}/act") ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="version" value="<?= (int) $g['version'] ?>">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="action" id="a-dispose" value="dispose" required <?= $picked === 'dispose' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="a-dispose"><strong>Dispose</strong> <span class="small text-muted">— resolve and close the grievance</span></label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="action" id="a-escalate" value="escalate" <?= $next === null ? 'disabled' : '' ?> <?= $picked === 'escalate' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="a-escalate"><strong>Transfer</strong>
                                <span class="small text-muted">— <?= $next === null ? 'not possible: this is the last level' : (count($targets) > 1 ? 'send to the next level or choose a later one' : 'send to ' . esc($next)) ?></span></label>
                            <?php if (count($targets) > 1): ?>
                                <select class="form-select form-select-sm mt-1 w-auto" name="target_level" id="target-level" aria-label="Transfer to">
                                    <?php foreach ($targets as $t): ?>
                                        <option value="<?= $t['id'] ?>" <?= (string) old('target_level') === (string) $t['id'] || ($t['is_next'] && old('target_level') === null) ? 'selected' : '' ?>>
                                            <?= esc($t['label']) ?><?= $t['is_next'] ? ' (next level)' : ' (skips ' . ($t['level_no'] - (int) $g['level_no'] - 1) . ' level' . ($t['level_no'] - (int) $g['level_no'] - 1 === 1 ? '' : 's') . ')' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="action" id="a-info" value="request_info" <?= $g['status'] === 'awaiting_submitter' ? 'disabled' : '' ?> <?= $picked === 'request_info' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="a-info"><strong>Request information</strong>
                                <span class="small text-muted">— ask the submitter a question; the overdue clock pauses</span></label>
                        </div>
                        <label class="form-label" for="remark">Remark <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="remark" name="remark" rows="5" maxlength="<?= WorkflowService::REMARK_MAX ?>" required
                                  placeholder="Decision, reason for the transfer, or the question for the submitter"><?= esc(old('remark', null, false) ?? '') ?></textarea>
                        <div class="form-text"><strong>The submitter can read this remark</strong> when tracking the grievance (your post is shown, not your name).
                            It is kept permanently in the history. For "Request information", write the question the submitter has to answer.</div>
                        <?php if ($g['status'] === 'awaiting_submitter'): ?>
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" name="confirm_waiting" id="confirm-waiting" value="1">
                                <label class="form-check-label small" for="confirm-waiting">I understand the submitter has not replied yet
                                    (needed only to transfer or dispose now)</label>
                            </div>
                        <?php endif; ?>
                        <button class="btn btn-primary mt-3" type="submit">Submit action</button>
                    </form>
                </div>
            </div>
        <?php elseif ($adminView): ?>
            <div class="card mb-3" id="admin-panel"><div class="card-body small">
                <p class="mb-2 text-muted">View only: the admin cannot act on grievances.</p>
                <?php if (is_true($g['archived'])): ?>
                    <form method="post" action="<?= site_url("admin/grievances/{$g['id']}/restore") ?>" class="m-0">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-secondary" type="submit">Restore from archive</button>
                    </form>
                <?php elseif ($archivable ?? false): ?>
                    <form method="post" action="<?= site_url("admin/grievances/{$g['id']}/archive") ?>" class="m-0">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-secondary" type="submit">Archive</button>
                        <span class="text-muted ms-1">possible once disposed more than <?= (int) \App\Libraries\ArchiveService::years() ?> year(s) ago</span>
                    </form>
                <?php endif; ?>
            </div></div>
        <?php elseif ($g['status'] !== 'disposed'): ?>
            <div class="alert alert-secondary small"><?= $isDirector ? 'Director oversight: you can view this grievance; you can act on it when it reaches the Director.' : 'You can view this grievance only. You can act on a grievance only while it is at a post you hold.' ?></div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
