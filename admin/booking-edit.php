<?php
/**
 * สร้าง/แก้ไขการจองจากหลังบ้าน (รับงานทางโทรศัพท์ / LINE / หน้าร้าน)
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$b  = $id ? db_one('SELECT * FROM bookings WHERE id = :i', [':i' => $id]) : null;
if ($id && !$b) {
    flash('danger', 'ไม่พบการจอง');
    redirect('admin/bookings.php');
}

$cars = db_all("SELECT * FROM cars WHERE status <> 'inactive' ORDER BY sort_order");

// หลังส่งมอบรถแล้ว รถ วัน–เวลา และราคา ผูกกับผลตรวจสภาพรถ เลขไมล์ และค่าใช้จ่ายตอนคืนรถไปแล้ว
// แก้ตรงนี้ได้เฉพาะข้อมูลติดต่อและหมายเหตุ — เลื่อนวันคืนให้ใช้ "เช่าต่อ" ในหน้าการจอง
$locked = $b && in_array($b['status'], ['picked_up', 'returned', 'completed', 'cancelled'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        // หน้านี้เปิดค้างไว้แล้วมีคนแก้การจองจากที่อื่น (เช่น กดเช่าต่อ รับเงิน) — ถ้าบันทึกทับ ข้อมูลใหม่จะหาย
        if ($b && (string) ($_POST['rev'] ?? '') !== (string) $b['updated_at']) {
            throw new RuntimeException('การจองนี้ถูกแก้ไขจากหน้าอื่นหลังจากที่เปิดหน้านี้ กรุณาตรวจข้อมูลล่าสุดด้านล่างแล้วแก้ไขใหม่อีกครั้ง');
        }
        $name  = mb_substr(trim((string) ($_POST['contact_name'] ?? '')), 0, 150);
        $phone = mb_substr(trim((string) ($_POST['contact_phone'] ?? '')), 0, 30);
        if ($name === '' || $phone === '') throw new RuntimeException('กรุณากรอกชื่อและเบอร์โทร');
        if (strlen(phone_digits($phone)) < 9) throw new RuntimeException('เบอร์โทรต้องมีตัวเลขอย่างน้อย 9 หลัก (ใช้ยืนยันตัวตนตอนลูกค้าตรวจสอบการจอง)');

        // ผูกลูกค้าจากเบอร์โทร
        $customer   = customer_by_phone($phone);
        $customerId = $customer['id'] ?? null;
        if (!$customerId) {
            $customerId = db_insert('customers', [
                'code' => next_customer_code(), 'type' => 'individual',
                'full_name' => $name, 'phone' => $phone,
                'email' => trim((string) ($_POST['contact_email'] ?? '')) ?: null,
                'line_id' => trim((string) ($_POST['contact_line'] ?? '')) ?: null,
            ]);
        }
        $data = [
            'customer_id'   => $customerId,
            'contact_name'  => $name,
            'contact_phone' => $phone,
            'contact_email' => mb_substr(trim((string) ($_POST['contact_email'] ?? '')), 0, 150) ?: null,
            'contact_line'  => mb_substr(trim((string) ($_POST['contact_line'] ?? '')), 0, 80) ?: null,
            'pickup_place'  => mb_substr(trim((string) ($_POST['pickup_place'] ?? '')), 0, 250) ?: null,
            'return_place'  => mb_substr(trim((string) ($_POST['return_place'] ?? '')), 0, 250) ?: null,
            'note'          => mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 1000) ?: null,
        ];
        // เปลี่ยนเบอร์ = อาจเป็นคนละคน → เลิกส่งแจ้งเตือน LINE ไปหาคนเดิม (ลูกค้าผูกใหม่ได้จากหน้า "การจองของฉัน")
        if ($b && !phone_match($b['contact_phone'], $phone)) {
            $data['line_user_id'] = null;
        }

        if (!$locked) {
            $carId = (int) ($_POST['car_id'] ?? 0);
            $car   = db_one('SELECT * FROM cars WHERE id = :i', [':i' => $carId]);
            if (!$car) throw new RuntimeException('กรุณาเลือกรถ');

            $pTs = strtotime((string) ($_POST['pickup_at'] ?? ''));
            $rTs = strtotime((string) ($_POST['return_at'] ?? ''));
            if ($pTs === false || $rTs === false) throw new RuntimeException('กรุณาเลือกวัน–เวลารับและคืนรถ');
            $pickDb = date('Y-m-d H:i:s', $pTs);
            $retDb  = date('Y-m-d H:i:s', $rTs);
            if ($rTs <= $pTs) throw new RuntimeException('วันคืนรถต้องหลังวันรับรถ');
            if (rental_days($pickDb, $retDb) > 365) throw new RuntimeException('ระยะเวลาเช่าเกิน 1 ปี กรุณาตรวจวันที่อีกครั้ง');
            if (!is_car_available($carId, $pickDb, $retDb, $id ?: null)) {
                throw new RuntimeException('รถคันนี้ไม่ว่างในช่วงเวลาที่เลือก');
            }

            $addons   = array_map(static fn($v) => max(0, min(5, (int) (is_scalar($v) ? $v : 0))), (array) ($_POST['addons'] ?? []));
            $discount = max(0, round((float) ($_POST['discount'] ?? 0), 2));
            $vat      = isset($_POST['vat']);
            $km       = max(0, min(2000, (float) ($_POST['delivery_km'] ?? 0)));
            if (delivery_out_of_range($addons, $km)) {
                throw new RuntimeException('ระยะทาง ' . number_format($km) . ' กม. เกินตารางค่าส่งรถที่ตั้งไว้ ระบบจึงคิดค่าส่งให้ไม่ได้ — เพิ่มช่วงระยะทางที่ หลังบ้าน → ตั้งค่า หรือใส่ระยะทางไม่เกินช่วงสุดท้ายของตาราง');
            }
            $q        = quote_booking($car, $pickDb, $retDb, $addons, $discount, $vat, $km);

            // เงินประกัน: เว้นว่างหรือใส่ 0 ตอนสร้างใหม่ = ใช้ตามเงื่อนไขของรถคันนั้น
            // (เดิมช่องนี้ส่ง 0 มาเสมอ การจองที่สร้างจากหลังบ้านจึงมีเงินประกัน 0 บาททุกใบ)
            $secIn = trim((string) ($_POST['security_deposit'] ?? ''));
            $sec   = $secIn === '' ? (float) $q['security_deposit'] : max(0, (float) $secIn);
            if (!$id && $sec <= 0) {
                $sec = (float) $q['security_deposit'];
            }
            $depIn = trim((string) ($_POST['deposit_amount'] ?? ''));
            $dep   = $depIn === '' ? (float) $q['deposit_amount'] : max(0, min((float) $q['grand_total'], (float) $depIn));

            $data += [
                'car_id'           => $carId,
                'pickup_at'        => $pickDb,
                'return_at'        => $retDb,
                'rental_days'      => $q['days'],
                'rate_breakdown'   => json_encode(['rental' => $q['rental_lines'], 'addons' => $q['addon_lines']], JSON_UNESCAPED_UNICODE),
                'rental_amount'    => $q['rental_amount'],
                'addon_amount'     => $q['addon_amount'],
                'discount'         => $q['discount'],
                'subtotal'         => $q['subtotal'],
                'vat_rate'         => $q['vat_rate'],
                'vat_amount'       => $q['vat_amount'],
                'grand_total'      => $q['grand_total'],
                'deposit_amount'   => $dep,
                'security_deposit' => $sec,
                'delivery_km'      => $km > 0 ? $km : null,
                'addons'           => json_encode($addons, JSON_UNESCAPED_UNICODE),
            ];
        }

        if ($id) {
            db_update('bookings', $data, 'id', $id);
            // ยอดรวมอาจเปลี่ยน → สถานะการชำระเงินต้องคิดใหม่ (ไม่งั้นขึ้น "ชำระครบ" ทั้งที่ยังค้าง)
            recalc_booking_payment($id);
            if (!$locked) {
                // วันรับ/คืนเปลี่ยน → ปลดเครื่องหมาย "เตือนแล้ว" ให้ระบบเตือนลูกค้าตามวันใหม่
                if ($data['pickup_at'] !== $b['pickup_at']) {
                    db_exec("UPDATE notifications SET tpl_key = 'cust_pickup_reminder_old' WHERE tpl_key = 'cust_pickup_reminder' AND ref_table = 'bookings' AND ref_id = :i", [':i' => $id]);
                }
                if ($data['return_at'] !== $b['return_at']) {
                    db_exec("UPDATE notifications SET tpl_key = 'cust_return_reminder_old' WHERE tpl_key = 'cust_return_reminder' AND ref_table = 'bookings' AND ref_id = :i", [':i' => $id]);
                }
            }
            log_activity('booking_updated', 'bookings', $id, $locked ? 'contact only' : null);
            flash('ok', 'บันทึกการแก้ไขแล้ว');
            redirect('admin/booking-view.php?id=' . $id);
        } else {
            $data['booking_no']     = next_booking_no();
            $data['status']         = in_array($_POST['status'] ?? '', ['pending','confirmed'], true) ? $_POST['status'] : 'confirmed';
            $data['payment_status'] = 'unpaid';
            $data['source']         = in_array($_POST['source'] ?? '', ['admin','line','phone','web'], true) ? $_POST['source'] : 'admin';
            $data['created_by']     = auth_user()['id'];
            $newId = db_insert('bookings', $data);
            log_activity('booking_created_admin', 'bookings', $newId, $data['booking_no']);
            flash('ok', 'สร้างการจอง ' . $data['booking_no'] . ' แล้ว');
            redirect('admin/booking-view.php?id=' . $newId);
        }
    } catch (Throwable $ex) {
        if (!($ex instanceof RuntimeException) || $ex instanceof PDOException) {
            error_log('[thonglorcarrent] booking-edit: ' . $ex->getMessage());
        }
        flash('danger', $ex instanceof RuntimeException && !($ex instanceof PDOException) ? $ex->getMessage() : 'บันทึกไม่สำเร็จ กรุณาตรวจข้อมูลแล้วลองใหม่');
        // โหลดข้อมูลล่าสุดมาแสดง (กรณีมีคนแก้จากหน้าอื่น)
        if ($id) {
            $b = db_one('SELECT * FROM bookings WHERE id = :i', [':i' => $id]) ?: $b;
            $locked = in_array($b['status'], ['picked_up', 'returned', 'completed', 'cancelled'], true);   // สถานะอาจเปลี่ยนไปแล้ว
        }
    }
}

$sel       = $b ?: [];
$selAddons = $b ? (json_decode((string) $b['addons'], true) ?: []) : [];
$defPick   = $b ? date('Y-m-d\TH:i', strtotime($b['pickup_at'])) : date('Y-m-d\TH:i', strtotime('tomorrow 10:00'));
$defRet    = $b ? date('Y-m-d\TH:i', strtotime($b['return_at'])) : date('Y-m-d\TH:i', strtotime('tomorrow +1 day 10:00'));

admin_head($id ? 'แก้ไขการจอง ' . $b['booking_no'] : 'สร้างการจองใหม่', 'bookings.php');
?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="rev" value="<?= e($b['updated_at'] ?? '') ?>">
  <?php if ($locked): ?>
    <div class="alert alert-warn"><b>การจองนี้<?= $b['status'] === 'cancelled' ? 'ถูกยกเลิกแล้ว' : 'ส่งมอบรถแล้ว' ?></b> — แก้ได้เฉพาะข้อมูลผู้เช่า จุดรับ–คืน และหมายเหตุ
      รถ วัน–เวลา และราคาถูกล็อกเพราะผูกกับผลตรวจสภาพรถและการเงินไปแล้ว<?= $b['status'] === 'picked_up' ? ' · ต้องการเลื่อนวันคืนให้ใช้ปุ่ม "เช่าต่อ / เลื่อนวันคืน" ในหน้าการจอง' : '' ?></div>
  <?php endif; ?>
  <fieldset class="plain-fs">
  <div class="split">
    <div>
      <div class="panel">
        <h3>รถและช่วงเวลา</h3>
        <div class="field">
          <label>เลือกรถ *</label>
          <select name="car_id" id="car_id" required <?= $locked ? 'disabled' : '' ?>>
            <option value="">— เลือกรถ —</option>
            <?php foreach ($cars as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (int) ($sel['car_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                <?= e($c['brand'] . ' ' . $c['model'] . ' — ' . $c['plate']) ?>
                (฿<?= money0($c['price_day']) ?>/วัน<?= (float) $c['price_month'] > 0 ? ', ฿' . money0($c['price_month']) . '/เดือน' : '' ?>)
                <?= $c['status'] !== 'available' ? ' [' . $c['status'] . ']' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="g2">
          <div class="field"><label>วัน–เวลารับรถ *</label>
            <input type="datetime-local" name="pickup_at" id="pickup_at" value="<?= e($defPick) ?>" required <?= $locked ? 'disabled' : '' ?>></div>
          <div class="field"><label>วัน–เวลาคืนรถ *</label>
            <input type="datetime-local" name="return_at" id="return_at" value="<?= e($defRet) ?>" required <?= $locked ? 'disabled' : '' ?>></div>
          <div class="field"><label>จุดรับรถ</label>
            <input type="text" name="pickup_place" value="<?= e($sel['pickup_place'] ?? '') ?>"></div>
          <div class="field"><label>จุดคืนรถ</label>
            <input type="text" name="return_place" value="<?= e($sel['return_place'] ?? '') ?>"></div>
        </div>
      </div>

      <div class="panel mt-2">
        <h3>ข้อมูลผู้เช่า</h3>
        <div class="g2">
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

      <div class="panel mt-2">
        <h3>บริการเสริม</h3>
        <div class="g2">
          <?php foreach (['delivery'=>'ส่งรถถึงที่','return_delivery'=>'รับรถคืนถึงที่','driver'=>'คนขับรถ',
                          'child_seat'=>'คาร์ซีทเด็ก','insurance_plus'=>'ประกันเสริม','gps'=>'GPS / WiFi'] as $k => $l): ?>
            <label class="checkline">
              <input type="checkbox" class="addon" name="addons[<?= $k ?>]" value="1" <?= !empty($selAddons[$k]) ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
              <span><?= e($l) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="field mt-2" style="max-width:260px"><label>ระยะทางส่ง–รับรถจากกรุงเทพ (กม.)</label>
          <input type="number" name="delivery_km" id="delivery_km" min="0" max="2000" step="1" <?= $locked ? 'disabled' : '' ?>
                 value="<?= $b ? e(rtrim(rtrim(number_format(booking_delivery_km($b), 1, '.', ''), '0'), '.')) : '0' ?>">
          <div class="hint"><?= e(delivery_tiers_text()) ?></div></div>
      </div>
    </div>

    <div>
      <div class="panel" style="position:sticky;top:78px">
        <h3>สรุปราคา</h3>
        <div id="quoteBox"><p class="small muted">เลือกรถและช่วงเวลาเพื่อคำนวณราคา</p></div>

        <div class="field mt-2"><label>ส่วนลด (บาท)</label>
          <input type="number" name="discount" id="discount" step="0.01" min="0" value="<?= e($sel['discount'] ?? 0) ?>" <?= $locked ? 'disabled' : '' ?>></div>
        <label class="checkline">
          <?php // การจองเดิม: ยึดตามที่บันทึกไว้ · การจองใหม่: ยึดตามตั้งค่าร้าน  (เดิมวงเล็บผิด ทำให้ช่องนี้ถูกติ๊กทุกครั้งที่เปิดแก้ไข แล้วบวก VAT เพิ่มเองเมื่อกดบันทึก)
                $vatOn = $b ? (float) $b['vat_rate'] > 0 : setting('vat_enabled') === '1'; ?>
          <input type="checkbox" name="vat" id="vat" value="1" <?= $vatOn ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
          <span>คิด VAT <?= e(setting('vat_rate', '7')) ?>% (ออกใบกำกับภาษี)</span>
        </label>
        <div class="g2 mt-2">
          <div class="field"><label>มัดจำ (บาท)</label>
            <input type="number" name="deposit_amount" id="deposit_amount" step="0.01" min="0" value="<?= e($sel['deposit_amount'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></div>
          <div class="field"><label>เงินประกัน (บาท)</label>
            <input type="number" name="security_deposit" id="security_deposit" step="0.01" min="0" value="<?= e($sel['security_deposit'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>
                   placeholder="ตามเงื่อนไขรถ"></div>
        </div>

        <?php if (!$id): ?>
          <div class="g2">
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

        <button class="btn btn-accent btn-block mt-2"><?= $id ? 'บันทึกการแก้ไข' : 'สร้างการจอง' ?></button>
        <a class="btn btn-ghost btn-block mt-1" href="<?= url($id ? 'admin/booking-view.php?id=' . $id : 'admin/bookings.php') ?>">ยกเลิก</a>
      </div>
    </div>
  </div>
  </fieldset>
</form>

<script>
(function () {
  const box = document.getElementById('quoteBox');
  const f = { car: document.getElementById('car_id'), p: document.getElementById('pickup_at'),
              r: document.getElementById('return_at'), d: document.getElementById('discount') };
  let t = null;

  function refresh() {
    if (!f.car.value || !f.p.value || !f.r.value) return;
    const addons = {};
    document.querySelectorAll('.addon:checked').forEach(function (el) {
      const m = el.name.match(/addons\[(\w+)\]/); if (m) addons[m[1]] = 1;
    });
    const kmEl = document.getElementById('delivery_km');
    const params = new URLSearchParams({ car_id: f.car.value, pickup: f.p.value, ret: f.r.value,
                                         addons: JSON.stringify(addons), admin: 1, km: (kmEl && kmEl.value) || 0,
                                         ignore: <?= (int) $id ?> });
    box.style.opacity = .5;
    fetch('<?= url('api/quote.php') ?>?' + params.toString())
      .then(function (r) { return r.json(); })
      .then(function (d) {
        box.innerHTML = d.html; box.style.opacity = 1;
        const dep = document.getElementById('deposit_amount');
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
<?php admin_foot(); ?>
