<?php
/**
 * สร้าง/แก้ไขการจองจากหลังบ้าน (รับงานทางโทรศัพท์ / LINE / หน้าร้าน)
 * ตัวแปร: b, id, locked, sel, selAddons, defPick, defRet, vatOn, cars, deliveryKm, tiersText
 */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'bookings']);
$dis = $locked ? 'disabled' : '';
?>
<form method="post" action="<?= url($id ? 'admin/bookings/' . $id . '/edit' : 'admin/bookings/new') ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="rev" value="<?= e($b['updated_at'] ?? '') ?>">
  <?php if ($locked): ?>
    <div class="alert alert-warn"><span><b>การจองนี้<?= $b['status'] === 'cancelled' ? 'ถูกยกเลิกแล้ว' : 'ส่งมอบรถแล้ว' ?></b> — แก้ได้เฉพาะข้อมูลผู้เช่า จุดรับ–คืน และหมายเหตุ
      รถ วัน–เวลา และราคาถูกล็อกเพราะผูกกับผลตรวจสภาพรถและการเงินไปแล้ว<?= $b['status'] === 'picked_up' ? ' · ต้องการเลื่อนวันคืนให้ใช้ปุ่ม "เช่าต่อ / เลื่อนวันคืน" ในหน้าการจอง' : '' ?></span></div>
  <?php endif; ?>

  <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
    <div class="min-w-0">
      <div class="panel">
        <h3>รถและช่วงเวลา</h3>
        <div class="field">
          <label>เลือกรถ *</label>
          <select name="car_id" id="car_id" required <?= $dis ?>>
            <option value="">— เลือกรถ —</option>
            <?php foreach ($cars as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= selected((int) ($sel['car_id'] ?? 0) === (int) $c['id']) ?>>
                <?= e($c['brand'] . ' ' . $c['model'] . ' — ' . $c['plate']) ?>
                (฿<?= money0($c['price_day']) ?>/วัน<?= (float) $c['price_month'] > 0 ? ', ฿' . money0($c['price_month']) . '/เดือน' : '' ?>)
                <?= $c['status'] !== 'available' ? ' [' . $c['status'] . ']' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="grid gap-x-3.5 sm:grid-cols-2">
          <div class="field"><label>วัน–เวลารับรถ *</label>
            <input type="datetime-local" name="pickup_at" id="pickup_at" value="<?= e($defPick) ?>" required <?= $dis ?>></div>
          <div class="field"><label>วัน–เวลาคืนรถ *</label>
            <input type="datetime-local" name="return_at" id="return_at" value="<?= e($defRet) ?>" required <?= $dis ?>></div>
          <div class="field"><label>จุดรับรถ</label>
            <input type="text" name="pickup_place" value="<?= e($sel['pickup_place'] ?? '') ?>"></div>
          <div class="field"><label>จุดคืนรถ</label>
            <input type="text" name="return_place" value="<?= e($sel['return_place'] ?? '') ?>"></div>
        </div>
      </div>

      <div class="panel mt-4">
        <h3>ข้อมูลผู้เช่า</h3>
        <div class="grid gap-x-3.5 sm:grid-cols-2">
          <div class="field"><label>ชื่อ–นามสกุล *</label>
            <input type="text" name="contact_name" value="<?= e($sel['contact_name'] ?? '') ?>" required></div>
          <div class="field"><label>เบอร์โทร *</label>
            <input type="tel" name="contact_phone" value="<?= e($sel['contact_phone'] ?? '') ?>" required></div>
          <div class="field"><label>อีเมล</label>
            <input type="email" name="contact_email" value="<?= e($sel['contact_email'] ?? '') ?>"></div>
          <div class="field"><label>LINE ID</label>
            <input type="text" name="contact_line" value="<?= e($sel['contact_line'] ?? '') ?>"></div>
        </div>
        <div class="field"><label>หมายเหตุ</label>
          <textarea name="note"><?= e($sel['note'] ?? '') ?></textarea></div>
      </div>

      <div class="panel mt-4">
        <h3>บริการเสริม</h3>
        <div class="grid gap-x-3.5 sm:grid-cols-2">
          <?php foreach (['delivery' => 'ส่งรถถึงที่', 'return_delivery' => 'รับรถคืนถึงที่', 'driver' => 'คนขับรถ',
                          'child_seat' => 'คาร์ซีทเด็ก', 'insurance_plus' => 'ประกันเสริม', 'gps' => 'GPS / WiFi'] as $k => $l): ?>
            <label class="checkline">
              <input type="checkbox" class="addon" name="addons[<?= $k ?>]" value="1" <?= !empty($selAddons[$k]) ? 'checked' : '' ?> <?= $dis ?>>
              <span><?= e($l) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="field mt-3 max-w-[260px]"><label>ระยะทางส่ง–รับรถจากกรุงเทพ (กม.)</label>
          <input type="number" name="delivery_km" id="delivery_km" min="0" max="2000" step="1" <?= $dis ?> value="<?= e($deliveryKm) ?>">
          <div class="hint"><?= e($tiersText) ?></div></div>
      </div>
    </div>

    <div class="min-w-0">
      <div class="panel lg:sticky lg:top-[78px]">
        <h3>สรุปราคา</h3>
        <div id="quoteBox"><p class="text-sm text-muted">เลือกรถและช่วงเวลาเพื่อคำนวณราคา</p></div>

        <div class="field mt-3"><label>ส่วนลด (บาท)</label>
          <input type="number" name="discount" id="discount" step="0.01" min="0" value="<?= e($sel['discount'] ?? 0) ?>" <?= $dis ?>></div>
        <label class="checkline">
          <input type="checkbox" name="vat" id="vat" value="1" <?= $vatOn ? 'checked' : '' ?> <?= $dis ?>>
          <span>คิด VAT <?= e(setting('vat_rate', '7')) ?>% (ออกใบกำกับภาษี)</span>
        </label>
        <div class="mt-3 grid gap-x-3.5 sm:grid-cols-2">
          <div class="field"><label>มัดจำ (บาท)</label>
            <input type="number" name="deposit_amount" id="deposit_amount" step="0.01" min="0" value="<?= e($sel['deposit_amount'] ?? '') ?>" <?= $dis ?>></div>
          <div class="field"><label>เงินประกัน (บาท)</label>
            <input type="number" name="security_deposit" id="security_deposit" step="0.01" min="0" value="<?= e($sel['security_deposit'] ?? '') ?>" <?= $dis ?>
                   placeholder="ตามเงื่อนไขรถ"></div>
        </div>

        <?php if (!$id): ?>
          <div class="grid gap-x-3.5 sm:grid-cols-2">
            <div class="field"><label>สถานะเริ่มต้น</label>
              <select name="status">
                <option value="confirmed">ยืนยันแล้ว</option>
                <option value="pending">รอยืนยัน</option>
              </select></div>
            <div class="field"><label>ช่องทาง</label>
              <select name="source">
                <option value="phone">โทรศัพท์</option>
                <option value="line">LINE</option>
                <option value="admin">หน้าร้าน</option>
              </select></div>
          </div>
        <?php endif; ?>

        <button class="btn btn-accent btn-block mt-3"><?= $id ? 'บันทึกการแก้ไข' : 'สร้างการจอง' ?></button>
        <a class="btn btn-ghost btn-block mt-2" href="<?= url($id ? 'admin/bookings/' . $id : 'admin/bookings') ?>">ยกเลิก</a>
      </div>
    </div>
  </div>
</form>

<?php $this->start('after_main'); ?>
<script>
(function () {
  var box = document.getElementById('quoteBox');
  var f = { car: document.getElementById('car_id'), p: document.getElementById('pickup_at'),
            r: document.getElementById('return_at'), d: document.getElementById('discount') };
  var t = null;

  function refresh() {
    if (!f.car.value || !f.p.value || !f.r.value) return;
    var addons = {};
    document.querySelectorAll('.addon:checked').forEach(function (el) {
      var m = el.name.match(/addons\[(\w+)\]/); if (m) addons[m[1]] = 1;
    });
    var kmEl = document.getElementById('delivery_km');
    var params = new URLSearchParams({ car_id: f.car.value, pickup: f.p.value, ret: f.r.value,
                                       addons: JSON.stringify(addons), admin: 1, km: (kmEl && kmEl.value) || 0,
                                       ignore: <?= (int) $id ?> });
    box.style.opacity = .5;
    fetch(<?= json_encode(url('api/quote')) ?> + '?' + params.toString())
      .then(function (r) { return r.json(); })
      .then(function (d) {
        box.innerHTML = d.html; box.style.opacity = 1;
        var dep = document.getElementById('deposit_amount');
        if (dep && !dep.disabled && (!dep.value || dep.value === '0')) dep.value = d.deposit;
      })
      .catch(function () { box.style.opacity = 1; });
  }

  document.addEventListener('change', function (e) {
    if (e.target.matches('#car_id, #pickup_at, #return_at, .addon, #delivery_km')) {
      clearTimeout(t); t = setTimeout(refresh, 150);
    }
  });
  refresh();
})();
</script>
<?php $this->end(); ?>
