<?php
use App\Services\BookingStatus;
$val = static fn(string $k): string => (string) ($in[$k] ?? '');
?>
<a href="<?= e(url('/admin/bookings')) ?>" class="text-sm text-slate-500 hover:text-slate-800">← การจองทั้งหมด</a>
<h1 class="text-2xl font-bold mt-2">สร้างการจอง</h1>

<?php if ($errors): ?>
  <div class="alert alert-error mt-4"><?php foreach ($errors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?></div>
<?php endif; ?>
<?php if (!empty($warnBlacklist)): ?>
  <div class="alert alert-error mt-4">เบอร์นี้เป็นลูกค้า blacklist — ตรวจสอบก่อนยืนยัน</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/bookings')) ?>" class="panel mt-6 space-y-6">
  <?= csrf_field() ?>

  <div class="grid gap-4 sm:grid-cols-3">
    <div class="sm:col-span-3">
      <label class="label" for="car_id">รถ</label>
      <select class="field" id="car_id" name="car_id" required>
        <option value="">— เลือกรถ —</option>
        <?php foreach ($cars as $c): ?>
          <option value="<?= e($c['id']) ?>" <?= $val('car_id') === (string) $c['id'] ? 'selected' : '' ?>>
            <?= e($c['plate'] . ' · ' . $c['brand'] . ' ' . $c['model']) ?> (฿<?= e(money($c['price_day'])) ?>/วัน)
            <?= $c['status'] !== 'available' ? ' — ' . e(\App\Services\CarService::STATUSES[$c['status']]) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label" for="pickup_at">รับรถ</label>
      <input class="field" id="pickup_at" type="datetime-local" name="pickup_at" value="<?= e($val('pickup_at')) ?>" required>
    </div>
    <div>
      <label class="label" for="return_at">คืนรถ</label>
      <input class="field" id="return_at" type="datetime-local" name="return_at" value="<?= e($val('return_at')) ?>" required>
    </div>
    <div>
      <label class="label" for="source">ช่องทาง</label>
      <select class="field" id="source" name="source">
        <?php foreach (['phone', 'line', 'admin'] as $s): ?>
          <option value="<?= e($s) ?>" <?= $val('source') === $s ? 'selected' : '' ?>><?= e(BookingStatus::SOURCE[$s]) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div>
    <h2 class="font-semibold">ผู้เช่า</h2>
    <div class="grid gap-4 sm:grid-cols-2 mt-3">
      <div><label class="label" for="name">ชื่อ–นามสกุล</label><input class="field" id="name" name="name" value="<?= e($val('name')) ?>" required></div>
      <div><label class="label" for="phone">เบอร์โทร</label><input class="field" id="phone" name="phone" type="tel" value="<?= e($val('phone')) ?>" required></div>
      <div><label class="label" for="email">อีเมล</label><input class="field" id="email" name="email" type="email" value="<?= e($val('email')) ?>"></div>
      <div><label class="label" for="line">LINE ID</label><input class="field" id="line" name="line" value="<?= e($val('line')) ?>"></div>
      <div><label class="label" for="pickup_place">จุดรับรถ</label><input class="field" id="pickup_place" name="pickup_place" value="<?= e($val('pickup_place')) ?>"></div>
      <div><label class="label" for="return_place">จุดคืนรถ</label><input class="field" id="return_place" name="return_place" value="<?= e($val('return_place')) ?>"></div>
    </div>
  </div>

  <div>
    <h2 class="font-semibold">บริการเสริม / ราคา</h2>
    <div class="grid gap-2 sm:grid-cols-3 mt-3">
      <?php foreach ($catalog as $code => $addon): ?>
        <label class="flex items-center gap-2 text-sm">
          <input type="checkbox" name="addons[<?= e($code) ?>]" value="1" <?= !empty($in['addons'][$code]) ? 'checked' : '' ?>>
          <?= e($addon['name']) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="grid gap-4 sm:grid-cols-4 mt-4">
      <div><label class="label" for="km">ระยะส่ง–รับรถ (กม.)</label><input class="field" id="km" type="number" min="0" name="km" value="<?= e($val('km')) ?>"></div>
      <div><label class="label" for="discount">ส่วนลด (บาท)</label><input class="field" id="discount" name="discount" value="<?= e($val('discount')) ?>"></div>
      <div><label class="label" for="deposit_amount">มัดจำจอง (ว่าง = ตามระบบ)</label><input class="field" id="deposit_amount" name="deposit_amount" value="<?= e($val('deposit_amount')) ?>"></div>
      <div><label class="label" for="security_deposit">เงินประกัน (ว่าง = ตามระบบ)</label><input class="field" id="security_deposit" name="security_deposit" value="<?= e($val('security_deposit')) ?>"></div>
    </div>
    <label class="flex items-center gap-2 text-sm mt-3">
      <input type="checkbox" name="vat" value="1" <?= !empty($in['vat']) ? 'checked' : '' ?>> คิด VAT
    </label>
  </div>

  <div>
    <label class="label" for="note">หมายเหตุ</label>
    <textarea class="field" id="note" name="note" rows="2"><?= e($val('note')) ?></textarea>
  </div>

  <div class="flex gap-3">
    <button class="btn btn-primary">สร้างการจอง</button>
    <a class="btn" href="<?= e(url('/admin/bookings')) ?>">ยกเลิก</a>
  </div>
</form>
