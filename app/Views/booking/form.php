<?php /** ฟอร์มจองฝั่งลูกค้า: $car $in $pickup $return $quote $errors $selected */ ?>
<a href="<?= e(url('/cars/' . $car['id'] . '?' . http_build_query(['pickup' => input_datetime($pickup), 'return' => input_datetime($return)]))) ?>"
   class="text-sm text-slate-500 hover:text-slate-800">← กลับหน้ารถ</a>
<h1 class="text-2xl font-bold mt-2">จอง <?= e($car['brand'] . ' ' . $car['model']) ?></h1>

<?php if ($errors): ?>
  <div class="alert alert-error mt-4">
    <?php foreach ($errors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($quote !== null): ?>
  <div class="panel mt-6">
    <p class="text-sm text-slate-600">รับรถ <strong><?= e(date('d/m/Y H:i', strtotime($pickup))) ?></strong>
      · คืนรถ <strong><?= e(date('d/m/Y H:i', strtotime($return))) ?></strong></p>
    <?php require __DIR__ . '/../partials/quote_box.php'; ?>
  </div>

  <form method="post" action="<?= e(url('/booking')) ?>" class="panel mt-6 space-y-4">
    <?= csrf_field() ?>
    <input type="hidden" name="car_id" value="<?= e($car['id']) ?>">
    <input type="hidden" name="pickup" value="<?= e(input_datetime($pickup)) ?>">
    <input type="hidden" name="return" value="<?= e(input_datetime($return)) ?>">
    <input type="hidden" name="km" value="<?= e($in['km'] ?? '') ?>">
    <?php foreach ($selected as $code => $qty): ?>
      <input type="hidden" name="addons[<?= e($code) ?>]" value="<?= e($qty) ?>">
    <?php endforeach; ?>

    <h2 class="font-semibold">ข้อมูลผู้เช่า</h2>
    <div class="grid gap-4 sm:grid-cols-2">
      <div>
        <label class="label" for="name">ชื่อ–นามสกุล</label>
        <input class="field" id="name" name="name" value="<?= e($in['name'] ?? '') ?>" required maxlength="150">
      </div>
      <div>
        <label class="label" for="phone">เบอร์โทรศัพท์</label>
        <input class="field" id="phone" name="phone" type="tel" value="<?= e($in['phone'] ?? '') ?>" required maxlength="30">
      </div>
      <div>
        <label class="label" for="email">อีเมล (ไม่บังคับ)</label>
        <input class="field" id="email" name="email" type="email" value="<?= e($in['email'] ?? '') ?>" maxlength="150">
      </div>
      <div>
        <label class="label" for="line">LINE ID (ไม่บังคับ)</label>
        <input class="field" id="line" name="line" value="<?= e($in['line'] ?? '') ?>" maxlength="80">
      </div>
    </div>

    <h2 class="font-semibold pt-2">จุดรับ–คืนรถ</h2>
    <div class="grid gap-4 sm:grid-cols-2">
      <?php foreach (['pick' => 'จุดรับรถ', 'ret' => 'จุดคืนรถ'] as $k => $label): ?>
      <div>
        <label class="label" for="<?= e($k) ?>_type"><?= e($label) ?></label>
        <select class="field" id="<?= e($k) ?>_type" name="<?= e($k) ?>_type">
          <option value="shop" <?= ($in[$k . '_type'] ?? 'shop') === 'shop' ? 'selected' : '' ?>>ที่ร้าน / จุดทั่วไป</option>
          <option value="airport" <?= ($in[$k . '_type'] ?? '') === 'airport' ? 'selected' : '' ?>>สนามบิน</option>
        </select>
        <input class="field mt-2" name="place_<?= e($k) ?>" placeholder="รายละเอียดสถานที่ (ถ้ามี)"
               value="<?= e($in['place_' . $k] ?? '') ?>" maxlength="250">
      </div>
      <?php endforeach; ?>
    </div>

    <div>
      <label class="label" for="note">หมายเหตุ (ไม่บังคับ)</label>
      <textarea class="field" id="note" name="note" rows="2" maxlength="1000"><?= e($in['note'] ?? '') ?></textarea>
    </div>

    <label class="flex items-start gap-2 text-sm">
      <input type="checkbox" name="accept" value="1" class="mt-1" <?= !empty($in['accept']) ? 'checked' : '' ?>>
      <span>ข้าพเจ้ายอมรับเงื่อนไขการเช่ารถและนโยบายข้อมูลส่วนบุคคล</span>
    </label>

    <button class="btn btn-primary">ยืนยันการจอง</button>
    <p class="text-sm text-slate-500">หลังจอง ร้านจะรอรับมัดจำเพื่อยืนยันการจอง</p>
  </form>
<?php endif; ?>
