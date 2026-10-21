<?php
/**
 * จัดการรถ (8 คัน) — สถานะ ราคา เอกสาร
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'status') {
    csrf_check();
    $carId  = (int) $_POST['car_id'];
    $status = in_array($_POST['status'] ?? '', ['available','maintenance','inactive'], true) ? $_POST['status'] : 'available';
    // รถที่ยังมีงานค้าง ห้ามปิดใช้งาน — ไม่งั้นการจองเหล่านั้นจะหายจากรายงาน และแก้ไข/เช่าต่อไม่ได้
    $open = $status === 'inactive' ? (int) db_val(
        "SELECT COUNT(*) FROM bookings WHERE car_id = :c AND status IN ('pending','confirmed','picked_up')", [':c' => $carId]) : 0;
    if ($open > 0) {
        flash('danger', 'รถคันนี้ยังมีการจองที่ยังไม่จบ ' . $open . ' รายการ — ปิดใช้งานไม่ได้ ให้ตั้งเป็น "ซ่อมบำรุง" หรือจัดการการจองให้เสร็จก่อน');
        redirect('admin/cars.php');
    }
    db_update('cars', ['status' => $status], 'id', $carId);
    log_activity('car_status', 'cars', $carId, $status);
    flash('ok', 'อัปเดตสถานะรถแล้ว');
    redirect('admin/cars.php');
}

$cars  = db_all('SELECT * FROM cars ORDER BY sort_order, id');
$today = date('Y-m-d');
$warnDays = (int) setting('reminder_days_before', '30');

admin_head('จัดการรถ', 'cars.php');
?>
<div class="toolbar">
  <div class="spacer"></div>
  <a class="btn btn-sm" href="<?= url('admin/car-edit.php') ?>">+ เพิ่มรถ</a>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>รถ</th><th class="num">รายวัน</th><th class="num">สัปดาห์</th><th class="num">เดือน</th>
          <th class="num">เลขไมล์</th><th>เอกสาร</th><th>สถานะ</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cars as $c):
            $activeBk = db_one("SELECT booking_no, return_at FROM bookings
                                WHERE car_id = :c AND status = 'picked_up' ORDER BY return_at LIMIT 1", [':c' => (int) $c['id']]);
        ?>
          <tr>
            <td>
              <b><?= e($c['brand'] . ' ' . $c['model']) ?></b> <?= e($c['year'] ?: '') ?>
              <div class="tiny muted"><?= e($c['plate']) ?> · <?= e(car_type_label($c['car_type'])) ?> ·
                <?= (int) $c['seats'] ?> ที่นั่ง · <?= $c['gear'] === 'auto' ? 'ออโต้' : 'ธรรมดา' ?></div>
              <?php if ($activeBk): ?>
                <div class="tiny" style="color:var(--ok)">กำลังเช่า <?= e($activeBk['booking_no']) ?> คืน <?= e(thai_date($activeBk['return_at'])) ?></div>
              <?php endif; ?>
            </td>
            <td class="num">฿<?= money0($c['price_day']) ?></td>
            <td class="num"><?= (float) $c['price_week'] > 0 ? '฿' . money0($c['price_week']) : '<span class="muted tiny">auto</span>' ?></td>
            <td class="num"><?= (float) $c['price_month'] > 0 ? '฿' . money0($c['price_month']) : '<span class="muted tiny">auto</span>' ?></td>
            <td class="num"><?= number_format((int) $c['mileage']) ?></td>
            <td class="small">
              <?php
              $docs = ['ประกัน' => $c['insurance_expire'], 'พ.ร.บ.' => $c['act_expire'], 'ภาษี' => $c['tax_expire']];
              foreach ($docs as $lb => $dt):
                  if (!$dt) continue;
                  $left = (int) floor((strtotime($dt) - strtotime($today)) / 86400);
                  $cls  = $left < 0 ? 'danger' : ($left <= $warnDays ? 'warn' : 'muted'); ?>
                <span class="badge badge-<?= $cls ?>" title="ครบ <?= e(thai_date($dt)) ?>"><?= e($lb) ?>
                  <?= $left < 0 ? 'หมดแล้ว' : $left . 'ว.' ?></span>
              <?php endforeach; ?>
            </td>
            <td>
              <form method="post" onchange="this.submit()">
                <?= csrf_field() ?><input type="hidden" name="act" value="status">
                <input type="hidden" name="car_id" value="<?= (int) $c['id'] ?>">
                <select name="status" style="padding:5px 8px;font-size:.84rem">
                  <option value="available"   <?= $c['status'] === 'available' ? 'selected' : '' ?>>พร้อมให้เช่า</option>
                  <option value="maintenance" <?= $c['status'] === 'maintenance' ? 'selected' : '' ?>>เข้าศูนย์/ซ่อม</option>
                  <option value="inactive"    <?= $c['status'] === 'inactive' ? 'selected' : '' ?>>ปิดใช้งาน</option>
                </select>
              </form>
            </td>
            <td class="right" style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="<?= url('admin/car-edit.php?id=' . (int) $c['id']) ?>">แก้ไข</a>
              <a class="btn btn-ghost btn-sm" href="<?= url('admin/bookings.php?car_id=' . (int) $c['id']) ?>">ประวัติ</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php admin_foot(); ?>
