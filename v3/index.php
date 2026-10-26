<?php
/** Front controller — ทุกคำขอเข้าที่นี่ */

// php -S localhost:8000 index.php : ให้ไฟล์ static (css/js/img) ออกตามปกติ
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && basename($file) !== 'index.php') {
        return false;
    }
}

require __DIR__ . '/app/bootstrap.php';

$router = new App\Core\Router();
require __DIR__ . '/app/routes.php';

$router->dispatch($_SERVER['REQUEST_METHOD'], request_path());
