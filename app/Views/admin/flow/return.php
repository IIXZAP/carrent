<?php
/** รับคืนรถ: $booking $out $in(GET) $preview $error $policy */
$v = static fn(string $k, string $d = ''): string => (string) ($in[$k] ?? $d);
$outMileage = $out['mileage'] ?? null; $outFuel = $out['fuel_level'] ?? null;
?>
<p class="mb-3 text-sm"><a href="<?= e(url('/admin/bookings/' . $booking['id'])) ?>">← <?= e($booking['booking_no']) ?></a></p>
<p class="text-sm text-ink-2 mt-1"><?= e($booking['brand'] . ' ' . $booking['model'] . ' · ' . $booking['plate']) ?>
  · ครบกำหนดคืน <strong><?= e(date('d/m/Y H:i', strtotime($booking['return_at']))) ?></strong>
  · ตอนส่งมอบ: ไมล์ <?= $outMileage !== null ? e(number_format((int) $outMileage)) : '-' ?> · น้ำมัน <?= $outFuel !== null ? e($outFuel) . '/8' : '-' ?></p>

<?php if ($error): ?><div class="alert alert-danger mt-4"><?= e($error) ?></div><?php endif; ?>

<form method="get" action="<?= e(url('/admin/bookings/' . $booking['id'] . '/return')) ?>" class="panel mt-6 grid gap-4 sm:grid-cols-4 items-end">
  <input type="hidden" name="calc" value="1">
  <div><label class="label" for="mileage_in">เลขไมล์ตอนคืน</label><input id="mileage_in" name="mileage_in" inputmode="numeric" value="<?= e($v('mileage_in')) ?>"></div>
  <div><label class="label" for="fuel_in">น้ำมันตอนคืน (0–8 ขีด)</label>
    <select id="fuel_in" name="fuel_in">
      <?php for ($i = 8; $i >= 0; $i--): ?><option value="<?= $i ?>" <?= $v('fuel_in', '8') === (string) $i ? 'selected' : '' ?>><?= $i ?>/8</option><?php endfor; ?>
    </select></div>
  <div><label class="label" for="returned_at">เวลารับคืนจริง <span class="text-muted">(เว้นว่าง = ตอนนี้)</span></label>
    <input id="returned_at" type="datetime-local" name="returned_at" value="<?= e($v('returned_at')) ?>"></div>
  <div class="sm:col-span-4"><label class="label" for="damage_note">บันทึกความเสียหาย / หมายเหตุ</label>
    <input id="damage_note" name="damage_note" maxlength="1000" value="<?= e($v('damage_note')) ?>"></div>
  <button class="btn">คำนวณค่าใช้จ่าย</button>
</form>

<?php if ($preview !== null): ?>
<form method="post" action="<?= e(url('/admin/bookings/' . $booking['id'] . '/return')) ?>" class="panel mt-6 space-y-4">
  <?= csrf_field() ?>
  <?php foreach (['mileage_in', 'fuel_in', 'returned_at', 'damage_note'] as $k): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v($k)) ?>"><?php endforeach; ?>

  <h2 class="font-semibold">ค่าใช้จ่ายเพิ่ม (รับคืน <?= e(date('d/m/Y H:i', strtotime($preview['returned_at']))) ?>)</h2>
  <p class="text-xs text-muted"><?= e($policy['grace']) ?> · <?= e($policy['fee']) ?> — ระบบคิดให้ตามเงื่อนไข ยกเว้นหรือแก้จำนวนได้ (ระบบเก็บยอดแนะนำไว้ตรวจย้อนหลัง)</p>

  <?php if (!$preview['lines']): ?>
    <p class="text-sm text-ok">ไม่มีค่าใช้จ่ายเพิ่มตามเงื่อนไข</p>
  <?php endif; ?>
  <?php foreach ($preview['lines'] as $line): ?>
    <div class="grid gap-2 sm:grid-cols-6 items-center border-b border-slate-100 pb-3">
      <label class="sm:col-span-4 flex items-start gap-2 text-sm">
        <input type="checkbox" class="mt-1" name="chg[<?= e($line['key']) ?>][on]" value="1" checked>
        <span><strong><?= e($line['label']) ?></strong><br><span class="text-muted"><?= e($line['detail']) ?></span></span>
      </label>
      <div class="sm:col-span-2"><input name="chg[<?= e($line['key']) ?>][amount]" inputmode="decimal" value="<?= e(number_format($line['amount'], 2, '.', '')) ?>"></div>
    </div>
  <?php endforeach; ?>

  <div class="grid gap-3 sm:grid-cols-3 items-end">
    <div class="sm:col-span-2"><label class="label" for="other_label">ค่าใช้จ่ายอื่น (เช่น ค่าทำความสะอาด)</label><input id="other_label" name="other_label" maxlength="120"></div>
    <div><label class="label" for="other_amount">จำนวน (บาท)</label><input id="other_amount" name="other_amount" inputmode="decimal"></div>
  </div>
  <button class="btn">ยืนยันรับคืนรถ</button>
  <p class="text-xs text-muted">เมื่อยืนยัน ผลตรวจสภาพรอบรับคืนจะถูกล็อก และยอดที่ต้องชำระของการจองจะถูกคำนวณใหม่</p>
</form>
<?php endif; ?>
