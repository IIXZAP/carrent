<?php
/**
 * ฟังก์ชันกลางที่ใช้ทั้งระบบ — settings, escape, CSRF, อัปโหลด, วันที่ไทย, running number
 */
require_once __DIR__ . '/db.php';

// ------------------------------------------------------------
// กันข้อมูลรูปแบบผิดทำให้หน้าเว็บล่ม
// ------------------------------------------------------------
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

// ข้อผิดพลาดที่ไม่มีใครดักไว้ → หน้าข้อความสั้นๆ แทนหน้าขาว และไม่เผยรายละเอียดภายในระบบ
set_exception_handler(static function (Throwable $e): void {
    error_log('[thonglorcarrent] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $bad = $e instanceof TypeError || $e instanceof ValueError;       // ข้อมูลที่ส่งมารูปแบบผิด
    if (!headers_sent()) {
        http_response_code($bad ? 400 : 500);
    }
    $msg  = $bad ? 'ข้อมูลที่ส่งมาไม่ถูกรูปแบบ กรุณากลับไปตรวจแล้วลองใหม่' : 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง หากยังไม่ได้โปรดติดต่อร้าน';
    $json = str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/api')
         || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    if ($json) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => $msg] + (APP_DEBUG ? ['debug' => $e->getMessage()] : []), JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="th"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>เกิดข้อผิดพลาด</title><body style="font-family:Sarabun,system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 20px;line-height:1.7;color:#1f2937">'
       . '<h1 style="font-size:1.3rem">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</h1>'
       . (APP_DEBUG ? '<pre style="white-space:pre-wrap;font-size:.85rem">' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>' : '')
       . '<p><a href="javascript:history.back()">← กลับไปหน้าก่อนหน้า</a></p></body></html>';
});

// ------------------------------------------------------------
// Session
// ------------------------------------------------------------
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(SESSION_NAME);
    // จำกัดคุกกี้ไว้เฉพาะโฟลเดอร์ที่ติดตั้ง — ถ้าลงระบบหลายชุดบนโดเมนเดียวกัน
    // (เช่น /thonglorcarrent กับ /thonglorcarrent2) เซสชันจะไม่ตีกัน
    // ล็อกอินหลังบ้านคนละชุดพร้อมกันได้ และ CSRF token ไม่ข้ามชุด
    $cookiePath = BASE_URL === '' ? '/' : rtrim(BASE_URL, '/') . '/';
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => $cookiePath,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

// ------------------------------------------------------------
// Settings (cache ในหน่วยความจำ 1 request)
// ------------------------------------------------------------
function settings(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db_all('SELECT skey, svalue FROM settings') as $r) {
            $cache[$r['skey']] = $r['svalue'];
        }
    }
    return $cache;
}

function setting(string $key, $default = '')
{
    $s = settings();
    return array_key_exists($key, $s) && $s[$key] !== null && $s[$key] !== '' ? $s[$key] : $default;
}

function set_setting(string $key, $value): void
{
    db_exec(
        'INSERT INTO settings (skey, svalue) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
        [':k' => $key, ':v' => (string) $value]
    );
}

// ------------------------------------------------------------
// Output / Escape
// ------------------------------------------------------------
function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * ที่อยู่ไฟล์ CSS/JS พร้อมเลขรุ่นที่เปลี่ยนเองเมื่อไฟล์ถูกแก้ (ใช้เวลาแก้ไขไฟล์)
 * อัปเดตระบบแล้วเบราว์เซอร์ของพนักงานและลูกค้าโหลดไฟล์ใหม่ทันที ไม่ต้องสั่งล้างแคช
 */
function asset(string $path): string
{
    $file = APP_ROOT . '/' . ltrim($path, '/');
    return url($path) . '?v=' . (@filemtime($file) ?: APP_VERSION);
}

function redirect(string $path): void
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------
// CSRF
// ------------------------------------------------------------
function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    start_session();
    $t = $_POST['_csrf'] ?? ($_GET['_csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $t)) {
        http_response_code(419);
        die('เซสชันหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่');
    }
}

/**
 * ตรวจว่าคำขอนี้ถูก PHP ทิ้งทั้งก้อนเพราะไฟล์ใหญ่เกิน post_max_size หรือไม่
 *
 * เคสนี้ร้ายที่สุดในบรรดาการอัปโหลดล้มเหลว เพราะ PHP ทิ้ง $_POST และ $_FILES
 * ทั้งหมดตั้งแต่ก่อนสคริปต์เริ่มทำงาน — ไม่มี CSRF token ไม่มีเลขที่จอง ไม่มีอะไรเลย
 * หน้าเว็บจึงไม่รู้ว่าผู้ใช้กำลังทำอะไรอยู่ แล้วเด้งกลับหน้าแรกแบบเงียบๆ
 * ลูกค้าเห็นแค่ "กดส่งแล้วหน้าเด้งกลับ ข้อมูลหาย" โดยไม่มีคำอธิบายใดๆ
 *
 * ตรวจได้จาก: เป็น POST + CONTENT_LENGTH มีค่า + $_POST ว่าง
 */
function post_too_large(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    return $len > 0 && empty($_POST) && empty($_FILES);
}

/**
 * แปลง HTTP_REFERER เป็น path ภายในเว็บนี้เท่านั้น
 *
 * ห้ามส่ง referer ดิบเข้า redirect() เด็ดขาด เพราะผู้ใช้ปลอม header นี้ได้
 * ถ้าปล่อยผ่านจะกลายเป็น open redirect — ส่งเหยื่อไปเว็บปลอมได้ด้วยลิงก์จากเว็บเรา
 * ฟังก์ชันนี้รับเฉพาะ path ที่อยู่ใต้ BASE_URL จริงๆ นอกนั้นคืนค่าสำรอง
 */
function safe_referer_path(string $fallback = 'index.php'): string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref === '') {
        return $fallback;
    }
    $parts = parse_url($ref);
    if ($parts === false) {
        return $fallback;
    }
    // ถ้ามีโฮสต์ ต้องเป็นโฮสต์เดียวกับที่กำลังเปิดอยู่
    $host = $parts['host'] ?? '';
    if ($host !== '' && strcasecmp($host, (string) ($_SERVER['HTTP_HOST'] ?? '')) !== 0) {
        return $fallback;
    }
    $path = $parts['path'] ?? '';
    $base = rtrim(BASE_URL, '/');
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $path = ltrim($path, '/');
    // กัน path แปลกๆ เช่น //evil.com หรือ ../
    if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
        return $fallback;
    }
    if (!empty($parts['query'])) {
        $path .= '?' . $parts['query'];
    }
    return $path;
}

/** ขนาดอัปโหลดสูงสุดที่ใช้ได้จริง (หน่วยไบต์) — ค่าที่น้อยกว่าระหว่าง upload_max กับ post_max */
function upload_limit_bytes(): int
{
    $toBytes = static function (string $v): int {
        $v = trim($v);
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g'     => $n * 1024 * 1024 * 1024,
            'm'     => $n * 1024 * 1024,
            'k'     => $n * 1024,
            default => $n,
        };
    };
    $u = $toBytes((string) ini_get('upload_max_filesize'));
    $p = $toBytes((string) ini_get('post_max_size'));
    $vals = array_filter([$u, $p]);
    return $vals ? min($vals) : 2 * 1024 * 1024;
}

/**
 * ตรวจ CSRF สำหรับคำขอแบบ AJAX — โยน exception แทนการ die
 * เพื่อให้ผู้เรียกตอบกลับเป็น JSON ได้ ไม่ใช่ HTML ที่ fetch อ่านไม่ออก
 */
function csrf_check_ajax(): void
{
    start_session();
    $t = $_POST['_csrf'] ?? ($_GET['_csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $t)) {
        throw new RuntimeException('เซสชันหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่');
    }
}

// ------------------------------------------------------------
// Flash message
// ------------------------------------------------------------
function flash(string $type, string $msg): void
{
    start_session();
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_render(): string
{
    start_session();
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $html .= '<div class="alert alert-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

// ------------------------------------------------------------
// วันที่ / เวลา แบบไทย
// ------------------------------------------------------------
const TH_MONTHS = [1=>'ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
const TH_MONTHS_FULL = [1=>'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
                        'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];

function thai_date(?string $datetime, bool $withTime = false, bool $full = false): string
{
    if (empty($datetime) || str_starts_with($datetime, '0000')) {
        return '-';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '-';
    }
    $d = (int) date('j', $ts);
    $m = (int) date('n', $ts);
    $y = (int) date('Y', $ts) + 543;
    $name = $full ? TH_MONTHS_FULL[$m] : TH_MONTHS[$m];
    // เต็ม: 19 กันยายน 2569 / สั้น: 19 ก.ย. 69
    $out = sprintf('%d %s %s', $d, $name, $full ? (string) $y : substr((string) $y, 2));
    if ($withTime) {
        $out .= ' ' . date('H:i', $ts) . ' น.';
    }
    return $out;
}

function money($n, bool $symbol = false): string
{
    $s = number_format((float) $n, 2);
    return $symbol ? '฿' . $s : $s;
}

function money0($n): string
{
    return number_format((float) $n, 0);
}

// ------------------------------------------------------------
// เลขที่เอกสารแบบรันนิ่ง: BK260919-001 / QT2609-0001
// ------------------------------------------------------------
/** เบอร์โทรแบบตัวเลขล้วน ใช้เทียบโดยไม่สนขีด ช่องว่าง วงเล็บ และรหัสประเทศ */
function phone_digits(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone);
    // +66812345678 / 66812345678 → 0812345678
    if (strlen($d) >= 11 && str_starts_with($d, '66')) {
        $d = '0' . substr($d, 2);
    }
    return $d;
}

/**
 * เบอร์โทรสองเบอร์เป็นเบอร์เดียวกันหรือไม่
 * ต้องมีตัวเลขอย่างน้อย 9 หลัก — เบอร์ที่เก็บไว้เป็น "-" หรือว่างจะไม่ตรงกับอะไรเลย
 * (ไม่งั้นการจองที่ไม่มีเบอร์จะเปิดดูได้ด้วยเบอร์อะไรก็ได้)
 */
function phone_match(?string $a, ?string $b): bool
{
    $x = phone_digits((string) $a);
    $y = phone_digits((string) $b);
    return strlen($x) >= 9 && hash_equals($x, $y);
}

/** หาลูกค้าเดิมจากเบอร์โทร — เทียบแบบตัวเลขล้วนทั้งสองฝั่ง */
function customer_by_phone(string $phone): ?array
{
    $d = phone_digits($phone);
    if (strlen($d) < 9) {
        return null;
    }
    // คัดในฐานข้อมูลก่อนด้วย 7 ตัวท้าย (ตัดขีด ช่องว่าง วงเล็บออก) แล้วเทียบเต็มเบอร์ใน PHP
    // เบอร์ในฐานข้อมูลมีได้หลายรูปแบบ เช่น "08-1234-56-78" หรือ "0812345678 (แม่)"
    // เรียงลูกค้าที่ติด Blacklist ขึ้นก่อน — ถ้ามีระเบียนซ้ำ ต้องเจอระเบียนที่ถูกห้ามจองเสมอ
    foreach (db_all('SELECT * FROM customers WHERE ' . phone_sql('phone') . ' LIKE :t ORDER BY is_blacklist DESC, id',
                    [':t' => '%' . substr($d, -7) . '%']) as $c) {
        if (phone_match($c['phone'], $d)) {
            return $c;
        }
    }
    return null;
}

/** นิพจน์ SQL ที่ตัดขีด ช่องว่าง วงเล็บ จุด และเครื่องหมายบวกออกจากคอลัมน์เบอร์โทร — ใช้คัดแถวก่อนเทียบเต็มเบอร์ด้วย phone_match() */
function phone_sql(string $col): string
{
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col, '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''), '+', '')";
}

/**
 * ข้อความแจ้งผู้ใช้เมื่อเกิดข้อผิดพลาด
 * ข้อความที่ระบบตั้งใจบอกผู้ใช้ (RuntimeException) แสดงตามนั้น
 * ข้อผิดพลาดจากฐานข้อมูลหรือจากโค้ด ไม่แสดงรายละเอียดให้ผู้ใช้เห็น — บันทึกลง error log แล้วแสดงข้อความกลางแทน
 */
function public_error(Throwable $e, string $fallback = 'ทำรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'): string
{
    if ($e instanceof RuntimeException && !($e instanceof PDOException)) {
        return $e->getMessage();
    }
    error_log('[thonglorcarrent] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return $fallback;
}

/** ย้อนทรานแซกชันถ้ายังเปิดอยู่ (หลัง deadlock ฐานข้อมูลอาจปิดให้เองแล้ว เรียก rollBack ตรงๆ จะเกิด error ซ้อน) */
function db_rollback(): void
{
    if (db()->inTransaction()) {
        db()->rollBack();
    }
}

function next_booking_no(): string
{
    $prefix = 'BK' . date('ymd') . '-';
    $max = db_val(
        'SELECT booking_no FROM bookings WHERE booking_no LIKE :p ORDER BY booking_no DESC LIMIT 1',
        [':p' => $prefix . '%']
    );
    $seq = $max ? ((int) substr((string) $max, -3)) + 1 : 1;
    return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
}

function next_doc_no(string $type): string
{
    $map = ['quotation' => 'QT', 'invoice' => 'IV', 'receipt' => 'RC', 'contract' => 'CT'];
    $prefix = ($map[$type] ?? 'DC') . date('ym') . '-';
    $max = db_val(
        'SELECT doc_no FROM documents WHERE doc_no LIKE :p ORDER BY doc_no DESC LIMIT 1',
        [':p' => $prefix . '%']
    );
    $seq = $max ? ((int) substr((string) $max, -4)) + 1 : 1;
    return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

function next_customer_code(): string
{
    // นับเฉพาะรหัสรูปแบบ CUS-ตัวเลข — รหัสที่พิมพ์เอง/นำเข้ามา (เช่น CUS-A01) ไม่ทำให้เลขรันสะดุดจนออกเลขซ้ำ
    $max = (int) db_val("SELECT COALESCE(MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)), 0) FROM customers WHERE code REGEXP '^CUS-[0-9]+$'");
    $seq = $max + 1;
    return 'CUS-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
}

// ------------------------------------------------------------
// อัปโหลดไฟล์ (ตรวจชนิดจริงด้วย finfo — ไม่เชื่อ extension)
// ------------------------------------------------------------
function upload_file(array $file, string $subdir, int $maxMB = 8): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        // แปลง error code ให้เป็นภาษาที่แอดมินหน้างานเข้าใจและแก้ได้เอง
        $msg = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (ขีดจำกัด ' . ini_get('upload_max_filesize') . ') '
                . 'กรุณาถ่ายผ่านหน้าเว็บบนมือถือ ระบบจะย่อรูปให้อัตโนมัติ',
            UPLOAD_ERR_PARTIAL   => 'อัปโหลดไม่ครบ สัญญาณอาจหลุดกลางคัน กรุณาลองใหม่',
            UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE => 'เซิร์ฟเวอร์เขียนไฟล์ไม่ได้ กรุณาติดต่อผู้ดูแลระบบ',
            default              => 'อัปโหลดไฟล์ไม่สำเร็จ (code ' . $file['error'] . ')',
        };
        throw new RuntimeException($msg);
    }
    if ($file['size'] > $maxMB * 1024 * 1024) {
        throw new RuntimeException("ไฟล์ใหญ่เกิน {$maxMB} MB");
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('รองรับเฉพาะไฟล์ JPG, PNG, WEBP และ PDF');
    }

    $dir = rtrim(UPLOAD_DIR, '/') . '/' . trim($subdir, '/');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('สร้างโฟลเดอร์อัปโหลดไม่สำเร็จ');
    }
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ');
    }
    return trim($subdir, '/') . '/' . $name;
}

function upload_url(?string $path): string
{
    return $path ? UPLOAD_URL . '/' . ltrim($path, '/') : '';
}

/**
 * มีไฟล์รูปอยู่จริงบนเซิร์ฟเวอร์หรือไม่
 * ใช้กันกรณี DB ชี้ไปที่ไฟล์ที่ยังไม่ได้อัปโหลด — จะได้แสดง placeholder แทนรูปแตก
 */
function upload_exists(?string $path): bool
{
    if (!$path) {
        return false;
    }
    $full = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($path, '/');
    return is_file($full) && filesize($full) > 0;
}

// ------------------------------------------------------------
// Log กิจกรรม
// ------------------------------------------------------------
function log_activity(string $action, ?string $table = null, ?int $refId = null, $detail = null): void
{
    start_session();
    try {
        db_insert('activity_logs', [
            'user_id'   => $_SESSION['user']['id'] ?? null,
            'action'    => $action,
            'ref_table' => $table,
            'ref_id'    => $refId,
            'detail'    => is_string($detail) || $detail === null ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE),
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // log ล้มเหลวไม่ควรทำให้ระบบหลักพัง
    }
}

// ------------------------------------------------------------
// ป้ายสถานะ
// ------------------------------------------------------------
function booking_status_label(string $s): array
{
    return match ($s) {
        'pending'   => ['รอยืนยัน', 'warn'],
        'confirmed' => ['ยืนยันแล้ว', 'info'],
        'picked_up' => ['รับรถแล้ว', 'primary'],
        'returned'  => ['คืนรถแล้ว', 'ok'],
        'completed' => ['ปิดงาน', 'ok'],
        'cancelled' => ['ยกเลิก', 'danger'],
        default     => [$s, 'muted'],
    };
}

function payment_status_label(string $s): array
{
    return match ($s) {
        'unpaid'       => ['ยังไม่ชำระ', 'danger'],
        'deposit_paid' => ['มัดจำแล้ว', 'warn'],
        'paid'         => ['ชำระครบ', 'ok'],
        'refunded'     => ['คืนเงินแล้ว', 'muted'],
        default        => [$s, 'muted'],
    };
}

function car_type_label(string $t): string
{
    return match ($t) {
        'eco'    => 'อีโคคาร์',
        'sedan'  => 'รถเก๋ง',
        'suv'    => 'SUV',
        'pickup' => 'กระบะ',
        'van'    => 'รถตู้',
        default  => $t,
    };
}

// ------------------------------------------------------------
// Bootstrap — เปิดเซสชันตั้งแต่ต้น request ก่อนมี output ใดๆ
// (สำคัญ: ถ้าเริ่มเซสชันหลังพ่น HTML แล้ว PHP จะส่ง cookie ไม่ได้
//  ทำให้ CSRF token และ flash message หายทุกครั้ง)
// ------------------------------------------------------------
start_session();
