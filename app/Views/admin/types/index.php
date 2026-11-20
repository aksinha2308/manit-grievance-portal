<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Grievance types<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $base = site_url('admin/types'); ?>

<h1 class="h4 mb-2">Grievance types</h1>
<p class="text-muted small">
    Each type has its own transfer matrix. The short code is used in reference numbers
    (for example <code>MANIT-2026-<strong>ACD</strong>-000001</code>) and must be unique.
</p>

<?php foreach ($groups as $role => $group): ?>
    <div class="card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong><?= esc(role_label($role)) ?> grievances</strong>
            <?php if (! $group['multiple']): ?>
                <span class="small text-muted">One type ("General") in this version. Rename it and edit its matrix; more types can be enabled in Settings.</span>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th><th style="width:110px">Short code</th>
                        <?php if ($role === 'student'): ?><th style="width:130px">Needs hostel</th><?php endif; ?>
                        <th style="width:80px">Order</th><th>Status</th><th>Levels</th><th>Grievances</th><th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($group['types'] as $t): $active = is_true($t['active']); $fid = 't-' . $t['id']; ?>
                    <tr class="<?= $active ? '' : 'table-secondary' ?>">
                        <td>
                            <form action="<?= $base ?>/<?= $t['id'] ?>/update" method="post" id="<?= $fid ?>"><?= csrf_field() ?></form>
                            <input class="form-control form-control-sm" form="<?= $fid ?>" name="name" value="<?= esc($t['name']) ?>" maxlength="150" required>
                        </td>
                        <td><input class="form-control form-control-sm text-uppercase" form="<?= $fid ?>" name="code" value="<?= esc($t['code']) ?>" maxlength="4" required></td>
                        <?php if ($role === 'student'): ?>
                            <td>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" form="<?= $fid ?>" name="requires_hostel" value="1" id="h-<?= $t['id'] ?>" <?= is_true($t['requires_hostel']) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="h-<?= $t['id'] ?>">Hostel route</label>
                                </div>
                            </td>
                        <?php endif; ?>
                        <td><input class="form-control form-control-sm" form="<?= $fid ?>" name="sort_order" type="number" value="<?= (int) $t['sort_order'] ?>"></td>
                        <td><?= $active ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
                        <td class="small"><?= (int) $t['level_count'] ?></td>
                        <td class="small"><?= (int) $t['grievance_count'] ?></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-primary" type="submit" form="<?= $fid ?>">Save</button>
                            <a class="btn btn-sm btn-primary" href="<?= $base ?>/<?= $t['id'] ?>/matrix">Matrix</a>
                            <?php if ($group['multiple']): ?>
                                <form action="<?= $base ?>/<?= $t['id'] ?>/toggle" method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $active ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <?php if ((int) $t['grievance_count'] === 0): ?>
                                    <form action="<?= $base ?>/<?= $t['id'] ?>/delete" method="post" class="d-inline"
                                          onsubmit="return confirm('Delete <?= esc($t['name'], 'js') ?> and its matrix?');">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($group['multiple']): ?>
            <div class="card-body border-top">
                <form action="<?= $base ?>/add" method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="role" value="<?= $role ?>">
                    <div class="col-md-4">
                        <label class="form-label small">New type name</label>
                        <input class="form-control" name="name" maxlength="150" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Short code</label>
                        <input class="form-control text-uppercase" name="code" maxlength="4" placeholder="e.g. LIB" required>
                    </div>
                    <?php if ($role === 'student'): ?>
                        <div class="col-md-2">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="requires_hostel" value="1" id="nh-<?= $role ?>">
                                <label class="form-check-label small" for="nh-<?= $role ?>">Needs hostel</label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="col-auto"><button class="btn btn-primary" type="submit">Add type</button></div>
                </form>
                <div class="form-text">A new type starts with a route that ends at the Director. Add the levels it needs on the next screen.</div>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<p class="text-muted small">
    "Needs hostel" marks the type whose route starts with the student's Warden: a student with no hostel on record cannot file it.
    Changing a short code affects new grievances only: reference numbers already issued never change.
</p>
<?= $this->endSection() ?>
