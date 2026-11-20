<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>My grievances<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$tabs = [
    'action'  => ['Needs action', $counts['action']],
    'waiting' => ['Waiting for submitter', $counts['waiting']],
    'earlier' => ['Earlier', $counts['earlier']],
];
if ($director) {
    $tabs['all'] = ['All grievances', $counts['all'] ?? 0];
}
$filtered = $filters['q'] !== '' || $filters['type_id'] > 0 || $filters['overdue'];
// Query string that keeps the current tab and filters (for paging and tab links)
$qs = static function (array $change) use ($tab, $filters): string {
    $p = array_filter([
        'tab'     => $tab,
        'q'       => $filters['q'],
        'type'    => $filters['type_id'] ?: null,
        'overdue' => $filters['overdue'] ? '1' : null,
    ] + [], static fn ($v) => $v !== null && $v !== '');
    $p = array_filter(array_merge($p, $change), static fn ($v) => $v !== null && $v !== '');

    return site_url('authority') . ($p === [] ? '' : '?' . http_build_query($p));
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">Welcome, <?= esc($user['full_name']) ?></h1>
        <?php if ($posts !== []): ?>
            <div class="small text-muted">
                Your post(s):
                <?= esc(implode(' · ', array_map(static fn ($p) => $p['post_name'] . ($p['scope_name'] ? " ({$p['scope_name']})" : ''), $posts))) ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($director): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= site_url('authority/report') ?>" id="report-link">Summary report</a>
    <?php endif; ?>
    <?php if ($counts['overdue'] > 0): ?>
        <div class="alert alert-danger py-2 px-3 mb-0" id="overdue-banner">
            <strong><?= (int) $counts['overdue'] ?></strong> grievance(s) are overdue. Please act on them first.
        </div>
    <?php endif; ?>
</div>

<?php if ($postCounts !== []): ?>
    <div class="row g-2 mb-3" id="post-counts">
        <?php foreach ($postCounts as $pc): ?>
            <div class="col-sm-6 col-lg-4">
                <div class="card h-100 <?= $pc['overdue'] > 0 ? 'border border-danger' : '' ?>"><div class="card-body py-2 small">
                    <div class="fw-semibold"><?= esc($pc['label']) ?></div>
                    <span class="me-2"><?= (int) $pc['pending'] ?> pending</span>
                    <span class="me-2 text-muted"><?= (int) $pc['waiting'] ?> waiting</span>
                    <?php if ($pc['overdue'] > 0): ?><span class="text-danger"><?= (int) $pc['overdue'] ?> overdue</span><?php endif; ?>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($posts === []): ?>
    <div class="card"><div class="card-body p-4">
        <p class="text-muted mb-0">No posts have been assigned to your account yet. Please contact the portal admin.</p>
    </div></div>
<?php endif; ?>

<?php if ($posts !== [] || $counts['earlier'] > 0): ?>
<form class="row g-2 align-items-end mb-3" method="get" action="<?= site_url('authority') ?>" id="filters">
    <input type="hidden" name="tab" value="<?= esc($tab) ?>">
    <div class="col-sm-5 col-lg-4">
        <label class="form-label small mb-1" for="q">Search</label>
        <input class="form-control form-control-sm" id="q" name="q" value="<?= esc($filters['q']) ?>" placeholder="Reference number or submitter name" maxlength="100">
    </div>
    <div class="col-sm-4 col-lg-3">
        <label class="form-label small mb-1" for="type">Type</label>
        <select class="form-select form-select-sm" id="type" name="type">
            <option value="">All types</option>
            <?php foreach ($types as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === $filters['type_id'] ? 'selected' : '' ?>><?= esc($t['name']) ?> (<?= esc(role_label($t['role'])) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="overdue" name="overdue" value="1" <?= $filters['overdue'] ? 'checked' : '' ?>>
            <label class="form-check-label small" for="overdue">Overdue only</label>
        </div>
    </div>
    <div class="col-auto">
        <button class="btn btn-sm btn-primary" type="submit">Apply</button>
        <?php if ($filtered): ?><a class="btn btn-sm btn-link" href="<?= site_url('authority?tab=' . $tab) ?>">Clear</a><?php endif; ?>
    </div>
    <?php if ($total > 0):
        $ex = static fn (string $kind, string $format) => site_url('authority/export') . '?' . http_build_query(array_filter([
            'tab' => $tab, 'q' => $filters['q'], 'type' => $filters['type_id'] ?: null, 'overdue' => $filters['overdue'] ? '1' : null,
            'kind' => $kind, 'format' => $format,
        ], static fn ($v) => $v !== null && $v !== '')); ?>
        <div class="col-auto ms-auto dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" type="button">Export this list (<?= (int) $total ?>)</button>
            <ul class="dropdown-menu dropdown-menu-end" id="export-menu">
                <li><a class="dropdown-item" href="<?= esc($ex('grievances', 'xlsx')) ?>">Grievances – Excel</a></li>
                <li><a class="dropdown-item" href="<?= esc($ex('grievances', 'csv')) ?>">Grievances – CSV</a></li>
                <li><a class="dropdown-item" href="<?= esc($ex('history', 'xlsx')) ?>">Full history – Excel</a></li>
                <li><a class="dropdown-item" href="<?= esc($ex('history', 'csv')) ?>">Full history – CSV</a></li>
            </ul>
        </div>
    <?php endif; ?>
</form>

<ul class="nav nav-tabs">
    <?php foreach ($tabs as $key => [$label, $n]): ?>
        <li class="nav-item">
            <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= esc($qs(['tab' => $key, 'page' => null])) ?>">
                <?= esc($label) ?> <span class="badge <?= $key === 'action' && $n > 0 ? 'text-bg-primary' : 'text-bg-secondary' ?>"><?= (int) $n ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
<div class="card rounded-top-0">
    <?php if ($tab === 'all'): ?>
        <div class="card-body py-2 small text-muted border-bottom">Every grievance in the portal, view only. You can act on a grievance only when it reaches the Director.</div>
    <?php endif; ?>
    <?php if ($rows === []): ?>
        <div class="card-body text-muted">
            <?php if ($filtered): ?>No grievance matches these filters.
            <?php elseif ($tab === 'action'): ?>Nothing needs your action right now.
            <?php elseif ($tab === 'waiting'): ?>No grievance is waiting for a submitter's reply.
            <?php else: ?>Nothing here yet.<?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Reference</th><th>Subject</th><th>Type</th><th>Submitted by</th>
                    <?php if (in_array($tab, ['earlier', 'all'], true)): ?><th>Status</th><?php else: ?><th>At post</th><th>Time at this level</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr class="<?= $r['overdue'] ? 'table-danger' : '' ?>">
                        <td class="text-nowrap">
                            <a href="<?= site_url('authority/grievances/' . (int) $r['id']) ?>"><?= esc($r['reference_no']) ?></a>
                            <?php if ($r['overdue']): ?><span class="badge text-bg-danger ms-1">Overdue</span><?php endif; ?>
                            <?php if ($tab === 'action' && in_array($r['last_action'], ['submitter_reply', 'late_reply'], true)): ?>
                                <span class="badge text-bg-info ms-1"><?= $r['last_action'] === 'late_reply' ? 'Late reply' : 'Replied' ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= esc(mb_strimwidth((string) $r['subject'], 0, 70, '…')) ?></td>
                        <td class="small"><?= esc($r['type_name']) ?><div class="text-muted"><?= esc(role_label($r['role'])) ?></div></td>
                        <td class="small"><?= esc($r['submitter_name']) ?></td>
                        <?php if (in_array($tab, ['earlier', 'all'], true)): ?>
                            <td class="small">
                                <?php if ($r['status'] === 'disposed'): ?>
                                    <span class="badge text-bg-success">Disposed</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary"><?= esc(\App\Libraries\WorkflowService::STATUS_LABELS[$r['status']]) ?></span>
                                    <div class="text-muted"><?= esc($r['level_label']) ?></div>
                                <?php endif; ?>
                            </td>
                        <?php else: ?>
                            <td class="small"><?= esc($r['level_label']) ?></td>
                            <td class="small text-nowrap">
                                <?= esc(duration_text($r['active_seconds'])) ?>
                                <div class="text-muted">limit <?= (int) $r['overdue_days'] ?> days</div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <div class="card-footer d-flex justify-content-between align-items-center small">
                <span><?= (int) $total ?> grievance(s) · page <?= (int) $page ?> of <?= (int) $pages ?></span>
                <span>
                    <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= esc($qs(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
                    <?php if ($page < $pages): ?><a class="btn btn-sm btn-outline-secondary" href="<?= esc($qs(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
                </span>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<p class="small text-muted mt-2">Time at a level does not count the days a grievance spends waiting for the submitter's reply.</p>
<?php endif; ?>
<?= $this->endSection() ?>
