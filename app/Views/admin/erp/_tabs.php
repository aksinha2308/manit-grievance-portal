<?php $cur = service('request')->getUri()->getSegment(3) ?: 'mapping'; ?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link<?= $cur === 'mapping' ? ' active' : '' ?>" href="<?= site_url('admin/erp') ?>">Connection and mapping</a></li>
    <li class="nav-item"><a class="nav-link<?= $cur === 'people' ? ' active' : '' ?>" href="<?= site_url('admin/erp/people') ?>">People from the ERP</a></li>
    <li class="nav-item"><a class="nav-link<?= $cur === 'sync' ? ' active' : '' ?>" href="<?= site_url('admin/erp/sync') ?>">Sync from ERP</a></li>
</ul>
<?php if (! ($enabled ?? false)): ?>
    <div class="alert alert-warning small">People are currently read from the <strong>master list</strong>, not the ERP. Nothing on these pages changes who can sign in until you run
        <code>php spark portal:person-source erp</code>.</div>
<?php endif; ?>
