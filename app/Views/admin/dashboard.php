<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>
<h1 class="h4 mb-3">Admin dashboard</h1>
<div class="row g-3 mb-4">
    <?php foreach ($counts as $label => $value): ?>
        <div class="col-6 col-md"><div class="card"><div class="card-body py-3"><div class="h3 mb-0"><?= (int) $value ?></div><div class="small text-muted"><?= esc($label) ?></div></div></div></div>
    <?php endforeach; ?>
</div>

<h2 class="h6 text-muted">Grievances (not archived)</h2>
<div class="row g-3 mb-4" id="grievance-counts">
    <?php foreach (['Open' => $workflow['open'], 'Pending with authorities' => $workflow['pending'], 'Awaiting submitter' => $workflow['awaiting'],
                    'Overdue' => $workflow['overdue'], 'Disposed' => $workflow['disposed']] as $label => $value): ?>
        <div class="col-6 col-md"><div class="card <?= $label === 'Overdue' && $value > 0 ? 'border border-danger' : '' ?>"><div class="card-body py-3">
            <div class="h3 mb-0"><?= (int) $value ?></div><div class="small text-muted"><?= esc($label) ?></div></div></div></div>
    <?php endforeach; ?>
</div>

<?php if ($stuck !== []): ?>
    <div class="card border-danger mb-4" id="stuck">
        <div class="card-header bg-danger-subtle"><strong><?= (int) $workflow['vacant'] ?> grievance(s) waiting at a post with no holder</strong>
            <span class="small text-muted ms-2">Nobody can act on these until you assign a holder. Authorities are not told.</span></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light"><tr><th class="ps-3">Reference</th><th>Type</th><th>Waiting at</th><th>Since</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($stuck as $s): ?>
                    <tr>
                        <td class="ps-3"><?= esc($s['reference_no']) ?></td>
                        <td class="small"><?= esc($s['type_name']) ?></td>
                        <td class="small"><?= esc($s['post']) ?></td>
                        <td class="small"><?= esc(ist($s['level_entered_at'], false)) ?></td>
                        <td class="text-end pe-3"><a class="btn btn-sm btn-outline-primary" href="<?= site_url("admin/post-holders/{$s['post_id']}") ?>">Assign holder</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($workflow['vacant'] > count($stuck)): ?><tr><td colspan="5" class="ps-3 text-muted small">…and <?= (int) $workflow['vacant'] - count($stuck) ?> more.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($vacancies !== []): ?>
    <div class="card border-warning mb-4">
        <div class="card-header bg-warning-subtle"><strong><?= count($vacancies) ?> vacant post(s) in active routes</strong>
            <span class="small text-muted ms-2">Grievances reaching these posts wait until someone is assigned.</span></div>
        <ul class="list-group list-group-flush">
            <?php foreach (array_slice($vacancies, 0, 25) as $v): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><?= esc($v['label']) ?></span>
                    <a class="btn btn-sm btn-outline-primary" href="<?= site_url("admin/post-holders/{$v['post_id']}") ?>">Assign</a>
                </li>
            <?php endforeach; ?>
            <?php if (count($vacancies) > 25): ?><li class="list-group-item text-muted small">…and <?= count($vacancies) - 25 ?> more.</li><?php endif; ?>
        </ul>
    </div>
<?php else: ?>
    <div class="alert alert-success">Every post in an active route has a holder.</div>
<?php endif; ?>

<p class="text-muted small">The admin can view all grievances (module 7) but cannot dispose, transfer or reassign them.
    Overdue reminders are logged by <code>php spark portal:reminders</code> (schedule it once a day).</p>
<?= $this->endSection() ?>
