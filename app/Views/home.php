<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Home<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="hero mb-4">
    <div class="eyebrow"><span lang="hi" class="hi-sans">संस्थान की आधिकारिक शिकायत प्रणाली</span> &nbsp;|&nbsp; OFFICIAL INSTITUTE GRIEVANCE SYSTEM</div>
    <h1 lang="hi">शिकायत निवारण पोर्टल</h1>
    <h2><?= esc(institute_name()) ?> Grievance Redressal Portal</h2>
    <div class="gold-rule"></div>
    <p class="mb-2" lang="hi">विद्यार्थी, संकाय सदस्य और गैर-शिक्षण कर्मचारी अपनी शिकायत दर्ज कर सकते हैं और समाधान होने तक उसकी स्थिति देख सकते हैं। कृपया अपने संस्थान के email खाते से साइन इन करें।</p>
    <p class="text-muted mb-0">Students, faculty and non-teaching staff can submit a grievance and follow it until it is resolved.
        Sign in with your Institute Email account.</p>
</div>

<?php if ($person === null && $user === null): ?>
    <div class="card" id="signin-card">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2 class="h5 mb-1">Sign in to continue <span class="hi-sans fw-normal text-muted" lang="hi">/ जारी रखने के लिए साइन इन करें</span></h2>
                <p class="text-muted small mb-0">Use your @<?= esc(config('Google')->studentDomain) ?> (students) or @<?= esc(config('Google')->staffDomain) ?> (staff) account.</p>
            </div>
            <a class="btn btn-primary" href="<?= site_url('login') ?>">Sign in</a>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <?php if ($person !== null): ?>
        <div class="col-md-6">
            <div class="card h-100"><div class="card-body d-flex flex-column">
                <h2 class="h5">Submit a grievance</h2>
                <p class="text-muted small flex-grow-1">As <?= esc(role_label($person['role'])) ?>: <?= esc($person['name']) ?> (<?= esc($person['id_code']) ?>).
                    You will receive an OTP on your e-mail and mobile to confirm it is you.</p>
                <?php if ($canSubmit): ?>
                    <a class="btn btn-primary" href="<?= site_url('submit') ?>">Submit a grievance</a>
                <?php else: ?>
                    <button class="btn btn-secondary" disabled>Not available yet</button>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="col-md-6">
            <div class="card h-100"><div class="card-body d-flex flex-column">
                <h2 class="h5">My grievances</h2>
                <p class="text-muted small flex-grow-1">See the status, read the authority's remarks and answer their questions.</p>
                <a class="btn btn-outline-primary" href="<?= site_url('my') ?>">My grievances</a>
            </div></div>
        </div>
    <?php endif; ?>
    <?php if ($user !== null): ?>
        <div class="col-md-6">
            <div class="card h-100"><div class="card-body d-flex flex-column">
                <h2 class="h5"><?= $user['is_admin'] ? 'Administration' : 'Grievances for your posts' ?></h2>
                <p class="text-muted small flex-grow-1"><?= $user['is_admin'] ? 'Lists, accounts, settings and reports.' : 'Grievances waiting for you, and those you have handled.' ?></p>
                <a class="btn btn-outline-primary" href="<?= site_url($user['is_admin'] ? 'admin' : 'authority') ?>"><?= $user['is_admin'] ? 'Admin dashboard' : 'Authority dashboard' ?></a>
            </div></div>
        </div>
    <?php endif; ?>
</div>
<?php if ($person === null && $user === null): ?>
    <h2 class="h4 mt-5 mb-3"><span lang="hi">कौन आवेदन कर सकता है</span><br><small class="fs-6">Who can submit a grievance</small></h2>
    <div class="row g-3" id="who-can-submit">
        <div class="col-md-4"><div class="card role-card h-100"><div class="card-body">
            <div class="role-hi" lang="hi">विद्यार्थी</div><div class="role-en mb-2">Students</div>
            <p class="small mb-2"><span lang="hi">शैक्षणिक, छात्रावास, अनुशासन और अन्य शिकायतें।</span><br>Academic, hostel, disciplinary and other grievances.</p>
            <div class="small text-muted border-top pt-2">Sign in with <strong>@<?= esc(config('Google')->studentDomain) ?></strong></div>
        </div></div></div>
        <div class="col-md-4"><div class="card role-card h-100"><div class="card-body">
            <div class="role-hi" lang="hi">संकाय सदस्य</div><div class="role-en mb-2">Faculty</div>
            <p class="small mb-2"><span lang="hi">संकाय से संबंधित सामान्य शिकायतें।</span><br>General grievances of faculty members.</p>
            <div class="small text-muted border-top pt-2">Sign in with <strong>@<?= esc(config('Google')->staffDomain) ?></strong></div>
        </div></div></div>
        <div class="col-md-4"><div class="card role-card h-100"><div class="card-body">
            <div class="role-hi" lang="hi">गैर-शिक्षण कर्मचारी</div><div class="role-en mb-2">Non-Teaching Staff</div>
            <p class="small mb-2"><span lang="hi">गैर-शिक्षण कर्मचारियों की सामान्य शिकायतें।</span><br>General grievances of non-teaching staff.</p>
            <div class="small text-muted border-top pt-2">Sign in with <strong>@<?= esc(config('Google')->staffDomain) ?></strong></div>
        </div></div></div>
    </div>

    <h2 class="h4 mt-5 mb-3" id="process"><span lang="hi">प्रक्रिया</span><br><small class="fs-6">How it works</small></h2>
    <div class="row g-4 mb-3">
        <div class="col-md-3"><div class="step"><div class="num">1</div><div class="fw-bold hi-sans" lang="hi">साइन इन करें</div><div class="fw-bold">Sign in</div><div class="small text-muted">Use your institute email account.</div></div></div>
        <div class="col-md-3"><div class="step"><div class="num">2</div><div class="fw-bold hi-sans" lang="hi">शिकायत भरें</div><div class="fw-bold">Fill the form</div><div class="small text-muted">Choose the type, describe it, attach up to 3 files.</div></div></div>
        <div class="col-md-3"><div class="step"><div class="num">3</div><div class="fw-bold hi-sans" lang="hi">OTP से सत्यापित करें</div><div class="fw-bold">Verify with OTP</div><div class="small text-muted">A one-time code goes to your e-mail and mobile.</div></div></div>
        <div class="col-md-3"><div class="step"><div class="num">4</div><div class="fw-bold hi-sans" lang="hi">स्थिति देखें</div><div class="fw-bold">Track progress</div><div class="small text-muted">See remarks and reply under My grievances.</div></div></div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
