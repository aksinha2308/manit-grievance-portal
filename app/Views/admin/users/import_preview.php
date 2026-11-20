<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Upload preview<?= $this->endSection() ?>
<?= $this->section('content') ?>
<h1 class="h4 mb-3">Upload preview</h1>
<div class="row g-3 mb-3">
    <div class="col-sm-4"><div class="card"><div class="card-body"><div class="h3 mb-0"><?= (int) $rows ?></div><div class="small text-muted">rows read</div></div></div></div>
    <div class="col-sm-4"><div class="card"><div class="card-body"><div class="h3 mb-0"><?= (int) $users ?></div><div class="small text-muted">accounts to create</div></div></div></div>
    <div class="col-sm-4"><div class="card"><div class="card-body"><div class="h3 mb-0"><?= (int) $posts ?></div><div class="small text-muted">post assignments</div></div></div></div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger"><strong><?= count($errors) ?> problem(s) found.</strong> Nothing was saved. Fix the file and upload it again.</div>
    <div class="card mb-3"><div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th style="width:90px">Row</th><th>Problem</th></tr></thead>
        <tbody><?php foreach ($errors as $e): ?><tr><td><?= (int) $e['row'] ?></td><td><?= esc($e['message']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <form method="post" action="<?= site_url('admin/users/import/cancel') ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="token" value="<?= esc($token) ?>"><button class="btn btn-outline-secondary">Discard</button></form>
    <a class="btn btn-primary" href="<?= site_url('admin/users/import') ?>">Upload a corrected file</a>
<?php else: ?>
    <div class="alert alert-success">No problems found. Confirm to create these accounts. They sign in with Google using the e-mail given.</div>
    <form method="post" action="<?= site_url('admin/users/import/confirm') ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="token" value="<?= esc($token) ?>"><button class="btn btn-primary">Confirm and create</button></form>
    <form method="post" action="<?= site_url('admin/users/import/cancel') ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="token" value="<?= esc($token) ?>"><button class="btn btn-outline-secondary">Discard</button></form>
<?php endif; ?>
<?= $this->endSection() ?>
