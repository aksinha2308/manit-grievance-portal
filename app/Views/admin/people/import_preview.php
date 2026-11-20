<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Upload preview<?= $this->endSection() ?>
<?= $this->section('content') ?>
<h1 class="h4 mb-3">Upload preview: <?= esc(role_label($role)) ?></h1>
<div class="row g-3 mb-3">
    <?php foreach (['rows' => 'rows read', 'new' => 'new people', 'changed' => 'existing, will change', 'same' => 'existing, no change'] as $k => $label): ?>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body py-3"><div class="h3 mb-0"><?= (int) $$k ?></div><div class="small text-muted"><?= esc($label) ?></div></div></div></div>
    <?php endforeach; ?>
</div>
<?php if ($inactive > 0): ?><div class="alert alert-info py-2">Note: <?= (int) $inactive ?> person(s) in this file are currently deactivated. The upload updates their details but does not reactivate them.</div><?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger"><strong><?= count($errors) ?> problem(s) found.</strong> Nothing was saved. Fix the file and upload it again.</div>
    <div class="card mb-3"><div class="table-responsive" style="max-height:420px"><table class="table table-sm mb-0">
        <thead><tr><th style="width:90px">Row</th><th>Problem</th></tr></thead>
        <tbody><?php foreach (array_slice($errors, 0, 500) as $e): ?><tr><td><?= (int) $e['row'] ?></td><td><?= esc($e['message']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <?php if (count($errors) > 500): ?><p class="small text-muted">Showing the first 500 problems.</p><?php endif; ?>
    <form method="post" action="<?= site_url('admin/people/import/cancel') ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="token" value="<?= esc($token) ?>"><button class="btn btn-outline-secondary">Discard</button></form>
    <a class="btn btn-primary" href="<?= site_url("admin/people/{$role}/import") ?>">Upload a corrected file</a>
<?php else: ?>
    <?php if ($changes !== []): ?>
        <div class="card mb-3"><div class="card-header bg-white"><strong>Changes to existing people</strong> <span class="small text-muted">(first <?= count($changes) ?>)</span></div>
        <div class="table-responsive" style="max-height:320px"><table class="table table-sm mb-0"><tbody>
            <?php foreach ($changes as $c): ?><tr><td style="width:80px">Row <?= (int) $c['row'] ?></td><td><code><?= esc($c['id_code']) ?></code></td><td class="small"><?= esc($c['diff']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif; ?>
    <div class="alert alert-success">No problems found. Confirm to save: <?= (int) $new ?> will be added, <?= (int) $changed ?> updated.</div>
    <form method="post" action="<?= site_url('admin/people/import/confirm') ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="token" value="<?= esc($token) ?>"><button class="btn btn-primary">Confirm and save</button></form>
    <form method="post" action="<?= site_url('admin/people/import/cancel') ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="token" value="<?= esc($token) ?>"><button class="btn btn-outline-secondary">Discard</button></form>
<?php endif; ?>
<?= $this->endSection() ?>
