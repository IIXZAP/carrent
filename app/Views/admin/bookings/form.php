<?php
/** สร้างการจอง (พนักงาน): $in $errors $cars $catalog $warnBlacklist */
use App\Services\BookingStatus;
$val = static fn(string $k): string => (string) ($in[$k] ?? '');
?>
<?php if ($errors): ?>
  <div class="alert alert-danger"><div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div></div>
<?php endif; ?>
<?php if (!empty($warnBlacklist)): ?>
  <div class="alert alert-danger">เบอร์นี้เป็นลูกค้า blacklist — ตรวจสอบก่อนยืนยัน</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/bookings')) ?>" class="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
  <?= csrf_field() ?>
  <div class="min-w-0 space-y-4">
    <div class="panel">
      <h3>รถและช่วงเวลา</h3>
      <div class="field">
        <label for="car_id">เลือกรถ *</label>
        <select id="car_id" name="car_id" required>
          <option value="">— เลือกรถ —</option>
          <?php foreach ($cars as $c): ?>
            <option value="<?= e($c['id']) ?>"<?= selected($val('car_id') === (string) $c['id']) ?>>
              <?= e($c['plate'] . ' · ' . $c['brand'] . ' ' . $c['model']) ?> (฿<?= e(money0($c['price_day'])) ?>/วัน)<?= $c['status'] !== 'available' ? ' — ' . e(\App\Services\CarService::STATUSES[$c['status']]) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grid gap-3.5 sm:grid-cols-2 sm:gap-4">
        <div class="field"><label for="pickup_at">วัน–เวลารับรถ *</label><input id="pickup_at" type="datetime-local" name="pickup_at" value="<?= e($val('pickup_at')) ?>" required></div>
        <div class="field"><label for="return_at">วัน–เวลาคืนรถ *</label><input id="return_at" type="datetime-local" name="return_at" value="<?= e($val('return_at')) ?>" required></div>
        <div class="field"><label for="pickup_place">จุดรับรถ</label><input id="pickup_place" name="pickup_place" value="<?= e($val('pickup_place')) ?>"></div>
        <div class="field"><label for="return_place">จุดคืนรถ</label><input id="return_place" name="return_place" value="<?= e($val('return_place')) ?>"></div>
      </div>
    </div>

    <div class="panel">
      <h3>ข้อมูลผู้เช่า</h3>
      <div class="grid gap-3.5 sm:grid-cols-2 sm:gap-4">
        <div class="field"><label for="name">ชื่อ–นามสกุล *</label><input id="name" name="name" value="<?= e($val('name')) ?>" required></div>
        <div class="field"><label for="phone">เบอร์โทร *</label><input id="phone" name="phone" type="tel" value="<?= e($val('phone')) ?>" required></div>
        <div class="field"><label for="email">อีเมล</label><input id="email" name="email" type="email" value="<?= e($val('email')) ?>"></div>
        <div class="field"><label for="line">LINE ID</label><input id="line" name="line" value="<?= e($val('line')) ?>"></div>
      </div>
      <div class="field"><label for="note">หมายเหตุ</label><textarea id="note" name="note" rows="2"><?= e($val('note')) ?></textarea></div>
    </div>

    <div class="panel">
      <h3>บริการเสริม</h3>
      <div class="grid gap-x-4 sm:grid-cols-2">
        <?php foreach ($catalog as $code => $addon): ?>
          <label class="checkline"><input type="checkbox" name="addons[<?= e($code) ?>]" value="1"<?= checked(!empty($in['addons'][$code])) ?>><span><?= e($addon['name']) ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="field mt-3 sm:w-1/2">
        <label for="km">ระยะทางส่ง–รับรถจากกรุงเทพ (กม.)</label>
        <input id="km" type="number" min="0" name="km" value="<?= e($val('km')) ?>">
        <div class="hint">กรอกเมื่อเลือกส่ง/รับรถถึงที่ — ตารางค่าส่งดูได้ที่ตั้งค่าร้าน</div>
      </div>
    </div>
  </div>

  <div class="min-w-0 lg:sticky lg:top-[78px]">
    <div class="panel">
      <h3>ราคาและเงินมัดจำ</h3>
      <div class="field"><label for="discount">ส่วนลด (บาท)</label><input id="discount" name="discount" inputmode="decimal" value="<?= e($val('discount')) ?>"></div>
      <label class="checkline"><input type="checkbox" name="vat" value="1"<?= checked(!empty($in['vat'])) ?>><span>คิด VAT (ออกใบกำกับภาษี)</span></label>
      <div class="grid grid-cols-2 gap-3 mt-2">
        <div class="field"><label for="deposit_amount">มัดจำ (บาท)</label><input id="deposit_amount" name="deposit_amount" inputmode="decimal" placeholder="ตามระบบ" value="<?= e($val('deposit_amount')) ?>"></div>
        <div class="field"><label for="security_deposit">เงินประกัน (บาท)</label><input id="security_deposit" name="security_deposit" inputmode="decimal" placeholder="ตามเงื่อนไขรถ" value="<?= e($val('security_deposit')) ?>"></div>
      </div>
      <div class="field">
        <label for="source">ช่องทาง</label>
        <select id="source" name="source">
          <?php foreach (['phone', 'line', 'admin'] as $s): ?>
            <option value="<?= e($s) ?>"<?= selected($val('source') === $s) ?>><?= e(BookingStatus::SOURCE[$s]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-accent btn-block btn-lg mt-2">สร้างการจอง</button>
      <a class="btn btn-ghost btn-block mt-2" href="<?= e(url('/admin/bookings')) ?>">ยกเลิก</a>
      <p class="mb-0 mt-3 text-xs text-muted">ระบบคิดราคาจากเรตของรถและบริการเสริมให้เมื่อกดสร้าง</p>
    </div>
  </div>
</form>
