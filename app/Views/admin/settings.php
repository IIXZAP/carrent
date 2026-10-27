<h1 class="text-2xl font-bold">ตั้งค่าร้าน</h1>
<form method="post" action="<?= e(url('/admin/settings')) ?>" class="mt-6 space-y-6">
  <?= csrf_field() ?>
  <?php $group = null; foreach ($fields as $key => [$label, $type, $g]): ?>
    <?php if ($g !== $group): if ($group !== null): ?></div></div><?php endif; $group = $g; ?>
      <div class="panel"><h2 class="font-semibold"><?= e($g) ?></h2><div class="grid gap-4 sm:grid-cols-2 mt-4">
    <?php endif; ?>
    <?php if ($type === 'bool'): ?>
      <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" name="<?= e($key) ?>" value="1" <?= ($values[$key] ?? '0') === '1' ? 'checked' : '' ?>> <?= e($label) ?></label>
    <?php elseif ($type === 'textarea'): ?>
      <div class="sm:col-span-2">
        <label class="label" for="<?= e($key) ?>"><?= e($label) ?></label>
        <textarea class="field <?= isset($errors[$key]) ? 'field-error' : '' ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" rows="4"><?= e($values[$key] ?? '') ?></textarea>
        <?php if (isset($errors[$key])): ?><p class="text-sm text-red-600 mt-1"><?= e($errors[$key]) ?></p><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="<?= $type === 'tiers' ? 'sm:col-span-2' : '' ?>">
        <label class="label" for="<?= e($key) ?>"><?= e($label) ?></label>
        <input class="field <?= isset($errors[$key]) ? 'field-error' : '' ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($values[$key] ?? '') ?>">
        <?php if (isset($errors[$key])): ?><p class="text-sm text-red-600 mt-1"><?= e($errors[$key]) ?></p><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
  </div></div>
  <button class="btn btn-primary">บันทึก</button>
</form>
