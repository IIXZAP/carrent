<?php
/** ฟังก์ชันช่วยเล็กๆ ที่เรียกได้ทุกที่ */

/** กัน XSS: แปลงอักขระพิเศษก่อนแสดงบนหน้าเว็บ — ข้อความจากผู้ใช้ต้องผ่านฟังก์ชันนี้เสมอ */
function e($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

/** แสดงหน้า (View) โดยส่งตัวแปรเข้าไป เช่น view('cars/index', ['cars' => $cars]) */
function view(string $name, array $data = []): void
{
    extract($data);                       // แปลง ['cars' => ...] เป็นตัวแปร $cars
    ob_start();                           // เก็บผลลัพธ์ของหน้าไว้ก่อน
    require __DIR__ . '/Views/' . $name . '.php';
    $content = ob_get_clean();            // เนื้อหาของหน้านี้
    require __DIR__ . '/Views/layout.php';   // ครอบด้วยโครงหน้า (header/footer)
}

/** ส่งผู้ใช้ไปหน้าอื่น แล้วหยุดทำงาน */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** ข้อความแจ้งผลครั้งเดียว (แสดงในหน้าถัดไปแล้วหาย) */
function flash(string $message = ''): string
{
    if ($message !== '') {
        $_SESSION['flash'] = $message;    // ตั้งข้อความ
        return '';
    }
    $text = $_SESSION['flash'] ?? '';     // อ่านแล้วลบทิ้ง
    unset($_SESSION['flash']);
    return $text;
}

/** โทเคนกัน CSRF: ใส่ในฟอร์มทุกอันที่ใช้ POST */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** ตรวจว่าโทเคนที่ส่งมาตรงกับที่เก็บไว้ */
function csrf_valid(): bool
{
    return isset($_POST['_csrf']) && hash_equals(csrf_token(), (string) $_POST['_csrf']);
}
