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

    t_fake_line();   // php -S ตัวนี้สืบทอด LINE_API_BASE ไปชี้ LINE ปลอม ไม่มีทางยิง LINE จริงจากเทสต์
    $log = tempnam(sys_get_temp_dir(), 'carrent-http');
    $GLOBALS['t_http_log'] = $log;
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", 'index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'w']], $pipes, APP_ROOT);
    register_shutdown_function(static function () use ($proc, $log): void {
        proc_terminate($proc);
        @unlink($log);
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

/** บรรทัด "PHP Warning/Notice/Deprecated/Fatal" ที่เซิร์ฟเวอร์ทดสอบเขียนลง error log ตั้งแต่เริ่มรัน — ใช้จับตัวแปรไม่มีค่า/พารามิเตอร์ผิดที่หน้าเว็บไม่แสดง */
function t_http_php_problems(): array
{
    $log = (string) ($GLOBALS['t_http_log'] ?? '');
    $lines = $log !== '' && is_file($log) ? (file($log, FILE_IGNORE_NEW_LINES) ?: []) : [];
    return array_values(array_filter($lines, static fn(string $l): bool => (bool) preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $l)));
}

/**
 * @param array $opt form(array) cookies(array, ส่งต่อเข้ามา/ออกไปทาง &) headers(string[])
 * @return array{status:int,headers:array,body:string,cookies:array}
 */
function t_http(string $method, string $path, array $opt = [], array &$cookies = []): array
{
    $headers = $opt['headers'] ?? [];
    $content = '';
    if (isset($opt['files']) || isset($opt['file_list'])) {
        // multipart/form-data: ช่องฟอร์มปกติ + ไฟล์ ['ชื่อช่อง' => [path, ชื่อไฟล์, mime]]
        $boundary = '----carrenttest' . bin2hex(random_bytes(8));
        foreach ($opt['form'] ?? [] as $name => $value) {
            $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        // file_list: หลายไฟล์ในช่องเดียว (name="images[]") — [[ชื่อช่อง, path, ชื่อไฟล์, mime], ...]
        foreach ($opt['file_list'] ?? [] as [$name, $filePath, $filename, $mime]) {
            $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"$filename\"\r\nContent-Type: $mime\r\n\r\n" . file_get_contents($filePath) . "\r\n";
        }
        foreach ($opt['files'] ?? [] as $name => [$filePath, $filename, $mime]) {   // ($filePath ไม่ใช่ $path — ห้ามทับ URL ที่จะยิง)
            $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"$filename\"\r\nContent-Type: $mime\r\n\r\n" . file_get_contents($filePath) . "\r\n";
        }
        $content .= "--$boundary--\r\n";
        $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
    } elseif (isset($opt['form'])) {
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

// ---------- ค่าที่ผู้ใช้ส่งมาเป็นรูปแบบแปลก (อาร์เรย์แทนข้อความ) ต้องไม่ทำให้ระบบ 500 ----------

function test_http_array_valued_parameters_never_cause_a_server_error(): void
{
    $car = Database::insert("INSERT INTO cars (plate, brand, model, car_type_code, price_day, status) VALUES (:p, 'T', 'T', 'sedan', 1000, 'available')", ['p' => 'H-' . bin2hex(random_bytes(4))]);
    $admin = t_http_admin('http-array@test.local');
    try {
        $cookies = [];
        $token = t_http_csrf('/admin/login', $cookies);
        $login = t_http('POST', '/admin/login', ['form' => ['_csrf' => $token, 'email' => 'http-array@test.local', 'password' => 'password-1234']], $cookies);
        assert_same(302, $login['status'], 'ล็อกอินเพื่อทดสอบหน้าหลังบ้าน');
        $adminToken = t_http_csrf('/admin/cars/create', $cookies);

        $get = [
            '/?pickup[]=1&return[]=2',
            '/?pickup=2030-01-01T10:00&return[]=2',
            '/?type[]=x',
            "/cars/$car?pickup[]=1",
            "/cars/$car?pickup=2030-01-01T10:00&return=2030-01-02T10:00&km[]=5&addons=x",
            "/booking?car[]=1",
            "/booking?car=$car&pickup[]=1&return=x&km[]=1",
            '/admin/bookings?status[]=x&q[]=y',
            '/admin/payments?status[]=x',
        ];
        foreach ($get as $path) {
            $status = t_http('GET', $path, [], $cookies)['status'];
            assert_true($status < 500, "GET $path ได้ $status");
        }

        $post = [
            ['/admin/login', ['_csrf' => ['x'], 'email' => 'a']],
            ['/admin/login', ['_csrf' => $token, 'email' => ['x'], 'password' => ['y']]],
            ['/track', ['_csrf' => $token, 'booking_no' => ['x'], 'phone' => ['y']]],
            ['/booking', ['_csrf' => $token, 'car_id' => ['1'], 'name' => ['x'], 'phone' => ['y'], 'pickup' => ['z'], 'accept' => ['1']]],
            ['/admin/bookings', ['_csrf' => $adminToken, 'car_id' => [$car], 'name' => ['x'], 'phone' => ['y'], 'source' => ['phone'], 'pickup_at' => ['a'], 'return_at' => ['b'], 'addons' => 'x']],
            ['/admin/cars', ['_csrf' => $adminToken, 'plate' => ['x'], 'brand' => ['y'], 'price_day' => ['1']]],
        ];
        foreach ($post as [$path, $form]) {
            $status = t_http('POST', $path, ['form' => $form], $cookies)['status'];
            assert_true($status < 500, "POST $path ได้ $status");
        }
    } finally {
        Database::run('DELETE FROM users WHERE id = :id', ['id' => $admin]);
        Database::run('DELETE FROM activity_logs WHERE action IN (\'login_failed\', \'track_failed\')');
        Database::run('DELETE FROM cars WHERE id = :id', ['id' => $car]);
    }
}

// ---------- วิธีเรียก HTTP ----------

function test_http_head_request_is_served_like_get_without_body(): void
{
    $get = t_http('GET', '/');
    $head = t_http('HEAD', '/');
    assert_same(200, $get['status']);
    assert_same(200, $head['status'], 'HEAD / (ตัวเช็กเว็บล่มใช้) ต้องไม่ใช่ 405');
    assert_same('', $head['body'], 'HEAD ไม่ส่งเนื้อหา');
}

function test_http_wrong_method_gets_405_with_allow_header(): void
{
    $r = t_http('POST', '/track/view', ['form' => []]);
    assert_same(405, $r['status']);
    assert_true(isset($r['headers']['allow']) && strpos($r['headers']['allow'][0], 'GET') !== false, 'ต้องบอกวิธีที่รองรับ');
    assert_same(404, t_http('GET', '/no-such-page')['status']);
}
