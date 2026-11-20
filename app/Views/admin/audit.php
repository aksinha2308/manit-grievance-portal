<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Audit log<?= $this->endSection() ?>
<?= $this->section('content') ?>
<h1 class="h4 mb-2">Audit log</h1>
<p class="text-muted small">Every configuration change, with before and after values. The log is append-only: entries cannot be edited or deleted.</p>
<form class="row g-2 mb-3" method="get">
    <div class="col-md-3"><select class="form-select" name="entity"><option value="">All entities</option><?php foreach ($entities as $e): ?><option <?= $e === $entity ? 'selected' : '' ?>><?= esc($e) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><select class="form-select" name="action"><option value="">All actions</option><?php foreach ($actions as $a): ?><option <?= $a === $action ? 'selected' : '' ?>><?= esc($a) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><input class="form-control" name="who" value="<?= esc($who) ?>" placeholder="User"></div>
    <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>When (IST)</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td class="small text-nowrap"><?= esc(ist($r['created_at'])) ?></td>
            <td class="small"><?= esc($r['username'] ?? '') ?></td>
            <td><span class="badge text-bg-light border"><?= esc($r['action']) ?></span></td>
            <td class="small"><?= esc($r['entity']) ?><?= $r['entity_id'] !== null ? ' #' . esc($r['entity_id']) : '' ?></td>
            <td class="small">
                <?php if ($r['before_data'] || $r['after_data']): ?>
                <details><summary>show</summary>
                    <?php if ($r['before_data']): ?><div><strong>Before:</strong> <code><?= esc($r['before_data']) ?></code></div><?php endif; ?>
                    <?php if ($r['after_data']): ?><div><strong>After:</strong> <code><?= esc($r['after_data']) ?></code></div><?php endif; ?>
                </details>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="5" class="text-center text-muted py-4">No entries.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?php if ($pages > 1): $qs = http_build_query(['entity' => $entity, 'action' => $action, 'who' => $who]); ?>
<nav class="mt-3 d-flex gap-2 align-items-center small">
    <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= $qs ?>&page=<?= $page - 1 ?>">Newer</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $pages ?> (<?= $total ?> entries)</span>
    <?php if ($page < $pages): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= $qs ?>&page=<?= $page + 1 ?>">Older</a><?php endif; ?>
</nav>
<?php endif; ?>
<?= $this->endSection() ?>
