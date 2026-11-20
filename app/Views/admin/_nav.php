<?php
$seg = service('request')->getUri()->getSegment(2) ?: 'dashboard';
$item = static fn (string $key, string $url, string $label) =>
    '<li class="nav-item"><a class="nav-link' . ($seg === $key ? ' active' : '') . '" href="' . site_url($url) . '">' . esc($label) . '</a></li>';
?>
<ul class="nav nav-pills mb-4 flex-wrap">
    <?= $item('dashboard', 'admin', 'Dashboard') ?>
    <?= $item('grievances', 'admin/grievances', 'Grievances') ?>
    <?= $item('report', 'admin/report', 'Report') ?>
    <?= $item('archive', 'admin/archive', 'Archive') ?>
    <?= $item('types', 'admin/types', 'Grievance types') ?>
    <?= $item('posts', 'admin/posts', 'Posts') ?>
    <?= $item('users', 'admin/users', 'Authorities') ?>
    <?= $item('post-holders', 'admin/post-holders', 'Post holders') ?>
    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle<?= $seg === 'lists' ? ' active' : '' ?>" data-bs-toggle="dropdown" href="#" role="button">Lists</a>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="<?= site_url('admin/lists/departments') ?>">Departments</a></li>
            <li><a class="dropdown-item" href="<?= site_url('admin/lists/semesters') ?>">Semesters</a></li>
        </ul>
    </li>
    <?= $item('fields', 'admin/fields', 'Form fields') ?>
    <?php if (! \App\Libraries\Erp\ErpDirectory::enabled()): ?><?= $item('people', 'admin/people', 'Master list') ?><?php endif; ?>
    <?= $item('erp', 'admin/erp', 'ERP') ?>
    <?= $item('settings', 'admin/settings', 'Settings') ?>
    <?= $item('audit', 'admin/audit', 'Audit log') ?>
</ul>
