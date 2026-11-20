<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?><?= $person ? 'Edit person' : 'Add person' ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $idLabel = $role === 'student' ? 'Scholar Number' : 'Employee Code'; $student = $role === 'student';
$v = static fn (string $k, $default = '') => old($k, null, false) ?? ($default); ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= site_url("admin/people/{$role}") ?>">Master list: <?= esc(role_label($role)) ?></a></li>
    <li class="breadcrumb-item active"><?= $person ? esc($person['id_code']) : 'New person' ?></li>
</ol></nav>
<div class="card" style="max-width:640px"><div class="card-body">
    <h1 class="h5 mb-3"><?= $person ? 'Edit person' : 'Add a person' ?></h1>
    <form method="post" action="<?= $person ? site_url("admin/people/{$role}/{$person['id']}/update") : site_url("admin/people/{$role}") ?>">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label"><?= esc($idLabel) ?></label>
            <?php if ($person): ?><input class="form-control" value="<?= esc($person['id_code']) ?>" disabled><div class="form-text">The <?= esc($idLabel) ?> cannot be changed<?= $grievances > 0 ? ' (this person has ' . (int) $grievances . ' grievance(s))' : '' ?>.</div>
            <?php else: ?><input class="form-control" name="id_code" value="<?= esc($v('id_code')) ?>" maxlength="50" required><?php endif; ?></div>
        <div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="name" value="<?= esc($v('name', $person['name'] ?? '')) ?>" maxlength="150" required></div>
        <div class="mb-3"><label class="form-label">Department</label>
            <select class="form-select" name="department_id" required><option value="">Choose…</option>
                <?php $cur = (int) $v('department_id', $person['department_id'] ?? 0); foreach ($depts as $d): ?><option value="<?= $d['id'] ?>" <?= (int) $d['id'] === $cur ? 'selected' : '' ?>><?= esc($d['name']) ?></option><?php endforeach; ?>
            </select></div>
        <?php if ($student): ?>
            <div class="mb-3"><label class="form-label">Semester</label>
                <select class="form-select" name="semester_id"><option value="">—</option>
                    <?php $cur = (int) $v('semester_id', $person['semester_id'] ?? 0); foreach ($semesters as $s): ?><option value="<?= $s['id'] ?>" <?= (int) $s['id'] === $cur ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="mb-3"><label class="form-label">Hostel</label>
                <input class="form-control" name="hostel" value="<?= esc($v('hostel', $person['hostel'] ?? '')) ?>" maxlength="150" placeholder="Leave blank for a day scholar"></div>
            <div class="mb-3"><label class="form-label">Room</label>
                <input class="form-control" name="room" value="<?= esc($v('room', $person['room'] ?? '')) ?>" maxlength="150"></div>
        <?php endif; ?>
        <div class="mb-3"><label class="form-label">Mobile / contact <span class="text-muted">(optional here)</span></label><input class="form-control" name="contact" value="<?= esc($v('contact', $person['contact'] ?? '')) ?>" maxlength="20">
            <div class="form-text">The OTP for submitting a grievance is sent to this number.</div></div>
        <?php $dom = $role === 'student' ? config('Google')->studentDomain : config('Google')->staffDomain; ?>
        <div class="mb-3"><label class="form-label" for="email">Institute e-mail</label><input class="form-control" id="email" name="email" type="email" value="<?= esc($v('email', $person['email'] ?? '')) ?>" maxlength="190" placeholder="name@<?= esc($dom) ?>">
            <div class="form-text">The Google account (@<?= esc($dom) ?>) this person signs in with. Without it they cannot sign in.</div></div>
        <button class="btn btn-primary"><?= $person ? 'Save' : 'Add person' ?></button>
        <a class="btn btn-link" href="<?= site_url("admin/people/{$role}") ?>">Cancel</a>
    </form>
    <?php if ($person && $grievances === 0): ?>
        <hr><form method="post" action="<?= site_url("admin/people/{$role}/{$person['id']}/delete") ?>" onsubmit="return confirm('Delete this person permanently?');"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Delete person</button></form>
    <?php endif; ?>
</div></div>
<?= $this->endSection() ?>
