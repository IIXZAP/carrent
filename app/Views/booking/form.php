<?php
/** ฟอร์มจองฝั่งลูกค้า: $car $in $pickup $return $quote $errors $selected */
\App\Core\View::layout('layouts/public', ['title' => 'จอง ' . $car['brand'] . ' ' . $car['model'], 'active' => 'cars', 'noindex' => true]);
$carName = $car['brand'] . ' ' . $car['model'];
$back = url('/cars/' . $car['id']) . period_query($pickup, $return);
?>
<div class="wrap pb-10 pt-3.5">
  <p class="mb-4 text-sm text-muted"><a href="<?= e(url('/cars')) ?>">รถเช่าของเรา</a> / <a href="<?= e($back) ?>"><?= e($carName) ?></a> / จอง</p>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div></div>
  <?php endif; ?>

  <?php if ($quote === null): ?>
    <div class="panel"><p class="mb-3">กรุณาเลือกวัน–เวลารับและคืนรถที่หน้ารถก่อน</p><a class="btn" href="<?= e($back) ?>"><?= icon('back') ?> กลับหน้ารถ</a></div>
  <?php else: ?>
  <div class="grid items-start gap-[18px] lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] lg:gap-6">
    <form method="post" action="<?= e(url('/booking')) ?>" class="panel min-w-0 order-2 lg:order-1">
      <?= csrf_field() ?>
      <input type="hidden" name="car_id" value="<?= e($car['id']) ?>">
      <input type="hidden" name="pickup" value="<?= e(input_datetime($pickup)) ?>">
      <input type="hidden" name="return" value="<?= e(input_datetime($return)) ?>">
      <input type="hidden" name="km" value="<?= e($in['km'] ?? '') ?>">
      <?php foreach ($selected as $code => $qty): ?><input type="hidden" name="addons[<?= e($code) ?>]" value="<?= e($qty) ?>"><?php endforeach; ?>

      <h2>กรอกข้อมูลเพื่อจอง</h2>

      <h3 class="mt-4 text-sm">ข้อมูลผู้เช่า</h3>
      <div class="grid gap-3.5 sm:grid-cols-2 sm:gap-4">
        <div class="field"><label for="name">ชื่อ–นามสกุล *</label>
          <input type="text" id="name" name="name" value="<?= e($in['name'] ?? '') ?>" required maxlength="150" autocomplete="name"></div>
        <div class="field"><label for="phone">เบอร์โทรศัพท์ *</label>
          <input type="tel" id="phone" name="phone" value="<?= e($in['phone'] ?? '') ?>" required maxlength="30" placeholder="08xxxxxxxx" inputmode="tel" autocomplete="tel"></div>
        <div class="field"><label for="email">อีเมล</label>
          <input type="email" id="email" name="email" value="<?= e($in['email'] ?? '') ?>" maxlength="150" inputmode="email" autocomplete="email"></div>
        <div class="field"><label for="line">LINE ID</label>
          <input type="text" id="line" name="line" value="<?= e($in['line'] ?? '') ?>" maxlength="80"></div>
      </div>

      <h3 class="mt-6 text-sm">จุดรับ–คืนรถ</h3>
      <div class="grid gap-3.5 sm:grid-cols-2 sm:gap-4">
        <?php foreach (['pick' => 'รับรถที่', 'ret' => 'คืนรถที่'] as $k => $label): ?>
        <div class="field">
          <label for="<?= e($k) ?>_type"><?= e($label) ?></label>
          <select id="<?= e($k) ?>_type" name="<?= e($k) ?>_type">
            <option value="shop"<?= selected(($in[$k . '_type'] ?? 'shop') === 'shop') ?>>ที่ร้าน / ส่งถึงที่</option>
            <option value="airport"<?= selected(($in[$k . '_type'] ?? '') === 'airport') ?>>สนามบิน</option>
          </select>
          <input type="text" class="mt-2" name="place_<?= e($k) ?>" placeholder="รายละเอียดสถานที่ (ถ้ามี)" value="<?= e($in['place_' . $k] ?? '') ?>" maxlength="250">
        </div>
        <?php endforeach; ?>
      </div>

      <div class="field mt-4">
        <label for="note">หมายเหตุถึงเจ้าหน้าที่</label>
        <textarea id="note" name="note" maxlength="1000" placeholder="เช่น ขอรถสีเข้ม, ต้องการใบกำกับภาษี, เดินทางต่างจังหวัด"><?= e($in['note'] ?? '') ?></textarea>
      </div>

      <label class="checkline">
        <input type="checkbox" name="accept" value="1" <?= !empty($in['accept']) ? 'checked' : '' ?> required>
        <span class="text-sm">ยอมรับ<a href="<?= e(url('/terms')) ?>" target="_blank" rel="noopener">เงื่อนไขการเช่ารถ</a>และยินยอมให้เก็บข้อมูลส่วนบุคคลเพื่อทำสัญญาเช่า ตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล</span>
      </label>

      <button class="btn btn-lg btn-block mt-4" type="submit"><?= icon('check') ?> ยืนยันการจอง</button>
      <p class="mb-0 mt-4 text-center text-[0.79rem] text-muted">กดจองยังไม่มีการตัดเงิน ร้านจะรอรับมัดจำเพื่อยืนยันการจอง</p>
    </form>

    <aside class="order-1 min-w-0 lg:order-2 lg:sticky lg:top-[88px]">
      <div class="panel">
        <?php if (upload_exists($car['cover_image'] ?? null)): ?>
          <img class="mb-3 h-auto w-full rounded-xl object-contain" src="<?= e(upload_url($car['cover_image'])) ?>" alt="<?= e($carName) ?>" width="900" height="640">
        <?php endif; ?>
        <h3 class="mb-1"><?= e($carName) ?></h3>
        <p class="mb-3 text-[0.82rem] text-ink-2">รับ <b><?= e(thai_date($pickup, true)) ?></b><br>คืน <b><?= e(thai_date($return, true)) ?></b></p>
        <?php require __DIR__ . '/../partials/quote_box.php'; ?>
      </div>
    </aside>
  </div>
  <?php endif; ?>
</div>
