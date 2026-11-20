<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?><?= esc($post['name']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $base = site_url("admin/post-holders/{$post['id']}"); $scoped = $post['scope_type'] !== null; ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= site_url('admin/post-holders') ?>">Post holders</a></li>
    <li class="breadcrumb-item active"><?= esc($post['name']) ?></li>
</ol></nav>
<h1 class="h4 mb-3"><?= esc($post['name']) ?></h1>

<?php
$groups = [];
foreach ($seats as $p) { $groups[$p['group']][] = $p; }
?>
<?php foreach ($groups as $group => $list): ?>
<div class="card mb-4">
    <?php if ($group !== ''): ?><div class="card-header bg-white"><strong><?= esc($group) ?></strong></div><?php endif; ?>
    <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th><?= $scoped ? esc(ucfirst($post['scope_type'])) : 'Post' ?></th><?php if ($post['scope_type'] === 'department'): ?><th>Used by</th><?php endif; ?><th>Current holder</th><th style="width:45%">Assign</th></tr></thead>
        <tbody>
        <?php foreach ($list as $p): $fid = 'p-' . ($p['scope_id'] ?? 0) . '-' . md5($group); ?>
            <tr>
                <td><?= $scoped ? esc($p['scope_name']) : esc($post['name']) ?></td>
                <?php if ($post['scope_type'] === 'department'): ?><td class="small text-muted"><?= esc($p['used_by']) ?></td><?php endif; ?>
                <td><?= $p['holder'] ? esc($p['holder']) : '<span class="badge text-bg-warning">Vacant</span>' ?></td>
                <td>
                    <form method="post" action="<?= $base ?>/assign" class="d-flex gap-2 flex-wrap align-items-center">
                        <?= csrf_field() ?>
                        <?php if ($post['scope_type'] === 'hostel'): ?><input type="hidden" name="hostel" value="<?= esc($p['scope_id']) ?>">
                        <?php elseif ($p['scope_id'] !== null): ?><input type="hidden" name="scope_id" value="<?= (int) $p['scope_id'] ?>"><?php endif; ?>
                        <select class="form-select form-select-sm w-auto" name="user_id" required aria-label="Person">
                            <option value="">Choose person…</option>
                            <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int) $p['user_id'] === (int) $u['id'] ? 'disabled' : '' ?>><?= esc($u['full_name']) ?> (<?= esc($u['username']) ?>)</option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-primary">Assign</button>
                    </form>
                    <?php if ($p['holder']): ?>
                        <form method="post" action="<?= $base ?>/vacate" class="mt-1" onsubmit="return confirm('Make this post vacant?');">
                            <?= csrf_field() ?>
                            <?php if ($post['scope_type'] === 'hostel'): ?><input type="hidden" name="hostel" value="<?= esc($p['scope_id']) ?>">
                            <?php elseif ($p['scope_id'] !== null): ?><input type="hidden" name="scope_id" value="<?= (int) $p['scope_id'] ?>"><?php endif; ?>
                            <button class="btn btn-sm btn-link text-danger p-0">Make vacant</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endforeach; ?>
<?php if ($post['scope_type'] === 'hostel'): ?>
    <div class="card">
        <div class="card-body">
            <h2 class="h6">Assign a Warden for another hostel</h2>
            <form method="post" action="<?= $base ?>/assign" class="d-flex gap-2 flex-wrap align-items-center">
                <?= csrf_field() ?>
                <input class="form-control form-control-sm" style="max-width:260px" name="hostel" maxlength="150" placeholder="Hostel name or ERP code" required>
                <select class="form-select form-select-sm w-auto" name="user_id" required aria-label="Person">
                    <option value="">Choose person…</option>
                    <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= esc($u['full_name']) ?> (<?= esc($u['username']) ?>)</option><?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-primary">Assign</button>
            </form>
            <p class="form-text mb-0">Use the same hostel text that appears in ERP/student records and grievances.</p>
        </div>
    </div>
<?php elseif ($seats === []): ?>
    <p class="text-muted">No posts to show. Add departments first.</p>
<?php endif; ?>
<?= $this->endSection() ?>
