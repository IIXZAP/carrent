<?php
/**
 * รายการการจอง — ค้นหา/กรอง/เปลี่ยนสถานะเร็ว
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

$q      = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$carId  = (int) ($_GET['car_id'] ?? 0);
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$page   = max(1, (int) ($_GET['page'] ?? 1));
$per    = 25;

$w = ['1=1'];
$p = [];
if ($q !== '') {
    $w[] = '(b.booking_no LIKE :q OR b.contact_name LIKE :q2 OR b.contact_phone LIKE :q3 OR c.plate LIKE :q4)';
    $p[':q'] = $p[':q2'] = $p[':q3'] = $p[':q4'] = '%' . $q . '%';
}
if ($status !== '' && in_array($status, ['pending','confirmed','picked_up','returned','completed','cancelled'], true)) {
    $w[] = 'b.status = :st';
    $p[':st'] = $status;
}
if ($carId) { $w[] = 'b.car_id = :car'; $p[':car'] = $carId; }
if ($from)  { $w[] = 'DATE(b.pickup_at) >= :f'; $p[':f'] = $from; }
if ($to)    { $w[] = 'DATE(b.pickup_at) <= :t'; $p[':t'] = $to; }
$where = implode(' AND ', $w);

$total = (int) db_val("SELECT COUNT(*) FROM bookings b JOIN cars c ON c.id = b.car_id WHERE $where", $p);
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$off   = ($page - 1) * $per;

$rows = db_all(
    "SELECT b.*, c.brand, c.model, c.plate
     FROM bookings b JOIN cars c ON c.id = b.car_id
     WHERE $where ORDER BY b.pickup_at DESC, b.id DESC LIMIT $per OFFSET $off",
    $p
);
$cars = db_all("SELECT id, brand, model, plate FROM cars ORDER BY sort_order");

// ยอดเงินรวมเป็นข้อมูลของเจ้าของร้าน — พนักงานเห็นเฉพาะจำนวนรายการ
// การจองที่ยกเลิกไม่นับเป็นมูลค่าและยอดค้างรับ (ไม่งั้น "ค้างรับ" จะรวมเงินที่ไม่มีวันได้)
$isOwner  = is_admin();
$sumTotal = $isOwner ? (float) db_val("SELECT COALESCE(SUM(b.grand_total + b.extra_charge),0) FROM bookings b JOIN cars c ON c.id = b.car_id WHERE ($where) AND b.status <> 'cancelled'", $p) : 0.0;
$sumPaid  = $isOwner ? (float) db_val("SELECT COALESCE(SUM(b.paid_amount),0) FROM bookings b JOIN cars c ON c.id = b.car_id WHERE ($where) AND b.status <> 'cancelled'", $p) : 0.0;

admin_head('การจอง', 'bookings.php');
?>
<form class="toolbar" method="get">
  <div class="field">
    <label>ค้นหา</label>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="เลขที่จอง / ชื่อ / เบอร์ / ทะเบียน">
  </div>
  <div class="field">
    <label>สถานะ</label>
    <select name="status">
      <option value="">ทั้งหมด</option>
      <?php foreach (['pending','confirmed','picked_up','returned','completed','cancelled'] as $s):
          [$l] = booking_status_label($s); ?>
        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label>รถ</label>
    <select name="car_id">
      <option value="">ทุกคัน</option>
      <?php foreach ($cars as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $carId === (int) $c['id'] ? 'selected' : '' ?>>
          <?= e($c['brand'] . ' ' . $c['model'] . ' (' . $c['plate'] . ')') ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>รับรถตั้งแต่</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="field"><label>ถึง</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-sm">กรอง</button>
  <a class="btn btn-ghost btn-sm" href="<?= url('admin/bookings.php') ?>">ล้าง</a>
  <div class="spacer"></div>
  <a class="btn btn-sm" href="<?= url('admin/booking-edit.php') ?>">+ จองใหม่</a>
</form>

<div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
  <div class="stat"><div class="lb">จำนวนรายการ</div><div class="vl"><?= $total ?></div></div>
  <?php if ($isOwner): ?>
  <div class="stat brand"><div class="lb">มูลค่ารวม</div><div class="vl">฿<?= money0($sumTotal) ?></div><div class="sub">ไม่รวมรายการที่ยกเลิก</div></div>
  <div class="stat ok"><div class="lb">เก็บเงินแล้ว</div><div class="vl">฿<?= money0($sumPaid) ?></div></div>
  <div class="stat accent"><div class="lb">ค้างรับ</div><div class="vl">฿<?= money0(max(0, $sumTotal - $sumPaid)) ?></div></div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>เลขที่</th><th>ลูกค้า</th><th>รถ</th><th>ช่วงเช่า</th>
          <th class="num">ยอดรวม</th><th class="num">ค้าง</th><th>สถานะ</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="center muted" style="padding:30px">ไม่พบรายการ</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            [$sl, $sc] = booking_status_label($r['status']);
            [$pl, $pc] = payment_status_label($r['payment_status']);
            $bal = (float) $r['grand_total'] + (float) $r['extra_charge'] - (float) $r['paid_amount'];
            $late = $r['status'] === 'picked_up' && strtotime($r['return_at']) < time();
        ?>
          <tr>
            <td>
              <a href="<?= url('admin/booking-view.php?id=' . (int) $r['id']) ?>"><b><?= e($r['booking_no']) ?></b></a>
              <div class="tiny muted"><?= e(thai_date($r['created_at'])) ?> · <?= e($r['source']) ?></div>
            </td>
            <td>
              <?= e($r['contact_name']) ?>
              <div class="tiny muted"><?= e($r['contact_phone']) ?></div>
            </td>
            <td class="small">
              <?= e($r['brand'] . ' ' . $r['model']) ?>
              <div class="tiny muted"><?= e($r['plate']) ?></div>
            </td>
            <td class="small">
              <?= e(thai_date($r['pickup_at'], true)) ?><br>
              <span class="muted">→ <?= e(thai_date($r['return_at'], true)) ?></span>
              <div class="tiny muted"><?= (int) $r['rental_days'] ?> วัน<?= $late ? ' · <b style="color:var(--danger)">เลยกำหนด</b>' : '' ?></div>
            </td>
            <td class="num"><?= money0($r['grand_total']) ?></td>
            <td class="num"><?= $bal > 0 ? '<b style="color:var(--warn)">' . money0($bal) . '</b>' : '<span class="muted">-</span>' ?></td>
            <td>
              <span class="badge badge-<?= $sc ?>"><?= e($sl) ?></span>
              <div class="tiny mt-1"><span class="badge badge-<?= $pc ?>"><?= e($pl) ?></span></div>
            </td>
            <td class="right">
              <a class="btn btn-ghost btn-sm" href="<?= url('admin/booking-view.php?id=' . (int) $r['id']) ?>">เปิด</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1):
      $qs = $_GET; ?>
    <div class="center mt-3">
      <?php for ($i = 1; $i <= $pages; $i++):
          $qs['page'] = $i; ?>
        <a class="btn btn-sm <?= $i === $page ? '' : 'btn-ghost' ?>" href="?<?= e(http_build_query($qs)) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<?php admin_foot(); ?>
