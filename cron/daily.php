<?php
/**
 * งานอัตโนมัติรายวัน — ตั้งใน cPanel → Cron Jobs (แนะนำวันละครั้ง 08:00)
 *
 *   แบบที่แนะนำ (รันบนเซิร์ฟเวอร์ตรงๆ ไม่ผ่านเว็บ ไม่โดนไฟร์วอลล์ของโฮสต์บล็อก):
 *     /usr/local/bin/php /home/ชื่อผู้ใช้/public_html/โฟลเดอร์ระบบ/cron/daily.php "CRON_KEY"
 *   แบบสำรอง (เรียกผ่านเว็บ):
 *     wget -q -O /dev/null "https://yourdomain.com/โฟลเดอร์ระบบ/cron/daily.php?key=CRON_KEY"
 *
 *   คำสั่งที่ใส่ค่าจริงให้แล้ว คัดลอกได้จากหน้า หลังบ้าน → ตั้งค่า → Cron
 *
 * ไฟล์นี้บางมาก: ตรวจรหัสแล้วเรียก App\Services\CronService::run() ซึ่งมีรายละเอียดงานทั้งหมด
 */
require_once __DIR__ . '/../app/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
$key   = $isCli ? (string) ($argv[1] ?? '') : (string) ($_GET['key'] ?? '');
$real  = (string) setting('cron_key', 'CHANGE-THIS-CRON-KEY');

if (!hash_equals($real, $key)) {
    http_response_code(403);
    exit("forbidden\n");
}
// รหัสตั้งต้นใครก็รู้ — ยอมให้ใช้ได้เฉพาะตอนรันบนเซิร์ฟเวอร์เอง ถ้าเรียกผ่านเว็บต้องเปลี่ยนรหัสก่อน
if (!$isCli && $real === 'CHANGE-THIS-CRON-KEY') {
    http_response_code(403);
    exit('กรุณาเปลี่ยน Cron Key ที่ หลังบ้าน → ตั้งค่า ก่อนเรียกงานอัตโนมัติผ่านเว็บ');
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}
$lines = \App\Services\CronService::run();
echo 'thonglorcarrent cron ' . date('Y-m-d H:i:s') . "\n" . implode("\n", $lines) . "\n";
