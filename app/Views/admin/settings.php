<?= $this->extend('layouts/main') ?>
<?= $this->section('title') ?>Settings<?= $this->endSection() ?>
<?= $this->section('content') ?>
<h1 class="h4 mb-3">Portal settings</h1>

<form action="<?= site_url('admin/settings') ?>" method="post">
    <?= csrf_field() ?>
    <?php foreach ($groups as $group => $fields): ?>
        <div class="card mb-4">
            <div class="card-header bg-white"><strong><?= esc($group) ?></strong></div>
            <div class="card-body">
                <?php foreach ($fields as $key => $def):
                    $value = old($key, null, false) ?? ($values[$key] ?? ''); ?>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="s-<?= $key ?>"><?= esc($def['label']) ?></label>
                        <div class="col-md-6">
                            <?php if ($def['type'] === 'bool'): ?>
                                <div class="form-check form-switch mt-2">
                                    <input type="hidden" name="<?= $key ?>" value="0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="s-<?= $key ?>" name="<?= $key ?>" value="1" <?= is_true($value) || $value === '1' ? 'checked' : '' ?>>
                                </div>
                            <?php elseif ($def['type'] === 'filetypes'):
                                $picked = old($key) !== null ? (array) old($key) : explode(',', (string) ($values[$key] ?? '')); ?>
                                <div class="d-flex flex-wrap gap-3 pt-2">
                                    <?php foreach ($filetypes as $ext => $label): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="ft-<?= $ext ?>" name="<?= $key ?>[]" value="<?= $ext ?>" <?= in_array($ext, $picked, true) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="ft-<?= $ext ?>"><?= esc($label) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif ($def['type'] === 'int'): ?>
                                <input class="form-control" id="s-<?= $key ?>" name="<?= $key ?>" type="number" min="<?= $def['min'] ?>" max="<?= $def['max'] ?>" value="<?= esc($value) ?>" required>
                            <?php else: ?>
                                <input class="form-control<?= $def['type'] === 'prefix' ? ' text-uppercase' : '' ?>" id="s-<?= $key ?>" name="<?= $key ?>" value="<?= esc($value) ?>" required>
                            <?php endif; ?>
                            <?php if (! empty($def['help'])): ?><div class="form-text"><?= esc($def['help']) ?></div><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <button class="btn btn-primary" type="submit">Save settings</button>
</form>
<?= $this->endSection() ?>
