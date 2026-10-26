<?php /** ฟอร์มเลือกวัน–เวลารับ/คืนรถ ($pickup, $return, $types?, $type?, $action) */ ?>
<form method="get" action="<?= e(url($action)) ?>" class="panel grid gap-4 sm:grid-cols-4 items-end">
  <div>
    <label class="label" for="pickup">วัน–เวลารับรถ</label>
    <input class="field" id="pickup" type="datetime-local" name="pickup" value="<?= e(input_datetime($pickup)) ?>" required>
  </div>
  <div>
    <label class="label" for="return">วัน–เวลาคืนรถ</label>
    <input class="field" id="return" type="datetime-local" name="return" value="<?= e(input_datetime($return)) ?>" required>
  </div>
  <?php if (!empty($types)): ?>
  <div>
    <label class="label" for="type">ประเภทรถ</label>
    <select class="field" id="type" name="type">
      <option value="">ทุกประเภท</option>
      <?php foreach ($types as $t): ?>
        <option value="<?= e($t['code']) ?>" <?= ($type ?? '') === $t['code'] ? 'selected' : '' ?>><?= e($t['label_th']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <button class="btn btn-primary">ค้นหารถว่าง</button>
</form>
