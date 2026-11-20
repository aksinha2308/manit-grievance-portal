<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Administrator sign in<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card">
            <div class="card-body p-4">
                <h1 class="h4 mb-2">Administrator sign in</h1>
                <p class="text-muted small">Use the Google account configured for the portal administrator, or the emergency username and password.</p>
                <?php if ($googleReady): ?>
                    <a class="btn btn-primary w-100 mb-3" href="<?= site_url('auth/admin/google') ?>">Sign in with Google</a>
                <?php else: ?>
                    <div class="alert alert-warning small">Google sign-in has not been configured. Use the emergency password or contact the administration.</div>
                <?php endif; ?>
                <hr>
                <!-- <form action="<?= site_url('admin-login') ?>" method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="username">Username</label>
                        <input class="form-control" id="username" name="username" value="<?= esc(old('username', null, false)) ?>" autocomplete="username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                    </div>
                    <button class="btn btn-outline-primary w-100" type="submit">Log in with password</button>
                </form>
                <p class="small text-muted mt-3 mb-0">Configure the administrator Google address with <code>php spark portal:admin-email name@<?= esc(config('Google')->staffDomain) ?></code>. Forgot the password? Run <code>php spark portal:reset-admin-password</code> on the server.</p>
                <p class="small mt-3 mb-0"><a href="<?= site_url('login') ?>">Back to sign in</a></p> -->
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
