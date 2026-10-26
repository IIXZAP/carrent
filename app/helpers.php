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
    $atBoundary = isset($path[strlen($base)]) ? $path[strlen($base)] === '/' : true;
    if ($base !== '' && strncmp($path, $base, strlen($base)) === 0 && $atBoundary) {
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

/** IP ของผู้ใช้ — เชื่อ X-Forwarded-For (ตัวซ้ายสุด) เฉพาะเมื่อ config trust_proxy เปิดไว้ */
function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (config('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
            return $first;
        }
    }
    return $remote;
}

/** แสดงเงิน: ไม่มีสตางค์ไม่โชว์ทศนิยม */
function money($amount): string
{
    $n = (float) $amount;
    return number_format($n, fmod($n, 1.0) == 0.0 ? 0 : 2);
}

/** รับค่าจาก <input type="datetime-local"> หรือ "Y-m-d H:i[:s]" → "Y-m-d H:i:s" (null = รูปแบบไม่ถูกต้อง) */
function normalize_datetime(?string $value): ?string
{
    $value = trim((string) $value);
    foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'] as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();
        if ($dt !== false && ($errors === false || ($errors['warning_count'] + $errors['error_count']) === 0)) {
            return $dt->format('Y-m-d H:i:s');
        }
    }
    return null;
}

/** "Y-m-d H:i:s" → ค่าสำหรับ <input type="datetime-local"> */
function input_datetime(?string $value): string
{
    return $value === null || $value === '' ? '' : date('Y-m-d\TH:i', strtotime($value));
}

function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}
