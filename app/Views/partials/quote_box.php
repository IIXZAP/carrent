<?php
/**
 * กล่องสรุปราคา — ใช้ร่วมกันระหว่างหน้ารายละเอียดรถ (render ครั้งแรก) และ /api/quote (อัปเดตสด)
 * ตัวแปร: $q (ผลจาก PricingService::quote), $available (bool), $periodError (?string)
 */
$q           = $q ?? [];
$available   = $available ?? false;
$periodError = $periodError ?? null;
?>
<?php if ($periodError): ?>
  <div class="alert alert-danger"><?= e($periodError) ?></div>
<?php elseif (!$available): ?>
  <div class="alert alert-warn">รถคันนี้ไม่ว่างในช่วงเวลาที่เลือก กรุณาเปลี่ยนวันหรือเลือกรถคันอื่น</div>
<?php endif; ?>

<div class="sum">
  <div class="sum-row"><span>ระยะเวลาเช่า</span><span><b><?= (int) ($q['days'] ?? 0) ?></b> วัน</span></div>
  <?php foreach (($q['rental_lines'] ?? []) as $l): ?>
    <div class="sum-row">
      <span><?= e($l['label']) ?> × <?= (int) $l['qty'] ?></span>
      <span><?= money($l['amount']) ?></span>
    </div>
  <?php endforeach; ?>
  <?php foreach (($q['addon_lines'] ?? []) as $l): ?>
    <div class="sum-row">
      <span class="text-muted"><?= e($l['label']) ?></span>
      <span><?= money($l['amount']) ?></span>
    </div>
  <?php endforeach; ?>
  <?php if (($q['discount'] ?? 0) > 0): ?>
    <div class="sum-row"><span>ส่วนลด</span><span>-<?= money($q['discount']) ?></span></div>
  <?php endif; ?>
  <?php if (($q['vat_rate'] ?? 0) > 0): ?>
    <div class="sum-row"><span>ยอดก่อน VAT</span><span><?= money($q['subtotal']) ?></span></div>
    <div class="sum-row"><span>VAT <?= rtrim(rtrim(number_format((float) $q['vat_rate'], 2), '0'), '.') ?>%</span><span><?= money($q['vat_amount']) ?></span></div>
  <?php endif; ?>
  <div class="sum-row total"><span>ค่าเช่ารวมทั้งสิ้น</span><span>฿<?= money($q['grand_total'] ?? 0) ?></span></div>
  <?php if (($q['avg_day'] ?? 0) > 0): ?>
    <div class="sum-row"><span class="text-[0.79rem] text-muted">เฉลี่ยค่าเช่าต่อวัน</span><span class="text-[0.79rem]">฿<?= money0($q['avg_day']) ?></span></div>
  <?php endif; ?>
</div>

<?php
// แยกให้เห็นชัดว่า "จ่ายตอนนี้เท่าไหร่" กับ "วันรับรถเตรียมเท่าไหร่"
// เพราะมัดจำจองหักจากค่าเช่า ส่วนเงินประกันเป็นเงินคนละก้อนที่ได้คืน
$dep = (float) ($q['deposit_amount'] ?? 0);
$bal = (float) ($q['balance_on_pickup'] ?? max(0, (float) ($q['grand_total'] ?? 0) - $dep));
$sec = (float) ($q['security_deposit'] ?? 0);
$due = (float) ($q['due_on_pickup'] ?? ($bal + $sec));
?>
<div class="mt-3.5 border-t border-line pt-3">
  <div class="grid min-w-0 grid-cols-[26px_1fr_auto] items-center gap-2.5 border-b border-line py-[9px]">
    <div class="flex h-[26px] w-[26px] flex-none items-center justify-center rounded-full bg-brand text-[0.82rem] font-bold text-white">1</div>
    <div class="flex min-w-0 flex-col gap-px">
      <b class="text-[0.95rem]">จ่ายตอนนี้เพื่อล็อกรถ</b>
      <span class="text-[0.79rem] text-muted">มัดจำการจอง — หักจากค่าเช่า ไม่ใช่เงินเพิ่ม</span>
    </div>
    <div class="whitespace-nowrap text-[1.02rem] font-bold text-dark">฿<?= money0($dep) ?></div>
  </div>
  <div class="grid min-w-0 grid-cols-[26px_1fr_auto] items-center gap-2.5 py-[9px]">
    <div class="flex h-[26px] w-[26px] flex-none items-center justify-center rounded-full bg-accent-600 text-[0.82rem] font-bold text-white">2</div>
    <div class="flex min-w-0 flex-col gap-px">
      <b class="text-[0.95rem]">วันรับรถ</b>
      <span class="text-[0.79rem] text-muted">
        ค่าเช่าส่วนที่เหลือ ฿<?= money0($bal) ?>
        <?php if ($sec > 0): ?> + เงินประกัน ฿<?= money0($sec) ?><?php endif; ?>
      </span>
    </div>
    <div class="whitespace-nowrap text-[1.02rem] font-bold text-accent-600">฿<?= money0($due) ?></div>
  </div>
  <?php if ($sec > 0): ?>
    <p class="mb-0 mt-2 text-[0.79rem] text-muted">
      เงินประกัน ฿<?= money0($sec) ?> ได้คืนเต็มจำนวนภายใน 3–7 วันทำการหลังคืนรถ
      หากไม่มีความเสียหายหรือค่าใช้จ่ายค้างชำระ
    </p>
  <?php endif; ?>
</div>
