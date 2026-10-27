<?php use App\Models\Payment; use App\Services\BookingStatus; ?>
<div class="flex items-center justify-between">
  <div>
    <p class="eyebrow">การจองของคุณ</p>
    <h1 class="text-2xl font-bold"><?= e($booking['booking_no']) ?></h1>
  </div>
  <form method="post" action="<?= e(url('/track/leave')) ?>"><?= csrf_field() ?><button class="text-sm text-slate-500 hover:text-slate-800">ออก</button></form>
</div>

<div class="panel mt-4">
  <div class="flex flex-wrap gap-2">
    <span class="badge badge-<?= e($booking['status']) ?>"><?= e(BookingStatus::STATUS[$booking['status']]) ?></span>
    <span class="badge badge-inactive"><?= e(BookingStatus::PAYMENT[$booking['payment_status']]) ?></span>
  </div>
  <p class="mt-3"><?= e($booking['brand'] . ' ' . $booking['model']) ?> · <?= e($booking['plate']) ?></p>
  <p class="text-sm text-slate-600 mt-1">รับรถ <?= e(date('d/m/Y H:i', strtotime($booking['pickup_at']))) ?>
    · คืนรถ <?= e(date('d/m/Y H:i', strtotime($booking['return_at']))) ?> (<?= e($booking['rental_days']) ?> วัน)</p>

  <table class="table mt-4">
    <tr><td>ค่าเช่ารวม</td><td class="text-right">฿<?= e(money($booking['grand_total'] + $booking['extra_charge'])) ?></td></tr>
    <tr><td>มัดจำเพื่อยืนยันการจอง</td><td class="text-right">฿<?= e(money($booking['deposit_amount'])) ?></td></tr>
    <tr><td>ชำระแล้ว (ที่ร้านตรวจสอบแล้ว)</td><td class="text-right">฿<?= e(money($paid)) ?></td></tr>
    <tr class="font-semibold"><td>คงเหลือค่าเช่า</td><td class="text-right text-brand-700">฿<?= e(money($remaining)) ?></td></tr>
    <tr><td>เงินประกัน (ชำระวันรับรถ คืนเมื่อคืนรถ)</td><td class="text-right">฿<?= e(money($booking['security_deposit'])) ?></td></tr>
  </table>
</div>

<?php $extraLines = array_filter(json_decode((string) ($booking['extra_breakdown'] ?? ''), true) ?: [], static fn(array $l): bool => empty($l['waived']) && (float) $l['amount'] > 0); ?>
<?php if ($extraLines): ?>
  <div class="panel mt-4">
    <h2 class="font-semibold">ค่าใช้จ่ายเพิ่มตอนรับคืนรถ</h2>
    <table class="table mt-2">
      <?php foreach ($extraLines as $l): ?>
        <tr><td><?= e($l['label']) ?><?php if (!empty($l['detail'])): ?><br><span class="text-xs text-slate-500"><?= e($l['detail']) ?></span><?php endif; ?></td><td class="text-right">฿<?= e(money($l['amount'])) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

<?php if ($booking['status'] === 'pending' && $paid + 0.01 < $booking['deposit_amount']): ?>
  <div class="alert alert-success mt-4">การจองจะยืนยันเมื่อร้านได้รับมัดจำ ฿<?= e(money($booking['deposit_amount'])) ?> กรุณาโอนแล้วแนบสลิปด้านล่าง
    <?php if (!empty($deadline)): ?><br><strong>ชำระก่อน <?= e(date('d/m/Y H:i', strtotime($deadline))) ?> น.</strong> มิฉะนั้นการจองจะถูกยกเลิกอัตโนมัติ<?php endif; ?></div>
<?php endif; ?>

<?php if ($canPay): ?>
<div class="panel mt-6">
  <h2 class="font-semibold">แจ้งโอนเงิน</h2>
  <div class="mt-3"><?php require __DIR__ . '/../partials/bank_info.php'; ?></div>
  <form method="post" action="<?= e(url('/track/slip')) ?>" enctype="multipart/form-data" class="mt-4 grid gap-4 sm:grid-cols-2">
    <?= csrf_field() ?>
    <div>
      <label class="label" for="amount">จำนวนเงินที่โอน (บาท)</label>
      <input class="field" id="amount" name="amount" inputmode="decimal" required placeholder="<?= e(money(max($remaining, 0))) ?>">
    </div>
    <div>
      <label class="label" for="paid_at">วัน–เวลาที่โอน</label>
      <input class="field" id="paid_at" type="datetime-local" name="paid_at" value="<?= e(date('Y-m-d\TH:i')) ?>">
    </div>
    <div class="sm:col-span-2">
      <label class="label" for="slip">รูปสลิป (JPG / PNG / WEBP ไม่เกิน 8 MB)</label>
      <input class="field" id="slip" type="file" name="slip" accept="image/jpeg,image/png,image/webp" required>
    </div>
    <div class="sm:col-span-2">
      <label class="label" for="note">หมายเหตุ (ไม่บังคับ)</label>
      <input class="field" id="note" name="note" maxlength="500">
    </div>
    <div class="sm:col-span-2"><button class="btn btn-primary">ส่งหลักฐานการโอน</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($payments): ?>
<div class="panel mt-6 p-0 overflow-x-auto">
  <table class="table">
    <thead><tr><th>วันที่</th><th>รายการ</th><th class="text-right">จำนวน</th><th>สถานะ</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td class="whitespace-nowrap"><?= e(date('d/m/y H:i', strtotime($p['paid_at'] ?? $p['created_at']))) ?></td>
        <td><?= e(Payment::KINDS[$p['kind']]) ?></td>
        <td class="text-right">฿<?= e(money($p['amount'])) ?></td>
        <td><span class="badge badge-<?= e($p['status']) ?>"><?= e(Payment::STATUS[$p['status']]) ?></span>
          <?php if ($p['status'] === 'rejected' && $p['reject_reason']): ?><br><span class="text-xs text-red-700"><?= e($p['reject_reason']) ?></span><?php endif; ?></td>
        <td><?php if ($p['slip_file']): ?><a class="text-brand-700 hover:underline" target="_blank" href="<?= e(url('/track/slip/' . $p['id'])) ?>">ดูสลิป</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
