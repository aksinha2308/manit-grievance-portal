<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Bulk upload<?= $this->endSection() ?>
<?= $this->section('content') ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= site_url('admin/users') ?>">Authorities</a></li>
    <li class="breadcrumb-item active">Bulk upload</li>
</ol></nav>
<div class="card"><div class="card-body">
    <h1 class="h5">Create many accounts from a spreadsheet</h1>
    <ol class="small">
        <li><a href="<?= site_url('admin/users/import/template') ?>">Download the template</a> (it lists the current posts, departments and hostels).</li>
        <li>Fill one row per post. Repeat a username to give one person several posts. Every person needs their institute e-mail (@<?= esc(config('Google')->staffDomain) ?>): they sign in with that Google account.</li>
        <li>Upload it here. You will see a preview and any errors; <strong>nothing is saved until you confirm</strong>.</li>
    </ol>
    <form method="post" action="<?= site_url('admin/users/import') ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-md-6"><label class="form-label small" for="file">File (.xlsx or .csv, up to 5 MB)</label>
            <input class="form-control" id="file" type="file" name="file" accept=".xlsx,.csv" required></div>
        <div class="col-auto"><button class="btn btn-primary">Check file</button></div>
    </form>
</div></div>
<?= $this->endSection() ?>
