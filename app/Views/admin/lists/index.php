<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?><?= esc($def['title']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $base = site_url("admin/lists/{$entity}"); ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= esc($def['title']) ?></h1>
</div>

<?php if ($entity === 'departments'): ?>
    <p class="text-muted small">
        Department IDs and names are read from <code>establishment.dpcode</code>. All listed departments are available
        to students, faculty, non-teaching staff, and department-scoped posts. Update the source table to change this list.
    </p>
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>ID</th><th>Name</th><th>Portal records</th></tr></thead>
        <tbody>
        <?php if ($items === []): ?><tr><td colspan="3" class="text-muted p-4">No departments are listed in establishment.dpcode.</td></tr><?php endif; ?>
        <?php foreach ($items as $item): ?>
            <tr><td><?= (int) $item['id'] ?></td><td><?= esc($item['name']) ?></td><td class="text-muted small"><?= (int) $item['usage'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
<?php endif; ?>

<?php if ($entity !== 'departments'): ?>
<div class="card mb-4">
    <div class="card-body">
        <form action="<?= $base ?>/add" method="post" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <?php if ($entity === 'departments'): ?>
                <div class="col-md-5">
                    <label class="form-label small d-block">Used by</label>
                    <?php foreach (['student', 'faculty', 'non_teaching'] as $r): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="for_<?= $r ?>" value="1" id="new-<?= $r ?>" <?= old("for_{$r}") ? 'checked' : '' ?>>
                            <label class="form-check-label" for="new-<?= $r ?>"><?= esc(role_label($r)) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="col-md-4">
                <label class="form-label small">Name</label>
                <input class="form-control" name="name" maxlength="150" value="<?= esc(old('name', null, false)) ?>" required>
            </div>
            <?php if ($entity === 'semesters'): ?>
                <div class="col-md-2">
                    <label class="form-label small">Order</label>
                    <input class="form-control" name="sort_order" type="number" value="<?= esc(old('sort_order', null, false) ?? 0) ?>">
                </div>
            <?php endif; ?>
            <div class="col-auto">
                <button class="btn btn-primary" type="submit">Add <?= esc($def['singular']) ?></button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <?php if ($entity === 'departments'): ?><th>Used by</th><?php endif; ?>
                    <?php if ($entity === 'semesters'): ?><th style="width:90px">Order</th><?php endif; ?>
                    <th>Status</th>
                    <th>Records</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($items === []): ?>
                <tr><td colspan="6" class="text-muted p-4">Nothing here yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $item): $active = is_true($item['active']); ?>
                <tr class="<?= $active ? '' : 'table-secondary' ?>">
                    <td>
                        <form action="<?= $base ?>/<?= $item['id'] ?>/rename" method="post" id="rename-<?= $item['id'] ?>"><?= csrf_field() ?></form>
                        <input class="form-control form-control-sm" form="rename-<?= $item['id'] ?>" name="name" value="<?= esc($item['name']) ?>" maxlength="150" required>
                    </td>
                    <?php if ($entity === 'departments'): ?>
                        <td class="text-nowrap">
                            <?php foreach (['student', 'faculty', 'non_teaching'] as $r): ?>
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="checkbox" form="rename-<?= $item['id'] ?>" name="for_<?= $r ?>" value="1" id="d<?= $item['id'] ?>-<?= $r ?>" <?= is_true($item["for_{$r}"]) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="d<?= $item['id'] ?>-<?= $r ?>"><?= esc(role_label($r)) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </td>
                    <?php endif; ?>
                    <?php if ($entity === 'semesters'): ?>
                        <td><input class="form-control form-control-sm" form="rename-<?= $item['id'] ?>" name="sort_order" type="number" value="<?= (int) $item['sort_order'] ?>"></td>
                    <?php endif; ?>
                    <td><?= $active ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
                    <td class="text-muted small"><?= (int) $item['usage'] === 0 ? 'not used yet' : (int) $item['usage'] . ' record(s)' ?></td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-outline-primary" type="submit" form="rename-<?= $item['id'] ?>">Save</button>
                        <form action="<?= $base ?>/<?= $item['id'] ?>/toggle" method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $active ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                        <?php if ((int) $item['usage'] === 0): ?>
                            <form action="<?= $base ?>/<?= $item['id'] ?>/delete" method="post" class="d-inline"
                                  onsubmit="return confirm('Delete <?= esc($item['name'], 'js') ?>? This cannot be undone.');">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php if ($entity !== 'departments'): ?><p class="text-muted small mt-3">
    Items that have been used can only be deactivated: they stop appearing on new forms but still display correctly in old records.
    Only items that were never used can be deleted.
</p><?php endif; ?>
<?php endif; ?>
<?= $this->endSection() ?>
