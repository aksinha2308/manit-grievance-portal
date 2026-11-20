<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>ERP<?= $this->endSection() ?>
<?= $this->section('content') ?>
<h1 class="h4 mb-2">ERP</h1>
<p class="text-muted small">The institute ERP and establishment department list are read-only for the portal. Here you check the connection and manage job titles.</p>
<?= $this->include('admin/erp/_tabs') ?>

<div class="card mb-4"><div class="card-body">
    <h2 class="h6">Connection</h2>
    <ul class="mb-0 small">
        <li>ERP main database: <?= $ping['main']['ok'] ? '<span class="badge text-bg-success">OK</span>' : '<span class="badge text-bg-danger">FAILED</span>' ?> <?= esc($ping['main']['detail']) ?></li>
        <li>ERP hostel database: <?= $ping['hostel']['ok'] ? '<span class="badge text-bg-success">OK</span>' : '<span class="badge text-bg-danger">FAILED</span>' ?> <?= esc($ping['hostel']['detail']) ?></li>
    </ul>
</div></div>

<?php if ($unmapped === null): ?>
    <div class="alert alert-danger">The ERP cannot be read, so codes cannot be checked. Fix the connection (see <code>.env</code> and <code>php spark portal:erp-test</code>) and reload.</div>
<?php else: ?>

<h2 class="h5">Departments</h2>
<p class="text-muted small">Department IDs and names come directly from <code>establishment.dpcode</code> and are available to all person roles and department-scoped posts. If an ERP department ID is missing from this table, correct the source data there.</p>
<?php if ($unmapped['departments'] !== []): ?>
    <div class="alert alert-warning small">
        <strong><?= count($unmapped['departments']) ?> ERP department value(s) are missing from establishment.dpcode:</strong>
        <?= esc(implode(', ', array_column($unmapped['departments'], 'code'))) ?>
    </div>
<?php else: ?>
    <div class="alert alert-success small">All department values found during the ERP scan match establishment.dpcode.</div>
<?php endif; ?>

<h2 class="h5">Job titles</h2>
<p class="text-muted small">Staff are Faculty or Non-Teaching only by their ERP job title. A person whose title is not listed cannot sign in. Faculty titles (Professor, Assistant Professor, ...) are kept as the person's rank.</p>
<div class="card mb-4"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Job title in the ERP</th><th>Staff with it</th><th>Treat as</th></tr></thead>
    <tbody>
    <?php foreach ($unmapped['titles'] as $title => $n): ?>
        <tr class="table-warning">
            <td><?= esc($title) ?> <span class="badge text-bg-warning">not listed</span></td>
            <td class="small"><?= (int) $n ?></td>
            <td>
                <?php if ($title !== '(blank)'): ?>
                <form action="<?= site_url('admin/erp/title') ?>" method="post" class="d-flex gap-2"><?= csrf_field() ?>
                    <input type="hidden" name="title" value="<?= esc($title) ?>">
                    <select class="form-select form-select-sm" name="role"><?php foreach ($roles as $k => $label): ?><option value="<?= esc($k) ?>"><?= esc($label) ?></option><?php endforeach; ?></select>
                    <button class="btn btn-sm btn-primary">Assign</button>
                </form>
                <?php else: ?><span class="small text-muted">These employees have no job title in the ERP: fix it there.</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php foreach ($titles as $t): ?>
        <tr>
            <td><?= esc($t['designation_text']) ?></td>
            <td class="small text-muted">listed</td>
            <td class="d-flex gap-2 align-items-center">
                <form action="<?= site_url('admin/erp/title') ?>" method="post" class="d-flex gap-2"><?= csrf_field() ?>
                    <input type="hidden" name="title" value="<?= esc($t['designation_text']) ?>">
                    <select class="form-select form-select-sm" name="role"><?php foreach ($roles as $k => $label): ?><option value="<?= esc($k) ?>" <?= $t['role'] === $k ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select>
                    <button class="btn btn-sm btn-outline-primary">Change</button>
                </form>
                <form action="<?= site_url('admin/erp/title/remove') ?>" method="post"><?= csrf_field() ?><input type="hidden" name="title" value="<?= esc($t['designation_text']) ?>"><button class="btn btn-sm btn-outline-secondary">Remove</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<?php endif; ?>
<?= $this->endSection() ?>
