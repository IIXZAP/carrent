<?php
/**
 * รายงาน — รายได้ อัตราการใช้รถ ต้นทุนบำรุงรักษา กำไรต่อคัน + export CSV
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_admin();   // ตัวเลขรายได้ — เฉพาะเจ้าของร้าน

$from = trim($_GET['from'] ?? date('Y-m-01'));
$to   = trim($_GET['to'] ?? date('Y-m-t'));
if (strtotime($to) < strtotime($from)) {
    [$from, $to] = [$to, $from];
}
$days = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);

// ---------- รายได้ต่อคัน + ต้นทุน ----------
$perCar = db_all(
    "SELECT c.id, c.plate, c.brand, c.model, c.price_day, c.mileage,
            COALESCE(SUM(b.grand_total + b.extra_charge), 0) AS revenue,
            COUNT(b.id)                                      AS jobs,
            COALESCE(SUM(b.rental_days), 0)                  AS rent_days
     FROM cars c
     LEFT JOIN bookings b ON b.car_id = c.id AND b.status NOT IN ('cancelled')
          AND DATE(b.pickup_at) BETWEEN :a AND :b
     WHERE c.status <> 'inactive'
     GROUP BY c.id ORDER BY revenue DESC",
    [':a' => $from, ':b' => $to]
);
// วันที่รถออกจริงในช่วงที่ดู — ใช้ฟังก์ชันเดียวกับหน้าภาพรวมและรายงานรายเดือน ตัวเลขอัตราการใช้รถจึงตรงกันทุกหน้า
// (เดิมรวม rental_days ของการจองที่ "รับรถ" ในช่วงนี้ การเช่าข้ามเดือนจึงถูกนับทั้งก้อนในเดือนที่รับรถ)
$sold = car_days_sold($from, $to);
foreach ($perCar as &$__r) {
    $__r['rent_days'] = (int) round($sold[(int) $__r['id']] ?? 0);
}
unset($__r);
$costByCar = [];
foreach (db_all(
    "SELECT car_id, COALESCE(SUM(cost),0) AS cost FROM maintenances
     WHERE status = 'done' AND done_date BETWEEN :a AND :b GROUP BY car_id",
    [':a' => $from, ':b' => $to]
) as $r) {
    $costByCar[(int) $r['car_id']] = (float) $r['cost'];
}

// ---------- Export CSV ----------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM ให้ Excel อ่านภาษาไทยถูก
    fputcsv($out, ['ทะเบียน','รถ','จำนวนงาน','วันเช่ารวม','อัตราการใช้รถ (%)','รายได้ (บาท)','ค่าบำรุงรักษา (บาท)','กำไรขั้นต้น (บาท)','รายได้เฉลี่ย/วันที่ปล่อยเช่า'], ',', '"', '');
    foreach ($perCar as $r) {
        $cost = $costByCar[(int) $r['id']] ?? 0;
        fputcsv($out, [
            $r['plate'], $r['brand'] . ' ' . $r['model'], (int) $r['jobs'], (int) $r['rent_days'],
            round((int) $r['rent_days'] / $days * 100, 1),
            round((float) $r['revenue'], 2), round($cost, 2), round((float) $r['revenue'] - $cost, 2),
            (int) $r['rent_days'] > 0 ? round((float) $r['revenue'] / (int) $r['rent_days'], 2) : 0,
        ], ',', '"', '');
    }
    fclose($out);
    exit;
}

// ---------- สรุปรวม ----------
$revenue    = array_sum(array_map(fn($r) => (float) $r['revenue'], $perCar));
$cost       = array_sum($costByCar);
$rentDays   = array_sum(array_map(fn($r) => (int) $r['rent_days'], $perCar));
$carCount   = count($perCar);
$util       = $carCount ? round($rentDays / ($carCount * $days) * 100, 1) : 0;
$collected  = (float) db_val(
    'SELECT COALESCE(SUM(' . PAYMENT_REVENUE_SQL . "),0)
     FROM payments WHERE status='approved' AND DATE(approved_at) BETWEEN :a AND :b",
    [':a' => $from, ':b' => $to]
);
$jobs       = array_sum(array_map(fn($r) => (int) $r['jobs'], $perCar));
$avgTicket  = $jobs ? $revenue / $jobs : 0;
$maxRev     = max(1, max(array_map(fn($r) => (float) $r['revenue'], $perCar ?: [['revenue' => 0]])));

// ---------- รายได้รายวัน ----------
$daily = db_all(
    'SELECT DATE(approved_at) d,
            SUM(' . PAYMENT_REVENUE_SQL . ") amt
     FROM payments WHERE status='approved' AND DATE(approved_at) BETWEEN :a AND :b
     GROUP BY DATE(approved_at) ORDER BY d",
    [':a' => $from, ':b' => $to]
);
$maxDaily = max(1, max(array_map(fn($r) => (float) $r['amt'], $daily ?: [['amt' => 0]])));

// ---------- ประเภทรถที่ทำเงิน ----------
$byType = db_all(
    "SELECT c.car_type, COUNT(b.id) jobs, COALESCE(SUM(b.grand_total + b.extra_charge),0) revenue
     FROM bookings b JOIN cars c ON c.id = b.car_id
     WHERE b.status NOT IN ('cancelled') AND DATE(b.pickup_at) BETWEEN :a AND :b
     GROUP BY c.car_type ORDER BY revenue DESC",
    [':a' => $from, ':b' => $to]
);

// ---------- แหล่งที่มาของงาน ----------
$bySource = db_all(
    "SELECT source, COUNT(*) n, COALESCE(SUM(grand_total + extra_charge),0) revenue FROM bookings
     WHERE status NOT IN ('cancelled') AND DATE(pickup_at) BETWEEN :a AND :b
     GROUP BY source ORDER BY n DESC",
    [':a' => $from, ':b' => $to]
);
$srcLabel = ['web'=>'จองผ่านเว็บ','admin'=>'หน้าร้าน','line'=>'LINE','phone'=>'โทรศัพท์'];

admin_head('รายงาน', 'reports.php');
?>
<form class="toolbar" method="get">
  <div class="field"><label>ตั้งแต่</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="field"><label>ถึง</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-sm">ดูรายงาน</button>
  <a class="btn btn-ghost btn-sm" href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?>">เดือนนี้</a>
  <a class="btn btn-ghost btn-sm" href="?from=<?= date('Y-m-01', strtotime('-1 month')) ?>&to=<?= date('Y-m-t', strtotime('-1 month')) ?>">เดือนที่แล้ว</a>
  <a class="btn btn-ghost btn-sm" href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-12-31') ?>">ปีนี้</a>
  <div class="spacer"></div>
  <a class="btn btn-sm" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&export=csv">⬇ Export Excel (CSV)</a>
  <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()">พิมพ์</button>
</form>

<div class="stats">
  <div class="stat brand"><div class="lb">ยอดขาย (มูลค่างาน)</div><div class="vl">฿<?= money0($revenue) ?></div>
    <div class="sub"><?= $jobs ?> งาน · เฉลี่ย ฿<?= money0($avgTicket) ?>/งาน</div></div>
  <div class="stat ok"><div class="lb">เงินรับจริง</div><div class="vl">฿<?= money0($collected) ?></div>
    <div class="sub">จากสลิป/เงินสดที่ยืนยันแล้ว</div></div>
  <div class="stat danger"><div class="lb">ค่าบำรุงรักษา</div><div class="vl">฿<?= money0($cost) ?></div>
    <div class="sub"><?= $revenue > 0 ? round($cost / $revenue * 100, 1) : 0 ?>% ของยอดขาย</div></div>
  <div class="stat accent"><div class="lb">กำไรขั้นต้น</div><div class="vl">฿<?= money0($revenue - $cost) ?></div>
    <div class="sub">ยังไม่หักค่าเสื่อม/ค่าประกัน/ดอกเบี้ย</div></div>
  <div class="stat"><div class="lb">อัตราการใช้รถ</div><div class="vl"><?= $util ?>%</div>
    <div class="meter mt-1 <?= $util < 40 ? 'danger' : ($util < 65 ? 'warn' : '') ?>"><i style="width:<?= min(100, $util) ?>%"></i></div>
    <div class="sub"><?= $rentDays ?> วัน จาก <?= $carCount * $days ?> วัน-คัน</div></div>
</div>

<div class="panel">
  <h3>รายได้รายวัน (เงินรับจริง)</h3>
  <?php if (!$daily): ?>
    <p class="small muted mb-0">ยังไม่มีการรับเงินในช่วงนี้</p>
  <?php else: ?>
    <div class="bars mt-2" style="height:150px">
      <?php foreach ($daily as $d): ?>
        <div class="b">
          <div class="fill" style="height:<?= max(2, round((float) $d['amt'] / $maxDaily * 100)) ?>%"
               title="<?= e(thai_date($d['d'])) ?> · <?= money($d['amt']) ?> บาท"></div>
          <div class="lb"><?= (int) date('j', strtotime($d['d'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="panel mt-2">
  <h3>ผลประกอบการรายคัน</h3>
  <div class="table-wrap mt-2">
    <table class="table">
      <thead>
        <tr><th>รถ</th><th class="num">งาน</th><th class="num">วันเช่า</th><th class="num">ใช้รถ%</th>
            <th class="num">รายได้</th><th class="num">ค่าบำรุง</th><th class="num">กำไรขั้นต้น</th>
            <th class="num">รายได้/วันปล่อยเช่า</th><th style="width:16%">สัดส่วน</th></tr>
      </thead>
      <tbody>
        <?php foreach ($perCar as $r):
            $c    = $costByCar[(int) $r['id']] ?? 0;
            $gp   = (float) $r['revenue'] - $c;
            $u    = round((int) $r['rent_days'] / $days * 100);
            $perD = (int) $r['rent_days'] > 0 ? (float) $r['revenue'] / (int) $r['rent_days'] : 0;
        ?>
          <tr>
            <td><b class="small"><?= e($r['brand'] . ' ' . $r['model']) ?></b>
              <div class="tiny muted"><?= e($r['plate']) ?> · เรต ฿<?= money0($r['price_day']) ?>/วัน</div></td>
            <td class="num"><?= (int) $r['jobs'] ?></td>
            <td class="num"><?= (int) $r['rent_days'] ?></td>
            <td class="num <?= $u < 30 ? 'muted' : '' ?>"><?= $u ?>%</td>
            <td class="num">฿<?= money0($r['revenue']) ?></td>
            <td class="num"><?= $c > 0 ? '฿' . money0($c) : '-' ?></td>
            <td class="num"><b style="color:<?= $gp >= 0 ? 'var(--ok)' : 'var(--danger)' ?>">฿<?= money0($gp) ?></b></td>
            <td class="num"><?= $perD > 0 ? '฿' . money0($perD) : '-' ?>
              <?php if ($perD > 0 && $perD < (float) $r['price_day'] * 0.85): ?>
                <div class="tiny" style="color:var(--warn)">ต่ำกว่าเรต</div>
              <?php endif; ?>
            </td>
            <td><div class="meter"><i style="width:<?= round((float) $r['revenue'] / $maxRev * 100) ?>%"></i></div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:var(--bg);font-weight:700">
          <td>รวม</td><td class="num"><?= $jobs ?></td><td class="num"><?= $rentDays ?></td>
          <td class="num"><?= $util ?>%</td><td class="num">฿<?= money0($revenue) ?></td>
          <td class="num">฿<?= money0($cost) ?></td><td class="num">฿<?= money0($revenue - $cost) ?></td>
          <td class="num"><?= $rentDays ? '฿' . money0($revenue / $rentDays) : '-' ?></td><td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div class="g2 mt-2">
  <div class="panel">
    <h3>รายได้ตามประเภทรถ</h3>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>ประเภท</th><th class="num">งาน</th><th class="num">รายได้</th><th class="num">สัดส่วน</th></tr></thead>
        <tbody>
          <?php if (!$byType): ?><tr><td colspan="4" class="center muted">ไม่มีข้อมูล</td></tr><?php endif; ?>
          <?php foreach ($byType as $t): ?>
            <tr>
              <td><?= e(car_type_label($t['car_type'])) ?></td>
              <td class="num"><?= (int) $t['jobs'] ?></td>
              <td class="num">฿<?= money0($t['revenue']) ?></td>
              <td class="num"><?= $revenue > 0 ? round((float) $t['revenue'] / $revenue * 100) : 0 ?>%</td>
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
          <?php if (!$bySource): ?><tr><td colspan="4" class="center muted">ไม่มีข้อมูล</td></tr><?php endif; ?>
          <?php foreach ($bySource as $s): ?>
            <tr>
              <td><?= e($srcLabel[$s['source']] ?? $s['source']) ?></td>
              <td class="num"><?= (int) $s['n'] ?></td>
              <td class="num">฿<?= money0($s['revenue']) ?></td>
              <td class="num"><?= $jobs > 0 ? round((int) $s['n'] / $jobs * 100) : 0 ?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php admin_foot(); ?>
