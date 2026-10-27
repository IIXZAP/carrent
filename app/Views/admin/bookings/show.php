<?php use App\Models\Payment; use App\Services\BookingStatus; use App\Services\Phone;
$due = (float) $booking['grand_total'] + (float) $booking['extra_charge']; ?>
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
      <?php if ($booking['delivery_km'] !== null): ?><div><dt class="inline text-slate-500">ระยะส่ง–รับรถ (ลูกค้าแจ้ง): </dt><dd class="inline"><?= e(rtrim(rtrim(number_format((float) $booking['delivery_km'], 1), '0'), '.')) ?> กม.</dd></div><?php endif; ?>
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

<?php if ($deliveryLines && in_array($booking['status'], ['pending', 'confirmed'], true)): ?>
<form method="post" action="<?= e(url('/admin/bookings/' . $booking['id'] . '/delivery')) ?>" class="panel mt-6 grid gap-3 sm:grid-cols-5 items-end">
  <?= csrf_field() ?>
  <div class="sm:col-span-5">
    <h2 class="font-semibold">ปรับค่าส่งรถ</h2>
    <p class="text-sm text-slate-500">ระยะที่ลูกค้ากรอกไม่ได้ถูกตรวจสอบ — ปรับค่าส่งต่อเที่ยวเองหรือกรอกระยะจริงให้ระบบคิดตามตาราง ยอดรวม VAT และสถานะการชำระเงินจะคำนวณใหม่ (ทำได้ก่อนรับรถเท่านั้น)</p>
    <p class="text-sm mt-1">ตอนนี้: <?php foreach ($deliveryLines as $l): ?><span class="mr-3"><?= e($l['name']) ?> ฿<?= e(money($l['amount'])) ?></span><?php endforeach; ?></p>
  </div>
  <div><label class="label" for="adj_km">ระยะจริง (กม.)</label><input class="field" id="adj_km" name="km" type="number" min="0" step="0.1"></div>
  <div><label class="label" for="adj_fee">หรือ ค่าส่งต่อเที่ยว (บาท)</label><input class="field" id="adj_fee" name="fee" inputmode="decimal"></div>
  <div class="sm:col-span-2"><label class="label" for="adj_reason">เหตุผล (บังคับ)</label><input class="field" id="adj_reason" name="reason" maxlength="250" required></div>
  <button class="btn btn-primary">ปรับค่าส่ง</button>
</form>
<?php endif; ?>

<div class="panel mt-6">
  <div class="flex flex-wrap items-baseline justify-between gap-2">
    <h2 class="font-semibold">การชำระเงิน</h2>
    <p class="text-sm text-slate-600">ชำระแล้วสุทธิ <strong>฿<?= e(money($netPaid)) ?></strong> / ยอดรวม ฿<?= e(money($due)) ?>
      · ค้าง <strong>฿<?= e(money(max(0, $due - $netPaid))) ?></strong> · เงินประกันที่ร้านถือ <strong>฿<?= e(money($securityHeld)) ?></strong></p>
  </div>
  <?php if ($booking['status'] === 'cancelled' && $netPaid > 0): ?>
    <div class="alert alert-error mt-3">การจองนี้ยกเลิกแล้ว แต่ยังไม่ได้คืนเงิน ฿<?= e(money($netPaid)) ?> — บันทึก "คืนค่าเช่า" ด้านล่าง</div>
  <?php endif; ?>

  <?php if ($payments): ?>
  <div class="overflow-x-auto mt-3">
  <table class="table">
    <thead><tr><th>วันที่</th><th>รายการ</th><th class="text-right">จำนวน</th><th>สถานะ</th><th>สลิป</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td class="whitespace-nowrap"><?= e(date('d/m/y H:i', strtotime($p['paid_at'] ?? $p['created_at']))) ?></td>
        <td><?= e(Payment::KINDS[$p['kind']]) ?> <span class="text-xs text-slate-500">· <?= e(Payment::METHODS[$p['method']]) ?></span>
          <?php if ($p['note']): ?><br><span class="text-xs text-slate-500"><?= e($p['note']) ?></span><?php endif; ?></td>
        <td class="text-right">฿<?= e(money($p['amount'])) ?></td>
        <td><span class="badge badge-<?= e($p['status']) ?>"><?= e(Payment::STATUS[$p['status']]) ?></span>
          <?php if ($p['approver']): ?><span class="text-xs text-slate-500"> โดย <?= e($p['approver']) ?></span><?php endif; ?>
          <?php if ($p['reject_reason']): ?><br><span class="text-xs text-red-700"><?= e($p['reject_reason']) ?></span><?php endif; ?></td>
        <td><?php if ($p['slip_file']): ?><a class="text-brand-700 hover:underline" target="_blank" rel="noopener" href="<?= e(url('/admin/payments/' . $p['id'] . '/slip')) ?>">ดูสลิป</a><?php endif; ?></td>
        <td class="whitespace-nowrap">
          <?php if ($p['status'] === 'pending'): ?>
            <form class="inline" method="post" action="<?= e(url('/admin/payments/' . $p['id'] . '/approve')) ?>"><?= csrf_field() ?><input type="hidden" name="from" value="booking">
              <button class="btn !px-3 !py-1 text-green-700 border-green-300 hover:bg-green-50">อนุมัติ</button></form>
            <form class="inline" method="post" action="<?= e(url('/admin/payments/' . $p['id'] . '/reject')) ?>"
                  onsubmit="var r = prompt('เหตุผลที่ปฏิเสธ (ลูกค้าจะเห็น)', 'ยอดไม่ตรง'); if (r === null) { return false; } this.reason.value = r; return true;"><?= csrf_field() ?>
              <input type="hidden" name="from" value="booking"><input type="hidden" name="reason"><button class="btn !px-3 !py-1 text-red-700 border-red-300 hover:bg-red-50">ปฏิเสธ</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?><p class="mt-3 text-sm text-slate-500">ยังไม่มีรายการเงิน</p><?php endif; ?>

  <form method="post" action="<?= e(url('/admin/bookings/' . $booking['id'] . '/payments')) ?>" class="mt-5 grid gap-3 sm:grid-cols-5 items-end border-t border-slate-100 pt-4">
    <?= csrf_field() ?>
    <div><label class="label" for="kind">บันทึกรายการ</label>
      <select class="field" id="kind" name="kind"><?php foreach (Payment::KINDS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div><label class="label" for="method">วิธี</label>
      <select class="field" id="method" name="method"><?php foreach (Payment::METHODS as $k => $label): ?><option value="<?= e($k) ?>" <?= $k === 'cash' ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div><label class="label" for="pay_amount">จำนวน (บาท)</label><input class="field" id="pay_amount" name="amount" inputmode="decimal" required></div>
    <div><label class="label" for="pay_note">หมายเหตุ</label><input class="field" id="pay_note" name="note" maxlength="500"></div>
    <button class="btn btn-primary">บันทึก</button>
  </form>
  <p class="mt-2 text-xs text-slate-500">บันทึกโดยพนักงานจะอนุมัติทันที · รับค่าเช่าครบมัดจำแล้วการจองจะยืนยันอัตโนมัติ · "หักจากเงินประกัน" ใช้กับค่าเช่าส่วนที่เหลือ/ค่าใช้จ่ายเพิ่ม</p>
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
  <p class="w-full text-xs text-slate-500">ถ้ารับเงินไว้แล้ว ต้องบันทึก "คืนค่าเช่า" ในส่วนการชำระเงินด้านบน</p>
</form>
<?php endif; ?>
