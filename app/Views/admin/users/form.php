<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?><?= $user ? 'Edit account' : 'Add account' ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= site_url('admin/users') ?>">Authorities</a></li>
    <li class="breadcrumb-item active"><?= $user ? esc($user['full_name']) : 'New account' ?></li>
</ol></nav>

<div class="row g-4">
<div class="col-lg-6">
    <div class="card"><div class="card-body">
        <h1 class="h5 mb-3"><?= $user ? 'Account details' : 'New authority account' ?></h1>
        <form method="post" action="<?= $user ? site_url("admin/users/{$user['id']}/update") : site_url('admin/users') ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <?php if ($user): ?>
                    <input class="form-control" id="username" value="<?= esc($user['username']) ?>" disabled>
                    <div class="form-text">The username cannot be changed.</div>
                <?php else: ?>
                    <input class="form-control" id="username" name="username" value="<?= esc(old('username', null, false)) ?>" required maxlength="60" autocomplete="off">
                    <div class="form-text">3-60 characters: letters, digits, dot, dash, underscore.</div>
                <?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="full_name">Full name</label>
                <input class="form-control" id="full_name" name="full_name" value="<?= esc(old('full_name', null, false) ?? $user['full_name'] ?? '') ?>" required maxlength="150">
            </div>
            <div class="mb-3">
                <label class="form-label" for="email">Institute e-mail <span class="text-danger">*</span></label>
                <input class="form-control" id="email" name="email" type="email" value="<?= esc(old('email', null, false) ?? $user['email'] ?? '') ?>" maxlength="190" required
                       placeholder="name@<?= esc(config('Google')->staffDomain) ?>">
                <div class="form-text">The authority signs in with this Google account (@<?= esc(config('Google')->staffDomain) ?>). There is no password.</div>
            </div>
            <button class="btn btn-primary" type="submit"><?= $user ? 'Save' : 'Create account' ?></button>
        </form>
    </div></div>
</div>

<?php if ($user): ?>
<div class="col-lg-6">
    <div class="card mb-3"><div class="card-body">
        <h2 class="h6">Posts held</h2>
        <?php if ($posts === []): ?>
            <p class="text-muted small mb-2">None. Assign posts under <a href="<?= site_url('admin/post-holders') ?>">Post holders</a>.</p>
        <?php else: ?>
            <ul class="small mb-2"><?php foreach ($posts as $p): ?><li><?= esc($p['label']) ?> <span class="text-muted">since <?= esc(ist($p['assigned_at'])) ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div></div>

    <?php $locked = $user['locked_until'] && strtotime($user['locked_until']) > time(); ?>
    <?php if ($locked): ?>
    <div class="card mb-3"><div class="card-body">
        <h2 class="h6">Account locked</h2>
        <p class="small text-muted">Locked after repeated failed logins until <?= esc(ist($user['locked_until'])) ?>.</p>
        <form method="post" action="<?= site_url("admin/users/{$user['id']}/unlock") ?>"><?= csrf_field() ?><button class="btn btn-outline-warning btn-sm">Unlock now</button></form>
    </div></div>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>
<?= $this->endSection() ?>
