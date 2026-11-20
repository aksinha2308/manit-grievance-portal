<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Sync from ERP<?= $this->endSection() ?>
<?= $this->section('content') ?>
<h1 class="h4 mb-2">ERP</h1>
<?= $this->include('admin/erp/_tabs') ?>
<?php if ($scan['error'] !== null): ?>
    <div class="alert alert-danger"><?= esc($scan['error']) ?></div>
<?php else:
    $list = static function (string $title, array $rows, string $cls = '') { if ($rows === []) { return; } ?>
        <details class="mb-2"><summary class="small"><?= esc($title) ?> (<?= count($rows) ?>)</summary>
            <div class="table-responsive"><table class="table table-sm mb-2 <?= $cls ?>"><tbody>
            <?php foreach (array_slice($rows, 0, 200) as $r): ?>
                <tr><td class="small"><code><?= esc($r['id_code'] ?? '') ?></code></td><td class="small"><?= esc($r['name'] ?? ($r['what'] ?? '')) ?></td>
                    <td class="small text-muted"><?= esc(isset($r['old_name']) && $r['old_name'] !== $r['name'] ? 'was: ' . $r['old_name'] : ($r['detail'] ?? ($r['email'] ?? ($r['role'] ?? '')))) ?></td></tr>
            <?php endforeach; ?>
            <?php if (count($rows) > 200): ?><tr><td colspan="3" class="small text-muted">... and <?= count($rows) - 200 ?> more</td></tr><?php endif; ?>
            </tbody></table></div>
        </details>
    <?php };
    $nothing = $scan['adopted'] === [] && $scan['changed'] === [] && $scan['reactivated'] === [] && $scan['missing'] === []; ?>
    <p class="text-muted small">This reads the whole ERP and shows what a sync <strong>would</strong> do. Nothing changes until you press the button. Accounts are created only when a person first signs in. The sync only fixes names, takes over old master-list rows, switches people back on and switches off people who are no longer in the ERP; nobody is ever deleted. The same sync can be run from a command line: <code>php spark portal:erp-sync</code> (add <code>--apply</code> to do it).</p>
    <div class="card mb-4"><div class="card-body">
        <p class="mb-2">The ERP has <strong><?= (int) $scan['students'] ?></strong> students and <strong><?= (int) $scan['staff'] ?></strong> staff.</p>
        <ul class="mb-3">
            <li><?= count($scan['not_yet']) ?> in the ERP have no portal account yet (it is created when they first sign in; the sync never creates accounts)</li>
            <li><strong><?= count($scan['adopted']) ?></strong> from the old master list to take over (same ID)</li>
            <li><strong><?= count($scan['changed']) ?></strong> with a changed name, e-mail or staff type</li>
            <li><strong><?= count($scan['reactivated']) ?></strong> switched off earlier that are back in the ERP</li>
            <li><strong><?= count($scan['missing']) ?></strong> active people who are no longer in the ERP (will be <em>switched off</em>)</li>
            <li><?= (int) $scan['unchanged'] ?> already up to date</li>
        </ul>
        <?php $list('Taken over from the old master list', $scan['adopted']); $list('Changed', $scan['changed']);
        $list('Switched back on', $scan['reactivated']); $list('Will be switched off', $scan['missing'], 'table-warning'); ?>

        <?php if ($scan['conflicts'] !== []): ?>
            <div class="alert alert-warning small mt-3"><strong><?= count($scan['conflicts']) ?> problem record(s)</strong> in the ERP will be skipped. Fix them in the ERP.
                <?php $list('Show', $scan['conflicts']); ?></div>
        <?php endif; ?>
        <?php if ($scan['blocked_titles'] !== []): ?>
            <div class="alert alert-warning small"><strong><?= array_sum($scan['blocked_titles']) ?> staff</strong> are skipped because their job title is not listed:
                <?= esc(implode(', ', array_map(static fn ($t, $n) => "{$t} ({$n})", array_keys($scan['blocked_titles']), $scan['blocked_titles']))) ?>.
                <a href="<?= site_url('admin/erp') ?>">Assign them here</a>, then sync again.</div>
        <?php endif; ?>
        <?php if ($scan['unmapped_departments'] !== []): ?>
            <div class="alert alert-info small"><?= count($scan['unmapped_departments']) ?> department value(s) are missing from establishment.dpcode.
                Department values must be corrected in establishment.dpcode.</div>
        <?php endif; ?>

        <form action="<?= site_url('admin/erp/sync') ?>" method="post"><?= csrf_field() ?>
            <?php if ($scan['mass_off']): ?>
                <div class="alert alert-danger small"><strong>Check this carefully:</strong> <?= count($scan['missing']) ?> of <?= (int) $scan['active_erp'] ?> active people would be switched off. That is a lot and may mean the ERP data is incomplete.
                    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="confirm_mass_off" value="1" id="cm"><label class="form-check-label" for="cm">I have checked, switch them off</label></div></div>
            <?php endif; ?>
            <button class="btn btn-primary" <?= $nothing ? 'disabled' : '' ?>>Run the sync now</button>
            <?php if ($nothing): ?><span class="text-muted small ms-2">Nothing to do.</span><?php endif; ?>
        </form>
    </div></div>
<?php endif; ?>
<?= $this->endSection() ?>
