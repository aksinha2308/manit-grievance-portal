<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Change password<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $min = \App\Libraries\Settings::int('password_min_length', 8); ?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card">
            <div class="card-body p-4">
                <h1 class="h5 mb-3">Change password</h1>
                <?php if (! empty($user['must_change_password'])): ?>
                    <div class="alert alert-warning">Your password was set by the admin. Choose a new one to continue.</div>
                <?php endif; ?>
                <form action="<?= site_url('account/password') ?>" method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password</label>
                        <input class="form-control" id="current_password" name="current_password" type="password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="new_password">New password</label>
                        <input class="form-control" id="new_password" name="new_password" type="password" minlength="<?= $min ?>" required>
                        <div class="form-text">At least <?= $min ?> characters.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="confirm_password">Confirm new password</label>
                        <input class="form-control" id="confirm_password" name="confirm_password" type="password" minlength="<?= $min ?>" required>
                    </div>
                    <button class="btn btn-primary" type="submit">Save new password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
