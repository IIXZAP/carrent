<?php
/**
 * เริ่มระบบ — โหลด config, autoloader, ฟังก์ชันช่วย, ตัวจัดการข้อผิดพลาด และเซสชัน
 * ทุกจุดเข้า (index.php, cron/daily.php) ต้อง require ไฟล์นี้ก่อนทำอย่างอื่น
 */

require_once dirname(__DIR__) . '/config/config.php';

define('APP_PATH', __DIR__);
define('VIEW_PATH', __DIR__ . '/Views');

// ---------- autoloader (namespace App\ → โฟลเดอร์ app/) ----------
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/Helpers/functions.php';

// ---------- กันข้อมูลรูปแบบผิดทำให้หน้าเว็บล่ม ----------
// ระบบนี้ไม่มีพารามิเตอร์ใน URL ที่เป็น array — ถ้ามีคนส่ง ?no[]=x มา ให้ถือว่าเป็นค่าว่าง
// (ไม่งั้น trim() จะโยน TypeError แล้วได้หน้าขาว 500)
foreach ($_GET as $__k => $__v) {
    if (is_array($__v)) {
        $_GET[$__k] = '';
        if (!isset($_POST[$__k])) {
            $_REQUEST[$__k] = '';
        }
    }
}
unset($__k, $__v);

\App\Core\ErrorHandler::register();

// เปิดเซสชันตั้งแต่ต้น request ก่อนมี output ใดๆ
// (ถ้าเริ่มเซสชันหลังพ่น HTML แล้ว PHP จะส่ง cookie ไม่ได้ ทำให้ CSRF token และ flash message หายทุกครั้ง)
\App\Core\Session::start();
