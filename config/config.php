<?php
/**
 * thonglorcarrent — ค่าคอนฟิกหลัก
 * แก้ไขไฟล์นี้ไฟล์เดียวตอนติดตั้งบน cPanel
 */

// ---------- ฐานข้อมูล ----------
define('DB_HOST', 'localhost');
define('DB_NAME', 'carrent');             // ชื่อฐานข้อมูลที่สร้างใน cPanel
define('DB_USER', 'carrent');             // ชื่อผู้ใช้ฐานข้อมูล
define('DB_PASS', 'CHANGE_ME');             // รหัสผ่านฐานข้อมูล
define('DB_CHARSET', 'utf8mb4');

// ---------- URL / Path ----------
/**
 * BASE_URL = ส่วนของ URL ที่นำหน้าระบบ
 *   ติดตั้งที่ root ของโดเมน        (example.com)          → ''
 *   ติดตั้งในโฟลเดอร์ย่อย            (example.com/carrent)  → '/carrent'
 *
 * ปกติ "ไม่ต้องแก้" — ระบบตรวจหาเองอัตโนมัติจากตำแหน่งไฟล์จริง
 * จะตั้งเองก็ได้ โดยใส่ค่าในบรรทัดล่างนี้ เช่น '/carrent'
 */
define('BASE_URL_MANUAL', null);   // null = ให้ระบบตรวจหาเอง

if (!defined('BASE_URL')) {
    if (is_string(BASE_URL_MANUAL)) {
        define('BASE_URL', rtrim(BASE_URL_MANUAL, '/'));
    } else {
        $__docRoot = str_replace('\\', '/', rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
        $__appRoot = str_replace('\\', '/', dirname(__DIR__));
        // เผื่อ host ที่ path มี symlink (cPanel บางเจ้า) ให้เทียบแบบ realpath ด้วย
        $__docReal = $__docRoot !== '' ? (realpath($__docRoot) ?: $__docRoot) : '';
        $__appReal = realpath($__appRoot) ?: $__appRoot;
        $__docReal = str_replace('\\', '/', rtrim((string) $__docReal, '/'));
        $__appReal = str_replace('\\', '/', $__appReal);

        $__base = '';
        if ($__docRoot !== '' && strpos($__appRoot, $__docRoot) === 0) {
            $__base = substr($__appRoot, strlen($__docRoot));
        } elseif ($__docReal !== '' && strpos($__appReal, $__docReal) === 0) {
            $__base = substr($__appReal, strlen($__docReal));
        } elseif (!empty($_SERVER['SCRIPT_NAME'])) {
            // ทางสำรองสุดท้าย: เดาจาก URL ของสคริปต์ที่กำลังรัน
            $__dir = str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME']));
            // ตัดโฟลเดอร์ย่อยของระบบออก (cron)
            $__base = preg_replace('#/cron$#', '', $__dir);
        }
        define('BASE_URL', rtrim(str_replace('\\', '/', (string) $__base), '/') === '/' ? '' : rtrim(str_replace('\\', '/', (string) $__base), '/'));
    }
}

define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('UPLOAD_URL', BASE_URL . '/uploads');

// ---------- ความปลอดภัย ----------
define('SESSION_NAME', 'THONGLORCARRENT');
define('SITE_VARIANT', 'A');
define('SITE_VARIANT_LABEL', 'ดีไซน์เดิม');
define('SESSION_LIFETIME', 7200);         // 2 ชั่วโมง
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCK_MINUTES', 15);

// ---------- ระบบ ----------
define('APP_TZ', 'Asia/Bangkok');
define('APP_DEBUG', false);               // production ให้เป็น false เสมอ
define('APP_VERSION', '2.0.0');

date_default_timezone_set(APP_TZ);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}
