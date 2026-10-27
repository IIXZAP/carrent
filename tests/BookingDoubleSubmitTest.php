<?php
use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Services\WebBookingService;

function test_db_double_submit_returns_the_same_booking_not_an_error(): void
{
    t_booking_settings();
    $car = t_make_car();
    $first = WebBookingService::create(t_web_input($car), '192.0.2.80');
    assert_same([], $first['errors']);
    $second = WebBookingService::create(t_web_input($car), '192.0.2.80');   // ผู้ใช้กดปุ่มซ้ำ / รีเฟรชหน้า
    assert_same([], $second['errors'], 'กดซ้ำต้องไม่ได้ข้อความ "รถไม่ว่าง"');
    assert_same($first['booking']['booking_no'], $second['booking']['booking_no'], 'ได้ใบจองเดิม');
    assert_same(1, (int) Database::value('SELECT COUNT(*) FROM bookings WHERE car_id = :c', ['c' => $car]), 'ไม่สร้างใบที่สอง');
    assert_same(1, ActivityLog::countByIpSince('booking_created', '192.0.2.80', '2000-01-01 00:00:00'), 'ไม่นับเป็นการจองเพิ่มในขีดจำกัดต่อ IP');
}

function test_db_double_submit_detection_needs_same_person_car_and_period(): void
{
    t_booking_settings();
    $car = t_make_car();
    WebBookingService::create(t_web_input($car), '192.0.2.81');
    $cases = [
        'คนละเบอร์'   => ['phone' => '0899998888'],
        'คนละชื่อ'    => ['name' => 'คนอื่นเลย'],
        'คนละช่วงเวลา' => ['return' => '2030-06-14T10:00'],
    ];
    foreach ($cases as $label => $override) {
        $r = WebBookingService::create(t_web_input($car, $override), '192.0.2.82');
        assert_true($r['errors'] !== [] && $r['booking'] === null, "$label ต้องไม่ถูกมองว่าเป็นใบเดิม (รถไม่ว่าง)");
    }
}

function test_db_double_submit_after_cancel_makes_a_fresh_booking(): void
{
    t_booking_settings();
    $car = t_make_car();
    $first = WebBookingService::create(t_web_input($car), '192.0.2.83');
    assert_true(Booking::cancel((int) $first['booking']['id'], 'ลูกค้ายกเลิก'));
    $again = WebBookingService::create(t_web_input($car), '192.0.2.83');
    assert_same([], $again['errors'], 'ใบเดิมยกเลิกแล้ว จองใหม่ได้');
    assert_true($again['booking']['id'] !== $first['booking']['id'], 'ต้องเป็นใบใหม่ ไม่ใช่ส่งใบที่ยกเลิกกลับมา');
}

function test_db_old_booking_is_not_treated_as_a_double_click(): void
{
    t_booking_settings();
    $car = t_make_car();
    $first = WebBookingService::create(t_web_input($car), '192.0.2.84');
    Database::run('UPDATE bookings SET created_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = :id', ['id' => $first['booking']['id']]);
    $again = WebBookingService::create(t_web_input($car), '192.0.2.84');
    assert_true($again['errors'] !== [] && $again['booking'] === null, 'ผ่านไปนานแล้ว ไม่ใช่การกดซ้ำ — รถไม่ว่างตามปกติ');
}

// ---------- กดซ้ำพร้อมกันจริง (คำขอที่สองมาตอนคำขอแรกยังไม่เสร็จ) ----------

function test_race_double_click_both_requests_end_on_the_same_booking(): void
{
    $car = t_make_car();
    $phone = '0877770777';
    $startAt = microtime(true) + 1.5;
    $procs = [];
    for ($i = 0; $i < 2; $i++) {
        $cmd = [PHP_BINARY, __DIR__ . '/workers/web_book.php', (string) $car, sprintf('%.4f', $startAt), $phone];
        $procs[] = [proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
    }
    $results = [];
    foreach ($procs as [$proc, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    try {
        assert_true(strncmp($results[0], 'OK ', 3) === 0 && strncmp($results[1], 'OK ', 3) === 0, 'ทั้งสองคำขอต้องจบที่ "จองสำเร็จ": ' . json_encode($results, JSON_UNESCAPED_UNICODE));
        assert_same($results[0], $results[1], 'ได้เลขที่จองเดียวกัน');
        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM bookings WHERE car_id = :c', ['c' => $car]), 'มีใบจองเดียวใน DB');
    } finally {
        Database::run('DELETE FROM activity_logs WHERE ip = :ip', ['ip' => '192.0.2.99']);
        t_race_cleanup([$car]);
        Database::run('DELETE FROM customers WHERE phone = :p', ['p' => $phone]);
    }
}
