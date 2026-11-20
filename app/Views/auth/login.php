<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Sign in<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $g = config('Google'); ?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card">
            <div class="card-body p-4">
                <h1 class="h4 mb-0" lang="hi">साइन इन</h1>
                <h2 class="h5 mb-2">Sign in</h2>
                <div class="gold-rule" style="width:64px;margin:.4rem 0 .8rem"></div>
                <p class="mb-2" lang="hi">अपने संस्थान के Email खाते से साइन इन करें। विद्यार्थी @<?= esc($g->studentDomain) ?> और संकाय/कर्मचारी/अधिकारी @<?= esc($g->staffDomain) ?> का उपयोग करें।</p>
                <p class="text-muted small">Students, faculty, staff and authorities sign in with their Institute Email account:
                    <strong>@<?= esc($g->studentDomain) ?></strong> (students) or <strong>@<?= esc($g->staffDomain) ?></strong> (faculty, non-teaching staff and authorities).</p>
                <?php if ($googleReady): ?>
                    <a class="btn btn-primary w-100" id="google-signin" href="<?= site_url('auth/google') ?>"><span class="d-block hi-sans small fw-normal" lang="hi">Institute Email से साइन इन करें</span>Sign in with Institute Email</a>
                <?php else: ?>
                    <div class="alert alert-warning small mb-0" id="google-missing">Google sign-in has not been set up yet (google.clientId and google.clientSecret in .env).</div>
                <?php endif; ?>
                <?php if ($devLogin): ?>
                    <a class="btn btn-outline-secondary w-100 mt-2" id="dev-signin" href="<?= site_url('auth/dev') ?>">Test sign-in (development only)</a>
                <?php endif; ?>
                <?php // Administrator sign-in link intentionally disabled. ?>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
