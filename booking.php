<?php
/**
 * รับข้อมูลการจองจากหน้าเว็บลูกค้า → บันทึก → แจ้งเตือน → ส่งไปหน้ายืนยัน
 */
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/notify.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('cars.php');
}
csrf_check();
require_once __DIR__ . '/includes/abtrack.php';
ab_track_once('submit');

$carId  = (int) ($_POST['car_id'] ?? 0);
$car    = db_one("SELECT * FROM cars WHERE id = :id AND status <> 'inactive'", [':id' => $carId]);
$back   = 'car.php?id=' . $carId;

if (!$car) {
    flash('danger', 'ไม่พบรถที่เลือก');
    redirect('cars.php');
}

// ---------- ตรวจข้อมูล ----------
// ข้อความทุกช่องถูกตัดให้ไม่เกินความยาวของคอลัมน์ — ไม่งั้นข้อความยาวเกินจะกลายเป็น error ของฐานข้อมูลโผล่ให้ลูกค้าเห็น
$str    = static fn(string $k, int $max): string => mb_substr(trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : ''), 0, $max);
$pickup = $str('pickup_at', 30);
$ret    = $str('return_at', 30);
$name   = $str('contact_name', 150);
$phone  = $str('contact_phone', 30);
$email  = $str('contact_email', 150);
$line   = $str('contact_line', 80);
$note   = $str('note', 1000);
$place  = [$str('pickup_place', 230), $str('return_place', 230)];
// จำนวนบริการเสริม: 0–5 ต่อรายการ (คาร์ซีทเลือกได้หลายตัว ที่เหลือเป็นเลือก/ไม่เลือก)
$addons = array_map(static fn($v) => max(0, min(5, (int) (is_scalar($v) ? $v : 0))), (array) ($_POST['addons'] ?? []));
$km       = max(0, min(2000, (float) (is_scalar($_POST['delivery_km'] ?? 0) ? ($_POST['delivery_km'] ?? 0) : 0)));
$pickType = ($_POST['pickup_place_type'] ?? 'shop') === 'airport' ? 'airport' : 'shop';
$retType  = ($_POST['return_place_type'] ?? 'shop') === 'airport' ? 'airport' : 'shop';

// ---------- กดยืนยันซ้ำ (ดับเบิลคลิก / กดย้อนแล้วส่งใหม่) ----------
// การจองแรกสำเร็จไปแล้ว คำขอที่สองจะเจอว่า "รถไม่ว่าง" (เพราะตัวเองเพิ่งจอง) แล้วลูกค้าไม่เห็นเลขที่จอง
// ถ้าเซสชันนี้เพิ่งจองรถคันเดิม ช่วงเวลาเดิม ภายใน 3 นาที → พาไปหน้าจองสำเร็จของรายการนั้นเลย
start_session();
if (!empty($_SESSION['last_booking']) && $pickup !== '' && $ret !== '') {
    $dup = db_one('SELECT booking_no FROM bookings WHERE booking_no = :n AND car_id = :c AND pickup_at = :p AND return_at = :r
                   AND created_at >= :t', [':n' => $_SESSION['last_booking'], ':c' => $carId,
                   ':p' => date('Y-m-d H:i:s', (int) strtotime($pickup)), ':r' => date('Y-m-d H:i:s', (int) strtotime($ret)),
                   ':t' => date('Y-m-d H:i:s', time() - 180)]);
    if ($dup) {
        redirect('booking-success.php?no=' . urlencode($dup['booking_no']));
    }
}

$errors = [];
if ($name === '' || mb_strlen($name) < 2)                 $errors[] = 'กรุณากรอกชื่อ–นามสกุล';
if (!preg_match('/^[0-9\-\+\s]{9,20}$/', $phone) || strlen(phone_digits($phone)) < 9) $errors[] = 'เบอร์โทรศัพท์ไม่ถูกต้อง';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'อีเมลไม่ถูกต้อง';
if (empty($_POST['accept']))                              $errors[] = 'กรุณายอมรับเงื่อนไขการเช่า';

$pickDb = ($pickup && strtotime($pickup) !== false) ? date('Y-m-d H:i:s', strtotime($pickup)) : '';
$retDb  = ($ret && strtotime($ret) !== false)       ? date('Y-m-d H:i:s', strtotime($ret))    : '';
if (!$pickDb || !$retDb) {
    $errors[] = 'กรุณาเลือกวัน–เวลารับและคืนรถ';
} elseif ($msg = validate_period($pickDb, $retDb, $pickType, $retType)) {
    $errors[] = $msg;
} elseif (!is_car_available($carId, $pickDb, $retDb)) {
    $errors[] = 'ขออภัย รถคันนี้เพิ่งถูกจองในช่วงเวลาดังกล่าว กรุณาเลือกวันใหม่';
}

if (delivery_out_of_range($addons, $km)) {
    $errors[] = 'ระยะทาง ' . number_format($km) . ' กม. อยู่นอกพื้นที่ที่คิดค่าส่งรถอัตโนมัติ กรุณาติดต่อเจ้าหน้าที่ ' . setting('phone') . ' เพื่อขอราคา';
}

// ---------- กันการจองถี่ผิดปกติ ----------
// การจองที่ยังไม่จ่ายมัดจำก็กันรถไว้ไม่ให้คนอื่นจอง — ถ้าไม่จำกัด คนเดียวจองรัวๆ ก็ทำให้รถทั้งร้าน "ไม่ว่าง" ได้
if (!$errors) {
    $openByPhone = 0;
    foreach (db_all("SELECT contact_phone FROM bookings WHERE status = 'pending' AND paid_amount <= 0 AND " . phone_sql('contact_phone') . " LIKE :t",
                    [':t' => '%' . substr(phone_digits($phone), -7) . '%']) as $r) {
        $openByPhone += phone_match($r['contact_phone'], $phone) ? 1 : 0;
    }
    $byIp = (int) db_val("SELECT COUNT(*) FROM activity_logs WHERE action = 'booking_created' AND ip = :ip AND ip <> '' AND created_at >= :t",
                         [':ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''), ':t' => date('Y-m-d H:i:s', time() - 3600)]);
    if ($openByPhone >= 3) {
        $errors[] = 'เบอร์นี้มีการจองที่ยังไม่ได้ชำระมัดจำอยู่ ' . $openByPhone . ' รายการ กรุณาชำระมัดจำรายการเดิมก่อน หรือติดต่อเจ้าหน้าที่ ' . setting('phone');
    } elseif ($byIp >= 8) {
        $errors[] = 'มีการจองจากอุปกรณ์นี้หลายรายการในเวลาใกล้กัน กรุณาลองใหม่ภายหลัง หรือติดต่อเจ้าหน้าที่ ' . setting('phone');
    }
}

if ($errors) {
    foreach ($errors as $err) {
        flash('danger', $err);
    }
    redirect($back . '&pickup=' . urlencode($pickup) . '&ret=' . urlencode($ret) . '#book');
}

// ---------- คำนวณราคา ----------
$q = quote_booking($car, $pickDb, $retDb, $addons, 0.0, null, $km);

// ---------- ลูกค้า: หาเดิมจากเบอร์ ไม่มีก็สร้างใหม่ ----------
$customer   = customer_by_phone($phone);
$customerId = $customer['id'] ?? null;

if ($customer && (int) $customer['is_blacklist'] === 1) {
    flash('danger', 'ไม่สามารถจองออนไลน์ได้ กรุณาติดต่อเจ้าหน้าที่ ' . setting('phone'));
    redirect($back);
}

// ---------- บันทึกการจอง (ใช้ transaction กันจองชนกัน) ----------
$pdo = db();
$bookingId = 0;
$bookingNo = '';
// เลขที่จองออกจาก "เลขล่าสุด + 1" — ถ้ามีสองคนจองพร้อมกันพอดีจะได้เลขซ้ำ ระบบจึงลองใหม่ให้เองสูงสุด 3 รอบ
for ($try = 1; $try <= 3 && !$bookingId; $try++) {
  $pdo->beginTransaction();
  try {
    // ล็อกแถวของรถคันนี้ไว้จนบันทึกเสร็จ — คนที่จองรถคันเดียวกันพร้อมกันต้องรอคิว แล้วจะเห็นว่ารถถูกจองไปแล้ว
    db_one('SELECT id FROM cars WHERE id = :c FOR UPDATE', [':c' => $carId]);
    if (!is_car_available($carId, $pickDb, $retDb)) {
        throw new RuntimeException('รถคันนี้เพิ่งถูกจองไปแล้ว กรุณาเลือกวันใหม่');
    }
    if (!$customerId) {
        $customerId = db_insert('customers', [
            'code'      => next_customer_code(),
            'type'      => 'individual',
            'full_name' => $name,
            'phone'     => $phone,
            'email'     => $email ?: null,
            'line_id'   => $line ?: null,
        ]);
    }

    $bookingNo = next_booking_no();
    $bookingId = db_insert('bookings', [
        'booking_no'       => $bookingNo,
        'customer_id'      => $customerId,
        'car_id'           => $carId,
        'contact_name'     => $name,
        'contact_phone'    => $phone,
        'contact_email'    => $email ?: null,
        'contact_line'     => $line ?: null,
        'pickup_at'        => $pickDb,
        'return_at'        => $retDb,
        // เก็บประเภทจุดไว้หน้าข้อความ เพื่อให้แอดมินเห็นชัดว่าเป็นสนามบินหรือไม่
        // (ใช้กับกติกาเวลาทำการ และเป็นข้อมูลให้คนไปรับ–ส่งรถ)
        'pickup_place'     => trim(($pickType === 'airport' ? '[สนามบิน] ' : '') . $place[0]) ?: null,
        'return_place'     => trim(($retType === 'airport' ? '[สนามบิน] ' : '') . $place[1]) ?: null,
        'delivery_km'      => $km > 0 ? $km : null,
        // จองจากในแอป LINE (เซสชันนี้ผ่านการยืนยันตัวตนกับ LINE มาแล้วในหน้า "การจองของฉัน")
        // → ผูกการจองกับ LINE นั้นทันที ลูกค้าได้ข้อความยืนยันและเห็นการจองโดยไม่ต้องกรอกอะไรอีก
        'line_user_id'     => preg_match('/^U[0-9a-f]{32}$/', (string) ($_SESSION['liff']['uid'] ?? '')) ? $_SESSION['liff']['uid'] : null,
        'rental_days'      => $q['days'],
        'rate_breakdown'   => json_encode(['rental' => $q['rental_lines'], 'addons' => $q['addon_lines']], JSON_UNESCAPED_UNICODE),
        'rental_amount'    => $q['rental_amount'],
        'addon_amount'     => $q['addon_amount'],
        'discount'         => 0,
        'subtotal'         => $q['subtotal'],
        'vat_rate'         => $q['vat_rate'],
        'vat_amount'       => $q['vat_amount'],
        'grand_total'      => $q['grand_total'],
        'deposit_amount'   => $q['deposit_amount'],
        'security_deposit' => $q['security_deposit'],
        'addons'           => json_encode($addons, JSON_UNESCAPED_UNICODE),
        'status'           => 'pending',
        'payment_status'   => 'unpaid',
        'source'           => 'web',
        'note'             => $note ?: null,
    ]);
    $pdo->commit();
  } catch (Throwable $ex) {
    db_rollback();
    $bookingId = 0;
    $customerId = $customer['id'] ?? null;      // ลูกค้าใหม่ที่เพิ่งสร้างถูกย้อนกลับไปด้วย
    $dupNo = $ex instanceof PDOException && (string) $ex->getCode() === '23000';
    if ($dupNo && $try < 3) {
        usleep(random_int(20000, 120000));
        continue;
    }
    // ข้อความจากระบบเองแสดงได้ — ข้อผิดพลาดจากฐานข้อมูลบันทึกลง log ไม่แสดงให้ลูกค้าเห็น
    flash('danger', public_error($ex, 'บันทึกการจองไม่สำเร็จ กรุณาลองใหม่อีกครั้ง หรือติดต่อเจ้าหน้าที่ ' . setting('phone')));
    redirect($back . '&pickup=' . urlencode($pickup) . '&ret=' . urlencode($ret) . '#book');
  }
}

$booking = db_one('SELECT * FROM bookings WHERE id = :id', [':id' => $bookingId]);
log_activity('booking_created', 'bookings', $bookingId, $bookingNo);

try {
    notify_new_booking($booking, $car);
} catch (Throwable $ex) {
    // แจ้งเตือนล้มเหลวไม่ควรทำให้การจองพัง
}

start_session();
$_SESSION['last_booking'] = $bookingNo;
ab_track('booked', $carId);
redirect('booking-success.php?no=' . urlencode($bookingNo));
