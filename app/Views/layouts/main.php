<?php helper("portal"); ?>
<?php $user = \App\Libraries\Auth::user(); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($this->renderSection('title') ?: 'Grievance Portal') ?> · <?= esc(institute_name()) ?></title>
    <link rel="stylesheet" href="<?= esc(bootstrap_asset('css')) ?>">
    <link rel="icon" type="image/png" href="<?= base_url('assets/img/favicon.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Serif+Devanagari:wght@500;700&amp;family=Noto+Serif:wght@400;600;700&amp;family=Noto+Sans+Devanagari:wght@400;600&amp;family=Source+Sans+3:wght@400;600;700&amp;display=swap">
    <link rel="stylesheet" href="<?= base_url('assets/css/portal.css') ?>">
</head>
<body>
<header class="site-header">
    <div class="container py-3 d-flex align-items-center gap-3">
        <a href="<?= site_url('/') ?>" class="d-flex align-items-center gap-3 text-decoration-none">
            <img class="logo" src="<?= base_url('assets/img/manit-logo.png') ?>" alt="MANIT Bhopal emblem">
            <span class="d-flex flex-column">
                <span class="name-hi" lang="hi"><?= esc(institute_name_hi()) ?></span>
                <span class="name-en"><?= esc(institute_name_en()) ?></span>
            </span>
        </a>
    </div>
</header>
<nav class="navbar navbar-expand-md navbar-dark brand-bar mb-4">
    <div class="container">
        <a class="portal-title" href="<?= site_url('/') ?>">
            <span class="hi" lang="hi">शिकायत निवारण पोर्टल</span>
            <span class="en">Grievance Redressal Portal</span>
        </a>
        <?php $person = \App\Libraries\Auth::person(); ?>
        <?php if ($user || $person): ?>
            <div class="d-flex flex-wrap align-items-center gap-2 text-white d-print-none">
                <span class="small me-1"><?= esc($user['full_name'] ?? $person['name']) ?><?= ($user['is_admin'] ?? false) ? ' (Admin)' : '' ?></span>
                <?php if ($person): ?>
                    <a class="btn btn-sm btn-outline-light" href="<?= site_url('my') ?>">My grievances</a>
                <?php endif; ?>
                <?php if ($user && ! $user['is_admin']): ?>
                    <a class="btn btn-sm btn-outline-light" href="<?= site_url('authority') ?>">Authority dashboard</a>
                <?php endif; ?>
                <?php if ($user && $user['is_admin'] && session('login_via') === 'password'): ?>
                    <a class="btn btn-sm btn-outline-light" href="<?= site_url('account/password') ?>">Change password</a>
                <?php endif; ?>
                <form action="<?= site_url('logout') ?>" method="post" class="m-0">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-light" type="submit">Log out</button>
                </form>
            </div>
        <?php else: ?>
            <a class="btn btn-sm btn-outline-light d-print-none" href="<?= site_url('login') ?>">Sign in</a>
        <?php endif; ?>
    </div>
</nav>

<main class="container pb-4">
    <?php if ($user && $user['is_admin'] && service('request')->getUri()->getSegment(1) === 'admin'): ?>
        <?= view('admin/_nav') ?>
    <?php endif; ?>
    <?php if ($msg = session()->getFlashdata('success')): ?>
        <div class="alert alert-success"><?= esc($msg) ?></div>
    <?php endif; ?>
    <?php if ($msg = session()->getFlashdata('error')): ?>
        <div class="alert alert-danger"><?= esc($msg) ?></div>
    <?php endif; ?>
    <?php if ($errs = session()->getFlashdata('errors')): ?>
        <div class="alert alert-danger mb-3">
            <ul class="mb-0">
                <?php foreach ((array) $errs as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?= $this->renderSection('content') ?>
</main>

<footer class="site-footer d-print-none">
    <div class="container py-3 text-center">
        Designed and Developed by: Dr. Akash Sinha and Mr. Saurabh Umalkar
    </div>
</footer>

<script src="<?= esc(bootstrap_asset('js')) ?>"></script>
</body>
</html>
