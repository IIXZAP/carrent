<?php
/** Front controller — ทุกคำขอเข้าที่นี่ */

// php -S localhost:8000 index.php : ให้ไฟล์ static ออกตามปกติ เฉพาะโฟลเดอร์ที่ตั้งใจเปิด
// (บน Apache ใช้ .htaccess ปิด storage/ app/ config/ ฯลฯ — เซิร์ฟเวอร์ dev ไม่อ่าน .htaccess จึงต้องจำกัดที่นี่ด้วย
//  ไม่งั้นสลิปใน storage/private จะเปิดอ่านได้ถ้าเดา URL)
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $isPublicStatic = preg_match('#^/(assets|uploads)/#', $path) === 1 || $path === '/robots.txt';
    if ($isPublicStatic && !str_contains($path, '..') && is_file(__DIR__ . $path)) {
        return false;
    }
}

require __DIR__ . '/app/bootstrap.php';

$router = new App\Core\Router();
require __DIR__ . '/app/routes.php';

$router->dispatch($_SERVER['REQUEST_METHOD'], request_path());
