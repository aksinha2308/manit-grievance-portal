<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Posts<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $base = site_url('admin/posts'); ?>

<h1 class="h4 mb-2">Posts</h1>
<p class="text-muted small">
    A post is a position such as Head of Department, Warden, Dean or Chairman. "One per department" and "One per hostel" posts
    (for example Head of Department) have a separate holder for each department or hostel, which you set under <em>Post holders</em>.
</p>

<div class="card mb-4">
    <div class="card-body">
        <form action="<?= $base ?>/add" method="post" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-5">
                <label class="form-label small">Name</label>
                <input class="form-control" name="name" maxlength="150" value="<?= esc(old('name', null, false)) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Scope</label>
                <select class="form-select" name="scope">
                    <?php foreach ($scopes as $value => $label): ?>
                        <option value="<?= esc($value) ?>" <?= old('scope') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto"><button class="btn btn-primary" type="submit">Add post</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr><th>Name</th><th>Scope</th><th>Status</th><th>In routes</th><th>Holders</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($posts as $d):
                $active   = is_true($d['active']);
                $director = is_true($d['is_director']);
                $locked   = (int) $d['route_uses'] > 0 || (int) $d['ever_held'] > 0; ?>
                <tr class="<?= $active ? '' : 'table-secondary' ?>">
                    <td>
                        <form action="<?= $base ?>/<?= $d['id'] ?>/update" method="post" id="d-<?= $d['id'] ?>"><?= csrf_field() ?></form>
                        <input class="form-control form-control-sm" form="d-<?= $d['id'] ?>" name="name" value="<?= esc($d['name']) ?>" maxlength="150" required>
                        <?php if ($director): ?><span class="badge text-bg-dark mt-1">Final authority</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($director || $locked): ?>
                            <input type="hidden" form="d-<?= $d['id'] ?>" name="scope" value="<?= esc($d['scope_type'] ?? '') ?>">
                            <span class="small"><?= esc($scopes[$d['scope_type'] ?? ''] ?? '') ?></span>
                        <?php else: ?>
                            <select class="form-select form-select-sm" form="d-<?= $d['id'] ?>" name="scope">
                                <?php foreach ($scopes as $value => $label): ?>
                                    <option value="<?= esc($value) ?>" <?= ($d['scope_type'] ?? '') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </td>
                    <td><?= $active ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
                    <td class="small"><?= (int) $d['route_uses'] ?></td>
                    <td class="small">
                        <?= (int) $d['holders'] ?>
                        <?php if ((int) $d['holders'] > 0 || (int) $d['route_uses'] > 0): ?>
                            <a href="<?= site_url('admin/post-holders/' . $d['id']) ?>" class="ms-1">manage</a>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-outline-primary" type="submit" form="d-<?= $d['id'] ?>">Save</button>
                        <?php if (! $director): ?>
                            <form action="<?= $base ?>/<?= $d['id'] ?>/toggle" method="post" class="d-inline">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $active ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                            <?php if (! $locked): ?>
                                <form action="<?= $base ?>/<?= $d['id'] ?>/delete" method="post" class="d-inline"
                                      onsubmit="return confirm('Delete <?= esc($d['name'], 'js') ?>?');">
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
</div>
<p class="text-muted small mt-3">
    A post used in a route cannot be deactivated or deleted: remove it from the matrix first. One that has ever had a holder can only be deactivated.
</p>
<?= $this->endSection() ?>
