<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Customer;
use App\Models\Notification;

/**
 * ตรวจสุขภาพระบบ — ใช้ทั้งหน้า หลังบ้าน → สุขภาพระบบ และจุดตรวจ /health
 */
final class HealthService
{
    /**
     * @return array<int,array{0:string,1:string,2:string,3:string}> [หัวข้อ, ระดับ ok|warn|fail, ผลที่พบ, คำแนะนำ]
     */
    public static function checks(): array
    {
        $c = [];
        $add = function (string $title, string $level, string $found, string $advice = '') use (&$c) {
            $c[] = [$title, $level, $found, $advice];
        };

        // --- PHP ---
        $add('เวอร์ชัน PHP', version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'warn', PHP_VERSION,
            version_compare(PHP_VERSION, '8.1', '>=') ? '' : 'อัปเป็น PHP 8.1 ขึ้นไปใน cPanel → Select PHP Version');
        $miss = array_values(array_filter(['pdo_mysql', 'curl', 'gd', 'fileinfo', 'mbstring', 'zlib'], fn($x) => !extension_loaded($x)));
        $add('ส่วนขยาย PHP ที่ระบบใช้', $miss ? 'fail' : 'ok', $miss ? 'ขาด: ' . implode(', ', $miss) : 'ครบ',
            $miss ? 'เปิดส่วนขยายที่ขาดใน cPanel → Select PHP Version → Extensions' : '');

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
              || str_starts_with((string) setting('site_url'), 'https://');
        $add('การเข้ารหัส HTTPS', $https ? 'ok' : 'fail', $https ? 'เปิดอยู่' : 'ไม่ได้ใช้ HTTPS',
            $https ? '' : 'ติดตั้ง SSL ใน cPanel → SSL/TLS Status — LINE และการกรอกข้อมูลลูกค้าต้องใช้ HTTPS');
        $add('โหมดแสดงข้อผิดพลาด', APP_DEBUG ? 'fail' : 'ok', APP_DEBUG ? 'APP_DEBUG เปิดอยู่' : 'ปิด (ถูกต้อง)',
            APP_DEBUG ? 'ตั้ง APP_DEBUG เป็น false ใน config/config.php — เปิดไว้ผู้ใช้จะเห็นรายละเอียดภายในระบบ' : '');

        // --- ฐานข้อมูล ---
        try {
            $size = (float) Database::value('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.TABLES WHERE table_schema = DATABASE()');
            $add('ฐานข้อมูล', 'ok', 'เชื่อมต่อได้ · ขนาด ' . human_bytes($size));
            $v4 = (int) Database::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                         AND ((TABLE_NAME = 'inspections' AND COLUMN_NAME = 'locked_at')
                                           OR (TABLE_NAME = 'notifications' AND COLUMN_NAME = 'tpl_key')
                                           OR (TABLE_NAME = 'inspection_photos' AND COLUMN_NAME = 'superseded_at'))");
            $v5 = (int) Database::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                         AND ((TABLE_NAME = 'bookings' AND COLUMN_NAME IN ('extra_breakdown', 'sign_token', 'delivery_km'))
                                           OR (TABLE_NAME = 'signatures' AND COLUMN_NAME = 'voided_at')
                                           OR (TABLE_NAME = 'customer_documents' AND COLUMN_NAME = 'mime'))");
            // ตารางของเวอร์ชัน 3 (รูปสภาพรถ) — ถ้าข้ามไฟล์นั้นมา update-v4.sql จะรันไม่ผ่านตั้งแต่บรรทัดแรก
            $v3 = (int) Database::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                                         AND TABLE_NAME IN ('inspections', 'inspection_photos')");
            $dbOk = $v3 === 2 && $v4 === 3 && $v5 === 5;
            $need = $v3 < 2 ? 'update-v3.sql → update-v4.sql → update-v5.sql' : ($v4 < 3 ? 'update-v4.sql → update-v5.sql' : 'update-v5.sql');
            $add('โครงสร้างฐานข้อมูล', $dbOk ? 'ok' : 'fail',
                $dbOk ? 'เวอร์ชัน 5 (ล่าสุด)' : 'ยังไม่ได้อัปเดตเป็นเวอร์ชัน ' . ($v3 < 2 ? '3' : ($v4 < 3 ? '4' : '5')),
                $dbOk ? '' : 'นำเข้าไฟล์ในโฟลเดอร์ database/ ตามลำดับ: ' . $need . ' ใน phpMyAdmin (ทุกไฟล์รันซ้ำได้ ไม่ลบข้อมูล)');
        } catch (\Throwable $e) {
            $add('ฐานข้อมูล', 'fail', 'เชื่อมต่อไม่ได้', 'ตรวจค่าใน config/config.php');
        }

        // --- ไฟล์ ---
        $up   = rtrim(UPLOAD_DIR, '/');
        $upOk = is_dir($up) && is_writable($up);
        [$bytes, $files] = UploadService::dirUsage($up);
        $add('โฟลเดอร์รูปและเอกสาร (uploads)', $upOk ? 'ok' : 'fail',
            $upOk ? number_format($files) . ' ไฟล์ · ' . human_bytes($bytes) : 'เขียนไฟล์ไม่ได้',
            $upOk ? '' : 'ตั้งสิทธิ์โฟลเดอร์ uploads เป็น 755');
        // ที่เก็บแบบปิด: ลายเซ็นลูกค้า + สำเนาบัตร/ใบขับขี่
        try {
            $pv   = PrivateFileService::dir('');
            $pvOk = is_writable($pv);
            [$pb, $pf] = UploadService::dirUsage($pv);
        } catch (\Throwable $e) {
            $pvOk = false;
            $pb = 0;
            $pf = 0;
        }
        $add('ที่เก็บไฟล์แบบปิด (ลายเซ็น / เอกสารลูกค้า)', $pvOk ? 'ok' : 'fail',
            $pvOk ? number_format($pf) . ' ไฟล์ · ' . human_bytes($pb) : 'เขียนไฟล์ไม่ได้',
            $pvOk ? '' : 'ตั้งสิทธิ์โฟลเดอร์ storage เป็น 755 — ไม่งั้นลูกค้าเซ็นสัญญาและแนบเอกสารไม่ได้');
        try {
            $legacy = Customer::legacyDocCount();
            $add('เอกสารลูกค้าในโฟลเดอร์สาธารณะ', $legacy > 0 ? 'warn' : 'ok',
                $legacy > 0 ? $legacy . ' ไฟล์ยังอยู่ใน uploads/docs' : 'ไม่มี',
                $legacy > 0 ? 'กดปุ่ม "ย้ายเข้าที่เก็บแบบปิด" ด้านล่างของหน้านี้ — สำเนาบัตรประชาชนไม่ควรอยู่ในโฟลเดอร์ที่เปิดผ่านเว็บได้' : '');
        } catch (\Throwable $e) {
            // ฐานข้อมูลยังไม่ถึงเวอร์ชันที่มีคอลัมน์นี้ — ข้อ "โครงสร้างฐานข้อมูล" แจ้งไว้แล้ว
        }
        $free = @disk_free_space(APP_ROOT);
        if ($free !== false) {
            $add('พื้นที่ว่างบนเซิร์ฟเวอร์', $free < 500 * 1048576 ? ($free < 100 * 1048576 ? 'fail' : 'warn') : 'ok', human_bytes((float) $free),
                $free < 500 * 1048576 ? 'พื้นที่เหลือน้อย — ลบไฟล์สำรองเก่าหรือขยายแพ็กเกจโฮสติ้ง' : '');
        }
        $lim = UploadService::limitBytes();
        $add('ขนาดไฟล์ที่อัปโหลดได้', $lim < 2 * 1048576 ? 'warn' : 'ok', human_bytes((float) $lim) . ' ต่อครั้ง',
            $lim < 2 * 1048576 ? 'ต่ำกว่า 2 MB — เพิ่ม upload_max_filesize และ post_max_size ใน cPanel → MultiPHP INI Editor' : 'ระบบย่อรูปก่อนส่งให้อัตโนมัติ');

        // --- งานอัตโนมัติ ---
        $cron = (string) setting('cron_last_at');
        $age  = $cron !== '' ? (time() - strtotime($cron)) / 3600 : null;
        $add('งานอัตโนมัติรายวัน (cron)', $age === null ? 'fail' : ($age > 36 ? 'fail' : 'ok'),
            $age === null ? 'ยังไม่เคยทำงาน' : 'ทำงานล่าสุด ' . thai_date($cron, true),
            ($age === null || $age > 36) ? 'ตั้ง Cron Job ใน cPanel ให้เรียก cron/daily.php วันละครั้ง — ไม่ทำงาน = ไม่มีเตือนลูกค้า ไม่มีสำรองข้อมูล' : '');
        $defKey = (string) setting('cron_key', 'CHANGE-THIS-CRON-KEY') === 'CHANGE-THIS-CRON-KEY';
        $add('รหัสป้องกัน cron', $defKey ? 'fail' : 'ok', $defKey ? 'ยังเป็นค่าตั้งต้น' : 'เปลี่ยนแล้ว',
            $defKey ? 'เปลี่ยน Cron Key ที่ หลังบ้าน → ตั้งค่า — ค่าตั้งต้นใครก็เรียกงานอัตโนมัติได้' : '');

        $bk    = (string) setting('backup_last_at');
        $bkAge = $bk !== '' ? (time() - strtotime($bk)) / 3600 : null;
        $list  = BackupService::list();
        $add('สำรองฐานข้อมูล', $bkAge === null ? 'fail' : ($bkAge > 36 ? 'warn' : 'ok'),
            $bkAge === null ? 'ยังไม่เคยสำรอง' : 'ล่าสุด ' . thai_date($bk, true) . ' · เก็บอยู่ ' . count($list) . ' ไฟล์',
            ($bkAge === null || $bkAge > 36) ? 'ตรวจว่า cron ทำงาน หรือกด "สำรองตอนนี้" ที่หน้า สำรองข้อมูล' : '');

        // --- แจ้งเตือน ---
        $fail  = Notification::failedLast7Days();
        $stuck = Notification::stuckQueued();
        $add('การส่งแจ้งเตือน 7 วันล่าสุด', $fail + $stuck > 0 ? 'warn' : 'ok',
            $fail + $stuck > 0 ? "ส่งไม่สำเร็จ $fail · ค้างคิว $stuck" : 'ปกติ',
            $fail + $stuck > 0 ? 'ดูสาเหตุที่ หลังบ้าน → เชื่อมต่อ LINE → ประวัติการส่ง' : '');
        $lineOk = trim((string) setting('line_channel_token')) !== '' && trim((string) setting('line_channel_secret')) !== ''
               && trim((string) setting('line_liff_id')) !== '' && setting('notify_line_enabled') === '1';
        $add('การเชื่อมต่อ LINE', $lineOk ? 'ok' : 'warn', $lineOk ? 'ตั้งค่าครบและเปิดใช้งาน' : 'ยังตั้งค่าไม่ครบหรือยังไม่เปิดใช้',
            $lineOk ? '' : 'ตั้งค่าที่ หลังบ้าน → เชื่อมต่อ LINE');

        // --- บัญชีผู้ใช้ ---
        $admins = (int) Database::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
        $add('บัญชีเจ้าของร้าน', $admins >= 1 ? 'ok' : 'fail', $admins . ' บัญชี',
            $admins === 1 ? 'มีบัญชีเดียว — ถ้าลืมรหัสผ่านจะต้องแก้ผ่านฐานข้อมูล แนะนำให้มีสำรองอีก 1 บัญชี' : '');
        $over = (int) Database::value("SELECT COUNT(*) FROM bookings WHERE status = 'picked_up' AND return_at < NOW()");
        $add('รถเลยกำหนดคืนที่ยังไม่บันทึกรับคืน', $over > 0 ? 'warn' : 'ok', $over . ' คัน',
            $over > 0 ? 'คันเหล่านี้ถูกกันไม่ให้จองต่อ — บันทึกรับคืนที่หน้า ภาพรวม' : '');

        return $c;
    }
}
