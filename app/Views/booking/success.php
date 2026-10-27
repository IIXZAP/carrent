<?php /** จองสำเร็จ: $booking $addons $deadline */
\App\Core\View::layout('layouts/public', ['title' => 'จองสำเร็จ', 'active' => 'cars', 'noindex' => true]); ?>
<div class="wrap max-w-[680px] py-9 sm:py-11">
  <div class="panel text-center">
    <div class="ic-box mx-auto mb-3 h-14 w-14 rounded-full bg-ok-bg text-ok"><?= icon('checkbig', 'h-7 w-7') ?></div>
    <div class="eyebrow">จองสำเร็จ</div>
    <h1 class="mb-1"><?= e($booking['booking_no']) ?></h1>
    <p class="mb-1 text-ink-2"><?= e($booking['brand'] . ' ' . $booking['model']) ?> · <?= e($booking['plate']) ?></p>
    <p class="text-sm text-muted">รับรถ <?= e(thai_date($booking['pickup_at'], true)) ?> · คืนรถ <?= e(thai_date($booking['return_at'], true)) ?> (<?= e($booking['rental_days']) ?> วัน)</p>

    <div class="sum mt-5 text-left">
      <div class="sum-row"><span>ค่าเช่ารวม</span><span>฿<?= e(money($booking['grand_total'])) ?></span></div>
      <div class="sum-row total"><span>มัดจำเพื่อยืนยันการจอง (หักจากค่าเช่า)</span><span>฿<?= e(money($booking['deposit_amount'])) ?></span></div>
      <div class="sum-row"><span>เงินประกัน (ชำระวันรับรถ คืนเมื่อคืนรถ)</span><span>฿<?= e(money($booking['security_deposit'])) ?></span></div>
    </div>

    <p class="mb-0 mt-5 text-sm text-ink-2">การจองจะยืนยันเมื่อร้านได้รับมัดจำ กรุณาจดเลขที่จองไว้ใช้ติดต่อร้าน</p>
    <?php if (!empty($deadline)): ?>
      <div class="alert alert-warn mt-3 justify-center text-center">กรุณาชำระมัดจำก่อน <?= e(thai_date($deadline, true)) ?> มิฉะนั้นการจองจะถูกยกเลิกอัตโนมัติ</div>
    <?php endif; ?>
    <div class="mt-4 text-left"><?php require __DIR__ . '/../partials/bank_info.php'; ?></div>
    <div class="mt-5 flex flex-wrap justify-center gap-3">
      <a class="btn" href="<?= e(url('/track/view')) ?>">แจ้งโอนเงิน / ดูสถานะการจอง</a>
      <a class="btn btn-ghost" href="<?= e(url('/')) ?>">กลับหน้าแรก</a>
    </div>
    <p class="mb-0 mt-3 text-xs text-muted">ภายหลังเข้าดูได้ที่หน้า "ตรวจสอบการจอง" ด้วยเลขที่จองและเบอร์โทร</p>
  </div>
</div>
