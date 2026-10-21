<?php
/**
 * หน้ายืนยันหลังจองสำเร็จ — แสดงเลขที่จอง + บัญชีโอน + ลิงก์อัปสลิป
 * ตัวแปร: b (การจอง + ข้อมูลรถ), title
 */
$this->layout('layouts/public', ['title' => $title, 'active' => 'cars', 'noindex' => true]);
$liffId = trim((string) setting('line_liff_id'));
$steps  = [['done', 'จองรถ'], ['on', 'โอนมัดจำ + อัปสลิป'], ['', 'เจ้าหน้าที่ยืนยัน'], ['', 'รับรถ']];
?>
<div class="wrap py-9 sm:py-11 lg:py-14">
  <ul class="scroll-hide m-0 mb-5 flex list-none gap-1 overflow-x-auto p-0">
    <?php foreach ($steps as [$state, $label]): ?>
      <li class="min-w-[78px] flex-1 border-b-[3px] px-1 py-2 text-center text-[0.76rem] leading-snug <?= $state === 'done' ? 'border-ok text-ok' : ($state === 'on' ? 'border-brand font-bold text-brand' : 'border-line text-muted') ?>"><?= e($label) ?></li>
    <?php endforeach; ?>
  </ul>

  <div class="panel border-t-4 border-t-ok text-center">
    <div class="mx-auto mb-3 grid h-[60px] w-[60px] place-items-center rounded-full bg-ok-bg text-ok"><?= icon('check', 'h-[30px] w-[30px]') ?></div>
    <h1 class="mb-1">รับการจองเรียบร้อยแล้ว</h1>
    <p class="text-muted">เลขที่การจองของคุณคือ</p>
    <div class="text-[1.9rem] font-extrabold tracking-wide text-brand"><?= e($b['booking_no']) ?></div>
    <p class="mb-0 mt-2 text-sm text-muted">กรุณาบันทึกเลขที่นี้ไว้ ใช้สำหรับตรวจสอบสถานะและอัปโหลดสลิป</p>
  </div>

  <div class="main-side-grid">
    <div class="panel min-w-0">
      <h3>รายละเอียดการจอง</h3>
      <div class="table-wrap">
        <table class="table">
          <tbody>
            <tr><th class="w-[36%]">รถ</th><td><?= e($b['brand'] . ' ' . $b['model']) ?> (<?= e($b['plate']) ?>) สี<?= e($b['color']) ?></td></tr>
            <tr><th>รับรถ</th><td><?= e(thai_date($b['pickup_at'], true, true)) ?><?php if ($b['pickup_place']): ?><br><span class="text-sm text-muted"><?= e($b['pickup_place']) ?></span><?php endif; ?></td></tr>
            <tr><th>คืนรถ</th><td><?= e(thai_date($b['return_at'], true, true)) ?><?php if ($b['return_place']): ?><br><span class="text-sm text-muted"><?= e($b['return_place']) ?></span><?php endif; ?></td></tr>
            <tr><th>ระยะเวลา</th><td><?= (int) $b['rental_days'] ?> วัน</td></tr>
            <tr><th>ผู้เช่า</th><td><?= e($b['contact_name']) ?> · <?= e($b['contact_phone']) ?></td></tr>
            <tr><th>ยอดรวม</th><td><b>฿<?= money($b['grand_total']) ?></b></td></tr>
            <tr><th>มัดจำที่ต้องโอน</th><td><b class="text-warn">฿<?= money($b['deposit_amount']) ?></b></td></tr>
            <tr><th>เงินประกัน (ชำระวันรับรถ คืนเมื่อคืนรถ)</th><td>฿<?= money($b['security_deposit']) ?></td></tr>
          </tbody>
        </table>
      </div>
      <button class="btn btn-ghost btn-sm mt-4 print:hidden" type="button" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
    </div>

    <div class="min-w-0">
      <div class="panel">
        <h3>ขั้นตอนถัดไป</h3>
        <div class="bank">
          <b class="text-sm">โอนมัดจำ ฿<?= money($b['deposit_amount']) ?> มาที่</b>
          <dl class="dl mt-2">
            <dt>ธนาคาร</dt><dd><?= e(setting('bank_name')) ?></dd>
            <dt>เลขที่บัญชี</dt><dd><?= e(setting('bank_account_no')) ?></dd>
            <dt>ชื่อบัญชี</dt><dd><?= e(setting('bank_account_name')) ?></dd>
            <?php if (setting('promptpay_id')): ?><dt>PromptPay</dt><dd><?= e(setting('promptpay_id')) ?></dd><?php endif; ?>
          </dl>
        </div>
        <a class="btn btn-accent btn-block mt-4 print:hidden"
           href="<?= url('track?no=' . urlencode($b['booking_no']) . '&phone=' . urlencode($b['contact_phone'])) ?>">
          อัปโหลดสลิปการโอน
        </a>
        <?php // ปุ่มนี้โผล่เมื่อร้านตั้งค่า LIFF แล้วเท่านั้น — เปิดในแอป LINE พร้อมเลขที่จองกรอกไว้ให้ ?>
        <?php if ($liffId !== ''): ?>
          <a class="btn btn-ghost btn-block mt-2 print:hidden"
             href="https://liff.line.me/<?= e($liffId) ?>?no=<?= e(urlencode($b['booking_no'])) ?>">
            รับแจ้งเตือนและแจ้งโอนทาง LINE
          </a>
        <?php endif; ?>
        <p class="mb-0 mt-2 text-[0.79rem] text-muted">เจ้าหน้าที่จะตรวจสอบและยืนยันการจองภายในเวลาทำการ
          (<?= e(setting('open_time')) ?>–<?= e(setting('close_time')) ?> น.)
          สอบถามเร่งด่วน โทร <?= e(setting('phone')) ?> หรือ LINE <?= e(setting('line_id')) ?></p>
      </div>

      <div class="panel mt-4">
        <h3 class="text-sm">เตรียมเอกสารวันรับรถ</h3>
        <ul class="m-0 pl-[18px] text-sm text-muted">
          <li>บัตรประชาชนตัวจริง</li>
          <li>ใบขับขี่ตัวจริง (ยังไม่หมดอายุ)</li>
          <li>เงินประกัน ฿<?= money0($b['security_deposit']) ?> (เงินสด/โอน คืนเมื่อคืนรถครบเงื่อนไข)</li>
        </ul>
      </div>
    </div>
  </div>
</div>
