<ul class="nav nav-tabs mb-3">
    <?php foreach (['student' => 'Students', 'faculty' => 'Faculty', 'non_teaching' => 'Non-Teaching'] as $r => $label): ?>
        <li class="nav-item"><a class="nav-link<?= $r === $role ? ' active' : '' ?>" href="<?= site_url("admin/people/{$r}") ?>"><?= esc($label) ?>
            <span class="badge text-bg-light border"><?= (int) $counts[$r]['total'] ?></span></a></li>
    <?php endforeach; ?>
</ul>
