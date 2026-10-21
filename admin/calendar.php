<?php
/**
 * ปฏิทินรถแบบ timeline — เห็นทั้ง 8 คันพร้อมกันว่าคันไหนว่างวันไหน
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

$month = trim($_GET['m'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$first    = $month . '-01';
$daysIn   = (int) date('t', strtotime($first));
$last     = date('Y-m-t', strtotime($first));
$prev     = date('Y-m', strtotime($first . ' -1 month'));
$next     = date('Y-m', strtotime($first . ' +1 month'));
$today    = date('Y-m-d');

// ---------- บล็อกรถ (ซ่อม/ใช้เอง) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (($_POST['act'] ?? '') === 'block') {
            $carId = (int) $_POST['car_id'];
            $s = date('Y-m-d H:i:s', strtotime($_POST['start_at']));
            $e = date('Y-m-d H:i:s', strtotime($_POST['end_at']));
            if (strtotime($e) <= strtotime($s)) throw new RuntimeException('วันสิ้นสุดต้องหลังวันเริ่ม');
            db_insert('car_blocks', ['car_id' => $carId, 'start_at' => $s, 'end_at' => $e,
                                     'reason' => trim($_POST['reason'] ?? '') ?: 'ปิดการใช้งาน']);
            log_activity('car_blocked', 'cars', $carId);
            flash('ok', 'บันทึกช่วงปิดรถแล้ว');
        } elseif (($_POST['act'] ?? '') === 'unblock') {
            db_exec('DELETE FROM car_blocks WHERE id = :i', [':i' => (int) $_POST['block_id']]);
            flash('ok', 'ยกเลิกช่วงปิดรถแล้ว');
        }
    } catch (Throwable $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('admin/calendar.php?m=' . $month);
}

$cars = db_all("SELECT * FROM cars WHERE status <> 'inactive' ORDER BY sort_order");

// ดึงการจองและบล็อกทั้งเดือน
$bookings = db_all(
    "SELECT b.*, c.plate FROM bookings b JOIN cars c ON c.id = b.car_id
     WHERE b.status IN ('pending','confirmed','picked_up','returned')
       AND DATE(b.pickup_at) <= :e AND DATE(b.return_at) >= :s
     ORDER BY FIELD(b.status,'returned','pending','confirmed','picked_up')",
    [':s' => $first, ':e' => $last]
);
$blocks = db_all(
    "SELECT * FROM car_blocks WHERE DATE(start_at) <= :e AND DATE(end_at) >= :s",
    [':s' => $first, ':e' => $last]
);

// map: [car_id][day] = item
$grid = [];
foreach ($bookings as $bk) {
    $s = date('Y-m', strtotime($bk['pickup_at'])) === $month ? (int) date('j', strtotime($bk['pickup_at'])) : 1;
    $e = date('Y-m', strtotime($bk['return_at']))  === $month ? (int) date('j', strtotime($bk['return_at']))  : $daysIn;
    for ($d = $s; $d <= $e; $d++) {
        $grid[(int) $bk['car_id']][$d] = [
            'kind'  => $bk['status'],
            'label' => $d === $s ? $bk['booking_no'] . ' ' . mb_substr($bk['contact_name'], 0, 10) : '',
            'link'  => url('admin/booking-view.php?id=' . (int) $bk['id']),
            'title' => $bk['booking_no'] . ' · ' . $bk['contact_name'] . ' · ' . thai_date($bk['pickup_at']) . '–' . thai_date($bk['return_at']),
        ];
    }
}
foreach ($blocks as $bl) {
    $s = date('Y-m', strtotime($bl['start_at'])) === $month ? (int) date('j', strtotime($bl['start_at'])) : 1;
    $e = date('Y-m', strtotime($bl['end_at']))   === $month ? (int) date('j', strtotime($bl['end_at']))   : $daysIn;
    for ($d = $s; $d <= $e; $d++) {
        if (isset($grid[(int) $bl['car_id']][$d])) continue;
        $grid[(int) $bl['car_id']][$d] = [
            'kind' => 'block', 'label' => $d === $s ? ($bl['reason'] ?: 'ปิดรถ') : '',
            'link' => '#', 'title' => 'ปิดรถ: ' . $bl['reason'],
        ];
    }
}

// อัตราการใช้รถในเดือนนี้
$usedCells = 0;
foreach ($grid as $days) { $usedCells += count($days); }
$util = count($cars) > 0 ? round($usedCells / (count($cars) * $daysIn) * 100) : 0;

admin_head('ปฏิทินรถ', 'calendar.php');
?>
<div class="toolbar no-print">
  <a class="btn btn-ghost btn-sm" href="?m=<?= $prev ?>">← <?= e(TH_MONTHS[(int) date('n', strtotime($prev . '-01'))]) ?></a>
  <h2 style="margin:0 8px"><?= e(TH_MONTHS_FULL[(int) date('n', strtotime($first))]) ?> <?= (int) date('Y', strtotime($first)) + 543 ?></h2>
  <a class="btn btn-ghost btn-sm" href="?m=<?= $next ?>"><?= e(TH_MONTHS[(int) date('n', strtotime($next . '-01'))]) ?> →</a>
  <a class="btn btn-ghost btn-sm" href="?m=<?= date('Y-m') ?>">เดือนนี้</a>
  <div class="spacer"></div>
  <span class="badge badge-primary">อัตราการใช้รถเดือนนี้ <?= $util ?>%</span>
  <a class="btn btn-sm" href="<?= url('admin/booking-edit.php') ?>">+ จองใหม่</a>
</div>

<div class="panel">
  <div class="cal">
    <table>
      <thead>
        <tr>
          <th class="carname">รถ</th>
          <?php for ($d = 1; $d <= $daysIn; $d++):
              $date = sprintf('%s-%02d', $month, $d);
              $dow  = (int) date('w', strtotime($date));
              $we   = ($dow === 0 || $dow === 6); ?>
            <th class="<?= $we ? 'we' : '' ?>"><?= $d ?><br><span style="font-weight:400;font-size:.68rem">
              <?= ['อา','จ','อ','พ','พฤ','ศ','ส'][$dow] ?></span></th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cars as $c): ?>
          <tr>
            <th class="carname">
              <?= e($c['brand'] . ' ' . $c['model']) ?>
              <div class="tiny muted"><?= e($c['plate']) ?> · ฿<?= money0($c['price_day']) ?>/วัน</div>
            </th>
            <?php for ($d = 1; $d <= $daysIn; $d++):
                $date = sprintf('%s-%02d', $month, $d);
                $dow  = (int) date('w', strtotime($date));
                $cell = $grid[(int) $c['id']][$d] ?? null;
                $cls  = ($dow === 0 || $dow === 6 ? 'we ' : '') . ($date === $today ? 'today' : ''); ?>
              <td class="day <?= $cls ?>">
                <?php if ($cell): ?>
                  <a class="bk <?= e($cell['kind']) ?>" href="<?= e($cell['link']) ?>" title="<?= e($cell['title']) ?>"><?= e($cell['label']) ?></a>
                <?php endif; ?>
              </td>
            <?php endfor; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="cal-legend">
    <span><i style="background:#c98a17"></i>รอยืนยัน</span>
    <span><i style="background:var(--brand)"></i>ยืนยันแล้ว</span>
    <span><i style="background:var(--ok)"></i>รถออกงาน</span>
    <span><i style="background:#aab4c2"></i>คืนรถแล้ว</span>
    <span><i style="background:#6b7789"></i>ปิดรถ/ซ่อม</span>
    <span><i style="border:2px solid var(--accent);background:transparent"></i>วันนี้</span>
  </div>
</div>

<div class="g2 mt-2 no-print">
  <div class="panel">
    <h3>ปิดรถชั่วคราว (ซ่อม / เจ้าของใช้เอง)</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="block">
      <div class="field"><label>รถ</label>
        <select name="car_id" required>
          <?php foreach ($cars as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= e($c['brand'] . ' ' . $c['model'] . ' — ' . $c['plate']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="g2">
        <div class="field"><label>ตั้งแต่</label><input type="datetime-local" name="start_at" required></div>
        <div class="field"><label>ถึง</label><input type="datetime-local" name="end_at" required></div>
      </div>
      <div class="field"><label>เหตุผล</label><input type="text" name="reason" placeholder="เช่น เข้าศูนย์เช็กระยะ, เจ้าของใช้เอง"></div>
      <button class="btn btn-block">บันทึกช่วงปิดรถ</button>
    </form>
  </div>

  <div class="panel">
    <h3>ช่วงปิดรถที่ตั้งไว้</h3>
    <?php
    $allBlocks = db_all('SELECT k.*, c.plate, c.brand, c.model FROM car_blocks k JOIN cars c ON c.id = k.car_id
                         WHERE k.end_at >= NOW() ORDER BY k.start_at LIMIT 20');
    if (!$allBlocks): ?>
      <p class="small muted mb-0">ยังไม่มีช่วงปิดรถในอนาคต</p>
    <?php endif; ?>
    <div class="table-wrap">
      <table class="table">
        <tbody>
          <?php foreach ($allBlocks as $bl): ?>
            <tr>
              <td class="small"><b><?= e($bl['plate']) ?></b><br><span class="tiny muted"><?= e($bl['reason']) ?></span></td>
              <td class="small"><?= e(thai_date($bl['start_at'], true)) ?><br><span class="muted">→ <?= e(thai_date($bl['end_at'], true)) ?></span></td>
              <td class="right">
                <form method="post" onsubmit="return confirm('ยกเลิกช่วงปิดรถนี้?')">
                  <?= csrf_field() ?><input type="hidden" name="act" value="unblock">
                  <input type="hidden" name="block_id" value="<?= (int) $bl['id'] ?>">
                  <button class="btn btn-ghost btn-sm">ลบ</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php admin_foot(); ?>
