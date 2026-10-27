<?php
use App\Core\Database;

/**
 * เทสต์ผ่าน HTTP จริง: เปิด php -S ชั่วคราว (ใช้ฐานข้อมูลเดียวกับเทสต์) แล้วยิงคำขอเข้า controller ตั้งแต่ต้นจนจบ
 * ข้อมูลที่ต้องให้เซิร์ฟเวอร์เห็นต้อง commit จริง — เทสต์ต้องลบทิ้งเองใน finally
 * ชื่อฟังก์ชัน test_http_* ไม่ถูกครอบด้วย transaction
 */

/** เปิดเซิร์ฟเวอร์ครั้งเดียวต่อการรัน คืน base URL */
function t_http_base(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(stream_socket_get_name($probe, false), strrpos(stream_socket_get_name($probe, false), ':') + 1);
    fclose($probe);

    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", 'index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, APP_ROOT);
    register_shutdown_function(static function () use ($proc): void {
        proc_terminate($proc);
    });
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($fp !== false) {
            fclose($fp);
            return $base = "http://127.0.0.1:$port";
        }
        usleep(100000);
    }
    throw new RuntimeException('เปิดเซิร์ฟเวอร์ทดสอบไม่ได้');
}

/**
 * @param array $opt form(array) cookies(array, ส่งต่อเข้ามา/ออกไปทาง &) headers(string[])
 * @return array{status:int,headers:array,body:string,cookies:array}
 */
function t_http(string $method, string $path, array $opt = [], array &$cookies = []): array
{
    $headers = $opt['headers'] ?? [];
    $content = '';
    if (isset($opt['form'])) {
        $content = http_build_query($opt['form']);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20,
    ]]);
    $body = @file_get_contents(t_http_base() . $path, false, $ctx);
    $raw = $http_response_header ?? [];
    $status = isset($raw[0]) && preg_match('#\s(\d{3})\s#', $raw[0], $m) ? (int) $m[1] : 0;
    $parsed = [];
    foreach (array_slice($raw, 1) as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $parsed[strtolower(trim($name))][] = trim($value);
        if (strtolower(trim($name)) === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', trim($value), $c)) {
            $cookies[$c[1]] = $c[2];
        }
    }
    return ['status' => $status, 'headers' => $parsed, 'body' => (string) $body, 'cookies' => $cookies];
}

/** ดึง CSRF token จากหน้าที่มีฟอร์ม */
function t_http_csrf(string $path, array &$cookies): string
{
    $r = t_http('GET', $path, [], $cookies);
    assert_true(preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m) === 1, "ไม่พบ CSRF token ที่ $path (status {$r['status']})");
    return $m[1];
}

function t_http_admin(string $email, string $password = 'password-1234'): int
{
    return Database::insert(
        "INSERT INTO users (name, email, password_hash, role) VALUES ('http-test', :e, :h, 'admin')",
        ['e' => $email, 'h' => password_hash($password, PASSWORD_DEFAULT)]
    );
}

// ---------- ล็อกอินแอดมินต้องจำกัดการเดารหัสผ่าน ----------

function test_http_admin_login_is_throttled_after_repeated_failures(): void
{
    $email = 'http-throttle@test.local';
    Database::run("DELETE FROM activity_logs WHERE action = 'login_failed'");
    $user = t_http_admin($email);
    try {
        $cookies = [];
        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $token = t_http_csrf('/admin/login', $cookies);
            $statuses[] = t_http('POST', '/admin/login', ['form' => ['_csrf' => $token, 'email' => $email, 'password' => 'wrong-' . $i]], $cookies)['status'];
        }
        assert_same(array_fill(0, 10, 200), array_slice($statuses, 0, 10), 'ผิด 10 ครั้งแรกยังตอบปกติ: ' . json_encode($statuses));
        assert_same([429, 429], array_slice($statuses, 10), 'ครั้งที่ 11 เป็นต้นไปถูกระงับ: ' . json_encode($statuses));

        // ถูกระงับแล้ว รหัสผ่านที่ถูกก็เข้าไม่ได้ (ไม่งั้นการเดายังได้ผลทางอ้อม)
        $token = t_http_csrf('/admin/login', $cookies);
        $ok = t_http('POST', '/admin/login', ['form' => ['_csrf' => $token, 'email' => $email, 'password' => 'password-1234']], $cookies);
        assert_same(429, $ok['status'], 'รหัสถูกแต่อยู่ในช่วงระงับ');
        assert_true(!isset($ok['headers']['location']), 'ต้องไม่ถูกส่งต่อเข้าหลังบ้าน');
    } finally {
        Database::run('DELETE FROM users WHERE id = :id', ['id' => $user]);
        Database::run("DELETE FROM activity_logs WHERE action = 'login_failed'");
    }
}

function test_http_admin_login_still_works_with_correct_password(): void
{
    $email = 'http-login@test.local';
    Database::run("DELETE FROM activity_logs WHERE action = 'login_failed'");
    $user = t_http_admin($email);
    try {
        $cookies = [];
        $token = t_http_csrf('/admin/login', $cookies);
        $r = t_http('POST', '/admin/login', ['form' => ['_csrf' => $token, 'email' => $email, 'password' => 'password-1234']], $cookies);
        assert_same(302, $r['status']);
        assert_true(str_ends_with($r['headers']['location'][0], '/admin'), 'เข้าหลังบ้านได้');
        assert_same(200, t_http('GET', '/admin', [], $cookies)['status']);
    } finally {
        Database::run('DELETE FROM users WHERE id = :id', ['id' => $user]);
    }
}
