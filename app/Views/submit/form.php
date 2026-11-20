<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Submit a grievance<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
use App\Libraries\SubmissionService;
$hasHostel = trim((string) ($person['hostel'] ?? '')) !== '';
// People from the ERP: say why there is no hostel, and show the room
$erp = $person['erp'] ?? null;
$hostelText = $hasHostel
    ? $person['hostel'] . (($person['room'] ?? null) ? ', room ' . $person['room'] : '')
    : match ($erp['hostel_status'] ?? 'none') {
        'unknown'  => 'Not available right now',
        'conflict' => 'Unclear in the records',
        default    => 'Day scholar (no hostel)',
    };
$blockers = SubmissionService::erpBlockers($person);
$locked = static fn (string $label, string $value) =>
    '<div class="col-md-6"><label class="form-label">' . esc($label) . '</label><input class="form-control" value="' . esc($value) . '" disabled></div>';
$oneType = count($types) === 1;
?>
<div class="row justify-content-center"><div class="col-lg-8">
<?php foreach ($blockers as $b): ?><div class="alert alert-warning"><?= esc($b) ?></div><?php endforeach; ?>
<form action="<?= site_url('submit') ?>" method="post" enctype="multipart/form-data" id="grievance-form">
    <?= csrf_field() ?>

    <div class="card mb-3"><div class="card-body p-4">
        <h1 class="h5 mb-1">Your details</h1>
        <p class="text-muted small">Taken from the institute's records. Only the contact number can be changed here; it is saved with this grievance only.
            If anything else is wrong, please contact the administration.</p>
        <div class="row g-3">
            <?php foreach ($fields as $f):
                $key = $f['field_key'];
                $lockedValue = [
                    'scholar_number' => $person['id_code'], 'employee_code' => $person['id_code'], 'name' => $person['name'],
                    'department' => $person['department'], 'semester' => (string) $person['semester'],
                    'hostel' => $hostelText,
                ];
                if (isset($lockedValue[$key])):
                    echo $locked($f['label'], $lockedValue[$key]);
                elseif ($key === 'contact'): ?>
                    <div class="col-md-6">
                        <label class="form-label" for="contact"><?= esc($f['label']) ?> <span class="text-danger">*</span></label>
                        <input class="form-control" id="contact" name="contact" value="<?= esc(old('contact', null, false) ?? (string) $person['contact']) ?>" required maxlength="20" inputmode="numeric">
                        <div class="form-text">For this grievance. The OTP always goes to the mobile number in the institute's records.</div>
                    </div>
                <?php else:
                    $name  = 'cf[' . $key . ']';
                    $value = old('cf.' . $key, null, false) ?? ''; ?>
                    <div class="col-md-6">
                        <label class="form-label" for="f-<?= esc($key) ?>"><?= esc($f['label']) ?><?= is_true($f['mandatory']) ? ' <span class="text-danger">*</span>' : '' ?></label>
                        <?php if ($f['field_type'] === 'dropdown'): ?>
                            <select class="form-select" id="f-<?= esc($key) ?>" name="<?= esc($name) ?>" <?= is_true($f['mandatory']) ? 'required' : '' ?>>
                                <option value="">Select…</option>
                                <?php foreach ($f['options'] as $o): ?>
                                    <option value="<?= esc($o) ?>" <?= $value === $o ? 'selected' : '' ?>><?= esc($o) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input class="form-control" id="f-<?= esc($key) ?>" name="<?= esc($name) ?>" value="<?= esc($value) ?>"
                                   type="<?= $f['field_type'] === 'date' ? 'date' : ($f['field_type'] === 'number' ? 'number' : 'text') ?>"
                                   <?= $f['field_type'] === 'number' ? 'step="any"' : '' ?> <?= is_true($f['mandatory']) ? 'required' : '' ?> maxlength="500">
                        <?php endif; ?>
                    </div>
                <?php endif;
            endforeach; ?>
        </div>
    </div></div>

    <div class="card mb-3"><div class="card-body p-4">
        <h2 class="h5 mb-3">Your grievance</h2>

        <div class="mb-3">
            <label class="form-label" for="type_id">Type of grievance <span class="text-danger">*</span></label>
            <?php if ($oneType): ?>
                <input type="hidden" name="type_id" value="<?= (int) $types[0]['id'] ?>">
                <input class="form-control" value="<?= esc($types[0]['name']) ?>" disabled>
            <?php else: $picked = (int) old('type_id'); ?>
                <select class="form-select" id="type_id" name="type_id" required>
                    <option value="">Select…</option>
                    <?php foreach ($types as $t):
                        $blocked = $t['requires_hostel'] && ! $hasHostel; ?>
                        <option value="<?= (int) $t['id'] ?>" <?= $picked === (int) $t['id'] ? 'selected' : '' ?> <?= $blocked ? 'disabled' : '' ?>>
                            <?= esc($t['name']) ?><?= $blocked ? ' (not available: no hostel recorded for you)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="subject">Subject <span class="text-danger">*</span></label>
            <input class="form-control" id="subject" name="subject" value="<?= esc(old('subject', null, false)) ?>" required maxlength="<?= \App\Libraries\SubmissionService::SUBJECT_MAX ?>">
        </div>

        <div class="mb-3">
            <label class="form-label" for="description">Description <span class="text-danger">*</span></label>
            <textarea class="form-control" id="description" name="description" rows="7" required maxlength="<?= \App\Libraries\SubmissionService::DESCRIPTION_MAX ?>"><?= esc(old('description', null, false)) ?></textarea>
            <div class="form-text">Describe what happened, when, and what you would like done. Up to <?= \App\Libraries\SubmissionService::DESCRIPTION_MAX ?> characters.</div>
        </div>

        <?php if ($maxFiles > 0): ?>
            <div class="mb-3">
                <label class="form-label" for="attachments">Attachments (optional)</label>
                <input class="form-control" id="attachments" name="attachments[]" type="file" multiple
                       accept="<?= esc(implode(',', array_map(static fn ($e) => '.' . $e, $allowedExt))) ?>">
                <div class="form-text">Up to <?= $maxFiles ?> file(s), <?= $maxMb ?> MB each. Allowed: <?= esc(strtoupper(implode(', ', $allowedExt))) ?>.</div>
                <div class="form-text text-danger" id="file-warning"></div>
            </div>
        <?php endif; ?>

        <?php if (! empty($duplicates)): ?>
            <div class="alert alert-warning">
                <p class="mb-2">Pending grievance(s) of this type already exist:
                    <?= esc(implode(', ', array_column($duplicates, 'reference_no'))) ?></p>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="confirm_duplicate" name="confirm_duplicate" value="1">
                    <label class="form-check-label" for="confirm_duplicate">I understand, submit another grievance of this type.</label>
                </div>
                <div class="small mt-2">Your files have to be selected again after this warning.</div>
            </div>
        <?php endif; ?>

    </div></div>

    <div class="card mb-3 border-primary" id="otp-card"><div class="card-body p-4">
        <h2 class="h5 mb-1">Confirm it is you</h2>
        <p class="small text-muted mb-3">
            <?php if ($otpTestMode): ?>
                Development testing is enabled. No OTP will be sent; enter <strong>1234</strong>.
            <?php else: ?>
            We send one 6-digit OTP to
            <?= esc(implode(' and ', array_filter([
                $otpMasked['email'] !== null ? 'your e-mail ' . $otpMasked['email'] : null,
                $otpMasked['mobile'] !== null ? 'your mobile ' . $otpMasked['mobile'] : null,
            ])) ?: 'you') ?>.
            The same OTP arrives on both; enter it from either. It is valid for <?= (int) $otpMinutes ?> minutes.
            <?php endif; ?>
        </p>
        <div class="alert alert-success small py-2 d-none" id="otp-status" role="status"></div>
        <div class="row g-2 align-items-end">
            <div class="col-sm-5">
                <label class="form-label" for="otp">OTP <span class="text-danger">*</span></label>
                <input class="form-control" id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="<?= $otpTestMode ? 4 : 6 ?>" pattern="[0-9]{<?= $otpTestMode ? 4 : 6 ?>}" placeholder="<?= $otpTestMode ? '4 digits' : '6 digits' ?>">
            </div>
            <div class="col-sm-auto">
                <button class="btn btn-outline-primary" type="submit" id="send-otp" formaction="<?= site_url('submit/otp') ?>" formnovalidate>
                    <?php if ($otpTestMode): ?>Enable test OTP<?php else: ?><?= empty($otpSentTo) ? 'Send OTP' : 'Send a new OTP' ?><?php endif; ?></button>
            </div>
        </div>
        <hr>
        <button class="btn btn-primary" type="submit" id="submit-btn">Submit grievance</button>
        <a class="btn btn-link" href="<?= site_url('my') ?>">Cancel</a>
    </div></div>
</form>
</div></div>
<script>
(function () {
    var form = document.getElementById('grievance-form');
    // "Send OTP" in the background, so the form (and the chosen files) stay as they are
    var sendBtn = document.getElementById('send-otp');
    var status = document.getElementById('otp-status');
    var countdown = null;
    function showStatus(ok, text) {
        status.className = 'alert small py-2 ' + (ok ? 'alert-success' : 'alert-danger');
        status.textContent = text;
    }
    function waitBeforeResend(seconds) {
        clearInterval(countdown);
        var left = seconds;
        sendBtn.disabled = true;
        var tick = function () {
            if (left <= 0) { clearInterval(countdown); sendBtn.disabled = false; sendBtn.textContent = 'Send a new OTP'; return; }
            sendBtn.textContent = 'Send a new OTP (' + left + ' s)';
            left--;
        };
        tick();
        countdown = setInterval(tick, 1000);
    }
    if (sendBtn && window.fetch && window.FormData) {
        sendBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var tokenInput = form.querySelector('input[name="<?= csrf_token() ?>"]');
            var data = new FormData();
            data.append(tokenInput.name, tokenInput.value);
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending…';
            fetch('<?= site_url('submit/otp') ?>', {
                method: 'POST', body: data, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                if (! r.ok) { throw new Error('http ' + r.status); }
                return r.json();
            }).then(function (res) {
                document.querySelectorAll('input[name="<?= csrf_token() ?>"]').forEach(function (i) { i.value = res.csrf; });
                showStatus(res.ok, res.message);
                if (res.ok) {
                    waitBeforeResend(res.resendAfter || 60);
                    document.getElementById('otp').focus();
                } else {
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Send OTP';
                }
            }).catch(function () {
                showStatus(false, 'The OTP could not be requested (your session may have expired). Please reload the page and try again.');
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send OTP';
            });
        });
    }
    var input = document.getElementById('attachments');
    var warn = document.getElementById('file-warning');
    var max = <?= (int) $maxFiles ?>, maxBytes = <?= (int) $maxMb ?> * 1024 * 1024;
    if (input) {
        input.addEventListener('change', function () {
            var msg = '';
            if (input.files.length > max) msg = 'Please choose at most ' + max + ' file(s).';
            for (var i = 0; i < input.files.length; i++) {
                if (input.files[i].size > maxBytes) msg = '"' + input.files[i].name + '" is larger than the limit.';
            }
            warn.textContent = msg;
        });
    }
    form.addEventListener('submit', function (e) {      // stop accidental double submission
        var sendingOtp = e.submitter && e.submitter.id === 'send-otp';
        var otp = document.getElementById('otp');
        if (! sendingOtp && ! new RegExp('^[0-9]{<?= $otpTestMode ? 4 : 6 ?>}$').test(otp.value.trim())) {
            e.preventDefault();
            otp.focus();
            alert('Please enter the <?= $otpTestMode ? 4 : 6 ?>-digit OTP. Use "Send OTP" if you have not requested one.');
            return;
        }
        var b = e.submitter || document.getElementById('submit-btn');
        setTimeout(function () { b.disabled = true; b.textContent = sendingOtp ? 'Sending…' : 'Submitting…'; }, 0);
    });
})();
</script>
<?= $this->endSection() ?>
