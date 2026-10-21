<?php

namespace App\Core;

/**
 * ห่อข้อมูลคำขอ HTTP ($_GET, $_POST, $_FILES, $_SERVER) ไว้ที่เดียว
 * ตัวอ่านค่าแบบมีชนิด (str/int/float) ตัดค่าที่ผิดรูปแบบให้เองโดยไม่โยน TypeError
 */
final class Request
{
    private static ?Request $current = null;

    private ?string $rawBody = null;

    public static function capture(): self
    {
        return self::$current = new self();
    }

    /** คำขอปัจจุบัน (ใช้ในฟังก์ชันช่วยที่ไม่ได้รับ Request ตรงๆ) */
    public static function current(): self
    {
        return self::$current ?? self::capture();
    }

    public function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /** path ของคำขอ ตัด BASE_URL ออก ขึ้นต้นด้วย / และไม่มี / ท้าย (ยกเว้นหน้าแรก) */
    public function path(): string
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = rawurldecode($path);
        if (BASE_URL !== '' && str_starts_with($path, BASE_URL)) {
            $path = substr($path, strlen(BASE_URL));
        }
        // ถ้าเปิดผ่าน /index.php/xxx ให้ถือเป็น /xxx
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }
        $path = '/' . trim($path, '/');
        return $path === '' ? '/' : $path;
    }

    public function queryString(): string
    {
        return (string) ($_SERVER['QUERY_STRING'] ?? '');
    }

    // ---------- ค่าที่ส่งมา ----------
    public function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    public function post(string $key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }

    /** POST ก่อน แล้วค่อย GET */
    public function input(string $key, $default = null)
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($_POST[$key]) || isset($_GET[$key]);
    }

    /** ข้อความ: ตัดช่องว่างหัวท้าย ตัดความยาวไม่เกิน $max ตัวอักษร (0 = ไม่ตัด) ถ้าไม่ใช่ข้อความคืนค่าเริ่มต้น */
    public function str(string $key, int $max = 0, string $default = ''): string
    {
        $v = $this->input($key);
        if (!is_string($v)) {
            return $default;
        }
        $v = trim($v);
        return $max > 0 ? mb_substr($v, 0, $max) : $v;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key);
        return is_scalar($v) ? (int) $v : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $v = $this->input($key);
        return is_scalar($v) ? (float) $v : $default;
    }

    /** ติ๊กช่องหรือไม่ (มีค่าและไม่ใช่ 0/ว่าง) */
    public function bool(string $key): bool
    {
        $v = $this->input($key);
        return !empty($v) && $v !== '0';
    }

    /** ค่าแบบ array (เช่น addons[delivery]=1) — ไม่ใช่ array คืน [] */
    public function arr(string $key): array
    {
        $v = $this->input($key);
        return is_array($v) ? $v : [];
    }

    public function all(): array
    {
        return $_POST + $_GET;
    }

    // ---------- ไฟล์ ----------
    public function file(string $key): array
    {
        $f = $_FILES[$key] ?? null;
        return is_array($f) ? $f : [];
    }

    /** ไฟล์ที่เลือกหลายไฟล์ (name="images[]") แตกเป็นรายการไฟล์ละ 1 element */
    public function fileList(string $key): array
    {
        $f = $_FILES[$key] ?? null;
        if (!is_array($f) || !isset($f['name']) || !is_array($f['name'])) {
            return [];
        }
        $out = [];
        foreach ($f['name'] as $i => $name) {
            $out[] = [
                'name'     => $name,
                'type'     => $f['type'][$i] ?? '',
                'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error'    => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $f['size'][$i] ?? 0,
            ];
        }
        return $out;
    }

    // ---------- ส่วนหัว / สภาพแวดล้อม ----------
    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$key] ?? '');
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public function userAgent(): string
    {
        return (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    public function host(): string
    {
        return (string) ($_SERVER['HTTP_HOST'] ?? '');
    }

    public function isSecure(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($this->header('X-Forwarded-Proto') === 'https');
    }

    /** ผู้เรียกต้องการ JSON (fetch/AJAX หรือ path ที่เป็น API) */
    public function wantsJson(): bool
    {
        $path = $this->path();
        return str_contains($this->header('Accept'), 'application/json')
            || strtolower($this->header('X-Requested-With')) === 'xmlhttprequest'
            || str_starts_with($path, '/api/')
            || $path === '/liff/api';
    }

    /** เนื้อหาดิบของคำขอ (ใช้ตรวจลายเซ็น webhook ของ LINE) */
    public function rawBody(): string
    {
        return $this->rawBody ??= (string) file_get_contents('php://input');
    }

    /**
     * คำขอนี้ถูก PHP ทิ้งทั้งก้อนเพราะไฟล์ใหญ่เกิน post_max_size หรือไม่
     *
     * เคสนี้ร้ายที่สุดในบรรดาการอัปโหลดล้มเหลว เพราะ PHP ทิ้ง $_POST และ $_FILES
     * ทั้งหมดตั้งแต่ก่อนสคริปต์เริ่มทำงาน — ไม่มี CSRF token ไม่มีเลขที่จอง ไม่มีอะไรเลย
     * ตรวจได้จาก: เป็น POST + CONTENT_LENGTH มีค่า + $_POST ว่าง
     */
    public function postTooLarge(): bool
    {
        if ($this->method() !== 'POST') {
            return false;
        }
        // เฉพาะฟอร์มปกติ/อัปโหลดไฟล์ — คำขอ JSON (เช่น webhook ของ LINE) ไม่มี $_POST อยู่แล้วโดยธรรมชาติ
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_starts_with($type, 'multipart/form-data') && !str_starts_with($type, 'application/x-www-form-urlencoded')) {
            return false;
        }
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        return $len > 0 && empty($_POST) && empty($_FILES);
    }

    /**
     * แปลง HTTP_REFERER เป็น path ภายในเว็บนี้เท่านั้น
     *
     * ห้ามส่ง referer ดิบเข้า redirect เด็ดขาด เพราะผู้ใช้ปลอม header นี้ได้
     * ถ้าปล่อยผ่านจะกลายเป็น open redirect — ส่งเหยื่อไปเว็บปลอมได้ด้วยลิงก์จากเว็บเรา
     * ฟังก์ชันนี้รับเฉพาะ path ที่อยู่ใต้ BASE_URL จริงๆ นอกนั้นคืนค่าสำรอง
     */
    public function safeRefererPath(string $fallback = ''): string
    {
        $ref = $this->header('Referer');
        if ($ref === '') {
            return $fallback;
        }
        $parts = parse_url($ref);
        if ($parts === false) {
            return $fallback;
        }
        // ถ้ามีโฮสต์ ต้องเป็นโฮสต์เดียวกับที่กำลังเปิดอยู่
        $host = $parts['host'] ?? '';
        if ($host !== '' && strcasecmp($host, $this->host()) !== 0) {
            // host อาจมีพอร์ตต่อท้าย (localhost:8080) — เทียบแบบรวมพอร์ตด้วย
            $withPort = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
            if (strcasecmp($withPort, $this->host()) !== 0) {
                return $fallback;
            }
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
}
