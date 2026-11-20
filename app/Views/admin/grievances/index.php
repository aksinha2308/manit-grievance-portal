<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Grievances<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
use App\Libraries\GrievanceSearch;
use App\Libraries\WorkflowService;

$f   = $filters;
$sel = static fn (string $k, $v) => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
$qs  = static fn (array $extra) => http_build_query(array_filter(array_merge($f, $extra), static fn ($v) => $v !== null && $v !== ''));
$active = count(array_diff_key($f, ['archived' => 1])) > 0 || ($f['archived'] ?? 'no') !== 'no';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Grievances</h1>
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" type="button">Export <?= (int) $total ?> grievance(s)</button>
        <ul class="dropdown-menu dropdown-menu-end" id="export-menu">
            <li><a class="dropdown-item" href="<?= site_url('admin/grievances/export') . '?' . esc($qs(['kind' => 'grievances', 'format' => 'xlsx'])) ?>">Grievances – Excel</a></li>
            <li><a class="dropdown-item" href="<?= site_url('admin/grievances/export') . '?' . esc($qs(['kind' => 'grievances', 'format' => 'csv'])) ?>">Grievances – CSV</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= site_url('admin/grievances/export') . '?' . esc($qs(['kind' => 'history', 'format' => 'xlsx'])) ?>">Full history – Excel</a></li>
            <li><a class="dropdown-item" href="<?= site_url('admin/grievances/export') . '?' . esc($qs(['kind' => 'history', 'format' => 'csv'])) ?>">Full history – CSV</a></li>
        </ul>
    </div>
</div>

<form class="card mb-3" method="get" action="<?= site_url('admin/grievances') ?>" id="filters">
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small mb-1" for="q">Search</label>
            <input class="form-control form-control-sm" id="q" name="q" value="<?= esc($f['q'] ?? '') ?>" maxlength="100" placeholder="Reference, Scholar No. / Employee Code, or name">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="role">Role</label>
            <select class="form-select form-select-sm" id="role" name="role">
                <option value="">All</option>
                <?php foreach (['student', 'faculty', 'non_teaching'] as $r): ?><option value="<?= $r ?>" <?= $sel('role', $r) ?>><?= esc(role_label($r)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1" for="type">Type</label>
            <select class="form-select form-select-sm" id="type" name="type">
                <option value="">All types</option>
                <?php foreach ($options['types'] as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $sel('type', $t['id']) ?>><?= esc($t['name']) ?> (<?= esc(role_label($t['role'])) ?>)</option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1" for="status">Status</label>
            <select class="form-select form-select-sm" id="status" name="status">
                <option value="">Any</option>
                <?php foreach (GrievanceSearch::STATUSES as $k => $label): ?><option value="<?= $k ?>" <?= $sel('status', $k) ?>><?= esc($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1" for="department">Department</label>
            <select class="form-select form-select-sm" id="department" name="department">
                <option value="">All</option>
                <?php foreach ($options['departments'] as $d): ?><option value="<?= (int) $d['id'] ?>" <?= $sel('department', $d['id']) ?>><?= esc($d['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="hostel">Hostel</label>
            <select class="form-select form-select-sm" id="hostel" name="hostel">
                <option value="">All</option>
                <?php foreach ($options['hostels'] as $h): ?><option value="<?= esc($h['name']) ?>" <?= $sel('hostel', $h['name']) ?>><?= esc($h['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1" for="post">Now with (post)</label>
            <select class="form-select form-select-sm" id="post" name="post">
                <option value="">Any</option>
                <?php foreach ($options['posts'] as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $sel('post', $p['id']) ?>><?= esc($p['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="from">Submitted from</label>
            <input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= esc($f['from'] ?? '') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="to">to</label>
            <input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= esc($f['to'] ?? '') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="archived">Archived</label>
            <select class="form-select form-select-sm" id="archived" name="archived">
                <option value="no" <?= $sel('archived', 'no') ?>>Not archived</option>
                <option value="yes" <?= $sel('archived', 'yes') ?>>Archived only</option>
                <option value="all" <?= $sel('archived', 'all') ?>>Both</option>
            </select>
        </div>
        <div class="col-auto">
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" id="overdue" name="overdue" value="1" <?= isset($f['overdue']) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="overdue">Overdue only</label>
            </div>
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-primary" type="submit">Apply</button>
            <?php if ($active): ?><a class="btn btn-sm btn-link" href="<?= site_url('admin/grievances') ?>">Clear</a><?php endif; ?>
        </div>
    </div>
</form>

<div class="card">
    <?php if ($rows === []): ?>
        <div class="card-body text-muted"><?= $active ? 'No grievance matches these filters.' : 'No grievances yet.' ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Reference</th><th>Submitted</th><th>Submitter</th><th>Type</th><th>Subject</th><th>Status</th><th>Now with / last at</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr class="<?= $r['overdue'] ? 'table-danger' : '' ?>">
                        <td class="text-nowrap">
                            <a href="<?= site_url('admin/grievances/' . (int) $r['id']) ?>"><?= esc($r['reference_no']) ?></a>
                            <?php if ($r['overdue']): ?><span class="badge text-bg-danger ms-1">Overdue</span><?php endif; ?>
                            <?php if ($r['archived']): ?><span class="badge text-bg-dark ms-1">Archived</span><?php endif; ?>
                        </td>
                        <td class="small text-nowrap"><?= esc(ist($r['created_at'], false)) ?></td>
                        <td class="small"><?= esc($r['submitter_name']) ?><div class="text-muted"><?= esc($r['id_code']) ?> · <?= esc(role_label($r['role'])) ?></div></td>
                        <td class="small"><?= esc($r['type_name']) ?></td>
                        <td><?= esc(mb_strimwidth((string) $r['subject'], 0, 60, '…')) ?></td>
                        <td class="small"><?= esc(WorkflowService::STATUS_LABELS[$r['status']] ?? $r['status']) ?></td>
                        <td class="small"><?= esc($r['post']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center small">
            <span><?= (int) $total ?> grievance(s)<?= $pages > 1 ? ' · page ' . (int) $page . ' of ' . (int) $pages : '' ?></span>
            <span>
                <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/grievances') . '?' . esc($qs(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
                <?php if ($page < $pages): ?><a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/grievances') . '?' . esc($qs(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
            </span>
        </div>
    <?php endif; ?>
</div>
<p class="small text-muted mt-2">View only: the admin cannot act on grievances. Every export is recorded in the audit log.</p>
<?= $this->endSection() ?>
