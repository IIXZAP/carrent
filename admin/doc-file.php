<?php
/**
 * เปิดดูเอกสารลูกค้า (สำเนาบัตรประชาชน / ใบขับขี่ / หนังสือเดินทาง)
 *
 * ไฟล์อยู่ในที่เก็บแบบปิด เปิดผ่านลิงก์ตรงไม่ได้ ต้องผ่านหน้านี้ซึ่ง
 *   1) ตรวจว่าเข้าระบบหลังบ้านแล้ว
 *   2) บันทึกทุกครั้งว่าใครเปิดเอกสารของลูกค้าคนไหน เมื่อไหร่ (ตรวจย้อนหลังได้ตามหลัก PDPA)
 *
 *   doc-file.php?id=<รหัสเอกสาร>                         เอกสารในที่เก็บแบบปิด
 *   doc-file.php?customer=<รหัสลูกค้า>&legacy=id_card   เอกสารที่อัปโหลดก่อนเวอร์ชัน 5 (ยังอยู่ใน uploads)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/private_files.php';
require_login();

$fail = function (int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
};

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $d = db_one('SELECT * FROM customer_documents WHERE id = :i', [':i' => $id]);
    $full = $d ? private_path($d['file_path']) : null;
    if (!$full) {
        $fail(404, 'ไม่พบไฟล์เอกสาร');
    }
    log_activity('document_viewed', 'customer_documents', $id, ['customer_id' => (int) $d['customer_id'], 'kind' => $d['doc_kind']]);
    send_private_file($full, 'customer-' . (int) $d['customer_id'] . '-' . $d['doc_kind']);
}

$cid = (int) ($_GET['customer'] ?? 0);
$col = ['id_card' => 'id_card_file', 'license' => 'license_file'][$_GET['legacy'] ?? ''] ?? null;
if ($cid > 0 && $col) {
    $c    = db_one("SELECT id, $col AS f FROM customers WHERE id = :i", [':i' => $cid]);
    $full = $c ? legacy_upload_path($c['f']) : null;
    // เอกสารลูกค้าต้องอยู่ใต้ uploads/docs เท่านั้น — กันใช้หน้านี้เปิดไฟล์อื่นในโฟลเดอร์อัปโหลด
    if (!$full || !str_starts_with(str_replace('\\', '/', (string) $c['f']), 'docs/')) {
        $fail(404, 'ไม่พบไฟล์เอกสาร');
    }
    log_activity('document_viewed', 'customers', $cid, ['legacy' => $col]);
    send_private_file($full, 'customer-' . $cid . '-' . $_GET['legacy']);
}

$fail(400, 'คำขอไม่ถูกต้อง');
