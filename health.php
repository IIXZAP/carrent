<?php
/**
 * จุดตรวจสำหรับบริการเฝ้าระวังระบบล่ม (uptime monitor)
 *
 *   /health.php            → ตอบ "OK" (200) เมื่อเว็บและฐานข้อมูลทำงานปกติ ไม่เช่นนั้นตอบ "FAIL" (503)
 *                            ไม่เปิดเผยข้อมูลใดๆ จึงเปิดสาธารณะได้
 *   /health.php?key=รหัส   → ตอบผลตรวจทุกข้อเป็น JSON (รหัสดูได้ที่ หลังบ้าน → สุขภาพระบบ)
 */
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

define('DB_THROW', true);   // ต่อฐานข้อมูลไม่ได้ให้โยนข้อผิดพลาดมาที่นี่ จะได้ตอบ FAIL (503) ตามที่บริการเฝ้าระวังคาดไว้
try {
    require_once __DIR__ . '/includes/backup.php';
    db_val('SELECT 1');
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'FAIL';
    exit;
}

$key = (string) ($_GET['key'] ?? '');
$real = (string) setting('health_key');
if ($key === '' || $real === '' || !hash_equals($real, $key)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'OK';
    exit;
}

require_once __DIR__ . '/includes/line.php';
$out = ['status' => 'ok', 'checked_at' => date('c'), 'checks' => []];
foreach (health_checks() as [$title, $level, $found, $advice]) {
    $out['checks'][] = ['title' => $title, 'level' => $level, 'found' => $found, 'advice' => $advice];
    if ($level === 'fail') {
        $out['status'] = 'fail';
    } elseif ($level === 'warn' && $out['status'] === 'ok') {
        $out['status'] = 'warn';
    }
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
