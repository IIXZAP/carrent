<?php
/**
 * API คิดราคาสด (ใช้ในหน้า car.php)
 * GET: car_id, pickup, ret, addons (JSON)
 */
require_once __DIR__ . '/../includes/pricing.php';

header('Content-Type: application/json; charset=utf-8');

$carId  = (int) ($_GET['car_id'] ?? 0);
$pickup = trim($_GET['pickup'] ?? '');
$ret    = trim($_GET['ret'] ?? '');
$addons = json_decode((string) ($_GET['addons'] ?? '{}'), true);
$addons = is_array($addons) ? array_map(static fn($v) => max(0, min(5, (int) (is_scalar($v) ? $v : 0))), $addons) : [];
$km       = max(0, min(2000, (float) ($_GET['km'] ?? 0)));
$pickType = ($_GET['pick_type'] ?? 'shop') === 'airport' ? 'airport' : 'shop';
$retType  = ($_GET['ret_type']  ?? 'shop') === 'airport' ? 'airport' : 'shop';

$car = db_one("SELECT * FROM cars WHERE id = :id AND status <> 'inactive'", [':id' => $carId]);
if (!$car) {
    echo json_encode(['available' => false, 'error' => 'ไม่พบรถคันนี้', 'html' => '<div class="alert alert-danger">ไม่พบรถคันนี้</div>'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pickDb = ($pickup && strtotime($pickup) !== false) ? date('Y-m-d H:i:s', strtotime($pickup)) : '';
$retDb  = ($ret && strtotime($ret) !== false)       ? date('Y-m-d H:i:s', strtotime($ret))    : '';

// โหมดหลังบ้าน: เจ้าหน้าที่จองย้อนหลัง/จองวันนี้ได้ ไม่ต้องติดเงื่อนไขจองล่วงหน้า
require_once __DIR__ . '/../includes/auth.php';
$adminMode = !empty($_GET['admin']) && auth_check();
// "ไม่นับการจองเลขนี้" ใช้ตอนแก้ไขการจองในหลังบ้านเท่านั้น — คนทั่วไปส่งมาจะถูกเมิน
// (ไม่งั้นใช้ไล่ดูได้ว่าการจองเลขไหนจองรถคันไหนช่วงไหน)
$ignoreId  = $adminMode ? ((int) ($_GET['ignore'] ?? 0) ?: null) : null;

if (!$pickDb || !$retDb) {
    $periodError = 'กรุณาเลือกวันรับและวันคืนรถ';
} elseif ($adminMode) {
    $periodError = strtotime($retDb) <= strtotime($pickDb) ? 'วันคืนรถต้องหลังวันรับรถ' : null;
} else {
    $periodError = validate_period($pickDb, $retDb, $pickType, $retType);
}
if (!$periodError && !$adminMode && delivery_out_of_range($addons, $km)) {
    $periodError = 'ระยะทาง ' . number_format($km) . ' กม. อยู่นอกพื้นที่ที่คิดค่าส่งรถอัตโนมัติ กรุณาติดต่อเจ้าหน้าที่ ' . setting('phone');
}
$available = $periodError ? false : is_car_available($carId, $pickDb, $retDb, $ignoreId);
$q           = $periodError
    ? ['days' => 0, 'rental_lines' => [], 'addon_lines' => [], 'discount' => 0, 'subtotal' => 0,
       'vat_rate' => 0, 'vat_amount' => 0, 'grand_total' => 0, 'deposit_amount' => 0,
       'security_deposit' => 0, 'avg_day' => 0]
    : quote_booking($car, $pickDb, $retDb, $addons, 0.0, null, $km);

ob_start();
include __DIR__ . '/../includes/_quote_box.php';
$html = ob_get_clean();

echo json_encode([
    'available' => $available,
    'error'     => $periodError ?: ($available ? null : 'รถไม่ว่างในช่วงนี้'),
    'days'      => $q['days'],
    'total'     => $q['grand_total'],
    'deposit'   => $q['deposit_amount'],
    'html'      => $html,
], JSON_UNESCAPED_UNICODE);
