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
        return array_merge(
            self::phpChecks(),
            self::databaseChecks(),
            self::fileChecks(),
            self::cronChecks(),
            self::notificationChecks(),
            self::accountChecks()
        );
    }

    /** แถวผลตรวจ 1 แถว */
    private static function row(string $title, string $level, string $found, string $advice = ''): array
    {
        return [$title, $level, $found, $advice];
    }

    // ------------------------------------------------------------
    // PHP / เซิร์ฟเวอร์
    // ------------------------------------------------------------
    private static function phpChecks(): array
    {
        $rows = [];

        $phpOk = version_compare(PHP_VERSION, '8.1', '>=');
        $rows[] = self::row('เวอร์ชัน PHP', $phpOk ? 'ok' : 'warn', PHP_VERSION,
            $phpOk ? '' : 'อัปเป็น PHP 8.1 ขึ้นไปใน cPanel → Select PHP Version');

        $missing = [];
        foreach (['pdo_mysql', 'curl', 'gd', 'fileinfo', 'mbstring', 'zlib'] as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }
        $rows[] = self::row('ส่วนขยาย PHP ที่ระบบใช้', $missing ? 'fail' : 'ok',
            $missing ? 'ขาด: ' . implode(', ', $missing) : 'ครบ',
            $missing ? 'เปิดส่วนขยายที่ขาดใน cPanel → Select PHP Version → Extensions' : '');

        $https = self::usesHttps();
        $rows[] = self::row('การเข้ารหัส HTTPS', $https ? 'ok' : 'fail', $https ? 'เปิดอยู่' : 'ไม่ได้ใช้ HTTPS',
            $https ? '' : 'ติดตั้ง SSL ใน cPanel → SSL/TLS Status — LINE และการกรอกข้อมูลลูกค้าต้องใช้ HTTPS');

        $rows[] = self::row('โหมดแสดงข้อผิดพลาด', APP_DEBUG ? 'fail' : 'ok', APP_DEBUG ? 'APP_DEBUG เปิดอยู่' : 'ปิด (ถูกต้อง)',
            APP_DEBUG ? 'ตั้ง APP_DEBUG เป็น false ใน config/config.php — เปิดไว้ผู้ใช้จะเห็นรายละเอียดภายในระบบ' : '');

        return $rows;
    }

    private static function usesHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        return str_starts_with((string) setting('site_url'), 'https://');
    }

    // ------------------------------------------------------------
    // ฐานข้อมูล
    // ------------------------------------------------------------
    private static function databaseChecks(): array
    {
        try {
            $size = (float) Database::value('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.TABLES WHERE table_schema = DATABASE()');
            return [
                self::row('ฐานข้อมูล', 'ok', 'เชื่อมต่อได้ · ขนาด ' . human_bytes($size)),
                self::schemaCheck(),
            ];
        } catch (\Throwable $e) {
            return [self::row('ฐานข้อมูล', 'fail', 'เชื่อมต่อไม่ได้', 'ตรวจค่าใน config/config.php')];
        }
    }

    /** ตรวจว่าฐานข้อมูลอัปเดตถึงเวอร์ชัน 5 แล้วหรือยัง (นับตารางและคอลัมน์ที่แต่ละเวอร์ชันเพิ่มเข้ามา) */
    private static function schemaCheck(): array
    {
        // ตารางของเวอร์ชัน 3 (รูปสภาพรถ) — ถ้าข้ามไฟล์นั้นมา update-v4.sql จะรันไม่ผ่านตั้งแต่บรรทัดแรก
        $v3 = (int) Database::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                                     AND TABLE_NAME IN ('inspections', 'inspection_photos')");
        $v4 = (int) Database::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                     AND ((TABLE_NAME = 'inspections' AND COLUMN_NAME = 'locked_at')
                                       OR (TABLE_NAME = 'notifications' AND COLUMN_NAME = 'tpl_key')
                                       OR (TABLE_NAME = 'inspection_photos' AND COLUMN_NAME = 'superseded_at'))");
        $v5 = (int) Database::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                     AND ((TABLE_NAME = 'bookings' AND COLUMN_NAME IN ('extra_breakdown', 'sign_token', 'delivery_km'))
                                       OR (TABLE_NAME = 'signatures' AND COLUMN_NAME = 'voided_at')
                                       OR (TABLE_NAME = 'customer_documents' AND COLUMN_NAME = 'mime'))");

        if ($v3 === 2 && $v4 === 3 && $v5 === 5) {
            return self::row('โครงสร้างฐานข้อมูล', 'ok', 'เวอร์ชัน 5 (ล่าสุด)');
        }
        if ($v3 < 2) {
            $version = '3';
            $files   = 'update-v3.sql → update-v4.sql → update-v5.sql';
        } elseif ($v4 < 3) {
            $version = '4';
            $files   = 'update-v4.sql → update-v5.sql';
        } else {
            $version = '5';
            $files   = 'update-v5.sql';
        }
        return self::row('โครงสร้างฐานข้อมูล', 'fail', 'ยังไม่ได้อัปเดตเป็นเวอร์ชัน ' . $version,
            'นำเข้าไฟล์ในโฟลเดอร์ database/ ตามลำดับ: ' . $files . ' ใน phpMyAdmin (ทุกไฟล์รันซ้ำได้ ไม่ลบข้อมูล)');
    }

    // ------------------------------------------------------------
    // ไฟล์และพื้นที่
    // ------------------------------------------------------------
    private static function fileChecks(): array
    {
        $rows = [];

        $uploads   = rtrim(UPLOAD_DIR, '/');
        $uploadsOk = is_dir($uploads) && is_writable($uploads);
        [$bytes, $files] = UploadService::dirUsage($uploads);
        $rows[] = self::row('โฟลเดอร์รูปและเอกสาร (uploads)', $uploadsOk ? 'ok' : 'fail',
            $uploadsOk ? number_format($files) . ' ไฟล์ · ' . human_bytes($bytes) : 'เขียนไฟล์ไม่ได้',
            $uploadsOk ? '' : 'ตั้งสิทธิ์โฟลเดอร์ uploads เป็น 755');

        $rows[] = self::privateStorageCheck();

        $legacyRow = self::legacyDocsCheck();
        if ($legacyRow) {
            $rows[] = $legacyRow;
        }

        $diskRow = self::diskSpaceCheck();
        if ($diskRow) {
            $rows[] = $diskRow;
        }

        $limit = UploadService::limitBytes();
        $small = $limit < 2 * 1048576;
        $rows[] = self::row('ขนาดไฟล์ที่อัปโหลดได้', $small ? 'warn' : 'ok', human_bytes((float) $limit) . ' ต่อครั้ง',
            $small ? 'ต่ำกว่า 2 MB — เพิ่ม upload_max_filesize และ post_max_size ใน cPanel → MultiPHP INI Editor' : 'ระบบย่อรูปก่อนส่งให้อัตโนมัติ');

        return $rows;
    }

    /** ที่เก็บแบบปิด: ลายเซ็นลูกค้า + สำเนาบัตร/ใบขับขี่ */
    private static function privateStorageCheck(): array
    {
        $ok = false;
        $bytes = 0;
        $files = 0;
        try {
            $dir = PrivateFileService::dir('');
            $ok  = is_writable($dir);
            [$bytes, $files] = UploadService::dirUsage($dir);
        } catch (\Throwable $e) {
            $ok = false;
        }
        return self::row('ที่เก็บไฟล์แบบปิด (ลายเซ็น / เอกสารลูกค้า)', $ok ? 'ok' : 'fail',
            $ok ? number_format($files) . ' ไฟล์ · ' . human_bytes($bytes) : 'เขียนไฟล์ไม่ได้',
            $ok ? '' : 'ตั้งสิทธิ์โฟลเดอร์ storage เป็น 755 — ไม่งั้นลูกค้าเซ็นสัญญาและแนบเอกสารไม่ได้');
    }

    /** @return ?array null = ตรวจไม่ได้ (ฐานข้อมูลยังไม่ถึงเวอร์ชันที่มีคอลัมน์นี้ ข้อ "โครงสร้างฐานข้อมูล" แจ้งไว้แล้ว) */
    private static function legacyDocsCheck(): ?array
    {
        try {
            $legacy = Customer::legacyDocCount();
        } catch (\Throwable $e) {
            return null;
        }
        return self::row('เอกสารลูกค้าในโฟลเดอร์สาธารณะ', $legacy > 0 ? 'warn' : 'ok',
            $legacy > 0 ? $legacy . ' ไฟล์ยังอยู่ใน uploads/docs' : 'ไม่มี',
            $legacy > 0 ? 'กดปุ่ม "ย้ายเข้าที่เก็บแบบปิด" ด้านล่างของหน้านี้ — สำเนาบัตรประชาชนไม่ควรอยู่ในโฟลเดอร์ที่เปิดผ่านเว็บได้' : '');
    }

    /** @return ?array null = อ่านพื้นที่ว่างไม่ได้ */
    private static function diskSpaceCheck(): ?array
    {
        $free = @disk_free_space(APP_ROOT);
        if ($free === false) {
            return null;
        }
        $level = 'ok';
        if ($free < 100 * 1048576) {
            $level = 'fail';
        } elseif ($free < 500 * 1048576) {
            $level = 'warn';
        }
        return self::row('พื้นที่ว่างบนเซิร์ฟเวอร์', $level, human_bytes((float) $free),
            $level !== 'ok' ? 'พื้นที่เหลือน้อย — ลบไฟล์สำรองเก่าหรือขยายแพ็กเกจโฮสติ้ง' : '');
    }

    // ------------------------------------------------------------
    // งานอัตโนมัติและการสำรองข้อมูล
    // ------------------------------------------------------------
    private static function cronChecks(): array
    {
        $rows = [];

        $cron    = (string) setting('cron_last_at');
        $cronAge = $cron !== '' ? (time() - strtotime($cron)) / 3600 : null;
        $cronBad = $cronAge === null || $cronAge > 36;
        $rows[] = self::row('งานอัตโนมัติรายวัน (cron)', $cronBad ? 'fail' : 'ok',
            $cronAge === null ? 'ยังไม่เคยทำงาน' : 'ทำงานล่าสุด ' . thai_date($cron, true),
            $cronBad ? 'ตั้ง Cron Job ใน cPanel ให้เรียก cron/daily.php วันละครั้ง — ไม่ทำงาน = ไม่มีเตือนลูกค้า ไม่มีสำรองข้อมูล' : '');

        $defaultKey = (string) setting('cron_key', 'CHANGE-THIS-CRON-KEY') === 'CHANGE-THIS-CRON-KEY';
        $rows[] = self::row('รหัสป้องกัน cron', $defaultKey ? 'fail' : 'ok', $defaultKey ? 'ยังเป็นค่าตั้งต้น' : 'เปลี่ยนแล้ว',
            $defaultKey ? 'เปลี่ยน Cron Key ที่ หลังบ้าน → ตั้งค่า — ค่าตั้งต้นใครก็เรียกงานอัตโนมัติได้' : '');

        $backup    = (string) setting('backup_last_at');
        $backupAge = $backup !== '' ? (time() - strtotime($backup)) / 3600 : null;
        $backupBad = $backupAge === null || $backupAge > 36;
        $level     = $backupAge === null ? 'fail' : ($backupBad ? 'warn' : 'ok');
        $rows[] = self::row('สำรองฐานข้อมูล', $level,
            $backupAge === null ? 'ยังไม่เคยสำรอง' : 'ล่าสุด ' . thai_date($backup, true) . ' · เก็บอยู่ ' . count(BackupService::list()) . ' ไฟล์',
            $backupBad ? 'ตรวจว่า cron ทำงาน หรือกด "สำรองตอนนี้" ที่หน้า สำรองข้อมูล' : '');

        return $rows;
    }

    // ------------------------------------------------------------
    // แจ้งเตือน / LINE
    // ------------------------------------------------------------
    private static function notificationChecks(): array
    {
        $failed = Notification::failedLast7Days();
        $stuck  = Notification::stuckQueued();
        $problem = $failed + $stuck > 0;

        $lineReady = trim((string) setting('line_channel_token')) !== ''
                  && trim((string) setting('line_channel_secret')) !== ''
                  && trim((string) setting('line_liff_id')) !== ''
                  && setting('notify_line_enabled') === '1';

        return [
            self::row('การส่งแจ้งเตือน 7 วันล่าสุด', $problem ? 'warn' : 'ok',
                $problem ? "ส่งไม่สำเร็จ $failed · ค้างคิว $stuck" : 'ปกติ',
                $problem ? 'ดูสาเหตุที่ หลังบ้าน → เชื่อมต่อ LINE → ประวัติการส่ง' : ''),
            self::row('การเชื่อมต่อ LINE', $lineReady ? 'ok' : 'warn',
                $lineReady ? 'ตั้งค่าครบและเปิดใช้งาน' : 'ยังตั้งค่าไม่ครบหรือยังไม่เปิดใช้',
                $lineReady ? '' : 'ตั้งค่าที่ หลังบ้าน → เชื่อมต่อ LINE'),
        ];
    }

    // ------------------------------------------------------------
    // บัญชีผู้ใช้ / รถค้างคืน
    // ------------------------------------------------------------
    private static function accountChecks(): array
    {
        $admins  = (int) Database::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
        $overdue = (int) Database::value("SELECT COUNT(*) FROM bookings WHERE status = 'picked_up' AND return_at < NOW()");

        return [
            self::row('บัญชีเจ้าของร้าน', $admins >= 1 ? 'ok' : 'fail', $admins . ' บัญชี',
                $admins === 1 ? 'มีบัญชีเดียว — ถ้าลืมรหัสผ่านจะต้องแก้ผ่านฐานข้อมูล แนะนำให้มีสำรองอีก 1 บัญชี' : ''),
            self::row('รถเลยกำหนดคืนที่ยังไม่บันทึกรับคืน', $overdue > 0 ? 'warn' : 'ok', $overdue . ' คัน',
                $overdue > 0 ? 'คันเหล่านี้ถูกกันไม่ให้จองต่อ — บันทึกรับคืนที่หน้า ภาพรวม' : ''),
        ];
    }
}
