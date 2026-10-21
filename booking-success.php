<?php
/**
 * หน้ายืนยันหลังจองสำเร็จ — แสดงเลขที่จอง + บัญชีโอน + ลิงก์อัปสลิป
 */
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/layout_public.php';

start_session();
$no = trim($_GET['no'] ?? '');
$b  = $no ? db_one('SELECT b.*, c.brand, c.model, c.plate, c.color
                    FROM bookings b JOIN cars c ON c.id = b.car_id
                    WHERE b.booking_no = :n', [':n' => $no]) : null;

// อนุญาตเฉพาะคนที่เพิ่งจอง (กันเดาเลขที่จองคนอื่น)
if (!$b || ($_SESSION['last_booking'] ?? '') !== $no) {
    page_head('ไม่พบการจอง', '', ['noindex' => true]);
    echo '<div class="wrap section"><div class="panel center"><h2>ไม่พบข้อมูลการจอง</h2>
          <p class="muted">กรุณาตรวจสอบผ่านหน้าตรวจสอบการจอง โดยใช้เลขที่จองและเบอร์โทรศัพท์</p>
          <a class="btn" href="' . url('track.php') . '">ตรวจสอบการจอง</a></div></div>';
    page_foot();
    exit;
}

page_head('จองสำเร็จ ' . $b['booking_no'], '', ['noindex' => true, 'active' => 'cars.php']);
?>
<div class="wrap section">
  <ul class="steps">
    <li class="done">จองรถ</li>
    <li class="on">โอนมัดจำ + อัปสลิป</li>
    <li>เจ้าหน้าที่ยืนยัน</li>
    <li>รับรถ</li>
  </ul>

  <div class="panel center" style="border-top:4px solid var(--ok)">
    <div style="width:60px;height:60px;border-radius:50%;background:var(--ok-bg);color:var(--ok);display:grid;place-items:center;margin:0 auto 12px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="width:30px;height:30px"><path d="M20 6.5L9.5 17 4 11.5"/></svg></div>
    <h1 class="mb-1">รับการจองเรียบร้อยแล้ว</h1>
    <p class="muted">เลขที่การจองของคุณคือ</p>
    <div style="font-size:1.9rem;font-weight:800;color:var(--brand);letter-spacing:.02em"><?= e($b['booking_no']) ?></div>
    <p class="small muted mt-1">กรุณาบันทึกเลขที่นี้ไว้ ใช้สำหรับตรวจสอบสถานะและอัปโหลดสลิป</p>
  </div>

  <div class="split mt-3">
    <div class="panel">
      <h3>รายละเอียดการจอง</h3>
      <div class="table-wrap">
        <table class="table">
          <tbody>
            <tr><th style="width:36%">รถ</th><td><?= e($b['brand'] . ' ' . $b['model']) ?> (<?= e($b['plate']) ?>) สี<?= e($b['color']) ?></td></tr>
            <tr><th>รับรถ</th><td><?= e(thai_date($b['pickup_at'], true, true)) ?><?= $b['pickup_place'] ? '<br><span class="small muted">' . e($b['pickup_place']) . '</span>' : '' ?></td></tr>
            <tr><th>คืนรถ</th><td><?= e(thai_date($b['return_at'], true, true)) ?><?= $b['return_place'] ? '<br><span class="small muted">' . e($b['return_place']) . '</span>' : '' ?></td></tr>
            <tr><th>ระยะเวลา</th><td><?= (int) $b['rental_days'] ?> วัน</td></tr>
            <tr><th>ผู้เช่า</th><td><?= e($b['contact_name']) ?> · <?= e($b['contact_phone']) ?></td></tr>
            <tr><th>ยอดรวม</th><td><b>฿<?= money($b['grand_total']) ?></b></td></tr>
            <tr><th>มัดจำที่ต้องโอน</th><td><b style="color:var(--warn)">฿<?= money($b['deposit_amount']) ?></b></td></tr>
            <tr><th>เงินประกัน (ชำระวันรับรถ คืนเมื่อคืนรถ)</th><td>฿<?= money($b['security_deposit']) ?></td></tr>
          </tbody>
        </table>
      </div>
      <button class="btn btn-ghost btn-sm no-print mt-2" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
    </div>

    <div>
      <div class="panel">
        <h3>ขั้นตอนถัดไป</h3>
        <div class="bank">
          <b class="small">โอนมัดจำ ฿<?= money($b['deposit_amount']) ?> มาที่</b>
          <dl class="mt-1">
            <dt>ธนาคาร</dt><dd><?= e(setting('bank_name')) ?></dd>
            <dt>เลขที่บัญชี</dt><dd><?= e(setting('bank_account_no')) ?></dd>
            <dt>ชื่อบัญชี</dt><dd><?= e(setting('bank_account_name')) ?></dd>
            <?php if (setting('promptpay_id')): ?><dt>PromptPay</dt><dd><?= e(setting('promptpay_id')) ?></dd><?php endif; ?>
          </dl>
        </div>
        <a class="btn btn-accent btn-block mt-2 no-print"
           href="<?= url('track.php?no=' . urlencode($b['booking_no']) . '&phone=' . urlencode($b['contact_phone'])) ?>">
          อัปโหลดสลิปการโอน
        </a>
        <?php // ปุ่มนี้โผล่เมื่อร้านตั้งค่า LIFF แล้วเท่านั้น — เปิดในแอป LINE พร้อมเลขที่จองกรอกไว้ให้
        $liffId = trim((string) setting('line_liff_id'));
        if ($liffId !== ''): ?>
          <a class="btn btn-ghost btn-block mt-1 no-print"
             href="https://liff.line.me/<?= e($liffId) ?>?no=<?= e(urlencode($b['booking_no'])) ?>">
            รับแจ้งเตือนและแจ้งโอนทาง LINE
          </a>
        <?php endif; ?>
        <p class="tiny muted mt-1 mb-0">เจ้าหน้าที่จะตรวจสอบและยืนยันการจองภายในเวลาทำการ
          (<?= e(setting('open_time')) ?>–<?= e(setting('close_time')) ?> น.)
          สอบถามเร่งด่วน โทร <?= e(setting('phone')) ?> หรือ LINE <?= e(setting('line_id')) ?></p>
      </div>

      <div class="panel mt-2">
        <h3 class="small">เตรียมเอกสารวันรับรถ</h3>
        <ul class="small muted" style="padding-left:18px;margin:0">
          <li>บัตรประชาชนตัวจริง</li>
          <li>ใบขับขี่ตัวจริง (ยังไม่หมดอายุ)</li>
          <li>เงินประกัน ฿<?= money0($b['security_deposit']) ?> (เงินสด/โอน คืนเมื่อคืนรถครบเงื่อนไข)</li>
        </ul>
      </div>
    </div>
  </div>
</div>
<?php page_foot(); ?>
