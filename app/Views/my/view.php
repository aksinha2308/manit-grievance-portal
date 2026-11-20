<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?><?= esc($g['reference_no']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$badge = ['pending' => 'text-bg-primary', 'awaiting_submitter' => 'text-bg-warning', 'disposed' => 'text-bg-success'][$g['status']] ?? 'text-bg-secondary';
$icons = ['submit' => 'secondary', 'escalate' => 'info', 'dispose' => 'success', 'request_info' => 'warning',
          'submitter_reply' => 'primary', 'late_reply' => 'primary', 'reroute' => 'dark'];
$mode  = $reply['mode'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <h1 class="h4 mb-0" id="ref"><?= esc($g['reference_no']) ?></h1>
        <span class="badge <?= $badge ?>" id="status"><?= esc($status_text) ?></span>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('my') ?>">Back to my grievances</a>
</div>

<div class="row g-3">
    <div class="col-lg-7 order-2 order-lg-1">
        <div class="card mb-3">
            <div class="card-body">
                <div class="small text-muted mb-1"><?= esc($g['type_name']) ?> · submitted <?= esc(ist($g['created_at'])) ?></div>
                <h2 class="h5"><?= esc($g['subject']) ?></h2>
                <div style="white-space: pre-wrap"><?= esc($g['description']) ?></div>
                <?php if ($g['custom'] !== []): ?>
                    <table class="table table-sm mt-3 mb-0">
                        <?php foreach ($g['custom'] as $c): ?>
                            <tr><th class="w-50 fw-normal text-muted"><?= esc($c['label'] ?? '') ?></th><td><?= esc($c['value'] ?? '') ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
                <?php if ($attachments !== []): ?>
                    <div class="mt-3">
                        <div class="small text-muted mb-1">Your attachments</div>
                        <?php foreach ($attachments as $a): ?>
                            <a class="btn btn-sm btn-outline-secondary me-1 mb-1" href="<?= site_url('my/' . (int) $g['id'] . '/attachments/' . (int) $a['id']) ?>">
                                <?= esc($a['original_name']) ?> <span class="text-muted">(<?= esc(file_size_text($a['size_bytes'])) ?>)</span></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white"><strong>History</strong></div>
            <ul class="list-group list-group-flush" id="history">
                <?php foreach ($history as $h): ?>
                    <li class="list-group-item">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <span class="badge text-bg-<?= $icons[$h['action']] ?? 'secondary' ?>"><?= esc($h['title']) ?></span>
                            <span class="small text-muted"><?= esc(ist($h['created_at'])) ?></span>
                        </div>
                        <div class="small text-muted mt-1"><?= esc($h['by']) ?></div>
                        <?php if ($h['remark'] !== null && $h['remark'] !== ''): ?>
                            <div class="mt-1 p-2 bg-light rounded small" style="white-space: pre-wrap"><?= esc($h['remark']) ?></div>
                        <?php endif; ?>
                        <?php foreach ($h['attachments'] as $a): ?>
                            <a class="btn btn-sm btn-outline-secondary mt-1 me-1" href="<?= site_url('my/' . (int) $g['id'] . '/attachments/' . (int) $a['id']) ?>"><?= esc($a['original_name']) ?></a>
                        <?php endforeach; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="col-lg-5 order-1 order-lg-2">
        <div class="card mb-3">
            <div class="card-body small">
                <?php if ($g['status'] === 'disposed'): ?>
                    <div>Disposed on <strong><?= esc(ist($g['disposed_at'])) ?></strong><?= $g['level_label'] ? ' by ' . esc($g['level_label']) : '' ?>.</div>
                    <div class="text-muted mt-1">The decision is in the history. If you are not satisfied, you may submit a new grievance; the authority will see this one alongside it.</div>
                    <?php if (is_true($g['archived'])): ?><div class="text-muted mt-1" id="archived-note">This grievance has been archived. It can still be viewed here.</div><?php endif; ?>
                <?php else: ?>
                    <div>Now with: <strong id="now-with"><?= esc($g['level_label']) ?></strong></div>
                    <div class="text-muted mt-1">Last update <?= esc(ist($g['updated_at'], false)) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($mode !== null): ?>
            <div class="card border-warning" id="reply-panel">
                <div class="card-header bg-warning-subtle">
                    <strong><?= $mode === 'reply' ? 'Your reply is needed' : 'You can still answer' ?></strong>
                </div>
                <div class="card-body">
                    <?php if ($mode === 'late'): ?>
                        <p class="small mb-2">This question was asked earlier, but your grievance has since moved on to
                            <strong><?= esc($g['level_label']) ?></strong>. You can still send your answer; it will be added to your grievance for them.</p>
                    <?php endif; ?>
                    <div class="small text-muted">Question from <?= esc($reply['question']['level_label'] ?? '') ?>, <?= esc(ist($reply['question']['created_at'] ?? null, false)) ?>:</div>
                    <div class="p-2 bg-light rounded small mb-3" style="white-space: pre-wrap"><?= esc($reply['question']['remark'] ?? '') ?></div>
                    <form method="post" action="<?= site_url('my/' . (int) $g['id'] . '/reply') ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <label class="form-label" for="reply">Your answer <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="reply" name="reply" rows="5" required maxlength="<?= \App\Libraries\WorkflowService::REPLY_MAX ?>"><?= esc(old('reply', null, false) ?? '') ?></textarea>
                        <?php if ($maxFiles > 0): ?>
                            <label class="form-label mt-3" for="attachments">Attach files (optional)</label>
                            <input class="form-control" type="file" id="attachments" name="attachments[]" multiple
                                   accept="<?= esc(implode(',', array_map(static fn ($e) => '.' . $e, $allowedExt))) ?>">
                            <div class="form-text">Up to <?= (int) $maxFiles ?> file(s), <?= (int) $maxMb ?> MB each: <?= esc(strtoupper(implode(', ', $allowedExt))) ?>.</div>
                        <?php endif; ?>
                        <button class="btn btn-primary mt-3" type="submit">Send answer</button>
                        <div class="form-text">You can answer once. Please check your answer before sending.</div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
