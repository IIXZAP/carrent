<?php
use App\Core\Database;
use App\Models\Notification;
use App\Models\Setting;
use App\Services\LineClient;
use App\Services\NotificationService;
use App\Services\WebBookingService;

/**
 * แจ้งเตือนร้านทาง LINE (ขั้น 7) — ใช้ LINE ปลอม (tests/fixtures/fake_line.php) ที่ตอบตาม token
 * ชื่อเทสต์ที่ขึ้นต้น test_db_ รันใน transaction แล้ว rollback
 */

const T_TOK_OK   = 'tok-ok-0123456789abcdef0123';
const T_TOK_BAD  = 'tok-bad-0123456789abcdef012';
const T_TOK_BUSY = 'tok-busy-0123456789abcdef01';
const T_TOK_BOOM = 'tok-boom-0123456789abcdef01';
const T_TOK_DUP  = 'tok-dup-0123456789abcdef012';
const T_TOK_SLOW = 'tok-slow-0123456789abcdef01';
const T_LINE_U1  = 'U0123456789abcdef0123456789abcdef';
const T_LINE_U2  = 'Ufedcba9876543210fedcba9876543210';

/** เปิด LINE ปลอมครั้งเดียวต่อการรัน แล้วชี้ config ของ process นี้ (และ php -S ที่เปิดทีหลัง) ไปที่มัน */
function t_fake_line(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    $log = tempnam(sys_get_temp_dir(), 'fakeline');
    putenv('FAKE_LINE_LOG=' . $log);
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/fake_line.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, __DIR__);
    register_shutdown_function(static function () use ($proc, $log): void {
        proc_terminate($proc);
        @unlink($log);
    });
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($fp !== false) {
            fclose($fp);
            $GLOBALS['t_fake_line_log'] = $log;
            putenv('LINE_API_BASE=http://127.0.0.1:' . $port);
            $GLOBALS['config']['line_api_base'] = 'http://127.0.0.1:' . $port;
            return $base = 'http://127.0.0.1:' . $port;
        }
        usleep(100000);
    }
    throw new RuntimeException('เปิด LINE ปลอมไม่ได้');
}

/** @return array[] คำขอที่ LINE ปลอมได้รับตั้งแต่รีเซ็ตครั้งล่าสุด */
function t_fake_line_requests(): array
{
    t_fake_line();
    $lines = is_file($GLOBALS['t_fake_line_log']) ? (file($GLOBALS['t_fake_line_log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    return array_map(static fn(string $l): array => json_decode($l, true), $lines);
}

function t_fake_line_reset(): void
{
    t_fake_line();
    file_put_contents($GLOBALS['t_fake_line_log'], '');
}

/** เปิดแจ้งเตือนด้วย token ที่เลือก (ถูก rollback พร้อมเทสต์) และเคลียร์คิวเดิม */
function t_notify_on(string $token = T_TOK_OK, array $recipients = [T_LINE_U1]): void
{
    t_fake_line();
    t_fake_line_reset();
    NotificationService::$autoFlush = false;
    Database::run('DELETE FROM notifications');
    Setting::set('notify_line_enabled', '1');
    Setting::set('line_channel_token', $token);
    Setting::set('line_admin_user_id', implode("\n", $recipients));
    foreach (['notify_new_booking', 'notify_new_slip', 'notify_signed'] as $k) {
        Setting::set($k, '1');
    }
}

function t_notify_row(int $id): array
{
    return Notification::find($id) ?? [];
}

function t_notify_all(): array
{
    return Database::all('SELECT * FROM notifications ORDER BY id');
}

function t_notify_booking(): array
{
    t_booking_settings();
    $car = t_make_car();
    return \App\Models\Booking::find(t_book($car, '2030-06-10 10:00:00', '2030-06-13 10:00:00', t_contact('0812223333', "สมชาย\nบรรทัดปลอม")));
}

// ---------- ผู้รับและค่าตั้ง ----------

function test_line_validTarget_accepts_only_real_line_ids(): void
{
    assert_true(LineClient::validTarget(T_LINE_U1));
    assert_true(LineClient::validTarget('C' . str_repeat('a', 32)));
    assert_true(LineClient::validTarget('R' . str_repeat('0', 32)));
    foreach (['', 'U123', 'X' . str_repeat('a', 32), 'U' . str_repeat('A', 32), 'U' . str_repeat('a', 33), T_LINE_U1 . "\n", ' ' . T_LINE_U1] as $bad) {
        assert_true(!LineClient::validTarget($bad), 'ต้องไม่ผ่าน: ' . json_encode($bad));
    }
}

function test_db_recipients_parse_dedupe_and_cap(): void
{
    $many = [];
    for ($i = 0; $i < 14; $i++) {
        $many[] = 'U' . str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
    }
    $raw = T_LINE_U1 . ",\n" . T_LINE_U1 . ' ; garbage ' . T_LINE_U2 . ' ' . implode(' ', $many);
    $r = NotificationService::recipients($raw);
    assert_same(T_LINE_U1, $r[0]);
    assert_same(T_LINE_U2, $r[1]);
    assert_same(NotificationService::RECIPIENT_LIMIT, count($r), 'จำกัดจำนวนผู้รับ');
    assert_same(count($r), count(array_unique($r)), 'ไม่ซ้ำ');
}

// ---------- เข้าคิว ----------

function test_db_new_booking_queues_one_row_per_recipient_with_clean_text(): void
{
    t_notify_on(T_TOK_OK, [T_LINE_U1, T_LINE_U2]);
    $b = t_notify_booking();
    $car = \App\Models\Car::find((int) $b['car_id']);
    NotificationService::newBooking($b, $car);
    $rows = t_notify_all();
    assert_same(2, count($rows));
    assert_same([T_LINE_U1, T_LINE_U2], array_column($rows, 'recipient'));
    assert_same('queued', $rows[0]['status']);
    $text = $rows[0]['message'];
    assert_true(strpos($text, $b['booking_no']) !== false, 'มีเลขจอง');
    assert_true(strpos($text, '0812223333') !== false, 'มีเบอร์ลูกค้า');
    assert_true(strpos($text, '/admin/bookings/' . $b['id']) !== false, 'มีลิงก์เปิดงาน');
    assert_true(strpos($text, "สมชาย บรรทัดปลอม") !== false && strpos($text, "สมชาย\n") === false, 'ชื่อที่มีขึ้นบรรทัดใหม่ถูกยุบเป็นบรรทัดเดียว');
    assert_same(36, strlen($rows[0]['retry_key']));
    assert_true($rows[0]['retry_key'] !== $rows[1]['retry_key'], 'retry key ไม่ซ้ำระหว่างแถว');
}

function test_db_same_event_is_not_queued_twice(): void
{
    t_notify_on();
    $b = t_notify_booking();
    $car = \App\Models\Car::find((int) $b['car_id']);
    NotificationService::newBooking($b, $car);
    NotificationService::newBooking($b, $car);
    assert_same(1, count(t_notify_all()), 'เหตุการณ์เดียวกันแจ้งครั้งเดียว');
}

function test_db_nothing_queued_when_disabled_or_unconfigured_or_toggle_off(): void
{
    t_notify_on();
    $b = t_notify_booking();
    $car = \App\Models\Car::find((int) $b['car_id']);

    Setting::set('notify_line_enabled', '0');
    NotificationService::newBooking($b, $car);
    assert_same(0, count(t_notify_all()), 'ปิดอยู่');

    Setting::set('notify_line_enabled', '1');
    Setting::set('line_channel_token', '');
    NotificationService::newBooking($b, $car);
    assert_same(0, count(t_notify_all()), 'ไม่มี token');

    Setting::set('line_channel_token', T_TOK_OK);
    Setting::set('line_admin_user_id', 'not-an-id');
    NotificationService::newBooking($b, $car);
    assert_same(0, count(t_notify_all()), 'ไม่มีผู้รับที่ถูกต้อง');

    Setting::set('line_admin_user_id', T_LINE_U1);
    Setting::set('notify_new_booking', '0');
    NotificationService::newBooking($b, $car);
    assert_same(0, count(t_notify_all()), 'ปิดเฉพาะเหตุการณ์นี้');

    Setting::set('notify_new_booking', '1');
    NotificationService::newBooking($b, $car);
    assert_same(1, count(t_notify_all()), 'เปิดครบแล้วต้องเข้าคิว');
}

function test_db_notification_failure_never_breaks_the_caller(): void
{
    t_notify_on();
    $b = t_notify_booking();
    $car = \App\Models\Car::find((int) $b['car_id']);
    $b['booking_no'] = str_repeat('x', 70000);   // ข้อความยาวเกินคอลัมน์ → INSERT ล้มเหลวจริง
    $prev = ini_set('error_log', '/dev/null');
    try {
        NotificationService::newBooking($b, $car);   // ต้องไม่โยนออกมา
    } finally {
        ini_set('error_log', (string) $prev);
    }
    assert_same(0, count(t_notify_all()), 'ไม่มีแถวค้าง');
}

function test_db_web_booking_service_queues_notification_after_success(): void
{
    t_notify_on();
    t_booking_settings();
    $car = t_make_car();
    $r = WebBookingService::create(t_web_input($car), '203.0.113.9');
    assert_same([], $r['errors']);
    $rows = t_notify_all();
    assert_same(1, count($rows));
    assert_same('bookings', $rows[0]['ref_table']);
    assert_same((int) $r['booking']['id'], (int) $rows[0]['ref_id']);
    // ส่งซ้ำ (กดซ้ำ/ใบเดิม) ไม่แจ้งซ้ำ
    WebBookingService::create(t_web_input($car), '203.0.113.9');
    assert_same(1, count(t_notify_all()), 'จองซ้ำใบเดิมไม่แจ้งซ้ำ');
}

function test_db_slip_submission_queues_notification(): void
{
    t_notify_on();
    $b = t_pay_booking();
    $pid = t_slip($b, 1000.0);
    t_slips_cleanup();
    $rows = t_notify_all();
    assert_same(1, count($rows));
    assert_same('payments', $rows[0]['ref_table']);
    assert_same($pid, (int) $rows[0]['ref_id']);
    assert_true(strpos($rows[0]['message'], '฿1,000') !== false, 'มียอดที่แจ้งโอน');
}

function test_db_signature_queues_notification_once(): void
{
    t_notify_on();
    $b = t_pay_booking();
    $id = t_sign($b);
    t_sig_cleanup();
    $rows = t_notify_all();
    assert_same(1, count($rows));
    assert_same('signatures', $rows[0]['ref_table']);
    assert_same($id, (int) $rows[0]['ref_id']);
    assert_true(strpos($rows[0]['message'], 'สัญญาเช่ารถ') !== false && strpos($rows[0]['message'], '/contract') !== false);
}

function test_db_refused_signature_does_not_notify(): void
{
    t_notify_on();
    $b = t_pay_booking();
    try {
        \App\Services\SignatureService::sign((int) $b['id'], 'contract', t_sig_input($b, 'contract', ['consent' => '']), ['ip' => '192.0.2.1', 'ua' => 'x']);
        throw new RuntimeException('ต้องถูกปฏิเสธ');
    } catch (\App\Services\UserError $e) {
        assert_same(0, count(t_notify_all()), 'เซ็นไม่ผ่านต้องไม่แจ้ง');
    }
}

// ---------- ส่งจริง (ผ่าน LINE ปลอม) ----------

function test_db_flush_sends_push_with_token_target_text_and_retry_key(): void
{
    t_notify_on();
    $id = Notification::queue('line', T_LINE_U1, "ข้อความทดสอบ\nบรรทัดสอง", null, null, 'k1');
    $r = NotificationService::flush(10, 10.0);
    assert_same(['sent' => 1, 'retry' => 0, 'failed' => 0], $r);
    $row = t_notify_row($id);
    assert_same('sent', $row['status']);
    assert_same(1, (int) $row['attempts']);
    assert_true($row['sent_at'] !== null && $row['error'] === null);
    $req = t_fake_line_requests();
    assert_same(1, count($req));
    assert_same('Bearer ' . T_TOK_OK, $req[0]['auth']);
    assert_same($row['retry_key'], $req[0]['retry_key']);
    assert_same(T_LINE_U1, $req[0]['body']['to']);
    assert_same("ข้อความทดสอบ\nบรรทัดสอง", $req[0]['body']['messages'][0]['text']);
    assert_same('text', $req[0]['body']['messages'][0]['type']);
}

function test_db_flush_does_not_resend_sent_rows(): void
{
    t_notify_on();
    Notification::queue('line', T_LINE_U1, 'a', null, null, 'k1');
    NotificationService::flush(10, 10.0);
    $r = NotificationService::flush(10, 10.0);
    assert_same(['sent' => 0, 'retry' => 0, 'failed' => 0], $r);
    assert_same(1, count(t_fake_line_requests()));
}

function test_db_bad_token_fails_permanently_without_leaking_token(): void
{
    t_notify_on(T_TOK_BAD);
    $id = Notification::queue('line', T_LINE_U1, 'a', null, null, 'k1');
    $r = NotificationService::flush(10, 10.0);
    assert_same(['sent' => 0, 'retry' => 0, 'failed' => 1], $r);
    $row = t_notify_row($id);
    assert_same('failed', $row['status']);
    assert_true(strpos((string) $row['error'], '401') !== false, 'บอกสาเหตุ: ' . $row['error']);
    assert_true(strpos((string) $row['error'], T_TOK_BAD) === false, 'error ต้องไม่มี token');
    assert_same(1, count(t_fake_line_requests()), 'ไม่ลองซ้ำเมื่อ token ผิด');
}

function test_db_server_error_retries_with_backoff_then_gives_up(): void
{
    t_notify_on(T_TOK_BOOM);
    $id = Notification::queue('line', T_LINE_U1, 'a', null, null, 'k1');
    $delays = [];
    for ($attempt = 1; $attempt <= NotificationService::MAX_ATTEMPTS; $attempt++) {
        $r = NotificationService::flush(10, 10.0);
        $row = t_notify_row($id);
        assert_same($attempt, (int) $row['attempts']);
        if ($attempt < NotificationService::MAX_ATTEMPTS) {
            assert_same(['sent' => 0, 'retry' => 1, 'failed' => 0], $r, "รอบ $attempt");
            assert_same('queued', $row['status']);
            $delays[] = (int) Database::value('SELECT TIMESTAMPDIFF(SECOND, NOW(), next_try_at) FROM notifications WHERE id = :i', ['i' => $id]);
            // ยังไม่ถึงเวลา — flush ซ้ำทันทีต้องไม่ส่ง
            assert_same(['sent' => 0, 'retry' => 0, 'failed' => 0], NotificationService::flush(10, 10.0), 'ยังไม่ถึงเวลาลองใหม่');
            Database::run('UPDATE notifications SET next_try_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = :i', ['i' => $id]);
        } else {
            assert_same(['sent' => 0, 'retry' => 0, 'failed' => 1], $r, 'ครบจำนวนครั้งแล้วเลิก');
            assert_same('failed', $row['status']);
        }
    }
    assert_true($delays[0] >= 55 && $delays[0] <= 61, 'ครั้งแรกรอ ~1 นาที: ' . $delays[0]);
    assert_true($delays[1] >= 295 && $delays[1] <= 301, 'ครั้งสองรอ ~5 นาที: ' . $delays[1]);
    assert_true($delays[2] >= 1795 && $delays[2] <= 1801, 'ครั้งสามรอ ~30 นาที: ' . $delays[2]);
    assert_true($delays[3] >= 7195 && $delays[3] <= 7201, 'ครั้งสี่รอ ~2 ชม.: ' . $delays[3]);
    $keys = array_unique(array_column(t_fake_line_requests(), 'retry_key'));
    assert_same(1, count($keys), 'ทุกครั้งที่ลองใหม่ใช้ retry key เดิม (LINE จะได้ไม่ส่งซ้ำ)');
}

function test_db_rate_limited_is_retried_not_dropped(): void
{
    t_notify_on(T_TOK_BUSY);
    $id = Notification::queue('line', T_LINE_U1, 'a', null, null, 'k1');
    assert_same(['sent' => 0, 'retry' => 1, 'failed' => 0], NotificationService::flush(10, 10.0));
    assert_same('queued', t_notify_row($id)['status']);
}

function test_db_conflict_with_retry_key_counts_as_delivered(): void
{
    t_notify_on(T_TOK_DUP);
    $id = Notification::queue('line', T_LINE_U1, 'a', null, null, 'k1');
    assert_same(['sent' => 1, 'retry' => 0, 'failed' => 0], NotificationService::flush(10, 10.0), '409 = ส่งสำเร็จไปแล้ว');
    assert_same('sent', t_notify_row($id)['status']);
}

function test_db_unreachable_line_retries(): void
{
    t_notify_on();
    $saved = $GLOBALS['config']['line_api_base'];
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($probe, false);
    fclose($probe);   // พอร์ตนี้ไม่มีใครฟัง
    $GLOBALS['config']['line_api_base'] = 'http://' . $name;
    try {
        $id = Notification::queue('line', T_LINE_U1, 'a', null, null, 'k1');
        assert_same(['sent' => 0, 'retry' => 1, 'failed' => 0], NotificationService::flush(10, 10.0));
        $row = t_notify_row($id);
        assert_same('queued', $row['status']);
        assert_true(strpos((string) $row['error'], 'เชื่อมต่อ LINE ไม่ได้') !== false, (string) $row['error']);
    } finally {
        $GLOBALS['config']['line_api_base'] = $saved;
    }
}

function test_db_flush_stops_when_time_budget_is_spent(): void
{
    t_notify_on(T_TOK_SLOW);
    for ($i = 1; $i <= 4; $i++) {
        Notification::queue('line', T_LINE_U1, "m$i", null, null, "k$i");
    }
    $t = microtime(true);
    $r = NotificationService::flush(10, 1.0);   // แต่ละรายการช้า 2 วิ → ส่งได้ 1 รายการแล้วหยุด
    assert_same(1, $r['sent'], 'ส่งได้แค่ 1 ก่อนหมดเวลา');
    assert_true(microtime(true) - $t < 4.5, 'ต้องไม่ลากต่อจนครบทุกแถว');
    assert_same(3, Notification::countByStatus()['queued'], 'ที่เหลือรอรอบหน้า');
}

function test_db_disabled_after_queueing_marks_failed_not_sent(): void
{
    t_notify_on();
    $id = Notification::queue('line', T_LINE_U1, 'a', null, null, 'k1');
    Setting::set('notify_line_enabled', '0');
    $r = NotificationService::flush(10, 10.0);
    assert_same(['sent' => 0, 'retry' => 0, 'failed' => 1], $r);
    assert_same(0, count(t_fake_line_requests()), 'ปิดอยู่ต้องไม่ยิง LINE');
    // เปิดใหม่ แล้วกด "ส่งใหม่" ได้
    Setting::set('notify_line_enabled', '1');
    assert_true(Notification::requeueFailed($id));
    assert_same(1, NotificationService::flush(10, 10.0)['sent']);
}

// ---------- คิวและการแข่งกัน ----------

function test_db_claim_is_exclusive(): void
{
    t_notify_on();
    $id = Notification::queue('line', T_LINE_U1, 'a', null, null, 'k1');
    assert_true(Notification::claim($id), 'คนแรกได้');
    assert_true(!Notification::claim($id), 'คนที่สองต้องไม่ได้');
    assert_same('sending', t_notify_row($id)['status']);
    assert_true(!Notification::requeueFailed($id), 'ส่งใหม่ได้เฉพาะแถว failed');
}

function test_db_stuck_sending_rows_are_recovered_only_when_old(): void
{
    t_notify_on();
    $fresh = Notification::queue('line', T_LINE_U1, 'a', null, null, 'k1');
    $old = Notification::queue('line', T_LINE_U1, 'b', null, null, 'k2');
    Notification::claim($fresh);
    Notification::claim($old);
    Database::run('UPDATE notifications SET claimed_at = DATE_SUB(NOW(), INTERVAL 11 MINUTE) WHERE id = :i', ['i' => $old]);
    assert_same(1, Notification::recoverStale(10));
    assert_same('sending', t_notify_row($fresh)['status'], 'แถวที่เพิ่งจองห้ามถูกแย่ง');
    assert_same('queued', t_notify_row($old)['status']);
}

function test_db_purge_removes_only_old_finished_rows(): void
{
    t_notify_on();
    $ids = [];
    foreach (['sent', 'failed', 'queued'] as $st) {
        foreach ([1, 200] as $daysOld) {
            $id = Notification::queue('line', T_LINE_U1, "$st$daysOld", null, null, "$st$daysOld");
            Database::run('UPDATE notifications SET status = :s, created_at = DATE_SUB(NOW(), INTERVAL :d DAY) WHERE id = :i', ['s' => $st, 'd' => $daysOld, 'i' => $id]);
            $ids["$st$daysOld"] = $id;
        }
    }
    assert_same(2, Notification::purge(60, 180), 'ลบ sent เก่า และ failed เก่า');
    $left = array_column(t_notify_all(), 'message');
    sort($left);
    assert_same(['failed1', 'queued1', 'queued200', 'sent1'], $left, 'คิวที่ยังรอส่งห้ามถูกลบ ไม่ว่าเก่าแค่ไหน');
}

function test_db_cron_runs_recover_flush_and_purge(): void
{
    t_notify_on();
    Notification::queue('line', T_LINE_U1, 'a', null, null, 'k1');
    $lines = NotificationService::cron();
    assert_same(1, count($lines));
    assert_true(strpos($lines[0], 'sent: 1') !== false, $lines[0]);
    assert_same([], NotificationService::cron(), 'ไม่มีอะไรทำ = เงียบ');
}

function test_db_sendTest_reports_ok_and_failure(): void
{
    t_notify_on();
    [$ok, $err] = NotificationService::sendTest(T_LINE_U1, null);
    assert_true($ok && $err === null);
    assert_same(1, count(t_fake_line_requests()));
    Setting::set('line_channel_token', T_TOK_BAD);
    [$ok, $err] = NotificationService::sendTest(T_LINE_U1, null);
    assert_true(!$ok && strpos((string) $err, '401') !== false);
    assert_same(0, count(t_notify_all()), 'ปุ่มทดสอบไม่ผ่านคิว');
}

function test_line_push_refuses_bad_input_without_calling_line(): void
{
    t_fake_line();
    t_fake_line_reset();
    assert_same([false, 'ยังไม่ได้ใส่ Channel access token', false], LineClient::push('', T_LINE_U1, 'x'));
    [$ok, $err, $retry] = LineClient::push(T_TOK_OK, "U1\r\nX-Evil: 1", 'x');
    assert_true(!$ok && !$retry, 'รหัสผู้รับผิดรูปห้ามส่ง (กัน header injection)');
    assert_same(0, count(t_fake_line_requests()));
}

function test_line_push_truncates_overlong_text(): void
{
    t_fake_line();
    t_fake_line_reset();
    [$ok] = LineClient::push(T_TOK_OK, T_LINE_U1, str_repeat('ก', 9000));
    assert_true($ok);
    $text = t_fake_line_requests()[0]['body']['messages'][0]['text'];
    assert_same(LineClient::MAX_TEXT, mb_strlen($text));
}

// ---------- ผ่าน HTTP จริง: หน้าตั้งค่า LINE และจองจริงแล้ว LINE ได้รับ ----------

/** ผู้ใช้หลังบ้านสำหรับเทสต์ HTTP + ค่าเดิมของ settings ที่เทสต์จะแตะ (คืนค่าใน finally) */
function t_http_line_fixture(string $role = 'admin'): array
{
    $email = 'http-line' . bin2hex(random_bytes(3)) . '@test.local';
    $id = Database::insert("INSERT INTO users (name, email, password_hash, role) VALUES ('http-line', :e, :h, :r)", ['e' => $email, 'h' => password_hash('password-1234', PASSWORD_DEFAULT), 'r' => $role]);
    $keys = ['notify_line_enabled', 'line_channel_token', 'line_admin_user_id', 'notify_new_booking', 'notify_new_slip', 'notify_signed'];
    $saved = [];
    foreach ($keys as $k) {
        $saved[$k] = Setting::get($k);
    }
    t_fake_line_reset();
    Database::run('DELETE FROM notifications');
    return ['email' => $email, 'admin' => $id, 'saved' => $saved];
}

function t_http_line_cleanup(array $fx): void
{
    foreach ($fx['saved'] as $k => $v) {
        Setting::set($k, (string) $v);
    }
    Database::run('DELETE FROM notifications');
    Database::run('DELETE FROM users WHERE id = :u', ['u' => $fx['admin']]);
    Database::run("DELETE FROM activity_logs WHERE action IN ('login', 'login_failed', 'line_test', 'line_settings')");
}

function t_http_line_form(string $token, array $over = []): array
{
    return $over + [
        'line_channel_token' => T_TOK_OK, 'line_admin_user_id' => T_LINE_U1,
        'notify_line_enabled' => '1', 'notify_new_booking' => '1', 'notify_new_slip' => '1', 'notify_signed' => '1', '_csrf' => $token,
    ];
}

function test_http_line_pages_are_owner_only(): void
{
    $fx = t_http_line_fixture('staff');
    try {
        $anon = [];
        foreach (['/admin/line'] as $path) {
            $r = t_http('GET', $path, [], $anon);
            assert_same(302, $r['status'], 'ยังไม่ล็อกอินต้องถูกส่งไปหน้าล็อกอิน');
        }
        $cookies = [];
        t_http_login($fx, $cookies);
        assert_same(403, t_http('GET', '/admin/line', [], $cookies)['status'], 'พนักงานเปิดหน้านี้ไม่ได้');
        $token = t_http_csrf('/admin', $cookies);
        foreach (['/admin/line', '/admin/line/test', '/admin/line/flush', '/admin/line/1/retry'] as $path) {
            assert_same(403, t_http('POST', $path, ['form' => t_http_line_form($token)], $cookies)['status'], "พนักงาน POST $path ต้องถูกปฏิเสธ");
        }
        assert_same($fx['saved']['line_channel_token'], Setting::get('line_channel_token'), 'พนักงานเปลี่ยน token ไม่ได้');
        assert_same([], t_http_php_problems());
    } finally {
        t_http_line_cleanup($fx);
    }
}

function test_http_line_settings_save_masks_token_and_validates(): void
{
    $fx = t_http_line_fixture();
    try {
        $cookies = [];
        t_http_login($fx, $cookies);
        $token = t_http_csrf('/admin/line', $cookies);

        // CSRF ไม่ถูก → ไม่บันทึก
        $r = t_http('POST', '/admin/line', ['form' => t_http_line_form('wrong')], $cookies);
        assert_same(419, $r['status']);

        // บันทึกปกติ
        $r = t_http('POST', '/admin/line', ['form' => t_http_line_form($token)], $cookies);
        assert_same(302, $r['status']);
        Setting::set('x_probe', '1');   // ล้าง cache ของ Setting ใน process นี้
        assert_same(T_TOK_OK, Setting::get('line_channel_token'));
        assert_same('1', Setting::get('notify_line_enabled'));
        assert_same(T_LINE_U1, Setting::get('line_admin_user_id'));

        // หน้าเว็บต้องไม่โชว์ token (เห็นแค่ 4 ตัวท้าย)
        $page = t_http('GET', '/admin/line', [], $cookies);
        assert_same(200, $page['status']);
        assert_true(strpos($page['body'], T_TOK_OK) === false, 'token ต้องไม่โผล่ในหน้าเว็บ');
        assert_true(strpos($page['body'], substr(T_TOK_OK, -4)) !== false, 'แสดง 4 ตัวท้ายให้รู้ว่าตั้งไว้');

        // เว้น token ว่าง = คงค่าเดิม
        $r = t_http('POST', '/admin/line', ['form' => t_http_line_form($token, ['line_channel_token' => '', 'notify_signed' => null])], $cookies);
        assert_same(302, $r['status']);
        Setting::set('x_probe', '2');
        assert_same(T_TOK_OK, Setting::get('line_channel_token'), 'ไม่ใส่ token ใหม่ต้องคงของเดิม');

        // ค่าผิด: รหัสผู้รับไม่ถูกต้อง / เปิดโดยไม่มีผู้รับ → ไม่บันทึก แจ้งเหตุผล
        $before = Setting::get('line_admin_user_id');
        $bad = t_http('POST', '/admin/line', ['form' => t_http_line_form($token, ['line_admin_user_id' => 'U123'])], $cookies);
        assert_same(200, $bad['status']);
        assert_true(strpos($bad['body'], 'รหัสผู้รับไม่ถูกต้อง') !== false);
        $none = t_http('POST', '/admin/line', ['form' => t_http_line_form($token, ['line_admin_user_id' => ''])], $cookies);
        assert_true(strpos($none['body'], 'อย่างน้อย 1 รายการ') !== false);
        $badTok = t_http('POST', '/admin/line', ['form' => t_http_line_form($token, ['line_channel_token' => "short token"])], $cookies);
        assert_true(strpos($badTok['body'], 'Channel access token ต้องเป็น') !== false);
        Setting::set('x_probe', '3');
        assert_same($before, Setting::get('line_admin_user_id'), 'ค่าผิดต้องไม่ถูกบันทึก');
        assert_same(T_TOK_OK, Setting::get('line_channel_token'));

        // ลบ token แล้วเปิดไม่ได้
        $r = t_http('POST', '/admin/line', ['form' => t_http_line_form($token, ['line_channel_token' => '', 'clear_token' => '1'])], $cookies);
        assert_same(200, $r['status'], 'ล้าง token แต่ยังติ๊กเปิด → ต้องแจ้งผิดพลาด');
        assert_same([], t_http_php_problems());
    } finally {
        Database::run("DELETE FROM settings WHERE skey = 'x_probe'");
        t_http_line_cleanup($fx);
    }
}

function test_http_line_test_button_retry_and_flush(): void
{
    $fx = t_http_line_fixture();
    try {
        $cookies = [];
        t_http_login($fx, $cookies);
        $token = t_http_csrf('/admin/line', $cookies);
        t_http('POST', '/admin/line', ['form' => t_http_line_form($token)], $cookies);

        $r = t_http('POST', '/admin/line/test', ['form' => ['_csrf' => $token]], $cookies);
        assert_same(302, $r['status']);
        $req = t_fake_line_requests();
        assert_same(1, count($req), 'ปุ่มทดสอบยิง LINE 1 ครั้ง');
        assert_true(strpos($req[0]['body']['messages'][0]['text'], 'ทดสอบ') !== false);

        // แถวที่ไม่สำเร็จ → ปุ่ม "ส่งใหม่" ส่งซ้ำได้ แถวอื่นกดไม่ได้
        t_fake_line_reset();
        $failed = Notification::queue('line', T_LINE_U1, 'ข้อความที่ค้าง', null, null, 'http-k1');
        Database::run("UPDATE notifications SET status = 'failed', attempts = 5, error = 'x' WHERE id = :i", ['i' => $failed]);
        $page = t_http('GET', '/admin/line', [], $cookies);
        assert_true(strpos($page['body'], "/admin/line/$failed/retry") !== false, 'แถว failed มีปุ่มส่งใหม่');
        t_http('POST', "/admin/line/$failed/retry", ['form' => ['_csrf' => $token]], $cookies);
        assert_same('sent', Notification::find($failed)['status']);
        assert_same(1, count(t_fake_line_requests()));
        t_http('POST', "/admin/line/$failed/retry", ['form' => ['_csrf' => $token]], $cookies);
        assert_same(1, count(t_fake_line_requests()), 'กดส่งใหม่ซ้ำกับแถวที่ส่งแล้วต้องไม่ส่งเบิ้ล');

        // ปุ่มส่งที่ค้างในคิว
        $q = Notification::queue('line', T_LINE_U1, 'ค้างในคิว', null, null, 'http-k2');
        t_http('POST', '/admin/line/flush', ['form' => ['_csrf' => $token]], $cookies);
        assert_same('sent', Notification::find($q)['status']);
        assert_same([], t_http_php_problems());
    } finally {
        t_http_line_cleanup($fx);
    }
}

function test_http_web_booking_notifies_shop_on_line(): void
{
    $fx = t_http_line_fixture();
    t_booking_settings_committed();
    $car = t_make_car();
    Database::run('UPDATE cars SET price_day = 1000, booking_deposit = 1000, security_deposit = 3000 WHERE id = :id', ['id' => $car]);
    $phone = '0899990' . random_int(100, 999);
    Setting::set('notify_line_enabled', '1');
    Setting::set('line_channel_token', T_TOK_OK);
    Setting::set('line_admin_user_id', T_LINE_U1);
    foreach (['notify_new_booking', 'notify_new_slip', 'notify_signed'] as $k) {
        Setting::set($k, '1');
    }
    try {
        $cookies = [];
        $token = t_http_csrf('/booking?car=' . $car . '&pickup=2031-09-10T10:00&return=2031-09-12T10:00', $cookies);
        $form = t_web_input($car, ['phone' => $phone, 'name' => 'ลูกค้า ไลน์', 'pickup' => '2031-09-10T10:00', 'return' => '2031-09-12T10:00']) + ['_csrf' => $token];
        $form['accept'] = '1';
        unset($form['addons']);
        $r = t_http('POST', '/booking', ['form' => $form], $cookies);
        assert_same(302, $r['status'], 'จองสำเร็จต้อง redirect: ' . substr($r['body'], 0, 200));

        // ส่งหลังตอบหน้าเว็บ — รอได้ไม่เกิน ~8 วินาที
        $req = [];
        for ($i = 0; $i < 80 && count($req) < 1; $i++) {
            usleep(100000);
            $req = t_fake_line_requests();
        }
        assert_same(1, count($req), 'LINE ร้านต้องได้รับ 1 ข้อความ');
        $text = $req[0]['body']['messages'][0]['text'];
        assert_same(T_LINE_U1, $req[0]['body']['to']);
        assert_true(strpos($text, 'จองใหม่ BK') !== false && strpos($text, $phone) !== false, $text);
        usleep(300000);
        assert_same('sent', Database::one('SELECT status FROM notifications ORDER BY id DESC LIMIT 1')['status']);
        assert_same([], t_http_php_problems());
    } finally {
        Database::run('DELETE FROM payments WHERE booking_id IN (SELECT id FROM bookings WHERE contact_phone = :p)', ['p' => $phone]);
        Database::run('DELETE FROM booking_addons WHERE booking_id IN (SELECT id FROM bookings WHERE contact_phone = :p)', ['p' => $phone]);
        Database::run('DELETE FROM bookings WHERE contact_phone = :p', ['p' => $phone]);
        Database::run('DELETE FROM customers WHERE phone = :p', ['p' => $phone]);
        Database::run('DELETE FROM cars WHERE id = :c', ['c' => $car]);
        Database::run("DELETE FROM activity_logs WHERE action = 'booking_created' AND detail LIKE 'BK%' AND ref_id NOT IN (SELECT id FROM bookings)");
        t_http_line_cleanup($fx);
    }
}

function test_http_web_booking_still_succeeds_when_line_is_down(): void
{
    $fx = t_http_line_fixture();
    t_booking_settings_committed();
    $car = t_make_car();
    Database::run('UPDATE cars SET price_day = 1000, booking_deposit = 1000, security_deposit = 3000 WHERE id = :id', ['id' => $car]);
    $phone = '0899991' . random_int(100, 999);
    Setting::set('notify_line_enabled', '1');
    Setting::set('line_channel_token', T_TOK_BOOM);   // LINE ตอบ 500 ทุกครั้ง
    Setting::set('line_admin_user_id', T_LINE_U1);
    Setting::set('notify_new_booking', '1');
    try {
        $cookies = [];
        $token = t_http_csrf('/booking?car=' . $car . '&pickup=2031-10-10T10:00&return=2031-10-12T10:00', $cookies);
        $form = t_web_input($car, ['phone' => $phone, 'pickup' => '2031-10-10T10:00', 'return' => '2031-10-12T10:00']) + ['_csrf' => $token];
        unset($form['addons']);
        $r = t_http('POST', '/booking', ['form' => $form], $cookies);
        assert_same(302, $r['status'], 'LINE ล่มต้องไม่ทำให้การจองล้ม');
        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => $phone]));
        $row = [];
        for ($i = 0; $i < 80 && ($row['status'] ?? '') !== 'queued'; $i++) {
            usleep(100000);
            $row = Database::one('SELECT status, attempts, next_try_at FROM notifications ORDER BY id DESC LIMIT 1') ?? [];
            if (($row['attempts'] ?? 0) < 1) {
                $row = [];
            }
        }
        assert_same('queued', $row['status'] ?? null, 'ส่งไม่ผ่านต้องกลับไปรอลองใหม่ ไม่หาย');
        assert_same(1, (int) $row['attempts']);
        assert_true($row['next_try_at'] !== null);
        assert_same([], t_http_php_problems());
    } finally {
        Database::run('DELETE FROM booking_addons WHERE booking_id IN (SELECT id FROM bookings WHERE contact_phone = :p)', ['p' => $phone]);
        Database::run('DELETE FROM bookings WHERE contact_phone = :p', ['p' => $phone]);
        Database::run('DELETE FROM customers WHERE phone = :p', ['p' => $phone]);
        Database::run('DELETE FROM cars WHERE id = :c', ['c' => $car]);
        t_http_line_cleanup($fx);
    }
}
