<?php
/**
 * รายละเอียดรถ + ฟอร์มจอง (คิดราคาสดผ่าน api/quote.php)
 */
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/layout_public.php';

$id  = (int) ($_GET['id'] ?? 0);
$car = db_one("SELECT * FROM cars WHERE id = :id AND status <> 'inactive'", [':id' => $id]);
if (!$car) {
    http_response_code(404);
    page_head('ไม่พบรถคันนี้', '', ['noindex' => true]);
    echo '<div class="wrap section"><div class="panel center" style="padding:40px 20px">
          <h2>ไม่พบรถคันนี้</h2><p class="muted">รถอาจถูกนำออกจากระบบแล้ว</p>
          <a class="btn" href="' . url('cars.php') . '">ดูรถทั้งหมด</a></div></div>';
    page_foot();
    exit;
}
require_once __DIR__ . '/includes/abtrack.php';
ab_track_once('view_car', (int) $car['id']);

$pickup = trim($_GET['pickup'] ?? '') ?: date('Y-m-d\TH:i', strtotime('tomorrow 10:00'));
$ret    = trim($_GET['ret'] ?? '')    ?: date('Y-m-d\TH:i', strtotime('tomorrow +1 day 10:00'));
$pickDb = date('Y-m-d H:i:s', strtotime($pickup));
$retDb  = date('Y-m-d H:i:s', strtotime($ret));

$periodError = validate_period($pickDb, $retDb);
$available   = $periodError ? false : is_car_available($id, $pickDb, $retDb);
$q           = quote_booking($car, $pickDb, $retDb, [], 0.0, null, 0.0);

$features = json_decode((string) $car['features'], true) ?: [];
$busy     = car_busy_ranges($id);
$carName  = $car['brand'] . ' ' . $car['model'];

// แถบล่างมือถือ — ราคา + ปุ่มจอง
ob_start(); ?>
<div class="mobile-bar no-print">
  <div class="mb-price">
    <b id="mbTotal"><?= money0($q['grand_total']) ?> บาท</b>
    <span id="mbSub"><?= (int) $q['days'] ?> วัน · มัดจำ <?= money0($q['deposit_amount']) ?> บ.</span>
  </div>
  <a class="btn" href="#book">จองคันนี้ <?= icon('arrow') ?></a>
</div>
<?php $mobileBar = ob_get_clean();

page_head($carName, 'เช่า ' . $carName . ' เริ่มต้น ' . money0($car['price_day']) . ' บาท/วัน ' .
          ($car['segment'] ?: ''), ['active' => 'cars.php', 'mobile_bar' => $mobileBar]);
?>

<div class="wrap" style="padding-top:14px">
  <p class="small muted mb-2">
    <a href="<?= url('cars.php') ?>">รถเช่าของเรา</a> / <?= e($carName) ?>
  </p>
</div>

<div class="wrap">
  <div class="split">
    <!-- ================= ซ้าย ================= -->
    <div>
      <div class="panel">
        <div style="background:#fff;border-radius:var(--r);overflow:hidden;margin-bottom:14px">
          <?php if ($car['cover_image']): ?>
            <img src="<?= e(upload_url($car['cover_image'])) ?>" alt="<?= e($carName) ?>"
                 style="width:100%;height:auto;object-fit:contain" width="900" height="640" fetchpriority="high">
          <?php else: ?>
            <div style="aspect-ratio:4/3;display:grid;place-items:center;background:var(--bg);color:var(--muted)">
              <?= icon('car') ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="eyebrow"><?= e($car['segment'] ?: car_type_label($car['car_type'])) ?></div>
        <h1 style="margin-bottom:.2em"><?= e($carName) ?> <?= e($car['year'] ?: '') ?></h1>
        <p class="small muted">สี<?= e($car['color']) ?> · ทะเบียน <?= e($car['plate']) ?> <?= e($car['province']) ?></p>

        <div class="car-specs mt-2" style="gap:8px 16px">
          <span class="sp"><?= icon('seat') ?><?= (int) $car['seats'] ?> ที่นั่ง</span>
          <span class="sp"><?= icon('gear') ?><?= $car['gear'] === 'auto' ? 'เกียร์ออโต้' : 'เกียร์ธรรมดา' ?></span>
          <span class="sp"><?= icon('fuel') ?><?= e($car['fuel']) ?></span>
          <?php if ($car['insurance_type']): ?>
            <span class="sp"><?= icon('shield') ?>ประกัน<?= e($car['insurance_type']) ?></span>
          <?php endif; ?>
        </div>

        <?php if ($car['description']): ?>
          <p class="mt-2 mb-0"><?= nl2br(e($car['description'])) ?></p>
        <?php endif; ?>

        <?php if ($features): ?>
          <div class="mt-3">
            <h3 class="small" style="margin-bottom:8px">อุปกรณ์และจุดเด่น</h3>
            <div class="car-specs" style="gap:8px">
              <?php foreach ($features as $f): ?>
                <span class="badge badge-primary"><?= e($f) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- เรตค่าเช่า -->
      <div class="panel mt-2">
        <h3>เรตค่าเช่า</h3>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>แบบ</th><th class="num">ราคา</th><th class="num">เฉลี่ย/วัน</th></tr></thead>
            <tbody>
              <tr><td>รายวัน</td><td class="num"><?= money0($car['price_day']) ?></td>
                  <td class="num"><?= money0($car['price_day']) ?></td></tr>
              <?php if ((float) $car['price_week'] > 0): ?>
                <tr><td>รายสัปดาห์ <span class="tiny muted">(7 วัน)</span></td>
                    <td class="num"><?= money0($car['price_week']) ?></td>
                    <td class="num" style="color:var(--ok)"><?= money0($car['price_week'] / 7) ?></td></tr>
              <?php endif; ?>
              <?php if ((float) $car['price_month'] > 0): ?>
                <tr><td>รายเดือน <span class="tiny muted">(30 วัน)</span></td>
                    <td class="num"><?= money0($car['price_month']) ?></td>
                    <td class="num" style="color:var(--ok)"><?= money0($car['price_month'] / 30) ?></td></tr>
              <?php endif; ?>
              <tr><td>เงินประกัน <span class="tiny muted">(คืนเมื่อคืนรถ)</span></td>
                  <td class="num"><?= money0($q['security_deposit']) ?></td><td class="num">—</td></tr>
            </tbody>
          </table>
        </div>
        <p class="tiny muted mt-2 mb-0">เช่ายาวระบบจะคิดเรตสัปดาห์/เดือนให้อัตโนมัติ เลือกยอดที่ถูกที่สุดเสมอ</p>
      </div>

      <!-- ================= ฟอร์มจอง ================= -->
      <div class="panel mt-2" id="book">
        <h2>กรอกข้อมูลเพื่อจอง</h2>
        <?= flash_render() ?>
        <form method="post" action="<?= url('booking.php') ?>" id="bookForm">
          <?= csrf_field() ?>
          <input type="hidden" name="car_id" value="<?= (int) $car['id'] ?>">

          <h3 class="small mt-2">ช่วงเวลาเช่า</h3>
          <div class="g2">
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
            <div class="field f-full" id="kmField" hidden>
              <label for="delivery_km">ระยะทางจากกรุงเทพ (กม.)</label>
              <input type="number" id="delivery_km" name="delivery_km" min="0" max="500" step="1"
                     inputmode="numeric" placeholder="เช่น 45">
              <div class="hint"><?= e(delivery_tiers_text()) ?></div>
            </div>
          </div>

          <h3 class="small mt-3">บริการเสริม</h3>
          <div class="g2">
            <?php
            $addonOpts = [
                'delivery'        => ['ส่งรถถึงที่', -1.0, 'ครั้ง'],
                'return_delivery' => ['รับรถคืนถึงที่', -1.0, 'ครั้ง'],
                'driver'          => ['คนขับรถ', (float) $car['driver_fee_day'], 'วัน'],
                'child_seat'      => ['คาร์ซีทเด็ก', 150.0, 'วัน'],
                'insurance_plus'  => ['ประกันเสริม ลดค่าเสียหายส่วนแรก', 300.0, 'วัน'],
                'gps'             => ['GPS / Pocket WiFi', 100.0, 'วัน'],
            ];
            foreach ($addonOpts as $k => [$label, $price, $unit]):
                if ($price == 0) continue;
                $priceText = $price < 0
                    ? 'คิดตามระยะทาง · ไม่เกิน ' . (int) free_delivery_km() . ' กม. ฟรี'
                    : money0($price) . ' บ. / ' . $unit; ?>
              <label class="checkline">
                <input type="checkbox" name="addons[<?= $k ?>]" value="1" class="addon">
                <span><?= e($label) ?>
                  <span class="tiny muted" style="display:block"><?= e($priceText) ?></span></span>
              </label>
            <?php endforeach; ?>
          </div>

          <h3 class="small mt-3">ข้อมูลผู้เช่า</h3>
          <div class="g2">
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
            <span class="small">ยอมรับ<a href="<?= url('terms.php') ?>" target="_blank">เงื่อนไขการเช่ารถ</a>
              และยินยอมให้เก็บข้อมูลส่วนบุคคลเพื่อทำสัญญาเช่า ตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล</span>
          </label>

          <button class="btn btn-lg btn-block mt-2" type="submit" id="submitBtn" <?= $available ? '' : 'disabled' ?>>
            <?= icon('check') ?> ยืนยันการจอง
          </button>
          <p class="tiny muted center mt-2 mb-0">
            กดจองยังไม่มีการตัดเงิน เจ้าหน้าที่จะติดต่อกลับเพื่อยืนยัน
          </p>
        </form>
      </div>
    </div>

    <!-- ================= ขวา: สรุปราคา ================= -->
    <div>
      <div class="sticky-side">
        <div class="panel">
          <h3>สรุปราคา</h3>
          <div id="quoteBox">
            <?php include __DIR__ . '/includes/_quote_box.php'; ?>
          </div>
          <a class="btn btn-block mt-2 hide-mobile" href="#book"><?= icon('check') ?> ไปที่ฟอร์มจอง</a>
        </div>

        <div class="panel mt-2">
          <h3 class="small">บัญชีรับโอนมัดจำ</h3>
          <div class="bank">
            <dl class="dl">
              <dt>ธนาคาร</dt><dd><?= e(setting('bank_name')) ?></dd>
              <dt>เลขที่</dt><dd><?= e(setting('bank_account_no')) ?></dd>
              <dt>ชื่อบัญชี</dt><dd style="font-size:.85rem"><?= e(setting('bank_account_name')) ?></dd>
              <?php if (setting('promptpay_id')): ?>
                <dt>PromptPay</dt><dd><?= e(setting('promptpay_id')) ?></dd>
              <?php endif; ?>
            </dl>
          </div>
        </div>

        <?php if ($busy): ?>
          <div class="panel mt-2">
            <h3 class="small">ช่วงที่รถไม่ว่าง</h3>
            <ul class="small muted" style="padding-left:18px;margin:0">
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
    fetch('<?= url('api/quote.php') ?>?' + params.toString())
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
    if (navigator.sendBeacon) {
      navigator.sendBeacon('<?= url('api/track.php') ?>', body);
    } else {
      fetch('<?= url('api/track.php') ?>', { method: 'POST', body: body,
        credentials: 'same-origin', keepalive: true }).catch(function () {});
    }
  });
})();
</script>

<?php page_foot(); ?>
