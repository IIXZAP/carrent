<?php
/**
 * ภาพรวม — ตัวเลขที่ต้องดูทุกเช้า: งานวันนี้ รายได้ อัตราการใช้รถ งานค้าง
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

$today   = date('Y-m-d');
$mStart  = date('Y-m-01');
$mEnd    = date('Y-m-t');
$daysInM = (int) date('t');

// ---------- ตัวเลขหลัก ----------
// เจ้าของร้านเท่านั้นที่เห็นตัวเลขรายได้ — พนักงานเห็นเฉพาะงานที่ต้องทำ
$isOwner = is_admin();
// เดิมนับเงินประกันความเสียหายเป็นรายได้ด้วย ทำให้ยอดสูงเกินจริงคันละ 3,000–10,000 บาท
$revenueMonth = revenue_collected($mStart, $mEnd);
$bookedMonth = (float) db_val(
    "SELECT COALESCE(SUM(grand_total + extra_charge),0) FROM bookings
     WHERE status NOT IN ('cancelled') AND DATE(pickup_at) BETWEEN :a AND :b",
    [':a' => $mStart, ':b' => $mEnd]
);
$activeNow  = (int) db_val("SELECT COUNT(*) FROM bookings WHERE status = 'picked_up'");
$totalCars  = (int) db_val("SELECT COUNT(*) FROM cars WHERE status <> 'inactive'");
$pending    = (int) db_val("SELECT COUNT(*) FROM bookings WHERE status = 'pending'");
$slipWait   = (int) db_val("SELECT COUNT(*) FROM payments WHERE status = 'pending'");

// อัตราการใช้รถเดือนนี้ = วันที่ถูกจองรวม / (จำนวนรถ × วันในเดือน)
// นับจากเวลาเช่าจริง — เดิมนับตามวันในปฏิทิน ทำให้เช่า 1 วันถูกนับเป็น 2 วัน
$bookedDays = round(array_sum(car_days_sold($mStart, $mEnd)), 1);
$utilization = $totalCars > 0 ? min(100, round($bookedDays / ($totalCars * $daysInM) * 100)) : 0;

// ---------- งานวันนี้ ----------
$pickupToday = db_all(
    "SELECT b.*, c.brand, c.model, c.plate FROM bookings b JOIN cars c ON c.id = b.car_id
     WHERE DATE(b.pickup_at) = :d AND b.status IN ('pending','confirmed') ORDER BY b.pickup_at",
    [':d' => $today]
);
$returnToday = db_all(
    "SELECT b.*, c.brand, c.model, c.plate FROM bookings b JOIN cars c ON c.id = b.car_id
     WHERE DATE(b.return_at) = :d AND b.status = 'picked_up' ORDER BY b.return_at",
    [':d' => $today]
);
$overdue = db_all(
    "SELECT b.*, c.brand, c.model, c.plate FROM bookings b JOIN cars c ON c.id = b.car_id
     WHERE b.status = 'picked_up' AND b.return_at < NOW() ORDER BY b.return_at",
    []
);

// ---------- งานประจำรถที่ใกล้ครบ ----------
$reminderDays = (int) setting('reminder_days_before', '30');
$dueTasks = db_all(
    "SELECT m.*, c.plate, c.brand, c.model FROM maintenances m JOIN cars c ON c.id = m.car_id
     WHERE m.status = 'pending' AND m.due_date IS NOT NULL AND m.due_date <= :d
     ORDER BY m.due_date LIMIT 10",
    [':d' => date('Y-m-d', strtotime("+$reminderDays days"))]
);

// ---------- รายได้ 6 เดือนย้อนหลัง ----------
$trend = [];
for ($i = 5; $i >= 0; $i--) {
    $s = date('Y-m-01', strtotime("-$i months"));
    $e = date('Y-m-t', strtotime("-$i months"));
    $trend[] = [
        'label' => TH_MONTHS[(int) date('n', strtotime($s))],
        'value' => max(0, revenue_collected($s, $e)),
    ];
}
$maxTrend = max(1, max(array_column($trend, 'value')));

// ---------- รายได้ต่อคัน (เดือนนี้) ----------
$perCar = db_all(
    "SELECT c.id, c.plate, c.brand, c.model, c.price_day,
            COALESCE(SUM(b.grand_total + b.extra_charge),0) AS revenue,
            COUNT(b.id) AS jobs,
            COALESCE(SUM(b.rental_days),0) AS days
     FROM cars c
     LEFT JOIN bookings b ON b.car_id = c.id
          AND b.status NOT IN ('cancelled')
          AND DATE(b.pickup_at) BETWEEN :a AND :b
     WHERE c.status <> 'inactive'
     GROUP BY c.id ORDER BY revenue DESC",
    [':a' => $mStart, ':b' => $mEnd]
);
$maxCarRev = max(1, max(array_map(fn($r) => (float) $r['revenue'], $perCar ?: [['revenue' => 0]])));

admin_head('ภาพรวม', 'dashboard.php');
?>

<div class="stats">
  <?php if ($isOwner): ?>
  <div class="stat brand">
    <div class="lb">รายได้รับจริงเดือนนี้</div>
    <div class="vl">฿<?= money0($revenueMonth) ?></div>
    <div class="sub">เงินที่ยืนยันแล้ว ไม่รวมเงินประกัน</div>
  </div>
  <div class="stat accent">
    <div class="lb">ยอดจองเดือนนี้</div>
    <div class="vl">฿<?= money0($bookedMonth) ?></div>
    <div class="sub">มูลค่างานที่รับไว้ (รวมยังไม่เก็บ)</div>
  </div>
  <?php endif; ?>
  <div class="stat ok">
    <div class="lb">อัตราการใช้รถ</div>
    <div class="vl"><?= $utilization ?>%</div>
    <div class="meter mt-1 <?= $utilization < 40 ? 'danger' : ($utilization < 65 ? 'warn' : '') ?>"><i style="width:<?= $utilization ?>%"></i></div>
    <div class="sub"><?= money0($bookedDays) ?> วัน จากทั้งหมด <?= $totalCars * $daysInM ?> วัน-คัน</div>
  </div>
  <div class="stat <?= $pending + $slipWait > 0 ? 'danger' : '' ?>">
    <div class="lb">งานรอดำเนินการ</div>
    <div class="vl"><?= $pending + $slipWait ?></div>
    <div class="sub">จองรอยืนยัน <?= $pending ?> · สลิปรอตรวจ <?= $slipWait ?></div>
  </div>
  <div class="stat">
    <div class="lb">รถที่ออกงานอยู่</div>
    <div class="vl"><?= $activeNow ?> / <?= $totalCars ?></div>
    <div class="sub">คันที่ลูกค้ากำลังใช้</div>
  </div>
</div>

<?php if ($overdue): ?>
  <!-- รถเลยกำหนดคืน: ระบบกันคันเหล่านี้ไม่ให้จองต่อจนกว่าจะบันทึกรับคืน จึงต้องเห็นและกดได้ทันที -->
  <div class="panel overdue-card">
    <h3>⚠️ รถเลยกำหนดคืน <?= count($overdue) ?> คัน</h3>
    <p class="small">คันเหล่านี้ <b>ลูกค้ารายอื่นจองไม่ได้</b> จนกว่าจะบันทึกรับคืน — ถ้ารถกลับมาแล้วให้กดบันทึกทันที</p>
    <?php foreach ($overdue as $o):
        $lateH = (int) floor((time() - strtotime($o['return_at'])) / 3600);
        $lateTxt = $lateH >= 24 ? floor($lateH / 24) . ' วัน ' . ($lateH % 24) . ' ชม.' : max(1, $lateH) . ' ชม.'; ?>
      <div class="overdue-row">
        <div>
          <b><?= e($o['brand'] . ' ' . $o['model']) ?></b> <span class="muted"><?= e($o['plate']) ?></span>
          <span class="small" style="display:block"><?= e($o['contact_name']) ?> · <?= e($o['contact_phone']) ?></span>
          <span class="tiny txt-danger" style="display:block">ครบกำหนด <?= e(thai_date($o['return_at'], true)) ?> · เลยมา <?= e($lateTxt) ?></span>
        </div>
        <a class="btn btn-sm" href="<?= url('admin/booking-view.php?id=' . (int) $o['id']) ?>#actions">บันทึกรับคืน</a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="split">
  <div>
    <!-- งานวันนี้ -->
    <div class="panel">
      <h3>งานวันนี้ — <?= e(thai_date($today, false, true)) ?></h3>
      <div class="g2 mt-2">
        <div>
          <h4 class="small" style="color:var(--brand)">🚗 ส่งมอบรถ (<?= count($pickupToday) ?>)</h4>
          <?php if (!$pickupToday): ?><p class="small muted">ไม่มีงานส่งมอบวันนี้</p><?php endif; ?>
          <?php foreach ($pickupToday as $p): [$l, $cl] = booking_status_label($p['status']); ?>
            <div style="border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin-bottom:8px">
              <div style="display:flex;justify-content:space-between;gap:8px">
                <b class="small"><?= date('H:i', strtotime($p['pickup_at'])) ?> น.</b>
                <span class="badge badge-<?= $cl ?>"><?= e($l) ?></span>
              </div>
              <div class="small"><?= e($p['brand'] . ' ' . $p['model']) ?> · <?= e($p['plate']) ?></div>
              <div class="tiny muted"><?= e($p['contact_name']) ?> · <?= e($p['contact_phone']) ?></div>
              <a class="tiny" href="<?= url('admin/booking-view.php?id=' . (int) $p['id']) ?>">เปิดงาน <?= e($p['booking_no']) ?> →</a>
            </div>
          <?php endforeach; ?>
        </div>
        <div>
          <h4 class="small" style="color:var(--ok)">🔙 รับคืนรถ (<?= count($returnToday) ?>)</h4>
          <?php if (!$returnToday): ?><p class="small muted">ไม่มีงานรับคืนวันนี้</p><?php endif; ?>
          <?php foreach ($returnToday as $p): ?>
            <div style="border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin-bottom:8px">
              <div style="display:flex;justify-content:space-between;gap:8px">
                <b class="small"><?= date('H:i', strtotime($p['return_at'])) ?> น.</b>
                <span class="badge badge-primary">รับรถแล้ว</span>
              </div>
              <div class="small"><?= e($p['brand'] . ' ' . $p['model']) ?> · <?= e($p['plate']) ?></div>
              <div class="tiny muted"><?= e($p['contact_name']) ?> · <?= e($p['contact_phone']) ?></div>
              <a class="tiny" href="<?= url('admin/booking-view.php?id=' . (int) $p['id']) ?>">เปิดงาน <?= e($p['booking_no']) ?> →</a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php if ($isOwner): ?>
    <!-- รายได้ย้อนหลัง -->
    <div class="panel mt-2">
      <h3>รายได้รับจริง 6 เดือนย้อนหลัง</h3>
      <div class="bars mt-2">
        <?php foreach ($trend as $t): ?>
          <div class="b">
            <div class="vl">฿<?= money0($t['value'] / 1000) ?>k</div>
            <div class="fill" style="height:<?= max(2, round($t['value'] / $maxTrend * 100)) ?>%" title="<?= money($t['value']) ?> บาท"></div>
            <div class="lb"><?= e($t['label']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- รายได้ต่อคัน -->
    <div class="panel mt-2">
      <h3>รายได้ต่อคัน เดือนนี้</h3>
      <div class="table-wrap mt-2">
        <table class="table">
          <thead><tr><th>รถ</th><th class="num">งาน</th><th class="num">วันเช่า</th><th class="num">ยอดจอง</th><th style="width:26%">สัดส่วน</th></tr></thead>
          <tbody>
            <?php foreach ($perCar as $r): ?>
              <tr>
                <td><b class="small"><?= e($r['brand'] . ' ' . $r['model']) ?></b><br><span class="tiny muted"><?= e($r['plate']) ?></span></td>
                <td class="num"><?= (int) $r['jobs'] ?></td>
                <td class="num"><?= (int) $r['days'] ?></td>
                <td class="num">฿<?= money0($r['revenue']) ?></td>
                <td><div class="meter"><i style="width:<?= round((float) $r['revenue'] / $maxCarRev * 100) ?>%"></i></div></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- คอลัมน์ขวา -->
  <div>
    <div class="panel">
      <h3>ทางลัด</h3>
      <a class="btn btn-block" href="<?= url('admin/booking-edit.php') ?>">+ สร้างการจองใหม่</a>
      <a class="btn btn-ghost btn-block mt-1" href="<?= url('admin/payments.php') ?>">ตรวจสลิป (<?= $slipWait ?>)</a>
      <a class="btn btn-ghost btn-block mt-1" href="<?= url('admin/calendar.php') ?>">ดูปฏิทินรถ</a>
      <a class="btn btn-ghost btn-block mt-1" href="<?= url('admin/document-edit.php?type=quotation') ?>">ออกใบเสนอราคา</a>
    </div>

    <div class="panel mt-2">
      <h3>งานประจำรถใกล้ครบกำหนด</h3>
      <?php if (!$dueTasks): ?>
        <p class="small muted mb-0">ไม่มีงานที่ใกล้ครบกำหนดใน <?= $reminderDays ?> วันข้างหน้า</p>
      <?php endif; ?>
      <?php foreach ($dueTasks as $t):
          $left = (int) floor((strtotime($t['due_date']) - strtotime($today)) / 86400);
          $cls  = $left < 0 ? 'danger' : ($left <= 7 ? 'warn' : 'info');
      ?>
        <div style="display:flex;gap:10px;align-items:flex-start;padding:9px 0;border-bottom:1px solid var(--line)">
          <span class="badge badge-<?= $cls ?>" style="flex:none"><?= $left < 0 ? 'เกิน ' . abs($left) . ' วัน' : $left . ' วัน' ?></span>
          <div style="min-width:0">
            <div class="small"><?= e($t['title']) ?></div>
            <div class="tiny muted"><?= e($t['plate']) ?> · ครบ <?= e(thai_date($t['due_date'])) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <a class="btn btn-ghost btn-sm btn-block mt-2" href="<?= url('admin/maintenance.php') ?>">ดูทั้งหมด</a>
    </div>

    <div class="panel mt-2">
      <h3>สถานะรถ</h3>
      <?php
      $carStatus = db_all("SELECT status, COUNT(*) n FROM cars GROUP BY status");
      $labels = ['available' => ['พร้อมให้เช่า', 'ok'], 'maintenance' => ['เข้าศูนย์/ซ่อม', 'warn'], 'inactive' => ['ปิดใช้งาน', 'muted']];
      foreach ($carStatus as $cs):
          [$l, $c] = $labels[$cs['status']] ?? [$cs['status'], 'muted']; ?>
        <div class="sum-row" style="border-bottom:1px solid var(--line)">
          <span><span class="badge badge-<?= $c ?>"><?= e($l) ?></span></span>
          <span><b><?= (int) $cs['n'] ?></b> คัน</span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php admin_foot(); ?>
