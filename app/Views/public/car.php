<?php
/**
 * รายละเอียดรถ + ฟอร์มจอง (คิดราคาสดผ่าน /api/quote)
 * ตัวแปร: car, carName, features, busy, addonOpts, pickup, ret, periodError, available, q
 */
$this->layout('layouts/public', ['title' => $title, 'desc' => $desc, 'active' => 'cars']);
?>
<?php $this->start('mobile_bar') ?>
<div class="fixed inset-x-0 bottom-0 z-[90] flex items-center gap-[9px] border-t border-line bg-white px-4 py-[9px] pb-[calc(9px+env(safe-area-inset-bottom))] shadow-[0_-4px_20px_rgba(22,33,46,.08)] lg:hidden print:hidden">
  <div class="min-w-0 flex-auto overflow-hidden">
    <b class="block truncate text-[1.05rem] leading-tight text-dark" id="mbTotal"><?= money0($q['grand_total']) ?> บาท</b>
    <span class="block truncate text-[0.72rem] text-muted" id="mbSub"><?= (int) $q['days'] ?> วัน · มัดจำ <?= money0($q['deposit_amount']) ?> บ.</span>
  </div>
  <a class="btn flex-none" href="#book">จองคันนี้ <?= icon('arrow') ?></a>
</div>
<?php $this->end() ?>

<div class="wrap pt-3.5">
  <p class="mb-4 text-sm text-muted">
    <a href="<?= url('cars') ?>">รถเช่าของเรา</a> / <?= e($carName) ?>
  </p>
</div>

<div class="wrap">
  <div class="grid items-start gap-[18px] lg:grid-cols-[minmax(0,1.9fr)_minmax(0,1fr)] lg:gap-6">
    <!-- ================= ซ้าย ================= -->
    <div class="min-w-0">
      <div class="panel">
        <div class="mb-3.5 overflow-hidden rounded-xl bg-white">
          <?php if ($car['cover_image']): ?>
            <img class="h-auto w-full object-contain" src="<?= e(upload_url($car['cover_image'])) ?>" alt="<?= e($carName) ?>"
                 width="900" height="640" fetchpriority="high">
          <?php else: ?>
            <div class="grid aspect-[4/3] place-items-center bg-surface text-muted"><?= icon('car') ?></div>
          <?php endif; ?>
        </div>

        <div class="eyebrow"><?= e($car['segment'] ?: car_type_label($car['car_type'])) ?></div>
        <h1 class="mb-[0.2em]"><?= e($carName) ?> <?= e($car['year'] ?: '') ?></h1>
        <p class="text-sm text-muted">สี<?= e($car['color']) ?> · ทะเบียน <?= e($car['plate']) ?> <?= e($car['province']) ?></p>

        <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-[0.8rem] text-ink-2">
          <span class="inline-flex items-center gap-1"><?= icon('seat', 'h-[14px] w-[14px] text-muted') ?><?= (int) $car['seats'] ?> ที่นั่ง</span>
          <span class="inline-flex items-center gap-1"><?= icon('gear', 'h-[14px] w-[14px] text-muted') ?><?= $car['gear'] === 'auto' ? 'เกียร์ออโต้' : 'เกียร์ธรรมดา' ?></span>
          <span class="inline-flex items-center gap-1"><?= icon('fuel', 'h-[14px] w-[14px] text-muted') ?><?= e($car['fuel']) ?></span>
          <?php if ($car['insurance_type']): ?>
            <span class="inline-flex items-center gap-1"><?= icon('shield', 'h-[14px] w-[14px] text-muted') ?>ประกัน<?= e($car['insurance_type']) ?></span>
          <?php endif; ?>
        </div>

        <?php if ($car['description']): ?>
          <p class="mb-0 mt-4"><?= nl2br(e($car['description'])) ?></p>
        <?php endif; ?>

        <?php if ($features): ?>
          <div class="mt-6">
            <h3 class="mb-2 text-sm">อุปกรณ์และจุดเด่น</h3>
            <div class="flex flex-wrap gap-2">
              <?php foreach ($features as $f): ?>
                <span class="badge badge-primary"><?= e($f) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- เรตค่าเช่า -->
      <div class="panel mt-4">
        <h3>เรตค่าเช่า</h3>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>แบบ</th><th class="num">ราคา</th><th class="num">เฉลี่ย/วัน</th></tr></thead>
            <tbody>
              <tr><td>รายวัน</td><td class="num"><?= money0($car['price_day']) ?></td>
                  <td class="num"><?= money0($car['price_day']) ?></td></tr>
              <?php if ((float) $car['price_week'] > 0): ?>
                <tr><td>รายสัปดาห์ <span class="text-[0.79rem] text-muted">(7 วัน)</span></td>
                    <td class="num"><?= money0($car['price_week']) ?></td>
                    <td class="num text-ok"><?= money0($car['price_week'] / 7) ?></td></tr>
              <?php endif; ?>
              <?php if ((float) $car['price_month'] > 0): ?>
                <tr><td>รายเดือน <span class="text-[0.79rem] text-muted">(30 วัน)</span></td>
                    <td class="num"><?= money0($car['price_month']) ?></td>
                    <td class="num text-ok"><?= money0($car['price_month'] / 30) ?></td></tr>
              <?php endif; ?>
              <tr><td>เงินประกัน <span class="text-[0.79rem] text-muted">(คืนเมื่อคืนรถ)</span></td>
                  <td class="num"><?= money0($q['security_deposit']) ?></td><td class="num">—</td></tr>
            </tbody>
          </table>
        </div>
        <p class="mb-0 mt-4 text-[0.79rem] text-muted">เช่ายาวระบบจะคิดเรตสัปดาห์/เดือนให้อัตโนมัติ เลือกยอดที่ถูกที่สุดเสมอ</p>
      </div>

      <!-- ================= ฟอร์มจอง ================= -->
      <div class="panel mt-4" id="book">
        <h2>กรอกข้อมูลเพื่อจอง</h2>
        <?= $this->partial('partials/flash') ?>
        <form method="post" action="<?= url('booking') ?>" id="bookForm">
          <?= csrf_field() ?>
          <input type="hidden" name="car_id" value="<?= (int) $car['id'] ?>">

          <h3 class="mt-4 text-sm">ช่วงเวลาเช่า</h3>
          <div class="grid gap-3.5 sm:grid-cols-2 sm:gap-4">
            <div class="field">
              <label for="pickup_at">วัน–เวลารับรถ *</label>
              <input type="datetime-local" id="pickup_at" name="pickup_at" value="<?= e($pickup) ?>" required>
            </div>
            <div class="field">
              <label for="return_at">วัน–เวลาคืนรถ *</label>
              <input type="datetime-local" id="return_at" name="return_at" value="<?= e($ret) ?>" required>
            </div>
            <div class="field">
              <label for="pickup_place_type">รับรถที่</label>
              <select id="pickup_place_type" name="pickup_place_type">
                <option value="shop">ที่ร้าน / ส่งถึงที่</option>
                <option value="airport">สนามบิน</option>
              </select>
              <div class="hint" id="pickWinHint"></div>
            </div>
            <div class="field">
              <label for="return_place_type">คืนรถที่</label>
              <select id="return_place_type" name="return_place_type">
                <option value="shop">ที่ร้าน / รับคืนถึงที่</option>
                <option value="airport">สนามบิน</option>
              </select>
              <div class="hint" id="retWinHint"></div>
            </div>
            <div class="field">
              <label for="pickup_place">จุดรับรถ</label>
              <input type="text" id="pickup_place" name="pickup_place" placeholder="เช่น สนามบินดอนเมือง">
            </div>
            <div class="field">
              <label for="return_place">จุดคืนรถ</label>
              <input type="text" id="return_place" name="return_place" placeholder="เว้นว่าง = คืนที่เดิม">
            </div>
            <div class="field sm:col-span-2" id="kmField" hidden>
              <label for="delivery_km">ระยะทางจากกรุงเทพ (กม.)</label>
              <input type="number" id="delivery_km" name="delivery_km" min="0" max="500" step="1"
                     inputmode="numeric" placeholder="เช่น 45">
              <div class="hint"><?= e(\App\Services\DeliveryFeeService::tiersText()) ?></div>
            </div>
          </div>

          <h3 class="mt-6 text-sm">บริการเสริม</h3>
          <div class="grid gap-3.5 sm:grid-cols-2 sm:gap-4">
            <?php foreach ($addonOpts as $k => [$label, $price, $unit]):
                if ($price == 0) continue;
                $priceText = $price < 0
                    ? 'คิดตามระยะทาง · ไม่เกิน ' . (int) \App\Services\DeliveryFeeService::freeKm() . ' กม. ฟรี'
                    : money0($price) . ' บ. / ' . $unit; ?>
              <label class="checkline">
                <input type="checkbox" name="addons[<?= e($k) ?>]" value="1" class="addon">
                <span><?= e($label) ?>
                  <span class="block text-[0.79rem] text-muted"><?= e($priceText) ?></span></span>
              </label>
            <?php endforeach; ?>
          </div>

          <h3 class="mt-6 text-sm">ข้อมูลผู้เช่า</h3>
          <div class="grid gap-3.5 sm:grid-cols-2 sm:gap-4">
            <div class="field">
              <label for="contact_name">ชื่อ–นามสกุล *</label>
              <input type="text" id="contact_name" name="contact_name" required maxlength="180" autocomplete="name">
            </div>
            <div class="field">
              <label for="contact_phone">เบอร์โทรศัพท์ *</label>
              <input type="tel" id="contact_phone" name="contact_phone" required maxlength="30"
                     placeholder="08xxxxxxxx" inputmode="tel" autocomplete="tel">
            </div>
            <div class="field">
              <label for="contact_email">อีเมล</label>
              <input type="email" id="contact_email" name="contact_email" maxlength="150"
                     inputmode="email" autocomplete="email">
              <div class="hint">ใส่อีเมลเพื่อรับใบยืนยันการจองอัตโนมัติ</div>
            </div>
            <div class="field">
              <label for="contact_line">LINE ID</label>
              <input type="text" id="contact_line" name="contact_line" maxlength="80">
            </div>
          </div>

          <div class="field">
            <label for="note">หมายเหตุถึงเจ้าหน้าที่</label>
            <textarea id="note" name="note" maxlength="1000"
                      placeholder="เช่น ขอรถสีเข้ม, ต้องการใบกำกับภาษี, เดินทางต่างจังหวัด"></textarea>
          </div>

          <label class="checkline">
            <input type="checkbox" name="accept" value="1" required>
            <span class="text-sm">ยอมรับ<a href="<?= url('terms') ?>" target="_blank">เงื่อนไขการเช่ารถ</a>
              และยินยอมให้เก็บข้อมูลส่วนบุคคลเพื่อทำสัญญาเช่า ตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล</span>
          </label>

          <button class="btn btn-lg btn-block mt-4" type="submit" id="submitBtn" <?= $available ? '' : 'disabled' ?>>
            <?= icon('check') ?> ยืนยันการจอง
          </button>
          <p class="mb-0 mt-4 text-center text-[0.79rem] text-muted">
            กดจองยังไม่มีการตัดเงิน เจ้าหน้าที่จะติดต่อกลับเพื่อยืนยัน
          </p>
        </form>
      </div>
    </div>

    <!-- ================= ขวา: สรุปราคา ================= -->
    <div class="min-w-0">
      <div class="lg:sticky lg:top-[88px]">
        <div class="panel">
          <h3>สรุปราคา</h3>
          <div id="quoteBox" class="transition-opacity">
            <?= $this->partial('partials/quote_box', ['q' => $q, 'available' => $available, 'periodError' => $periodError]) ?>
          </div>
          <a class="btn btn-block mt-4 hidden lg:flex" href="#book"><?= icon('check') ?> ไปที่ฟอร์มจอง</a>
        </div>

        <div class="panel mt-4">
          <h3 class="text-sm">บัญชีรับโอนมัดจำ</h3>
          <div class="bank">
            <dl class="dl">
              <dt>ธนาคาร</dt><dd><?= e(setting('bank_name')) ?></dd>
              <dt>เลขที่</dt><dd><?= e(setting('bank_account_no')) ?></dd>
              <dt>ชื่อบัญชี</dt><dd class="text-[0.85rem]"><?= e(setting('bank_account_name')) ?></dd>
              <?php if (setting('promptpay_id')): ?>
                <dt>PromptPay</dt><dd><?= e(setting('promptpay_id')) ?></dd>
              <?php endif; ?>
            </dl>
          </div>
        </div>

        <?php if ($busy): ?>
          <div class="panel mt-4">
            <h3 class="text-sm">ช่วงที่รถไม่ว่าง</h3>
            <ul class="m-0 pl-[18px] text-sm text-muted">
              <?php foreach (array_slice($busy, 0, 6) as $b): ?>
                <li><?= e(thai_date($b['start_at'])) ?> – <?= e(thai_date($b['end_at'])) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var form = document.getElementById('bookForm');
  var box  = document.getElementById('quoteBox');
  var btn  = document.getElementById('submitBtn');
  var mbT  = document.getElementById('mbTotal');
  var mbS  = document.getElementById('mbSub');
  var carId = <?= (int) $car['id'] ?>;
  var timer = null;

  function fmt(n) { return Number(n).toLocaleString('th-TH', { maximumFractionDigits: 0 }); }

  function refresh() {
    var pickup = form.pickup_at.value, ret = form.return_at.value;
    if (!pickup || !ret) return;

    var addons = {};
    form.querySelectorAll('.addon:checked').forEach(function (el) {
      var m = el.name.match(/addons\[(\w+)\]/);
      if (m) addons[m[1]] = 1;
    });

    var params = new URLSearchParams({
      car_id: carId, pickup: pickup, ret: ret,
      addons: JSON.stringify(addons),
      km: (form.delivery_km && form.delivery_km.value) || 0,
      pick_type: form.pickup_place_type ? form.pickup_place_type.value : 'shop',
      ret_type:  form.return_place_type ? form.return_place_type.value : 'shop'
    });
    box.style.opacity = .5;
    fetch(<?= json_encode(url('api/quote')) ?> + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        box.innerHTML = d.html;
        box.style.opacity = 1;
        btn.disabled = !d.available;
        btn.textContent = d.available ? 'ยืนยันการจอง' : (d.error || 'รถไม่ว่างในช่วงนี้');
        if (mbT) mbT.textContent = fmt(d.total) + ' บาท';
        if (mbS) mbS.textContent = d.days + ' วัน · มัดจำ ' + fmt(d.deposit) + ' บ.';
      })
      .catch(function () { box.style.opacity = 1; });
  }

  form.addEventListener('change', function (e) {
    if (e.target.matches('#pickup_at, #return_at, .addon, #delivery_km, #pickup_place_type, #return_place_type')) {
      clearTimeout(timer);
      timer = setTimeout(refresh, 150);
    }
  });

  // เลื่อนไปฟอร์มแบบนุ่มนวล เผื่อ header sticky บัง
  document.querySelectorAll('a[href="#book"]').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      ev.preventDefault();
      var t = document.getElementById('book');
      var y = t.getBoundingClientRect().top + window.pageYOffset - 70;
      window.scrollTo({ top: y, behavior: 'smooth' });
      setTimeout(function () { form.contact_name.focus({ preventScroll: true }); }, 500);
    });
  });
  var kmIn = document.getElementById('delivery_km');
  if (kmIn) {
    kmIn.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(refresh, 400);
    });
  }

  (function () {
    var WIN = {
      shop:    { open: <?= json_encode(setting('open_time', '08:00')) ?>,
                 close: <?= json_encode(setting('close_time', '20:00')) ?> },
      airport: { open: <?= json_encode(setting('airport_open_time', '05:00')) ?>,
                 close: <?= json_encode(setting('airport_close_time', '24:00')) ?> }
    };
    var kmField = document.getElementById('kmField');
    var pickSel = document.getElementById('pickup_place_type');
    var retSel  = document.getElementById('return_place_type');
    var pickHint = document.getElementById('pickWinHint');
    var retHint  = document.getElementById('retWinHint');
    function win(sel) { return WIN[sel.value] || WIN.shop; }
    function paint() {
      if (pickHint) { var w = win(pickSel); pickHint.textContent = 'รับรถได้ ' + w.open + '–' + w.close + ' น.'; }
      if (retHint)  { var v = win(retSel);  retHint.textContent  = 'คืนรถได้ ' + v.open + '–' + v.close + ' น.'; }
      var wantDelivery = form.querySelector('input[name="addons[delivery]"]:checked')
                      || form.querySelector('input[name="addons[return_delivery]"]:checked');
      if (kmField) kmField.hidden = !wantDelivery;
    }
    if (pickSel) pickSel.addEventListener('change', paint);
    if (retSel)  retSel.addEventListener('change', paint);
    form.addEventListener('change', function (e) {
      if (e.target.matches('input[name="addons[delivery]"], input[name="addons[return_delivery]"]')) paint();
    });
    paint();
  })();

  // ---------- สถิติเทียบ UX: นับเมื่อลูกค้า "เริ่มกรอกฟอร์ม" จริง ----------
  var formTouched = false;
  form.addEventListener('focusin', function (ev) {
    if (formTouched) return;
    if (!ev.target.matches('input, select, textarea')) return;
    formTouched = true;
    var body = new URLSearchParams({ step: 'start_form', car_id: carId });
    var trackUrl = <?= json_encode(url('api/track')) ?>;
    if (navigator.sendBeacon) {
      navigator.sendBeacon(trackUrl, body);
    } else {
      fetch(trackUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(function () {});
    }
  });
})();
</script>
