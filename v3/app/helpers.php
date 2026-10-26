<?php
/** ฟังก์ชันช่วยที่ใช้ทั้งใน Controller และ View */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

function config(string $key, $default = null)
{
    $value = $GLOBALS['config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** กัน XSS — ครอบข้อมูลจากผู้ใช้ทุกครั้งที่แสดงใน View */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** โฟลเดอร์ที่แอปอยู่ (รองรับติดตั้งในโฟลเดอร์ย่อย) เช่น "" หรือ "/v3" */
function base_path(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
}

function url(string $path = '/'): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

/** path ของคำขอ โดยตัด base_path ออก */
function request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = base_path();
    if ($base !== '' && strncmp($path, $base, strlen($base)) === 0) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . trim($path, '/');
    return $path;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function view_render(string $name, array $data = [], ?string $layout = 'layouts/public'): string
{
    return View::render($name, $data, $layout);
}

function view(string $name, array $data = [], ?string $layout = 'layouts/public'): void
{
    echo View::render($name, $data, $layout);
}

function abort(int $status, string $message = ''): never
{
    http_response_code($status);
    echo View::render('errors/' . ($status === 404 ? '404' : 'error'), [
        'status'  => $status,
        'message' => $message,
    ], 'layouts/public');
    exit;
}

function flash(string $type, string $message): void
{
    Flash::add($type, $message);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

/** ค่าที่ผู้ใช้กรอกไว้ (ใช้เติมกลับในฟอร์มเมื่อ validate ไม่ผ่าน) */
function old(array $data, string $key, $default = '')
{
    return $data[$key] ?? $default;
}

function money($amount): string
{
    return number_format((float) $amount, 0);
}

function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}
