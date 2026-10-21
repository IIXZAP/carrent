<?php
/**
 * ฐานลูกค้า — ค้นหา ประวัติการเช่า blacklist
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

$q    = trim($_GET['q'] ?? '');
$only = $_GET['only'] ?? '';

$w = ['1=1'];
$p = [];
if ($q !== '') {
    $w[] = '(c.full_name LIKE :q OR c.phone LIKE :q2 OR c.code LIKE :q3 OR c.email LIKE :q4 OR c.tax_id LIKE :q5)';
    $p[':q'] = $p[':q2'] = $p[':q3'] = $p[':q4'] = $p[':q5'] = '%' . $q . '%';
}
if ($only === 'blacklist') { $w[] = 'c.is_blacklist = 1'; }
$where = implode(' AND ', $w);

$rows = db_all(
    "SELECT c.*,
            (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id AND b.status <> 'cancelled') AS jobs,
            (SELECT COALESCE(SUM(b.grand_total + b.extra_charge),0) FROM bookings b WHERE b.customer_id = c.id AND b.status <> 'cancelled') AS spend,
            (SELECT MAX(b.pickup_at) FROM bookings b WHERE b.customer_id = c.id) AS last_rent,
            (SELECT COUNT(*) FROM customer_documents d WHERE d.customer_id = c.id) AS docs
     FROM customers c WHERE $where
     ORDER BY c.is_blacklist DESC, last_rent DESC, c.id DESC LIMIT 200",
    $p
);

$totalCustomers = (int) db_val('SELECT COUNT(*) FROM customers');
$blacklist      = (int) db_val('SELECT COUNT(*) FROM customers WHERE is_blacklist = 1');
$repeat         = (int) db_val("SELECT COUNT(*) FROM (SELECT customer_id FROM bookings
                                WHERE status <> 'cancelled' AND customer_id IS NOT NULL
                                GROUP BY customer_id HAVING COUNT(*) > 1) t");

admin_head('ลูกค้า', 'customers.php');
?>
<div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
  <div class="stat brand"><div class="lb">ลูกค้าทั้งหมด</div><div class="vl"><?= $totalCustomers ?></div></div>
  <div class="stat ok"><div class="lb">ลูกค้าที่กลับมาเช่าซ้ำ</div><div class="vl"><?= $repeat ?></div>
    <div class="sub"><?= $totalCustomers ? round($repeat / $totalCustomers * 100) : 0 ?>% ของฐานลูกค้า</div></div>
  <div class="stat <?= $blacklist ? 'danger' : '' ?>"><div class="lb">Blacklist</div><div class="vl"><?= $blacklist ?></div></div>
</div>

<form class="toolbar" method="get">
  <div class="field"><label>ค้นหา</label>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="ชื่อ / เบอร์ / รหัส / เลขผู้เสียภาษี" style="min-width:260px"></div>
  <button class="btn btn-sm">ค้นหา</button>
  <a class="btn btn-ghost btn-sm" href="?only=blacklist">ดูเฉพาะ Blacklist</a>
  <a class="btn btn-ghost btn-sm" href="<?= url('admin/customers.php') ?>">ล้าง</a>
  <div class="spacer"></div>
  <a class="btn btn-sm" href="<?= url('admin/customer-edit.php') ?>">+ เพิ่มลูกค้า</a>
</form>

<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>รหัส</th><th>ชื่อ</th><th>ติดต่อ</th><th>เอกสาร</th>
            <th class="num">เช่า</th><th class="num">ยอดสะสม</th><th>ล่าสุด</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="center muted" style="padding:30px">ไม่พบลูกค้า</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="small"><?= e($r['code']) ?></td>
            <td>
              <b><?= e($r['full_name']) ?></b>
              <?= (int) $r['is_blacklist'] ? ' <span class="badge badge-danger">Blacklist</span>' : '' ?>
              <div class="tiny muted"><?= $r['type'] === 'company' ? 'นิติบุคคล' . ($r['tax_id'] ? ' · ' . e($r['tax_id']) : '') : 'บุคคลธรรมดา' ?></div>
            </td>
            <td class="small"><?= e($r['phone']) ?>
              <div class="tiny muted"><?= e($r['email'] ?: ($r['line_id'] ?: '-')) ?></div></td>
            <td class="small">
              <?php // เอกสารเปิดดูได้จากหน้าลูกค้าเท่านั้น (ผ่านหน้าที่ตรวจสิทธิ์และบันทึกประวัติ) ไม่วางลิงก์ไฟล์ตรงในตาราง
                    $nd = (int) $r['docs'] + ($r['id_card_file'] ? 1 : 0) + ($r['license_file'] ? 1 : 0); ?>
              <?php if ($nd > 0): ?><a href="<?= url('admin/customer-edit.php?id=' . (int) $r['id']) ?>"><?= $nd ?> ไฟล์</a>
              <?php else: ?><span class="muted tiny">ยังไม่มี</span><?php endif; ?>
            </td>
            <td class="num"><?= (int) $r['jobs'] ?></td>
            <td class="num">฿<?= money0($r['spend']) ?></td>
            <td class="small"><?= e(thai_date($r['last_rent'])) ?></td>
            <td class="right"><a class="btn btn-ghost btn-sm" href="<?= url('admin/customer-edit.php?id=' . (int) $r['id']) ?>">เปิด</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php admin_foot(); ?>
