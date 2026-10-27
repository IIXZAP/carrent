<?php use App\Models\Payment; use App\Services\BookingStatus; use App\Services\Phone;
$due = (float) $booking['grand_total'] + (float) $booking['extra_charge']; ?>
<p class="mb-3 text-sm"><a href="<?= e(url('/admin/bookings')) ?>">← การจองทั้งหมด</a></p>
<div class="flex flex-wrap items-center gap-3 mt-2">
  <h1 class="text-2xl font-bold"><?= e($booking['booking_no']) ?></h1>
  <span class="badge badge-<?= e(status_tone('booking', $booking['status'])) ?>"><?= e(BookingStatus::STATUS[$booking['status']]) ?></span>
  <span class="text-sm text-muted"><?= e(BookingStatus::PAYMENT[$booking['payment_status']]) ?> · ช่องทาง: <?= e(BookingStatus::SOURCE[$booking['source']]) ?></span>
</div>

<div class="grid gap-6 lg:grid-cols-2 mt-6">
  <div class="panel">
    <h2 class="font-semibold">ผู้เช่า</h2>
    <dl class="mt-3 text-sm space-y-1">
      <div><dt class="inline text-muted">ชื่อ: </dt><dd class="inline"><?= e($booking['contact_name']) ?><?php if (!empty($booking['customer_id'])): ?> · <a href="<?= e(url('/admin/customers/' . (int) $booking['customer_id'] . '/edit')) ?>">ดูข้อมูลลูกค้า</a><?php endif; ?></dd></div>
      <div><dt class="inline text-muted">เบอร์: </dt><dd class="inline"><?= e(Phone::display($booking['contact_phone'])) ?></dd></div>
      <?php if ($booking['contact_email']): ?><div><dt class="inline text-muted">อีเมล: </dt><dd class="inline"><?= e($booking['contact_email']) ?></dd></div><?php endif; ?>
      <?php if ($booking['contact_line']): ?><div><dt class="inline text-muted">LINE: </dt><dd class="inline"><?= e($booking['contact_line']) ?></dd></div><?php endif; ?>
    </dl>
    <h2 class="font-semibold mt-6">รถและช่วงเวลา</h2>
    <dl class="mt-3 text-sm space-y-1">
      <div><dt class="inline text-muted">รถ: </dt><dd class="inline"><?= e($booking['brand'] . ' ' . $booking['model'] . ' · ' . $booking['plate']) ?></dd></div>
      <div><dt class="inline text-muted">รับรถ: </dt><dd class="inline"><?= e(date('d/m/Y H:i', strtotime($booking['pickup_at']))) ?> <?= e($booking['pickup_place'] ?? '') ?></dd></div>
      <div><dt class="inline text-muted">คืนรถ: </dt><dd class="inline"><?= e(date('d/m/Y H:i', strtotime($booking['return_at']))) ?> <?= e($booking['return_place'] ?? '') ?></dd></div>
      <div><dt class="inline text-muted">จำนวนวัน: </dt><dd class="inline"><?= e($booking['rental_days']) ?></dd></div>
      <?php if ($booking['delivery_km'] !== null): ?><div><dt class="inline text-muted">ระยะส่ง–รับรถ (ลูกค้าแจ้ง): </dt><dd class="inline"><?= e(rtrim(rtrim(number_format((float) $booking['delivery_km'], 1), '0'), '.')) ?> กม.</dd></div><?php endif; ?>
    </dl>
    <?php if ($booking['note']): ?><p class="mt-4 text-sm whitespace-pre-line"><span class="text-muted">หมายเหตุ:</span> <?= e($booking['note']) ?></p><?php endif; ?>
    <?php if ($booking['cancel_reason']): ?><p class="mt-2 text-sm text-danger">เหตุผลที่ยกเลิก: <?= e($booking['cancel_reason']) ?></p><?php endif; ?>
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

<?php $docTypes = \App\Services\DocumentService::TYPES; $bookingDocs = \App\Models\Document::forBooking((int) $booking['id']); ?>
<div class="panel mt-6">
  <div class="flex flex-wrap items-center gap-2">
    <h2 class="font-semibold mr-auto">เอกสาร</h2>
    <a class="btn btn-ghost" href="<?= e(url('/admin/bookings/' . $booking['id'] . '/contract')) ?>">สัญญา / ลายเซ็น</a>
    <?php foreach (['quotation', 'invoice', 'receipt'] as $dt): ?>
      <a class="btn btn-ghost" href="<?= e(url('/admin/documents/create?booking=' . $booking['id'] . '&type=' . $dt)) ?>">+ <?= e($docTypes[$dt]) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($bookingDocs === []): ?><p class="text-sm text-muted mt-2">ยังไม่มีเอกสารของการจองนี้</p>
  <?php else: ?><ul class="mt-2 text-sm space-y-1">
    <?php foreach ($bookingDocs as $d): ?><li><a class="text-brand-700 hover:underline" href="<?= e(url('/admin/documents/' . $d['id'])) ?>"><?= e($d['doc_no']) ?></a> · <?= e($docTypes[$d['doc_type']]) ?> · ฿<?= e(money($d['grand_total'])) ?> · <?= e(\App\Services\DocumentService::STATUSES[$d['status']]) ?></li><?php endforeach; ?>
  </ul><?php endif; ?>
</div>

<?php
$id = (int) $booking['id'];
$depositMissing = max(0.0, (float) $booking['deposit_amount'] - $netPaid);
$securityMissing = max(0.0, (float) $booking['security_deposit'] - $securityHeld);
$outProgress = \App\Services\InspectionService::progress($outInspection);
$inProgress = \App\Services\InspectionService::progress($inInspection);
?>
<div class="panel mt-6">
  <h2 class="font-semibold">ขั้นตอนงาน</h2>
  <p class="text-sm text-muted mt-1">รอยืนยัน → ยืนยัน → ส่งมอบรถ → รับคืนรถ → ปิดงาน (ยกเลิกได้ก่อนส่งมอบ)</p>

  <div class="mt-3 text-sm grid gap-1 sm:grid-cols-2">
    <p>ตรวจสภาพตอนส่งมอบ: ถ่ายแล้ว <?= e($outProgress['done']) ?>/<?= e($outProgress['required']) ?>
      <?= $outInspection && $outInspection['locked_at'] ? ' · <span class="text-ok">ล็อกแล้ว</span>' : '' ?>
      · <a class="text-brand-700 hover:underline" href="<?= e(url('/admin/bookings/' . $id . '/inspection/out')) ?>">เปิดหน้าถ่ายรูป</a></p>
    <p>ตรวจสภาพตอนรับคืน: ถ่ายแล้ว <?= e($inProgress['done']) ?>/<?= e($inProgress['required']) ?>
      <?= $inInspection && $inInspection['locked_at'] ? ' · <span class="text-ok">ล็อกแล้ว</span>' : '' ?>
      · <a class="text-brand-700 hover:underline" href="<?= e(url('/admin/bookings/' . $id . '/inspection/in')) ?>">เปิดหน้าถ่ายรูป</a></p>
    <?php if ($booking['picked_up_at']): ?><p>ส่งมอบ: <?= e(date('d/m/Y H:i', strtotime($booking['picked_up_at']))) ?>
      <?= $outInspection ? '· ไมล์ ' . ($outInspection['mileage'] !== null ? e(number_format((int) $outInspection['mileage'])) : '-') . ' · น้ำมัน ' . ($outInspection['fuel_level'] !== null ? e($outInspection['fuel_level']) . '/8' : '-') : '' ?></p><?php endif; ?>
    <?php if ($booking['returned_at']): ?><p>รับคืน: <?= e(date('d/m/Y H:i', strtotime($booking['returned_at']))) ?>
      <?= $inInspection ? '· ไมล์ ' . ($inInspection['mileage'] !== null ? e(number_format((int) $inInspection['mileage'])) : '-') . ' · น้ำมัน ' . ($inInspection['fuel_level'] !== null ? e($inInspection['fuel_level']) . '/8' : '-') : '' ?></p><?php endif; ?>
  </div>
  <?php if ($booking['damage_note']): ?><p class="mt-2 text-sm text-danger">ความเสียหาย: <?= e($booking['damage_note']) ?></p><?php endif; ?>
  <?php if ((float) $booking['extra_charge'] > 0): ?>
    <p class="mt-2 text-sm">ค่าใช้จ่ายเพิ่ม ฿<?= e(money($booking['extra_charge'])) ?> — <span class="text-muted"><?= e($booking['extra_note']) ?></span></p>
  <?php endif; ?>

  <?php if (in_array($booking['status'], ['pending', 'confirmed'], true)): ?>
    <?php if ($depositMissing > 0.01 || $securityMissing > 0.01): ?>
      <div class="alert alert-danger mt-3 text-sm">
        <?php if ($depositMissing > 0.01): ?>ยังรับมัดจำไม่ครบ (ขาด ฿<?= e(money($depositMissing)) ?>) <?php endif; ?>
        <?php if ($securityMissing > 0.01): ?>· ยังไม่ได้รับเงินประกันครบ (ขาด ฿<?= e(money($securityMissing)) ?>) — บันทึกที่ส่วน "การชำระเงิน" ด้านล่าง<?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="mt-4 flex flex-wrap items-end gap-4">
      <?php if ($booking['status'] === 'pending'): ?>
        <form method="post" action="<?= e(url('/admin/bookings/' . $id . '/confirm')) ?>"><?= csrf_field() ?><button class="btn">ยืนยันการจอง</button></form>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/admin/bookings/' . $id . '/pickup')) ?>" class="flex flex-wrap items-end gap-3"
            onsubmit="return confirm('ยืนยันส่งมอบรถ? ผลตรวจสภาพรอบส่งมอบจะถูกล็อก แก้ไม่ได้อีก')">
        <?= csrf_field() ?>
        <div><label class="label" for="mileage_out">เลขไมล์ตอนส่งมอบ</label><input class="w-40" id="mileage_out" name="mileage_out" inputmode="numeric" value="<?= $outInspection && $outInspection['mileage'] !== null ? e($outInspection['mileage']) : '' ?>"></div>
        <div><label class="label" for="fuel_out">น้ำมัน</label>
          <select class="w-24" id="fuel_out" name="fuel_out"><?php for ($i = 8; $i >= 0; $i--): ?><option value="<?= $i ?>" <?= ($outInspection['fuel_level'] ?? 8) == $i ? 'selected' : '' ?>><?= $i ?>/8</option><?php endfor; ?></select></div>
        <button class="btn">ส่งมอบรถ</button>
      </form>
    </div>
    <?php if (!$outProgress['complete']): ?><p class="mt-2 text-xs text-warn">ยังถ่ายรูปสภาพรถไม่ครบ (<?= e($outProgress['done']) ?>/<?= e($outProgress['required']) ?>) — ควรถ่ายก่อนส่งมอบ เพราะส่งมอบแล้วถ่ายย้อนหลังไม่ได้</p><?php endif; ?>
  <?php elseif ($booking['status'] === 'picked_up'): ?>
    <div class="mt-4"><a class="btn" href="<?= e(url('/admin/bookings/' . $id . '/return')) ?>">รับคืนรถ</a>
      <?php if (strtotime($booking['return_at']) < time()): ?><span class="ml-3 text-sm text-danger">เลยกำหนดคืนแล้ว (<?= e(date('d/m/Y H:i', strtotime($booking['return_at']))) ?>)</span><?php endif; ?></div>
  <?php elseif ($booking['status'] === 'returned'): ?>
    <div class="mt-4 flex flex-wrap gap-3">
      <form method="post" action="<?= e(url('/admin/bookings/' . $id . '/complete')) ?>"><?= csrf_field() ?><button class="btn">ปิดงาน</button></form>
    </div>
  <?php endif; ?>
  <?php if (in_array($booking['status'], ['returned', 'completed', 'cancelled'], true) && $securityHeld > 0): ?>
    <div class="mt-3"><a class="btn" href="<?= e(url('/admin/bookings/' . $id . '/security')) ?>">ปิดเงินประกัน (ถืออยู่ ฿<?= e(money($securityHeld)) ?>)</a></div>
  <?php endif; ?>
  <?php if ($booking['status'] === 'completed' && ((float) $booking['grand_total'] + (float) $booking['extra_charge'] - $netPaid) > 0.01): ?>
    <p class="mt-3 text-sm text-danger">ปิดงานแล้วแต่ยังค้างชำระ ฿<?= e(money((float) $booking['grand_total'] + (float) $booking['extra_charge'] - $netPaid)) ?></p>
  <?php endif; ?>
</div>

<?php if ($deliveryLines && in_array($booking['status'], ['pending', 'confirmed'], true)): ?>
<form method="post" action="<?= e(url('/admin/bookings/' . $booking['id'] . '/delivery')) ?>" class="panel mt-6 grid gap-3 sm:grid-cols-5 items-end">
  <?= csrf_field() ?>
  <div class="sm:col-span-5">
    <h2 class="font-semibold">ปรับค่าส่งรถ</h2>
    <p class="text-sm text-muted">ระยะที่ลูกค้ากรอกไม่ได้ถูกตรวจสอบ — ปรับค่าส่งต่อเที่ยวเองหรือกรอกระยะจริงให้ระบบคิดตามตาราง ยอดรวม VAT และสถานะการชำระเงินจะคำนวณใหม่ (ทำได้ก่อนรับรถเท่านั้น)</p>
    <p class="text-sm mt-1">ตอนนี้: <?php foreach ($deliveryLines as $l): ?><span class="mr-3"><?= e($l['name']) ?> ฿<?= e(money($l['amount'])) ?></span><?php endforeach; ?></p>
  </div>
  <div><label class="label" for="adj_km">ระยะจริง (กม.)</label><input id="adj_km" name="km" type="number" min="0" step="0.1"></div>
  <div><label class="label" for="adj_fee">หรือ ค่าส่งต่อเที่ยว (บาท)</label><input id="adj_fee" name="fee" inputmode="decimal"></div>
  <div class="sm:col-span-2"><label class="label" for="adj_reason">เหตุผล (บังคับ)</label><input id="adj_reason" name="reason" maxlength="250" required></div>
  <button class="btn btn-ghost">ปรับค่าส่ง</button>
</form>
<?php endif; ?>

<div class="panel mt-6">
  <div class="flex flex-wrap items-baseline justify-between gap-2">
    <h2 class="font-semibold">การชำระเงิน</h2>
    <p class="text-sm text-ink-2">ชำระแล้วสุทธิ <strong>฿<?= e(money($netPaid)) ?></strong> / ยอดรวม ฿<?= e(money($due)) ?>
      · ค้าง <strong>฿<?= e(money(max(0, $due - $netPaid))) ?></strong> · เงินประกันที่ร้านถือ <strong>฿<?= e(money($securityHeld)) ?></strong></p>
  </div>
  <?php if ($booking['status'] === 'cancelled' && $netPaid > 0): ?>
    <div class="alert alert-danger mt-3">การจองนี้ยกเลิกแล้ว แต่ยังไม่ได้คืนเงิน ฿<?= e(money($netPaid)) ?> — บันทึก "คืนค่าเช่า" ด้านล่าง</div>
  <?php endif; ?>

  <?php if ($payments): ?>
  <div class="overflow-x-auto mt-3">
  <table class="table">
    <thead><tr><th>วันที่</th><th>รายการ</th><th class="text-right">จำนวน</th><th>สถานะ</th><th>สลิป</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td class="whitespace-nowrap"><?= e(date('d/m/y H:i', strtotime($p['paid_at'] ?? $p['created_at']))) ?></td>
        <td><?= e(Payment::KINDS[$p['kind']]) ?> <span class="text-xs text-muted">· <?= e(Payment::METHODS[$p['method']]) ?></span>
          <?php if ($p['note']): ?><br><span class="text-xs text-muted"><?= e($p['note']) ?></span><?php endif; ?></td>
        <td class="text-right">฿<?= e(money($p['amount'])) ?></td>
        <td><span class="badge badge-<?= e(status_tone('slip', $p['status'])) ?>"><?= e(Payment::STATUS[$p['status']]) ?></span>
          <?php if ($p['approver']): ?><span class="text-xs text-muted"> โดย <?= e($p['approver']) ?></span><?php endif; ?>
          <?php if ($p['reject_reason']): ?><br><span class="text-xs text-danger"><?= e($p['reject_reason']) ?></span><?php endif; ?></td>
        <td><?php if ($p['slip_file']): ?><a class="text-brand-700 hover:underline" target="_blank" rel="noopener" href="<?= e(url('/admin/payments/' . $p['id'] . '/slip')) ?>">ดูสลิป</a><?php endif; ?></td>
        <td class="whitespace-nowrap">
          <?php if ($p['status'] === 'pending'): ?>
            <form class="inline" method="post" action="<?= e(url('/admin/payments/' . $p['id'] . '/approve')) ?>"><?= csrf_field() ?><input type="hidden" name="from" value="booking">
              <button class="btn btn-ghost btn-sm text-ok">อนุมัติ</button></form>
            <form class="inline" method="post" action="<?= e(url('/admin/payments/' . $p['id'] . '/reject')) ?>"
                  onsubmit="var r = prompt('เหตุผลที่ปฏิเสธ (ลูกค้าจะเห็น)', 'ยอดไม่ตรง'); if (r === null) { return false; } this.reason.value = r; return true;"><?= csrf_field() ?>
              <input type="hidden" name="from" value="booking"><input type="hidden" name="reason"><button class="btn btn-ghost btn-sm text-danger">ปฏิเสธ</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?><p class="mt-3 text-sm text-muted">ยังไม่มีรายการเงิน</p><?php endif; ?>

  <form method="post" action="<?= e(url('/admin/bookings/' . $booking['id'] . '/payments')) ?>" class="mt-5 grid gap-3 sm:grid-cols-5 items-end border-t border-slate-100 pt-4">
    <?= csrf_field() ?>
    <div><label class="label" for="kind">บันทึกรายการ</label>
      <select id="kind" name="kind"><?php foreach (Payment::KINDS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div><label class="label" for="method">วิธี</label>
      <select id="method" name="method"><?php foreach (Payment::METHODS as $k => $label): ?><option value="<?= e($k) ?>" <?= $k === 'cash' ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div><label class="label" for="pay_amount">จำนวน (บาท)</label><input id="pay_amount" name="amount" inputmode="decimal" required></div>
    <div><label class="label" for="pay_note">หมายเหตุ</label><input id="pay_note" name="note" maxlength="500"></div>
    <button class="btn">บันทึก</button>
  </form>
  <p class="mt-2 text-xs text-muted">บันทึกโดยพนักงานจะอนุมัติทันที · รับค่าเช่าครบมัดจำแล้วการจองจะยืนยันอัตโนมัติ · "หักจากเงินประกัน" ใช้กับค่าเช่าส่วนที่เหลือ/ค่าใช้จ่ายเพิ่ม</p>
</div>

<?php if (BookingStatus::canCancel($booking)): ?>
<form method="post" action="<?= e(url('/admin/bookings/' . $booking['id'] . '/cancel')) ?>" class="panel mt-6 flex flex-wrap items-end gap-3"
      onsubmit="return confirm('ยกเลิกการจอง <?= e($booking['booking_no']) ?> ?')">
  <?= csrf_field() ?>
  <div class="grow">
    <label class="label" for="reason">เหตุผลที่ยกเลิก</label>
    <input id="reason" name="reason" maxlength="250">
  </div>
  <button class="btn btn-danger">ยกเลิกการจอง</button>
  <p class="w-full text-xs text-muted">ถ้ารับเงินไว้แล้ว ต้องบันทึก "คืนค่าเช่า" ในส่วนการชำระเงินด้านบน</p>
</form>
<?php endif; ?>
