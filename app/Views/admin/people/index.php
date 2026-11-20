<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Master list<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $idLabel = $role === 'student' ? 'Scholar Number' : 'Employee Code'; $qs = http_build_query(['q' => $q, 'department' => $dept ?: '', 'status' => $status]); ?>
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h1 class="h4 mb-0">Master list</h1>
    <div>
        <a class="btn btn-outline-primary" href="<?= site_url("admin/people/{$role}/import") ?>">Bulk upload</a>
        <a class="btn btn-primary" href="<?= site_url("admin/people/{$role}/new") ?>">Add one person</a>
    </div>
</div>
<p class="text-muted small">People who can raise a grievance. They are identified by their <?= esc($idLabel) ?>; name, department<?= $role === 'student' ? ', semester and hostel' : '' ?> are filled in from here. <?= (int) $counts[$role]['active'] ?> of <?= (int) $counts[$role]['total'] ?> are active.</p>

<?= view('admin/people/_tabs', ['role' => $role, 'counts' => $counts]) ?>

<form class="row g-2 mb-3" method="get">
    <div class="col-md-4"><input class="form-control" name="q" value="<?= esc($q) ?>" placeholder="Search <?= esc($idLabel) ?>, name or e-mail"></div>
    <div class="col-md-3"><select class="form-select" name="department"><option value="">All departments</option><?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>" <?= (int) $d['id'] === $dept ? 'selected' : '' ?>><?= esc($d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><select class="form-select" name="status"><option value="">Any status</option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
    <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button></div>
</form>

<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th><?= esc($idLabel) ?></th><th>Name</th><th>Department</th><?php if ($role === 'student'): ?><th>Semester</th><th>Hostel</th><?php endif; ?><th>Contact</th><th>E-mail</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): $active = is_true($p['active']); ?>
        <tr class="<?= $active ? '' : 'table-secondary' ?>">
            <td><code><?= esc($p['id_code']) ?></code></td>
            <td><?= esc($p['name']) ?></td>
            <td><?= esc($p['department']) ?></td>
            <?php if ($role === 'student'): ?><td><?= esc($p['semester'] ?? '') ?></td><td><?= $p['hostel'] ? esc($p['hostel']) : '<span class="text-muted">day scholar</span>' ?></td><?php endif; ?>
            <td><?= esc($p['contact'] ?? '') ?></td>
            <td class="small"><?= ($p['email'] ?? '') !== '' ? esc($p['email']) : '<span class="badge text-bg-warning">none: cannot sign in</span>' ?></td>
            <td><?= $active ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
            <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="<?= site_url("admin/people/{$role}/{$p['id']}/edit") ?>">Edit</a>
                <form class="d-inline" method="post" action="<?= site_url("admin/people/{$role}/{$p['id']}/toggle") ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><?= $active ? 'Deactivate' : 'Activate' ?></button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="8" class="text-center text-muted py-4">No one found. Use Bulk upload to load the list.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?php if ($pages > 1): ?>
<nav class="mt-3 d-flex gap-2 align-items-center small">
    <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= $qs ?>&page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $pages ?> (<?= $total ?> people)</span>
    <?php if ($page < $pages): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= $qs ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?>
</nav>
<?php endif; ?>
<?= $this->endSection() ?>
