<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Authorities<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0">Authority accounts</h1>
    <div>
        <a class="btn btn-outline-primary" href="<?= site_url('admin/users/import') ?>">Bulk upload</a>
        <a class="btn btn-primary" href="<?= site_url('admin/users/new') ?>">Add account</a>
    </div>
</div>

<form class="row g-2 mb-3" method="get">
    <div class="col-md-5"><input class="form-control" name="q" value="<?= esc($q) ?>" placeholder="Search username, name or email"></div>
    <div class="col-md-2">
        <select class="form-select" name="status">
            <option value="">All</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button></div>
</form>

<div class="card"><div class="table-responsive">
<table class="table align-middle mb-0">
    <thead><tr><th>Name</th><th>Username</th><th>Posts held</th><th>Status</th><th>Last login</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $active = is_true($u['active']); ?>
        <tr class="<?= $active ? '' : 'table-secondary' ?>">
            <td><strong><?= esc($u['full_name']) ?></strong><div class="small text-muted"><?= esc($u['email'] ?? '') ?></div></td>
            <td><code><?= esc($u['username']) ?></code></td>
            <td class="small"><?= $u['posts'] === [] ? '<span class="text-muted">none</span>' : esc(implode('; ', $u['posts'])) ?></td>
            <td>
                <?= $active ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?>
                <?php if (is_true($u['is_locked'])): ?><span class="badge text-bg-danger">Locked</span><?php endif; ?>
                <?php if (($u['email'] ?? '') === ''): ?><span class="badge text-bg-warning" title="Add the institute e-mail so this person can sign in with Google">No e-mail: cannot sign in</span><?php endif; ?>
            </td>
            <td class="small"><?= $u['last_login_at'] ? esc(ist($u['last_login_at'])) : '<span class="text-muted">never</span>' ?></td>
            <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="<?= site_url("admin/users/{$u['id']}/edit") ?>">Edit</a>
                <form class="d-inline" method="post" action="<?= site_url("admin/users/{$u['id']}/toggle") ?>"
                      onsubmit="return confirm('<?= $active ? 'Deactivate this account? Any posts it holds become vacant.' : 'Activate this account?' ?>');">
                    <?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><?= $active ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <?php if ($u['deletable']): ?>
                    <form class="d-inline" method="post" action="<?= site_url("admin/users/{$u['id']}/delete") ?>" onsubmit="return confirm('Delete this account permanently?');">
                        <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($users === []): ?><tr><td colspan="6" class="text-center text-muted py-4">No accounts found.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<p class="small text-muted mt-2">Accounts that have held a post or handled a grievance cannot be deleted, only deactivated.</p>
<?= $this->endSection() ?>
