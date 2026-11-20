<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Upload master list<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $idLabel = $role === 'student' ? 'Scholar Number' : 'Employee Code'; ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= site_url("admin/people/{$role}") ?>">Master list: <?= esc(role_label($role)) ?></a></li>
    <li class="breadcrumb-item active">Bulk upload</li>
</ol></nav>
<div class="card"><div class="card-body">
    <h1 class="h5">Load the <?= esc(strtolower(role_label($role))) ?> list from a spreadsheet</h1>
    <ol class="small">
        <li><a href="<?= site_url("admin/people/{$role}/import/template") ?>">Download the template</a> (it contains the current departments<?= $role === 'student' ? ' and semesters; Hostel and Room are free-text values' : '' ?>).</li>
        <li>Fill one row per person. Format the <?= esc($idLabel) ?> column as <strong>Text</strong> so leading zeros are kept.</li>
        <li>Upload it. People already on the list are <strong>updated</strong>, new people are <strong>added</strong>, and nobody is removed if missing from the file.</li>
        <li>You will see a preview with counts and any errors. <strong>Nothing is saved until you confirm.</strong></li>
    </ol>
    <form method="post" action="<?= site_url("admin/people/{$role}/import") ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-md-6"><label class="form-label small" for="file">File (.xlsx or .csv, up to 15 MB, up to 25,000 rows)</label>
            <input class="form-control" id="file" type="file" name="file" accept=".xlsx,.csv" required></div>
        <div class="col-auto"><button class="btn btn-primary">Check file</button></div>
    </form>
</div></div>
<?= $this->endSection() ?>
