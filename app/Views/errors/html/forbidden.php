<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Not allowed<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card">
    <div class="card-body p-4">
        <h1 class="h5">You do not have access to this page</h1>
        <p class="text-muted mb-0"><?= esc($message ?? 'This area is not available for your account.') ?></p>
    </div>
</div>
<?= $this->endSection() ?>
