<div class="panel text-center">
  <p class="eyebrow">จองสำเร็จ</p>
  <h1 class="text-3xl font-bold mt-2"><?= e($booking['booking_no']) ?></h1>
  <p class="mt-2 text-slate-600"><?= e($booking['brand'] . ' ' . $booking['model']) ?> · <?= e($booking['plate']) ?></p>
  <p class="mt-1 text-slate-600">รับรถ <?= e(date('d/m/Y H:i', strtotime($booking['pickup_at']))) ?>
    · คืนรถ <?= e(date('d/m/Y H:i', strtotime($booking['return_at']))) ?> (<?= e($booking['rental_days']) ?> วัน)</p>

  <table class="table mt-6 text-left">
    <tr><td>ค่าเช่ารวม</td><td class="text-right font-semibold">฿<?= e(money($booking['grand_total'])) ?></td></tr>
    <tr><td>มัดจำเพื่อยืนยันการจอง (หักจากค่าเช่า)</td><td class="text-right font-semibold text-brand-700">฿<?= e(money($booking['deposit_amount'])) ?></td></tr>
    <tr><td>เงินประกัน (ชำระวันรับรถ คืนเมื่อคืนรถ)</td><td class="text-right">฿<?= e(money($booking['security_deposit'])) ?></td></tr>
  </table>

  <p class="mt-6 text-sm text-slate-600">การจองจะยืนยันเมื่อร้านได้รับมัดจำ กรุณาจดเลขที่จองไว้ใช้ติดต่อร้าน</p>
  <div class="mt-4 text-left"><?php require __DIR__ . '/../partials/bank_info.php'; ?></div>
  <div class="mt-4 flex flex-wrap justify-center gap-3">
    <a class="btn btn-primary" href="<?= e(url('/track/view')) ?>">แจ้งโอนเงิน / ดูสถานะการจอง</a>
    <a class="btn" href="<?= e(url('/')) ?>">กลับหน้าแรก</a>
  </div>
  <p class="mt-3 text-xs text-slate-500">ภายหลังเข้าดูได้ที่หน้า "ตรวจสอบการจอง" ด้วยเลขที่จองและเบอร์โทร</p>
</div>
