<?php
/**
 * กล่องสรุปราคา — ใช้ร่วมกันระหว่าง car.php (render ครั้งแรก) และ api/quote.php (อัปเดตสด)
 * ต้องมีตัวแปร: $q (ผลจาก quote_booking), $available (bool), $periodError (?string)
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
      <span class="muted"><?= e($l['label']) ?></span>
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
    <div class="sum-row"><span class="muted tiny">เฉลี่ยค่าเช่าต่อวัน</span><span class="tiny">฿<?= money0($q['avg_day']) ?></span></div>
  <?php endif; ?>
</div>

<?php
// แยกให้เห็นชัดว่า "จ่ายตอนนี้เท่าไหร่" กับ "วันรับรถเตรียมเท่าไหร่"
// เพราะมัดจำจองหักจากค่าเช่า ส่วนเงินประกันเป็นเงินคนละก้อนที่ได้คืน
$dep  = (float) ($q['deposit_amount'] ?? 0);
$bal  = (float) ($q['balance_on_pickup'] ?? max(0, (float) ($q['grand_total'] ?? 0) - $dep));
$sec  = (float) ($q['security_deposit'] ?? 0);
$due  = (float) ($q['due_on_pickup'] ?? ($bal + $sec));
?>
<div class="paysteps">
  <div class="paystep">
    <div class="ps-no">1</div>
    <div class="ps-body">
      <b>จ่ายตอนนี้เพื่อล็อกรถ</b>
      <span class="tiny muted">มัดจำการจอง — หักจากค่าเช่า ไม่ใช่เงินเพิ่ม</span>
    </div>
    <div class="ps-amt">฿<?= money0($dep) ?></div>
  </div>
  <div class="paystep">
    <div class="ps-no">2</div>
    <div class="ps-body">
      <b>วันรับรถ</b>
      <span class="tiny muted">
        ค่าเช่าส่วนที่เหลือ ฿<?= money0($bal) ?>
        <?php if ($sec > 0): ?> + เงินประกัน ฿<?= money0($sec) ?><?php endif; ?>
      </span>
    </div>
    <div class="ps-amt">฿<?= money0($due) ?></div>
  </div>
  <?php if ($sec > 0): ?>
    <p class="tiny muted mb-0 mt-1">
      เงินประกัน ฿<?= money0($sec) ?> ได้คืนเต็มจำนวนภายใน 3–7 วันทำการหลังคืนรถ
      หากไม่มีความเสียหายหรือค่าใช้จ่ายค้างชำระ
    </p>
  <?php endif; ?>
</div>
