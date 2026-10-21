<?php
/**
 * รับสัญญาณ "เริ่มกรอกฟอร์ม" จากหน้าเว็บ
 *
 * เป็นขั้นเดียวในกรวยที่ฝั่งเซิร์ฟเวอร์ไม่รู้เอง — ต้องให้เบราว์เซอร์บอก
 * รับเฉพาะชื่อขั้นที่อยู่ในรายการเท่านั้น ไม่รับค่าอื่นใดจากผู้ใช้
 */
require_once __DIR__ . '/../includes/abtrack.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$allowed = ['start_form'];
$step    = (string) ($_POST['step'] ?? '');
$carId   = (int) ($_POST['car_id'] ?? 0) ?: null;

if (!in_array($step, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

ab_track_once($step, $carId);
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
