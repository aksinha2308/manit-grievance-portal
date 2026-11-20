<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Grievance submitted<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row justify-content-center"><div class="col-lg-7">
    <div class="card"><div class="card-body p-4 text-center">
        <h1 class="h4 text-success">Your grievance has been submitted</h1>
        <p class="text-muted mb-2">Your reference number is below. You can follow this grievance any time under <strong>My grievances</strong>.</p>
        <div class="display-6 fw-bold my-3" id="ref"><?= esc($receipt['reference_no']) ?></div>
        <table class="table table-sm w-auto mx-auto text-start">
            <tr><th class="pe-4">Type</th><td><?= esc($receipt['type']) ?></td></tr>
            <tr><th>Submitted</th><td><?= esc(ist($receipt['created_at'])) ?></td></tr>
            <tr><th>Sent to</th><td><?= esc($receipt['level']) ?></td></tr>
        </table>
        <div class="d-print-none">
            <button type="button" class="btn btn-outline-primary mt-2" onclick="window.print()">Print / save receipt</button>
            <a class="btn btn-primary mt-2" href="<?= site_url('my/' . (int) $receipt['id']) ?>">View this grievance</a>
            <a class="btn btn-outline-primary mt-2" href="<?= site_url('my') ?>">My grievances</a>
            <a class="btn btn-link mt-2" href="<?= site_url('/') ?>">Back to home</a>
        </div>
    </div></div>
</div></div>
<?= $this->endSection() ?>
