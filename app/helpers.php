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

/** กัน XSS — ครอบข้อมูลจากผู้ใช้ทุกครั้งที่แสดงใน View (ค่าที่เป็นอาร์เรย์ = ว่าง ไม่ใช่คำว่า "Array") */
function e($value): string
{
    return is_array($value) ? '' : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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

/** โฟลเดอร์เก็บไฟล์ส่วนตัว (สลิป ฯลฯ) — อยู่นอกการเข้าถึงผ่านเว็บ (storage/.htaccess ปฏิเสธทั้งหมด) */
function storage_path(string $relative = ''): string
{
    return APP_ROOT . '/storage/private' . ($relative === '' ? '' : '/' . ltrim($relative, '/'));
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
function normalize_datetime($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
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

/** ค่าจาก query string — ไม่ใช่ข้อความ (เช่น ?x[]=1) ให้ถือเป็นค่าว่าง ไม่ใช่ error */
function get_str(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

/** ช่องที่ควรเป็นอาร์เรย์ เช่น addons[code]=1 — ไม่ใช่อาร์เรย์ = อาร์เรย์ว่าง */
function get_array(string $key): array
{
    return is_array($_GET[$key] ?? null) ? $_GET[$key] : [];
}

function post_array(string $key): array
{
    return is_array($_POST[$key] ?? null) ? $_POST[$key] : [];
}

/** เก็บเฉพาะค่าที่เป็นข้อความจากฟอร์ม (ช่องที่ระบุใน $arrayKeys อนุญาตให้เป็นอาร์เรย์ได้) — กัน service ได้ค่าชนิดที่ไม่คาดคิด */
function request_strings(array $source, array $arrayKeys = []): array
{
    $out = [];
    foreach ($source as $key => $value) {
        if (is_string($value)) {
            $out[$key] = $value;
        } elseif (is_array($value) && in_array($key, $arrayKeys, true)) {
            $out[$key] = $value;
        }
    }
    return $out;
}

function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

/** วันที่แบบไทย พ.ศ. เช่น "10 มิ.ย. 2573" (+ " 10:00 น." เมื่อ $withTime) — รับ Y-m-d หรือ Y-m-d H:i:s */
function thai_date(?string $value, bool $withTime = false): string
{
    $ts = $value ? strtotime($value) : false;
    if ($ts === false) {
        return '-';
    }
    $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $text = (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts)] . ' ' . ((int) date('Y', $ts) + 543);
    return $withTime ? $text . ' ' . date('H:i', $ts) . ' น.' : $text;
}

/** เงินแบบบัญชี: ทศนิยม 2 ตำแหน่งเสมอ (ใช้บนเอกสาร) */
function money2($amount): string
{
    return number_format((float) $amount, 2);
}

/** URL เต็มสำหรับส่งให้คนนอกระบบ (ลิงก์เซ็นสัญญา) — ใช้ config app_url ถ้าตั้งไว้ ไม่งั้นอนุมานจากคำขอ */
function absolute_url(string $path): string
{
    $configured = (string) config('app_url', '');
    if ($configured !== '') {
        return $configured . '/' . ltrim($path, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (config('trust_proxy') && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    return ($https ? 'https' : 'http') . '://' . $host . url($path);
}

/**
 * ไฟล์ที่อัปโหลดในช่อง $key — รองรับทั้งช่องเดี่ยวและ multiple (name="x[]") คืนรายการโครงสร้างแบบ $_FILES ต่อไฟล์
 * ข้ามช่องที่ไม่ได้เลือกไฟล์ ; โครงสร้างผิดรูป (เช่น files[a][b]) ถือว่าไม่มีไฟล์
 */
function uploaded_files(string $key): array
{
    $f = $_FILES[$key] ?? null;
    if (!is_array($f) || !isset($f['error'])) {
        return [];
    }
    if (!is_array($f['error'])) {
        return (int) $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
    }
    $out = [];
    foreach ($f['error'] as $i => $err) {
        if (!is_int($i) || !is_int($err) || $err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['name' => (string) ($f['name'][$i] ?? ''), 'type' => (string) ($f['type'][$i] ?? ''), 'tmp_name' => (string) ($f['tmp_name'][$i] ?? ''),
                  'error' => $err, 'size' => (int) ($f['size'][$i] ?? 0)];
    }
    return $out;
}
