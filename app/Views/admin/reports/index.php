<?php
/** รายงาน — ตัวแปรดูใน ReportController::index */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'reports']);
$meterTone = $util < 40 ? 'danger' : ($util < 65 ? 'warn' : '');
?>
<form class="toolbar print:hidden" method="get">
  <div class="field"><label>ตั้งแต่</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="field"><label>ถึง</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-sm">ดูรายงาน</button>
  <a class="btn btn-ghost btn-sm" href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?>">เดือนนี้</a>
  <a class="btn btn-ghost btn-sm" href="?from=<?= date('Y-m-01', strtotime('-1 month')) ?>&to=<?= date('Y-m-t', strtotime('-1 month')) ?>">เดือนที่แล้ว</a>
  <a class="btn btn-ghost btn-sm" href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-12-31') ?>">ปีนี้</a>
  <a class="btn btn-sm ml-auto" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&export=csv">⬇ Export Excel (CSV)</a>
  <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()">พิมพ์</button>
</form>

<div class="stat-grid-lg">
  <div class="stat stat-brand"><div class="lb">ยอดขาย (มูลค่างาน)</div><div class="vl">฿<?= money0($revenue) ?></div>
    <div class="sub"><?= (int) $jobs ?> งาน · เฉลี่ย ฿<?= money0($avgTicket) ?>/งาน</div></div>
  <div class="stat stat-ok"><div class="lb">เงินรับจริง</div><div class="vl">฿<?= money0($collected) ?></div>
    <div class="sub">จากสลิป/เงินสดที่ยืนยันแล้ว</div></div>
  <div class="stat stat-danger"><div class="lb">ค่าบำรุงรักษา</div><div class="vl">฿<?= money0($cost) ?></div>
    <div class="sub"><?= $revenue > 0 ? round($cost / $revenue * 100, 1) : 0 ?>% ของยอดขาย</div></div>
  <div class="stat stat-accent"><div class="lb">กำไรขั้นต้น</div><div class="vl">฿<?= money0($revenue - $cost) ?></div>
    <div class="sub">ยังไม่หักค่าเสื่อม/ค่าประกัน/ดอกเบี้ย</div></div>
  <div class="stat"><div class="lb">อัตราการใช้รถ</div><div class="vl"><?= $util ?>%</div>
    <div class="meter mt-1 <?= $meterTone ?>"><i style="width:<?= (int) min(100, $util) ?>%"></i></div>
    <div class="sub"><?= (int) $rentDays ?> วัน จาก <?= (int) ($carCount * $days) ?> วัน-คัน</div></div>
</div>

<div class="panel">
  <h3>รายได้รายวัน (เงินรับจริง)</h3>
  <?php if (!$daily): ?>
    <p class="mb-0 text-sm text-muted">ยังไม่มีการรับเงินในช่วงนี้</p>
  <?php else: ?>
    <div class="mt-2 flex h-[150px] items-end gap-2.5 pt-2.5">
      <?php foreach ($daily as $d): ?>
        <div class="flex h-full flex-1 flex-col items-center justify-end gap-1.5">
          <div class="min-h-[3px] w-full rounded-t-[5px] bg-brand hover:bg-accent" style="height:<?= max(2, (int) round((float) $d['amt'] / $maxDaily * 100)) ?>%"
               title="<?= e(thai_date($d['d'])) ?> · <?= e(money($d['amt'])) ?> บาท"></div>
          <div class="whitespace-nowrap text-xs text-muted"><?= (int) date('j', strtotime($d['d'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="panel mt-4">
  <h3>ผลประกอบการรายคัน</h3>
  <div class="table-wrap mt-2">
    <table class="table">
      <thead>
        <tr><th>รถ</th><th class="num">งาน</th><th class="num">วันเช่า</th><th class="num">ใช้รถ%</th>
            <th class="num">รายได้</th><th class="num">ค่าบำรุง</th><th class="num">กำไรขั้นต้น</th>
            <th class="num">รายได้/วันปล่อยเช่า</th><th class="w-[16%]">สัดส่วน</th></tr>
      </thead>
      <tbody>
        <?php foreach ($perCar as $r):
            $c    = $costByCar[(int) $r['id']] ?? 0;
            $gp   = (float) $r['revenue'] - $c;
            $u    = (int) round((int) $r['rent_days'] / $days * 100);
            $perD = (int) $r['rent_days'] > 0 ? (float) $r['revenue'] / (int) $r['rent_days'] : 0; ?>
          <tr>
            <td><b class="text-sm"><?= e($r['brand'] . ' ' . $r['model']) ?></b>
              <div class="text-xs text-muted"><?= e($r['plate']) ?> · เรต ฿<?= money0($r['price_day']) ?>/วัน</div></td>
            <td class="num"><?= (int) $r['jobs'] ?></td>
            <td class="num"><?= (int) $r['rent_days'] ?></td>
            <td class="num <?= $u < 30 ? 'text-muted' : '' ?>"><?= $u ?>%</td>
            <td class="num">฿<?= money0($r['revenue']) ?></td>
            <td class="num"><?= $c > 0 ? '฿' . money0($c) : '-' ?></td>
            <td class="num"><b class="<?= $gp >= 0 ? 'text-ok' : 'text-danger' ?>">฿<?= money0($gp) ?></b></td>
            <td class="num"><?= $perD > 0 ? '฿' . money0($perD) : '-' ?>
              <?php if ($perD > 0 && $perD < (float) $r['price_day'] * 0.85): ?>
                <div class="text-xs text-warn">ต่ำกว่าเรต</div>
              <?php endif; ?>
            </td>
            <td><div class="meter"><i style="width:<?= (int) round((float) $r['revenue'] / $maxRev * 100) ?>%"></i></div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="bg-surface font-bold">
          <td class="px-2.5 py-[11px]">รวม</td><td class="num"><?= (int) $jobs ?></td><td class="num"><?= (int) $rentDays ?></td>
          <td class="num"><?= $util ?>%</td><td class="num">฿<?= money0($revenue) ?></td>
          <td class="num">฿<?= money0($cost) ?></td><td class="num">฿<?= money0($revenue - $cost) ?></td>
          <td class="num"><?= $rentDays ? '฿' . money0($revenue / $rentDays) : '-' ?></td><td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
  <div class="panel">
    <h3>รายได้ตามประเภทรถ</h3>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>ประเภท</th><th class="num">งาน</th><th class="num">รายได้</th><th class="num">สัดส่วน</th></tr></thead>
        <tbody>
          <?php if (!$byType): ?><tr><td colspan="4" class="text-center text-muted">ไม่มีข้อมูล</td></tr><?php endif; ?>
          <?php foreach ($byType as $t): ?>
            <tr>
              <td><?= e(car_type_label($t['car_type'])) ?></td>
              <td class="num"><?= (int) $t['jobs'] ?></td>
              <td class="num">฿<?= money0($t['revenue']) ?></td>
              <td class="num"><?= $revenue > 0 ? (int) round((float) $t['revenue'] / $revenue * 100) : 0 ?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <h3>งานมาจากช่องทางไหน</h3>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>ช่องทาง</th><th class="num">จำนวนงาน</th><th class="num">มูลค่า</th><th class="num">สัดส่วน</th></tr></thead>
        <tbody>
          <?php if (!$bySource): ?><tr><td colspan="4" class="text-center text-muted">ไม่มีข้อมูล</td></tr><?php endif; ?>
          <?php foreach ($bySource as $s): ?>
            <tr>
              <td><?= e($srcLabel[$s['source']] ?? $s['source']) ?></td>
              <td class="num"><?= (int) $s['n'] ?></td>
              <td class="num">฿<?= money0($s['revenue']) ?></td>
              <td class="num"><?= $jobs > 0 ? (int) round((int) $s['n'] / $jobs * 100) : 0 ?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
