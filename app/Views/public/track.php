<?php
/**
 * ตรวจสอบสถานะการจอง + อัปโหลดสลิป
 * ตัวแปร: no, phone, b, err, payments, extras, limit
 */
$this->layout('layouts/public', [
    'title'   => 'ตรวจสอบการจอง',
    'desc'    => 'ตรวจสอบสถานะการจองและแจ้งโอนเงิน',
    'active'  => 'track',
    'noindex' => true,
]);
?>
<section class="bg-gradient-to-b from-[#eaf2fc] to-surface py-7">
  <div class="wrap">
    <div class="eyebrow">My booking</div>
    <h1 class="mb-1">ตรวจสอบการจอง</h1>
    <p class="mb-0 text-muted">กรอกเลขที่การจองและเบอร์โทรศัพท์ที่ใช้จอง เพื่อดูสถานะและแจ้งโอนเงิน</p>
  </div>
</section>

<div class="wrap pb-9 pt-[22px] sm:pb-11 lg:pb-14">

  <form class="panel mt-4" method="get" action="<?= url('track') ?>">
    <div class="grid gap-3.5 sm:grid-cols-2 sm:gap-4">
      <div class="field">
        <label for="no">เลขที่การจอง</label>
        <input type="text" id="no" name="no" value="<?= e($no) ?>" placeholder="BK260919-001" required>
      </div>
      <div class="field">
        <label for="phone">เบอร์โทรศัพท์</label>
        <input type="tel" id="phone" name="phone" value="<?= e($phone) ?>" placeholder="08xxxxxxxx" required>
      </div>
    </div>
    <button class="btn">ตรวจสอบ</button>
  </form>

  <div class="mt-4"><?= $this->partial('partials/flash') ?></div>
  <?php if ($err): ?><div class="alert alert-danger mt-4"><?= e($err) ?></div><?php endif; ?>

  <?php if ($b):
      [$sLabel, $sColor] = booking_status_label($b['status']);
      [$pLabel, $pColor] = payment_status_label($b['payment_status']);
      $balance = \App\Models\Booking::balance($b);   // รวมค่าใช้จ่ายเพิ่มตอนคืนรถ
  ?>
    <div class="main-side-grid">
      <div class="panel min-w-0">
        <div class="flex flex-wrap items-start justify-between gap-3.5">
          <div>
            <h2 class="mb-0"><?= e($b['booking_no']) ?></h2>
            <p class="text-sm text-muted">จองเมื่อ <?= e(thai_date($b['created_at'], true)) ?></p>
          </div>
          <div class="text-right">
            <span class="badge badge-<?= e($sColor) ?>"><?= e($sLabel) ?></span>
            <span class="badge badge-<?= e($pColor) ?>"><?= e($pLabel) ?></span>
          </div>
        </div>

        <div class="table-wrap mt-4">
          <table class="table">
            <tbody>
              <tr><th class="w-[34%]">รถ</th><td><?= e($b['brand'] . ' ' . $b['model']) ?> (<?= e($b['plate']) ?>)</td></tr>
              <tr><th>รับรถ</th><td><?= e(thai_date($b['pickup_at'], true, true)) ?><?php if ($b['pickup_place']): ?><br><span class="text-sm text-muted"><?= e($b['pickup_place']) ?></span><?php endif; ?></td></tr>
              <tr><th>คืนรถ</th><td><?= e(thai_date($b['return_at'], true, true)) ?></td></tr>
              <tr><th>ระยะเวลา</th><td><?= (int) $b['rental_days'] ?> วัน</td></tr>
              <tr><th>ยอดรวม</th><td>฿<?= money($b['grand_total']) ?></td></tr>
              <tr><th>ชำระแล้ว</th><td>฿<?= money($b['paid_amount']) ?></td></tr>
              <tr><th>คงเหลือ</th><td><b class="<?= $balance > 0 ? 'text-warn' : 'text-ok' ?>">฿<?= money(max(0, $balance)) ?></b></td></tr>
              <?php if ($extras): ?>
                <tr><th>ค่าใช้จ่ายเพิ่มตอนคืนรถ</th><td>฿<?= money($b['extra_charge']) ?>
                  <?php foreach ($extras as $l): ?>
                    <br><span class="text-sm text-muted"><?= e($l['label']) ?> <?= money0($l['amount']) ?> บาท</span>
                  <?php endforeach; ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($payments): ?>
          <h3 class="mt-6 text-sm">ประวัติการแจ้งโอน</h3>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>วันที่โอน</th><th class="num">จำนวน</th><th>สถานะ</th><th>สลิป</th></tr></thead>
              <tbody>
                <?php foreach ($payments as $p): ?>
                  <tr>
                    <td><?= e(thai_date($p['paid_at'], true)) ?></td>
                    <td class="num"><?= money($p['amount']) ?></td>
                    <td>
                      <?php if ($p['status'] === 'approved'): ?><span class="badge badge-ok">ยืนยันแล้ว</span>
                      <?php elseif ($p['status'] === 'rejected'): ?><span class="badge badge-danger">ไม่ผ่าน</span>
                        <div class="text-[0.79rem] text-muted"><?= e($p['reject_reason']) ?></div>
                      <?php else: ?><span class="badge badge-warn">รอตรวจสอบ</span><?php endif; ?>
                    </td>
                    <td><?php if ($p['slip_file']): ?><a href="<?= e(upload_url($p['slip_file'])) ?>" target="_blank">ดูสลิป</a><?php else: ?>-<?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="min-w-0">
        <?php if (!in_array($b['status'], ['cancelled', 'completed'], true) && $balance > 0): ?>
          <div class="panel">
            <h3>แจ้งโอนเงิน</h3>
            <div class="bank mb-4">
              <dl class="dl">
                <dt>ธนาคาร</dt><dd><?= e(setting('bank_name')) ?></dd>
                <dt>เลขที่</dt><dd><?= e(setting('bank_account_no')) ?></dd>
                <dt>ชื่อบัญชี</dt><dd><?= e(setting('bank_account_name')) ?></dd>
                <?php if (setting('promptpay_id')): ?><dt>PromptPay</dt><dd><?= e(setting('promptpay_id')) ?></dd><?php endif; ?>
              </dl>
            </div>
            <form method="post" action="<?= url('track') ?>" enctype="multipart/form-data" id="slipForm">
              <?= csrf_field() ?>
              <input type="hidden" name="no" value="<?= e($b['booking_no']) ?>">
              <input type="hidden" name="phone" value="<?= e($phone) ?>">
              <div class="field">
                <label for="amount">จำนวนเงินที่โอน (บาท) *</label>
                <input type="number" id="amount" name="amount" step="0.01" min="1"
                       value="<?= e((float) $b['paid_amount'] > 0 ? max(0, $balance) : $b['deposit_amount']) ?>" required>
              </div>
              <div class="field">
                <label for="paid_at">วัน–เวลาที่โอน</label>
                <input type="datetime-local" id="paid_at" name="paid_at" value="<?= date('Y-m-d\TH:i') ?>">
              </div>
              <div class="field">
                <label for="slip">ไฟล์สลิป (JPG / PNG / PDF) *</label>
                <input type="file" id="slip" name="slip" accept="image/*,application/pdf" required>
                <div class="hint" id="slipHint">ถ่ายภาพหน้าจอสลิปจากแอปธนาคารได้เลย ระบบย่อรูปให้อัตโนมัติ</div>
              </div>
              <div class="field">
                <label for="note">หมายเหตุ</label>
                <input type="text" id="note" name="note" maxlength="200">
              </div>
              <button class="btn btn-accent btn-block">ส่งสลิป</button>
            </form>
          </div>
        <?php elseif ($balance <= 0): ?>
          <div class="panel text-center">
            <h3>ชำระเงินครบแล้ว</h3>
            <p class="mb-0 text-sm text-muted">ขอบคุณที่ใช้บริการ <?= e(setting('site_name')) ?></p>
          </div>
        <?php endif; ?>

        <div class="panel mt-4">
          <h3 class="text-sm">ต้องการเปลี่ยนแปลง/ยกเลิก?</h3>
          <p class="mb-0 text-sm text-muted">โทร <?= e(setting('phone')) ?> หรือ LINE <?= e(setting('line_id')) ?>
            ภายในเวลาทำการ <?= e(setting('open_time')) ?>–<?= e(setting('close_time')) ?> น.</p>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
/* ---------- ย่อรูปสลิปในเครื่องก่อนส่ง ----------
   สาเหตุที่ลูกค้าอัปสลิปไม่ได้: เซิร์ฟเวอร์รับไฟล์ได้จำกัด (มักตั้งไว้ 2 MB)
   แต่รูปที่ถ่ายจากกล้องมือถือใบละ 3–8 MB จึงถูกปฏิเสธทุกครั้ง
   และถ้าเกิน post_max_size ด้วย PHP จะทิ้งคำขอทั้งก้อนแบบไม่มีข้อความบอก

   ย่อในเครื่องก่อนส่งจึงแก้ที่ต้นเหตุ — สลิปอ่านออกสบายที่ด้านยาว 1400 px */
(function () {
  var form  = document.getElementById('slipForm');
  if (!form) return;
  var input = document.getElementById('slip');
  var hint  = document.getElementById('slipHint');
  var btn   = form.querySelector('button');
  var LIMIT = <?= (int) $limit ?>;
  var MAX_SIDE = 1400, QUALITY = 0.82;
  var swapping = false;   // กันวนลูปตอนเราเปลี่ยนไฟล์ใน input เอง

  /* แสดง KB เมื่อไฟล์เล็ก ไม่งั้นจะขึ้น "0.0 MB" ซึ่งอ่านแล้วงง */
  function sz(n) {
    return n < 1048576 ? Math.round(n / 1024) + ' KB' : (n / 1048576).toFixed(1) + ' MB';
  }

  function shrink(file) {
    return new Promise(function (resolve) {
      if (file.type === 'application/pdf' || !/^image\//.test(file.type)) { resolve(null); return; }
      var img = new Image(), url = URL.createObjectURL(file);
      img.onload = function () {
        URL.revokeObjectURL(url);
        var w = img.naturalWidth, h = img.naturalHeight;
        var s = Math.min(1, MAX_SIDE / Math.max(w, h));
        w = Math.round(w * s); h = Math.round(h * s);
        var cv = document.createElement('canvas');
        cv.width = w; cv.height = h;
        cv.getContext('2d').drawImage(img, 0, 0, w, h);
        cv.toBlob(function (blob) { resolve(blob); }, 'image/jpeg', QUALITY);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(null); };
      img.src = url;
    });
  }

  /* ใส่ไฟล์ที่ย่อแล้วกลับเข้า input แทนไฟล์เดิม
     ทำแบบนี้เพื่อให้ฟอร์มส่งแบบปกติต่อไปได้ — ถ้าส่งด้วย fetch แทน
     ข้อความยืนยันที่เซิร์ฟเวอร์ฝากมาจะถูกใช้ไปตั้งแต่ตอน fetch ลูกค้าจะไม่เห็นอะไรเลย */
  function replaceInputFile(blob, name) {
    if (typeof DataTransfer === 'undefined') return false;
    try {
      var dt = new DataTransfer();
      dt.items.add(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
      input.files = dt.files;
      return input.files.length === 1;
    } catch (e) {
      return false;
    }
  }

  input.addEventListener('change', async function () {
    if (swapping) return;          // เป็นการเปลี่ยนที่เราทำเอง ไม่ใช่ผู้ใช้เลือกไฟล์ใหม่
    var f = input.files && input.files[0];
    if (!f) { hint.textContent = 'ถ่ายภาพหน้าจอสลิปจากแอปธนาคารได้เลย ระบบย่อรูปให้อัตโนมัติ'; return; }

    hint.textContent = 'กำลังเตรียมไฟล์…';
    btn.disabled = true;

    var blob = await shrink(f);
    var done = false;

    if (blob && blob.size < f.size) {
      swapping = true;
      done = replaceInputFile(blob, 'slip.jpg');
      swapping = false;
    }

    if (done) {
      hint.textContent = 'ย่อรูปให้แล้ว ' + sz(f.size) + ' → ' + sz(blob.size) + ' พร้อมส่ง';
    } else if (f.size > LIMIT) {
      /* ย่อไม่ได้ (PDF / เบราว์เซอร์เก่า) และไฟล์ยังใหญ่เกิน
         บอกตรงนี้เลยดีกว่าปล่อยให้กดส่งแล้วเด้งกลับ */
      hint.innerHTML = '<b class="text-danger">ไฟล์ใหญ่เกินไป ' + sz(f.size)
        + ' (เซิร์ฟเวอร์รับได้ไม่เกิน ' + sz(LIMIT) + ')</b><br>'
        + 'กรุณาถ่ายภาพหน้าจอสลิปจากแอปธนาคารแทนการถ่ายรูปจากกล้อง';
    } else {
      hint.textContent = 'ไฟล์พร้อมส่ง (' + sz(f.size) + ')';
    }
    btn.disabled = false;
  });

  form.addEventListener('submit', function (ev) {
    var f = input.files && input.files[0];
    if (f && f.size > LIMIT) {
      ev.preventDefault();
      hint.innerHTML = '<b class="text-danger">ไฟล์ยังใหญ่เกิน ' + sz(LIMIT)
        + ' — กรุณาเลือกไฟล์ใหม่</b>';
      return;
    }
    btn.disabled = true;
    btn.textContent = 'กำลังส่ง…';
  });
})();
</script>
