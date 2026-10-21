<?php
/**
 * รายการรถ + ผลการค้นหารถว่างตามช่วงวัน
 */
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/layout_public.php';

// รับได้ทั้งแบบแยกวัน/เวลา (pd,pt,rd,rt) และแบบเดิม (pickup,ret)
$pd = trim($_GET['pd'] ?? '');
$pt = trim($_GET['pt'] ?? '10:00');
$rd = trim($_GET['rd'] ?? '');
$rt = trim($_GET['rt'] ?? '10:00');

$pickup = trim($_GET['pickup'] ?? '');
$ret    = trim($_GET['ret'] ?? '');
if ($pd !== '' && $rd !== '') {
    $pickup = $pd . 'T' . $pt;
    $ret    = $rd . 'T' . $rt;
}

$type = trim($_GET['type'] ?? '');
$type = in_array($type, ['eco', 'sedan', 'suv', 'pickup', 'van'], true) ? $type : '';

$searching = $pickup !== '' && $ret !== '';
$error = null;
$days  = 0;
$cars  = [];

if ($searching) {
    $pickupDb = date('Y-m-d H:i:s', strtotime($pickup));
    $retDb    = date('Y-m-d H:i:s', strtotime($ret));
    $error    = validate_period($pickupDb, $retDb);
    if (!$error) {
        $cars = available_cars($pickupDb, $retDb, $type ?: null);
        $days = rental_days($pickupDb, $retDb);
    }
} else {
    $sql = "SELECT * FROM cars WHERE status <> 'inactive'";
    $p   = [];
    if ($type) { $sql .= ' AND car_type = :t'; $p[':t'] = $type; }
    $sql .= ' ORDER BY sort_order, price_day';
    $cars = db_all($sql, $p);
}

// ค่าเริ่มต้นของแถบค้นหา
$sbPick = $pickup ?: date('Y-m-d\TH:i', strtotime('tomorrow 10:00'));
$sbRet  = $ret    ?: date('Y-m-d\TH:i', strtotime('tomorrow +1 day 10:00'));
$sbType = $type;

$title = $searching ? 'รถว่างช่วง ' . thai_date($pickup) . ' – ' . thai_date($ret) : 'รถเช่าของเรา';
page_head($title, 'เลือกวันรับ–คืนรถ เพื่อดูรถที่ว่างจริงพร้อมราคาสุทธิ ไม่มีค่าใช้จ่ายแอบแฝง',
          ['active' => 'cars.php']);
?>

<section style="background:linear-gradient(170deg,#eaf2fc,var(--bg));padding:26px 0 0">
  <div class="wrap">
    <div class="eyebrow">Our Cars</div>
    <h1 style="margin-bottom:.25em">รถเช่าของเรา</h1>
    <p class="muted" style="margin-bottom:0">เลือกวันรับ–คืนรถ ระบบจะแสดงเฉพาะคันที่ว่างจริง พร้อมราคาสุทธิ</p>
  </div>
  <div class="wrap">
    <?php include __DIR__ . '/includes/_searchbar.php'; ?>
  </div>
</section>

<section class="section" style="padding-top:22px">
  <div class="wrap">
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= icon('close') ?><span><?= e($error) ?></span></div>
    <?php elseif ($searching): ?>
      <div class="alert alert-info">
        <?= icon('checkbig') ?>
        <span>พบรถว่าง <b><?= count($cars) ?></b> คัน · เช่า <b><?= $days ?></b> วัน
          (<?= e(thai_date($pickup, true)) ?> → <?= e(thai_date($ret, true)) ?>)</span>
      </div>
    <?php endif; ?>

    <?php if (empty($cars)): ?>
      <div class="panel center" style="padding:36px 20px">
        <div class="ic-box ic-round" style="background:var(--bg);color:var(--muted);margin:0 auto 14px"><?= icon('car') ?></div>
        <h3>ไม่พบรถว่างในช่วงเวลานี้</h3>
        <p class="muted small">ลองเปลี่ยนวันหรือประเภทรถ หรือโทรสอบถามเจ้าหน้าที่</p>
        <a class="btn" href="tel:<?= e(preg_replace('/\D/', '', setting('phone'))) ?>">
          <?= icon('phone') ?> <?= e(setting('phone')) ?></a>
      </div>
    <?php else: ?>
      <div class="car-grid">
        <?php
        $showTotal = $searching;
        foreach ($cars as $c) {
            $cardLink = url('car.php?id=' . (int) $c['id']
                . ($searching ? '&pickup=' . urlencode($pickup) . '&ret=' . urlencode($ret) : ''));
            include __DIR__ . '/includes/_car_card.php';
        }
        ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php page_foot(); ?>
