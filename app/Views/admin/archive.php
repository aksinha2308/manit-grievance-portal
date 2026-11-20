<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Archive<?= $this->endSection() ?>
<?= $this->section('content') ?>
<h1 class="h4 mb-3">Archive old grievances</h1>
<div class="card mb-3">
    <div class="card-body">
        <p class="mb-2">Disposed grievances can be archived <strong><?= (int) $years ?> year(s)</strong> after their disposal date
            (change this under <a href="<?= site_url('admin/settings') ?>">Settings</a>).</p>
        <ul class="small text-muted mb-3">
            <li>Nothing is deleted, attachments included. Archived grievances leave the authorities' lists and counts.</li>
            <li>You still find them under <a href="<?= site_url('admin/grievances?archived=yes') ?>">Grievances</a> with the "Archived" filter, and you can restore any of them.</li>
            <li>Submitters can still track them.</li>
        </ul>
        <?php if ($count === 0): ?>
            <div class="alert alert-success mb-0" id="nothing-eligible">No grievance is old enough to archive.</div>
        <?php else: ?>
            <form method="post" action="<?= site_url('admin/archive') ?>" class="d-flex flex-wrap align-items-center gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="expected" value="<?= (int) $count ?>">
                <button class="btn btn-warning" type="submit" id="archive-all">Archive <?= (int) $count ?> grievance(s)</button>
                <span class="small text-muted">The list below shows <?= count($preview) < $count ? 'the oldest ' . count($preview) . ' of them' : 'all of them' ?>.</span>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($preview !== []): ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="eligible">
                <thead class="table-light"><tr><th class="ps-3">Reference</th><th>Type</th><th>Subject</th><th>Submitted</th><th>Disposed</th></tr></thead>
                <tbody>
                <?php foreach ($preview as $p): ?>
                    <tr>
                        <td class="ps-3"><a href="<?= site_url('admin/grievances/' . (int) $p['id']) ?>"><?= esc($p['reference_no']) ?></a></td>
                        <td class="small"><?= esc($p['type_name']) ?></td>
                        <td class="small"><?= esc(mb_strimwidth((string) $p['subject'], 0, 60, '…')) ?></td>
                        <td class="small text-nowrap"><?= esc(ist($p['created_at'], false)) ?></td>
                        <td class="small text-nowrap"><?= esc(ist($p['disposed_at'], false)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
