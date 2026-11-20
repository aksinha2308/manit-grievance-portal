<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Form fields<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $base = site_url("admin/fields/{$role}"); $n = count($fields); ?>
<h1 class="h4 mb-2">Submission form</h1>
<p class="text-muted small">
    This is the form the person fills in to raise a grievance. Details from the master list (marked <span class="badge text-bg-secondary">auto</span>)
    are filled in automatically and cannot be edited by the submitter, except the contact number. Core fields can be renamed and moved but never hidden.
</p>

<ul class="nav nav-tabs mb-3">
    <?php foreach (['student', 'faculty', 'non_teaching'] as $r): ?>
        <li class="nav-item"><a class="nav-link<?= $r === $role ? ' active' : '' ?>" href="<?= site_url("admin/fields/{$r}") ?>"><?= esc(role_label($r)) ?></a></li>
    <?php endforeach; ?>
</ul>

<div class="card mb-4"><div class="table-responsive">
<table class="table align-middle mb-0">
    <thead><tr><th style="width:90px">Order</th><th>Label</th><th>Type</th><th>Required</th><th>Shown</th><th>Choices (dropdown)</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($fields as $i => $f):
        $core = is_true($f['is_core']); $fid = 'f-' . $f['id']; $visible = is_true($f['visible']); ?>
        <tr class="<?= $visible ? '' : 'table-secondary' ?>">
            <td class="text-nowrap">
                <form class="d-inline" method="post" action="<?= $base ?>/<?= $f['id'] ?>/move"><?= csrf_field() ?><input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-outline-secondary" <?= $i === 0 ? 'disabled' : '' ?> title="Move up">↑</button></form>
                <form class="d-inline" method="post" action="<?= $base ?>/<?= $f['id'] ?>/move"><?= csrf_field() ?><input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-outline-secondary" <?= $i === $n - 1 ? 'disabled' : '' ?> title="Move down">↓</button></form>
            </td>
            <td>
                <form id="<?= $fid ?>" method="post" action="<?= $base ?>/<?= $f['id'] ?>/update"><?= csrf_field() ?></form>
                <input class="form-control form-control-sm" form="<?= $fid ?>" name="label" value="<?= esc($f['label']) ?>" maxlength="150" required>
                <div class="mt-1">
                    <?php if (! $f['custom']): ?><span class="badge text-bg-secondary">auto</span><?php endif; ?>
                    <?php if ($core): ?><span class="badge text-bg-dark">core</span><?php endif; ?>
                    <?php if ($f['custom'] && (int) $f['usage'] > 0): ?><span class="badge text-bg-info"><?= (int) $f['usage'] ?> grievance(s)</span><?php endif; ?>
                </div>
            </td>
            <td class="small"><?= esc(\App\Libraries\FormFieldService::TYPES[$f['field_type']] ?? $f['field_type']) ?></td>
            <td>
                <?php if ($core): ?>
                    <?= is_true($f['mandatory']) ? 'Yes' : 'No' ?> <span class="text-muted small">(fixed)</span>
                <?php else: ?>
                    <input class="form-check-input" type="checkbox" form="<?= $fid ?>" name="mandatory" value="1" <?= is_true($f['mandatory']) ? 'checked' : '' ?>>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($core): ?>Yes <span class="text-muted small">(fixed)</span>
                <?php else: ?><input class="form-check-input" type="checkbox" form="<?= $fid ?>" name="visible" value="1" <?= $visible ? 'checked' : '' ?>><?php endif; ?>
            </td>
            <td style="min-width:200px">
                <?php if ($f['custom'] && $f['field_type'] === 'dropdown'): ?>
                    <textarea class="form-control form-control-sm" form="<?= $fid ?>" name="options" rows="3" placeholder="One choice per line"><?= esc(implode("\n", $f['options'])) ?></textarea>
                <?php elseif ($f['field_type'] === 'dropdown'): ?>
                    <span class="text-muted small">from the <?= $f['field_key'] === 'department' ? 'Departments' : ($f['field_key'] === 'hostel' ? 'Hostels' : 'Semesters') ?> list</span>
                <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-outline-primary" form="<?= $fid ?>">Save</button>
                <?php if ($f['custom'] && (int) $f['usage'] === 0): ?>
                    <form class="d-inline" method="post" action="<?= $base ?>/<?= $f['id'] ?>/delete" onsubmit="return confirm('Delete this field?');"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>

<div class="row g-4">
<div class="col-lg-6">
    <div class="card"><div class="card-header bg-white"><strong>Add a field</strong></div><div class="card-body">
        <form method="post" action="<?= $base ?>/add">
            <?= csrf_field() ?>
            <div class="mb-3"><label class="form-label small">Label (what the submitter sees)</label>
                <input class="form-control" name="label" maxlength="150" value="<?= esc(old('label', null, false)) ?>" required></div>
            <div class="mb-3"><label class="form-label small">Type</label>
                <select class="form-select" name="field_type" id="newType" required>
                    <?php foreach ($types as $k => $label): ?><option value="<?= $k ?>" <?= old('field_type') === $k ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?>
                </select></div>
            <div class="mb-3" id="newOptions" style="display:none"><label class="form-label small">Choices (one per line, at least two)</label>
                <textarea class="form-control" name="options" rows="4"><?= esc(old('options', null, false)) ?></textarea></div>
            <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="mandatory" value="1" id="newMand"><label class="form-check-label" for="newMand">Required</label></div>
            <button class="btn btn-primary">Add field</button>
        </form>
    </div></div>
</div>
<div class="col-lg-6">
    <div class="card"><div class="card-header bg-white"><strong>Preview</strong> <span class="small text-muted">as the submitter will see it</span></div><div class="card-body">
        <?php foreach ($fields as $f): if (! is_true($f['visible'])) { continue; } $auto = ! $f['custom'] && $f['field_key'] !== 'contact'; ?>
            <div class="mb-2">
                <label class="form-label small mb-0"><?= esc($f['label']) ?><?= is_true($f['mandatory']) ? ' <span class="text-danger">*</span>' : '' ?></label>
                <?php if ($f['field_type'] === 'dropdown' && $f['custom']): ?>
                    <select class="form-select form-select-sm" disabled><option>Choose…</option><?php foreach ($f['options'] as $o): ?><option><?= esc($o) ?></option><?php endforeach; ?></select>
                <?php else: ?>
                    <input class="form-control form-control-sm" disabled type="<?= $f['field_type'] === 'date' ? 'date' : 'text' ?>" value="<?= $auto ? 'filled from the master list' : '' ?>">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <hr class="my-2">
        <div class="small text-muted">Always on the form: <strong>Subject</strong> *, <strong>Description</strong> *, up to 3 attachments, and the OTP check.</div>
    </div></div>
</div>
</div>
<script>
(function () {
    var t = document.getElementById('newType'), o = document.getElementById('newOptions');
    function sync() { o.style.display = t.value === 'dropdown' ? '' : 'none'; }
    t.addEventListener('change', sync); sync();
})();
</script>
<?= $this->endSection() ?>
