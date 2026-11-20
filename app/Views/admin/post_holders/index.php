<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Post holders<?= $this->endSection() ?>
<?= $this->section('content') ?>
<h1 class="h4 mb-2">Post holders</h1>
<p class="text-muted small">Pick a post to see and change who holds it in each department or hostel. Access to grievances follows the post: a new holder sees everything handled by the post before; the previous holder loses access.</p>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Post</th><th>Applies to</th><th>Filled</th><th>In use</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($posts as $d): ?>
        <tr>
            <td><strong><?= esc($d['name']) ?></strong><?= is_true($d['is_director']) ? ' <span class="badge text-bg-dark">Director</span>' : '' ?></td>
            <td class="small"><?= match ($d['scope_type']) { 'department' => 'one per department', 'hostel' => 'one per hostel', default => 'single post' } ?></td>
            <td>
                <?= (int) $d['filled'] ?> / <?= (int) $d['total'] ?>
                <?php if ((int) $d['vacant_in_use'] > 0): ?><span class="badge text-bg-warning"><?= (int) $d['vacant_in_use'] ?> vacant in a route</span><?php endif; ?>
            </td>
            <td class="small"><?= $d['used'] ? 'In a route' : '<span class="text-muted">Not in any route</span>' ?></td>
            <td class="text-end"><a class="btn btn-sm btn-primary" href="<?= site_url("admin/post-holders/{$d['id']}") ?>">Manage</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<?= $this->endSection() ?>
