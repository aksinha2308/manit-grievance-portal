<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>My grievances<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $badge = ['pending' => 'text-bg-primary', 'awaiting_submitter' => 'text-bg-warning', 'disposed' => 'text-bg-success']; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">My grievances</h1>
        <div class="small text-muted"><?= esc($person['name']) ?> · <?= esc($person['id_code']) ?> · <?= esc(role_label($person['role'])) ?></div>
    </div>
    <?php if ($canSubmit): ?>
        <a class="btn btn-primary" href="<?= site_url('submit') ?>" id="new-grievance">Submit a new grievance</a>
    <?php endif; ?>
</div>

<?php if ($list === []): ?>
    <div class="card"><div class="card-body text-muted" id="none">You have not submitted any grievance yet.</div></div>
<?php else: ?>
    <div class="card"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="my-list">
            <thead><tr><th>Reference</th><th>Subject</th><th>Type</th><th>Submitted</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($list as $g):
                $status = match (true) {
                    $g['status'] === 'disposed'          => 'Disposed',
                    $g['reply_mode'] === 'reply'         => 'Waiting for your reply',
                    default                              => 'Under review · ' . ($g['level_label'] ?? ''),
                }; ?>
                <tr>
                    <td class="text-nowrap"><a href="<?= site_url('my/' . (int) $g['id']) ?>"><?= esc($g['reference_no']) ?></a></td>
                    <td><?= esc($g['subject']) ?></td>
                    <td class="small"><?= esc($g['type_name']) ?></td>
                    <td class="small text-nowrap"><?= esc(ist($g['created_at'], false)) ?></td>
                    <td><span class="badge <?= $badge[$g['status']] ?? 'text-bg-secondary' ?>"><?= esc($status) ?></span>
                        <?php if (is_true($g['archived'])): ?><span class="badge text-bg-light">Archived</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
<?php endif; ?>
<?= $this->endSection() ?>
