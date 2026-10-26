<?php use App\Services\BookingStatus; use App\Services\Phone; ?>
<a href="<?= e(url('/admin/bookings')) ?>" class="text-sm text-slate-500 hover:text-slate-800">← การจองทั้งหมด</a>
<div class="flex flex-wrap items-center gap-3 mt-2">
  <h1 class="text-2xl font-bold"><?= e($booking['booking_no']) ?></h1>
  <span class="badge badge-<?= e($booking['status']) ?>"><?= e(BookingStatus::STATUS[$booking['status']]) ?></span>
  <span class="text-sm text-slate-500"><?= e(BookingStatus::PAYMENT[$booking['payment_status']]) ?> · ช่องทาง: <?= e(BookingStatus::SOURCE[$booking['source']]) ?></span>
</div>

<div class="grid gap-6 lg:grid-cols-2 mt-6">
  <div class="panel">
    <h2 class="font-semibold">ผู้เช่า</h2>
    <dl class="mt-3 text-sm space-y-1">
      <div><dt class="inline text-slate-500">ชื่อ: </dt><dd class="inline"><?= e($booking['contact_name']) ?></dd></div>
      <div><dt class="inline text-slate-500">เบอร์: </dt><dd class="inline"><?= e(Phone::display($booking['contact_phone'])) ?></dd></div>
      <?php if ($booking['contact_email']): ?><div><dt class="inline text-slate-500">อีเมล: </dt><dd class="inline"><?= e($booking['contact_email']) ?></dd></div><?php endif; ?>
      <?php if ($booking['contact_line']): ?><div><dt class="inline text-slate-500">LINE: </dt><dd class="inline"><?= e($booking['contact_line']) ?></dd></div><?php endif; ?>
    </dl>
    <h2 class="font-semibold mt-6">รถและช่วงเวลา</h2>
    <dl class="mt-3 text-sm space-y-1">
      <div><dt class="inline text-slate-500">รถ: </dt><dd class="inline"><?= e($booking['brand'] . ' ' . $booking['model'] . ' · ' . $booking['plate']) ?></dd></div>
      <div><dt class="inline text-slate-500">รับรถ: </dt><dd class="inline"><?= e(date('d/m/Y H:i', strtotime($booking['pickup_at']))) ?> <?= e($booking['pickup_place'] ?? '') ?></dd></div>
      <div><dt class="inline text-slate-500">คืนรถ: </dt><dd class="inline"><?= e(date('d/m/Y H:i', strtotime($booking['return_at']))) ?> <?= e($booking['return_place'] ?? '') ?></dd></div>
      <div><dt class="inline text-slate-500">จำนวนวัน: </dt><dd class="inline"><?= e($booking['rental_days']) ?></dd></div>
    </dl>
    <?php if ($booking['note']): ?><p class="mt-4 text-sm whitespace-pre-line"><span class="text-slate-500">หมายเหตุ:</span> <?= e($booking['note']) ?></p><?php endif; ?>
    <?php if ($booking['cancel_reason']): ?><p class="mt-2 text-sm text-red-700">เหตุผลที่ยกเลิก: <?= e($booking['cancel_reason']) ?></p><?php endif; ?>
  </div>

  <div class="panel">
    <h2 class="font-semibold">ยอดเงิน</h2>
    <table class="table mt-2">
      <tr><td>ค่าเช่า</td><td class="text-right">฿<?= e(money($booking['rental_amount'])) ?></td></tr>
      <?php foreach ($addons as $a): ?>
        <tr><td><?= e($a['name']) ?></td><td class="text-right">฿<?= e(money($a['amount'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($booking['discount'] > 0): ?><tr><td>ส่วนลด</td><td class="text-right">−฿<?= e(money($booking['discount'])) ?></td></tr><?php endif; ?>
      <?php if ($booking['vat_rate'] > 0): ?><tr><td>VAT <?= e(money($booking['vat_rate'])) ?>%</td><td class="text-right">฿<?= e(money($booking['vat_amount'])) ?></td></tr><?php endif; ?>
      <tr class="font-semibold"><td>รวม</td><td class="text-right">฿<?= e(money($booking['grand_total'])) ?></td></tr>
      <tr><td>มัดจำจอง</td><td class="text-right">฿<?= e(money($booking['deposit_amount'])) ?></td></tr>
      <tr><td>เงินประกัน</td><td class="text-right">฿<?= e(money($booking['security_deposit'])) ?></td></tr>
    </table>
  </div>
</div>

<?php if (BookingStatus::canCancel($booking)): ?>
<form method="post" action="<?= e(url('/admin/bookings/' . $booking['id'] . '/cancel')) ?>" class="panel mt-6 flex flex-wrap items-end gap-3"
      onsubmit="return confirm('ยกเลิกการจอง <?= e($booking['booking_no']) ?> ?')">
  <?= csrf_field() ?>
  <div class="grow">
    <label class="label" for="reason">เหตุผลที่ยกเลิก</label>
    <input class="field" id="reason" name="reason" maxlength="250">
  </div>
  <button class="btn text-red-700 border-red-300 hover:bg-red-50">ยกเลิกการจอง</button>
  <p class="w-full text-xs text-slate-500">ถ้ารับมัดจำไว้แล้ว ต้องคืนเงินแยกต่างหาก (ระบบชำระเงินจะมาในขั้นถัดไป)</p>
</form>
<?php endif; ?>
