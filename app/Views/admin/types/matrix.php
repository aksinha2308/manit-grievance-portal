<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Matrix: <?= esc($type['name']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $base = site_url("admin/types/{$type['id']}/matrix"); $count = count($levels); ?>

<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= site_url('admin/types') ?>">Grievance types</a></li>
    <li class="breadcrumb-item active"><?= esc(role_label($type['role'])) ?> · <?= esc($type['name']) ?> (<?= esc($type['code']) ?>)</li>
</ol></nav>

<h1 class="h4 mb-2">Transfer matrix</h1>
<p class="text-muted small">
    The submitter's grievance starts at level 1. At each level the authority can dispose of it (remark mandatory) or transfer it to the next level
    (remark mandatory). If "may skip ahead" is ticked for a level, its authority can instead transfer the grievance directly to any later level.
    The Director is always the last level and can only dispose.
</p>

<div class="card mb-4">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th style="width:70px">Level</th><th>Authority</th><th>Resolved by</th><th style="width:230px">Overdue after</th><th style="width:190px">Skip ahead</th><th>Open</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($levels as $i => $l): $final = is_true($l['is_final']); $fid = 'lv-' . $l['id']; ?>
                <tr class="<?= $final ? 'table-light' : '' ?>">
                    <td><span class="badge text-bg-primary"><?= (int) $l['level_no'] ?></span></td>
                    <td>
                        <strong><?= esc($l['post_name']) ?></strong>
                        <?php if ($final): ?><span class="badge text-bg-dark ms-1">Final · dispose only</span><?php endif; ?>
                        <?php if (! is_true($l['post_active'])): ?><span class="badge text-bg-warning ms-1">Inactive</span><?php endif; ?>
                    </td>
                    <td class="small text-muted">
                        <?= match ($l['scope_type']) {
                            'department' => "the submitter's department",
                            'hostel'     => "the student's hostel",
                            default      => 'fixed post',
                        } ?>
                    </td>
                    <td>
                        <form action="<?= $base ?>/<?= $l['id'] ?>/days" method="post" id="<?= $fid ?>" class="input-group input-group-sm">
                            <?= csrf_field() ?>
                            <input class="form-control" name="overdue_days" type="number" min="1" max="365" value="<?= (int) $l['overdue_days'] ?>" required>
                            <span class="input-group-text">days</span>
                            <button class="btn btn-outline-primary" type="submit">Save</button>
                        </form>
                    </td>
                    <td>
                        <?php if (! $final): ?>
                            <form action="<?= $base ?>/<?= $l['id'] ?>/skip" method="post" class="d-flex align-items-center gap-2 m-0">
                                <?= csrf_field() ?>
                                <div class="form-check m-0">
                                    <input class="form-check-input" type="checkbox" name="can_skip" value="1" id="skip-<?= $l['id'] ?>" <?= is_true($l['can_skip']) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="skip-<?= $l['id'] ?>">may skip ahead</label>
                                </div>
                                <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                            </form>
                        <?php else: ?>
                            <span class="small text-muted">–</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= (int) $l['open_grievances'] ?></td>
                    <td class="text-end text-nowrap">
                        <?php if (! $final): ?>
                            <form action="<?= $base ?>/<?= $l['id'] ?>/move" method="post" class="d-inline"><?= csrf_field() ?>
                                <input type="hidden" name="direction" value="up">
                                <button class="btn btn-sm btn-outline-secondary" type="submit" <?= $i === 0 ? 'disabled' : '' ?> title="Move up">↑</button>
                            </form>
                            <form action="<?= $base ?>/<?= $l['id'] ?>/move" method="post" class="d-inline"><?= csrf_field() ?>
                                <input type="hidden" name="direction" value="down">
                                <button class="btn btn-sm btn-outline-secondary" type="submit" <?= $i >= $count - 2 ? 'disabled' : '' ?> title="Move down">↓</button>
                            </form>
                            <form action="<?= $base ?>/<?= $l['id'] ?>/remove" method="post" class="d-inline"
                                  onsubmit="return confirm('Remove <?= esc($l['post_name'], 'js') ?> from this route?<?= (int) $l['open_grievances'] > 0 ? ' ' . (int) $l['open_grievances'] . ' open grievance(s) at this level will move to the next level.' : '' ?>');">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                            </form>
                        <?php else: ?>
                            <span class="small text-muted">fixed</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white"><strong>Add a level</strong></div>
    <div class="card-body">
        <?php if ($available === []): ?>
            <p class="text-muted mb-0">Every active post is already in this route. Create more under <a href="<?= site_url('admin/posts') ?>">Posts</a>.</p>
        <?php else: ?>
            <form action="<?= $base ?>/add" method="post" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col-md-4">
                    <label class="form-label small">Post</label>
                    <select class="form-select" name="post_id" required>
                        <option value="">Choose…</option>
                        <?php foreach ($available as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= esc($d['name']) ?><?= $d['scope_type'] ? ' (per ' . esc($d['scope_type']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Insert before</label>
                    <select class="form-select" name="position" required>
                        <?php foreach ($levels as $l): ?>
                            <option value="<?= (int) $l['level_no'] ?>" <?= is_true($l['is_final']) ? 'selected' : '' ?>>
                                Level <?= (int) $l['level_no'] ?> · <?= esc($l['post_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Overdue after (days)</label>
                    <input class="form-control" name="overdue_days" type="number" min="1" max="365" value="<?= (int) $defaultDays ?>" required>
                </div>
                <div class="col-auto"><button class="btn btn-primary" type="submit">Add level</button></div>
            </form>
        <?php endif; ?>
    </div>
</div>

<p class="text-muted small mt-3">
    Changes apply to grievances already in progress. A level added later in the route is simply passed through when a grievance is transferred;
    a level added earlier is not applied to grievances that have already passed that point. If you remove the level where a grievance is waiting,
    it moves to the next level and its history records the change. Reordering never moves a grievance: it stays with its current authority.
</p>
<?= $this->endSection() ?>
