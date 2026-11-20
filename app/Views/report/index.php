<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Summary report<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$t   = $r['totals'];
$pct = static fn ($a, $b) => (int) $b > 0 ? round(100 * (int) $a / (int) $b) . '%' : '—';
$d   = static fn ($v) => $v === null ? '—' : number_format((float) $v, 1);
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">Summary report</h1>
        <div class="small text-muted">Grievances submitted from <?= esc($r['from']) ?> to <?= esc($r['to']) ?> (archived ones included)</div>
    </div>
    <form class="d-flex flex-wrap align-items-end gap-2" method="get" action="<?= site_url($base) ?>" id="report-dates">
        <div><label class="form-label small mb-1" for="from">From</label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= esc($r['from']) ?>"></div>
        <div><label class="form-label small mb-1" for="to">To</label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= esc($r['to']) ?>"></div>
        <button class="btn btn-sm btn-primary" type="submit">Show</button>
        <a class="btn btn-sm btn-outline-primary" href="<?= site_url($base . '/xlsx') . '?' . esc(http_build_query(['from' => $r['from'], 'to' => $r['to']])) ?>">Download Excel</a>
    </form>
</div>

<div class="row g-3 mb-4" id="totals">
    <?php foreach ([
        'Submitted' => (int) $t['submitted'],
        'Disposed' => (int) $t['disposed'] . ' (' . $pct($t['disposed'], $t['submitted']) . ')',
        'Still open' => (int) $t['pending'] + (int) $t['awaiting'],
        'Transferred at least once' => (int) $t['escalated'] . ' (' . $pct($t['escalated'], $t['submitted']) . ')',
        'Average days to dispose' => $d($t['avg_days']),
        'Median days to dispose' => $d($t['median_days']),
    ] as $label => $value): ?>
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body py-3">
            <div class="h4 mb-0"><?= esc((string) $value) ?></div><div class="small text-muted"><?= esc($label) ?></div>
        </div></div></div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header bg-white"><strong>By type</strong></div>
            <div class="table-responsive"><table class="table table-sm mb-0" id="by-type">
                <thead class="table-light"><tr><th class="ps-3">Type</th><th class="text-end">Total</th><th class="text-end">Pending</th><th class="text-end">Awaiting</th><th class="text-end">Disposed</th><th class="text-end pe-3">Avg days</th></tr></thead>
                <tbody>
                <?php foreach ($r['byType'] as $x): ?>
                    <tr><td class="ps-3"><?= esc($x['name']) ?> <span class="text-muted small">(<?= esc(role_label($x['role'])) ?>)</span></td>
                        <td class="text-end"><?= (int) $x['total'] ?></td><td class="text-end"><?= (int) $x['pending'] ?></td>
                        <td class="text-end"><?= (int) $x['awaiting'] ?></td><td class="text-end"><?= (int) $x['disposed'] ?></td>
                        <td class="text-end pe-3"><?= esc($d($x['avg_days'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-white"><strong>Open grievances by post, right now</strong>
                <span class="small text-muted ms-1">(not limited to the dates above)</span></div>
            <?php if ($r['posts'] === []): ?>
                <div class="card-body text-muted small">No open grievances.</div>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-sm mb-0" id="by-post">
                    <thead class="table-light"><tr><th class="ps-3">Post</th><th class="text-end">Open</th><th class="text-end">Awaiting submitter</th><th class="text-end pe-3">Overdue</th></tr></thead>
                    <tbody>
                    <?php foreach ($r['posts'] as $x): ?>
                        <tr class="<?= (int) $x['overdue'] > 0 ? 'table-danger' : '' ?>">
                            <td class="ps-3"><?= esc($x['post']) ?><?= $x['vacant'] ? ' <span class="badge text-bg-warning">vacant</span>' : '' ?></td>
                            <td class="text-end"><?= (int) $x['open'] ?></td><td class="text-end"><?= (int) $x['awaiting'] ?></td>
                            <td class="text-end pe-3"><?= (int) $x['overdue'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-white"><strong>By month</strong></div>
            <div class="table-responsive"><table class="table table-sm mb-0" id="by-month">
                <thead class="table-light"><tr><th class="ps-3">Month</th><th class="text-end">Submitted</th><th class="text-end pe-3">Disposed</th></tr></thead>
                <tbody>
                <?php $max = max(1, ...array_map(static fn ($x) => (int) $x['submitted'], $r['byMonth'] ?: [['submitted' => 1]])); ?>
                <?php foreach ($r['byMonth'] as $x): ?>
                    <tr><td class="ps-3 text-nowrap"><?= esc(date('M Y', strtotime($x['month'] . '-01'))) ?></td>
                        <td class="text-end" style="width:45%">
                            <div class="d-flex align-items-center justify-content-end gap-2">
                                <div class="bg-primary rounded" style="height:8px;width:<?= (int) round(100 * (int) $x['submitted'] / $max) ?>%"></div>
                                <span><?= (int) $x['submitted'] ?></span>
                            </div>
                        </td>
                        <td class="text-end pe-3"><?= (int) $x['disposed'] ?></td></tr>
                <?php endforeach; ?>
                <?php if ($r['byMonth'] === []): ?><tr><td colspan="3" class="ps-3 text-muted small">Nothing in this period.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
            <div class="card-footer small text-muted bg-white">Disposed = disposed during that month (whenever submitted).</div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-white"><strong>By department</strong></div>
            <div class="table-responsive"><table class="table table-sm mb-0" id="by-department">
                <thead class="table-light"><tr><th class="ps-3">Department</th><th class="text-end">Total</th><th class="text-end">Open</th><th class="text-end pe-3">Disposed</th></tr></thead>
                <tbody>
                <?php foreach ($r['byDepartment'] as $x): ?>
                    <tr><td class="ps-3"><?= esc($x['name']) ?></td><td class="text-end"><?= (int) $x['total'] ?></td>
                        <td class="text-end"><?= (int) $x['open'] ?></td><td class="text-end pe-3"><?= (int) $x['disposed'] ?></td></tr>
                <?php endforeach; ?>
                <?php if ($r['byDepartment'] === []): ?><tr><td colspan="4" class="ps-3 text-muted small">Nothing in this period.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
<?php if (str_starts_with($base, 'authority')): ?>
    <p class="small"><a href="<?= site_url('authority') ?>">&larr; Back to my grievances</a></p>
<?php endif; ?>
<?= $this->endSection() ?>
