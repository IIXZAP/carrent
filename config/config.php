<?php
/**
 * ค่าตั้งต้น — ค่าจริงของเซิร์ฟเวอร์ให้ใส่ใน config/config.local.php (ไม่ commit)
 * หรือกำหนดผ่าน environment variable (DB_HOST, DB_NAME, DB_USER, DB_PASS)
 */
$config = [
    'app_name'    => 'Thonglor Car Rent',
    'debug'       => false,
    'timezone'    => 'Asia/Bangkok',
    // เปิดเมื่อเว็บอยู่หลัง proxy/CDN ที่ใส่ X-Forwarded-For ให้ (ไม่งั้นทุกคนจะมี IP เดียวกัน = IP ของ proxy)
    // ห้ามเปิดถ้าเข้าเซิร์ฟเวอร์ตรงๆ — ผู้ใช้จะปลอม IP เพื่อหลบขีดจำกัดได้
    'trust_proxy' => filter_var(getenv('TRUST_PROXY') ?: '0', FILTER_VALIDATE_BOOLEAN),
    // ที่อยู่เว็บเต็ม (เช่น https://rent.example.com) ใช้สร้างลิงก์ที่ส่งให้ลูกค้า — เว้นว่างจะอนุมานจากคำขอ
    'app_url'     => rtrim((string) (getenv('APP_URL') ?: ''), '/'),
    // ปลายทาง LINE Messaging API — ใช้ค่าจริงเสมอ เปลี่ยนเฉพาะตอนเทสต์ (ไม่มีหน้าตั้งค่าให้แก้ เพื่อกันถูกชี้ไปเซิร์ฟเวอร์อื่น)
    'line_api_base' => getenv('LINE_API_BASE') ?: 'https://api.line.me',
    'db_timezone' => '+07:00',
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'name' => getenv('DB_NAME') ?: 'carrent_v3',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

return $config;
