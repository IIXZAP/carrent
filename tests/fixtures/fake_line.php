<?php
/**
 * LINE Messaging API ปลอมสำหรับเทสต์ (php -S ... tests/fixtures/fake_line.php)
 * พฤติกรรมเลือกจาก token ใน Authorization: มีคำว่า bad→401, busy→429, boom→500, dup→409, slow→หน่วง 2 วิ แล้วตอบ 200, ไม่มี→200
 * บันทึกทุกคำขอเป็น JSON 1 บรรทัดลงไฟล์ใน env FAKE_LINE_LOG
 */
$headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);
$auth = (string) ($headers['authorization'] ?? '');
$entry = [
    'method'    => $_SERVER['REQUEST_METHOD'],
    'path'      => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'auth'      => $auth,
    'retry_key' => $headers['x-line-retry-key'] ?? null,
    'body'      => json_decode((string) file_get_contents('php://input'), true),
];
$log = getenv('FAKE_LINE_LOG');
if ($log) {
    file_put_contents($log, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
header('Content-Type: application/json');
if ($entry['path'] !== '/v2/bot/message/push' || $entry['method'] !== 'POST') {
    http_response_code(404);
    echo '{"message":"not found"}';
    return;
}
foreach (['bad' => 401, 'busy' => 429, 'boom' => 500, 'dup' => 409] as $word => $code) {
    if (strpos($auth, $word) !== false) {
        http_response_code($code);
        echo json_encode(['message' => "fake $word"]);
        return;
    }
}
if (strpos($auth, 'slow') !== false) {
    sleep(2);
}
echo '{}';
