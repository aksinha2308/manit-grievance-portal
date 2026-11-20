<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>People from the ERP<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $roleNames = ['student' => 'Student', 'faculty' => 'Faculty', 'non_teaching' => 'Non-Teaching']; ?>
<h1 class="h4 mb-2">ERP</h1>
<?= $this->include('admin/erp/_tabs') ?>
<p class="text-muted small">Read only. The portal keeps just the identity of each person it has met in the ERP (ID, name, e-mail); department, semester, hostel and rank are read from the ERP at every sign-in. People are added when they first sign in or by <em>Sync from ERP</em>, and switched off when they are no longer in the ERP. They are never deleted.</p>
<form class="row g-2 mb-3" method="get">
    <div class="col-md-3"><select class="form-select" name="role"><option value="">All roles</option><?php foreach ($roleNames as $k => $l): ?><option value="<?= $k ?>" <?= $role === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><select class="form-select" name="status"><option value="">Any status</option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Switched off</option></select></div>
    <div class="col-md-4"><input class="form-control" name="q" value="<?= esc($q) ?>" placeholder="ID, name or e-mail"></div>
    <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Role</th><th>ID</th><th>Name</th><th>E-mail</th><th>Status</th><th>Last seen in the ERP (IST)</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr class="<?= is_true($r['active']) ? '' : 'table-secondary' ?>">
            <td class="small"><?= esc($roleNames[$r['role']] ?? $r['role']) ?></td>
            <td><code><?= esc($r['id_code']) ?></code></td>
            <td><?= esc($r['name']) ?></td>
            <td class="small"><?= esc($r['email'] ?? '') ?></td>
            <td><?= is_true($r['active']) ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Switched off</span>' ?></td>
            <td class="small text-nowrap"><?= $r['erp_synced_at'] ? esc(ist($r['erp_synced_at'], false)) : '' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-muted py-4">No people yet. They appear when they sign in, or run <em>Sync from ERP</em>.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?php if ($pages > 1): $qs = http_build_query(['role' => $role, 'status' => $status, 'q' => $q]); ?>
<nav class="mt-3 d-flex gap-2 align-items-center small">
    <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= $qs ?>&page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $pages ?> (<?= $total ?> people)</span>
    <?php if ($page < $pages): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= $qs ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?>
</nav>
<?php endif; ?>
<?= $this->endSection() ?>
