<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Test sign-in<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card border-warning">
            <div class="card-body p-4">
                <h1 class="h5">Test sign-in (development only)</h1>
                <p class="small text-muted">Signs you in as the owner of an institute e-mail without asking Google. This page exists only while
                    <code>CI_ENVIRONMENT = development</code> and <code>google.devLogin = true</code>. Never switch it on for the live portal.</p>
                <form action="<?= site_url('auth/dev') ?>" method="post">
                    <?= csrf_field() ?>
                    <label class="form-label" for="email">Institute e-mail</label>
                    <input class="form-control mb-3" id="email" name="email" type="email" required autofocus>
                    <button class="btn btn-warning w-100" type="submit">Sign in</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
