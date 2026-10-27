<?php
use App\Core\Database;
use App\Models\Booking;
use App\Models\Inspection;
use App\Models\Payment;
use App\Services\BookingFlowService;
use App\Services\BookingService;
use App\Services\PaymentService;

/**
 * ส่งมอบ/รับคืน/ปิดเงินประกันพร้อมกันด้วย process จริง (worker commit จริง เทสต์ลบข้อมูลทิ้งเอง)
 */

/** สร้างข้อมูลที่ commit จริง: รถ + การจอง $count ใบ (คนละช่วงเวลา) ยืนยันแล้ว มีผู้ใช้ 8 คน */
function t_flow_race_fixture(int $count = 1): array
{
    t_booking_settings_committed();
    $car = t_make_car();
    Database::run('UPDATE cars SET price_day = 1000, booking_deposit = 1000, security_deposit = 3000 WHERE id = :id', ['id' => $car]);
    $users = [];
    for ($i = 0; $i < 8; $i++) {
        $users[] = Database::insert("INSERT INTO users (name, email, password_hash, role) VALUES ('race', :e, 'x', 'staff')", ['e' => 'race' . bin2hex(random_bytes(5)) . '@x.test']);
    }
    $bookings = [];
    for ($i = 0; $i < $count; $i++) {
        $month = 7 + $i;
        $id = BookingService::createBooking($car, "2031-0$month-10 10:00:00", "2031-0$month-13 10:00:00", ['name' => 'race', 'phone' => '087777' . (1000 + $i), 'email' => '', 'line' => ''], ['source' => 'web']);
        PaymentService::record($id, $users[0], 'deposit', 'cash', 1000.0, null);
        $bookings[] = $id;
    }
    return ['car' => $car, 'users' => $users, 'bookings' => $bookings];
}

function t_flow_race_cleanup(array $fx): void
{
    foreach ($fx['bookings'] as $bid) {
        Database::run('DELETE FROM inspection_photos WHERE inspection_id IN (SELECT id FROM inspections WHERE booking_id = :b)', ['b' => $bid]);
        Database::run('DELETE FROM inspections WHERE booking_id = :b', ['b' => $bid]);
        Database::run('DELETE FROM payments WHERE booking_id = :b', ['b' => $bid]);
        Database::run('DELETE FROM booking_addons WHERE booking_id = :b', ['b' => $bid]);
        Database::run('DELETE FROM bookings WHERE id = :b', ['b' => $bid]);
    }
    Database::run("DELETE FROM customers WHERE phone LIKE '087777%'");
    Database::run('DELETE FROM cars WHERE id = :c', ['c' => $fx['car']]);
    foreach ($fx['users'] as $u) {
        Database::run('DELETE FROM users WHERE id = :u', ['u' => $u]);
    }
}

/** เรียก worker พร้อมกัน: $jobs = [[action, bookingId, userId], ...] */
function t_flow_race_run(array $jobs, ?int $now = null): array
{
    $startAt = microtime(true) + 1.5;
    $procs = [];
    foreach ($jobs as [$action, $bookingId, $userId]) {
        $cmd = [PHP_BINARY, __DIR__ . '/workers/flow.php', $action, (string) $bookingId, sprintf('%.4f', $startAt), (string) $userId];
        if ($now !== null) {
            $cmd[] = (string) $now;
        }
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        assert_true(is_resource($proc), 'เปิด worker ไม่ได้');
        $procs[] = [$proc, $pipes];
    }
    $out = [];
    foreach ($procs as [$proc, $pipes]) {
        $out[] = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    return $out;
}

function t_flow_count(array $results, string $prefix): int
{
    return count(array_filter($results, static fn(string $r): bool => strncmp($r, $prefix, strlen($prefix)) === 0));
}

function test_race_flow_pickup_same_booking_only_once(): void
{
    $fx = t_flow_race_fixture();
    try {
        $jobs = [];
        foreach ($fx['users'] as $u) {
            $jobs[] = ['pickup', $fx['bookings'][0], $u];
        }
        $results = t_flow_race_run($jobs, strtotime('2031-07-10 10:05:00'));
        assert_same(1, t_flow_count($results, 'OK'), 'ส่งมอบสำเร็จครั้งเดียว: ' . json_encode($results, JSON_UNESCAPED_UNICODE));
        assert_same(7, t_flow_count($results, 'REFUSED'), 'ที่เหลือถูกปฏิเสธด้วยข้อความที่อ่านได้ (ไม่ใช่ deadlock): ' . json_encode($results, JSON_UNESCAPED_UNICODE));
        assert_same(1, (int) Database::value("SELECT COUNT(*) FROM inspections WHERE booking_id = :b AND kind = 'out'", ['b' => $fx['bookings'][0]]));
        assert_same('picked_up', Booking::find($fx['bookings'][0])['status']);
    } finally {
        t_flow_race_cleanup($fx);
    }
}

function test_race_flow_two_bookings_of_the_same_car_cannot_both_be_picked_up(): void
{
    $fx = t_flow_race_fixture(2);
    try {
        $jobs = [['pickup', $fx['bookings'][0], $fx['users'][0]], ['pickup', $fx['bookings'][1], $fx['users'][1]]];
        $results = t_flow_race_run($jobs, strtotime('2031-07-10 10:05:00'));
        assert_same(1, t_flow_count($results, 'OK'), 'รถคันเดียวส่งมอบได้ครั้งเดียว: ' . json_encode($results, JSON_UNESCAPED_UNICODE));
        assert_same(1, t_flow_count($results, 'REFUSED'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $out = (int) Database::value("SELECT COUNT(*) FROM bookings WHERE car_id = :c AND status = 'picked_up'", ['c' => $fx['car']]);
        assert_same(1, $out, 'รถอยู่กับลูกค้าได้รายเดียว');
    } finally {
        t_flow_race_cleanup($fx);
    }
}

function test_race_flow_return_twice_does_not_double_the_charges(): void
{
    $fx = t_flow_race_fixture();
    try {
        $bid = $fx['bookings'][0];
        BookingFlowService::pickup($bid, 10000, 8, $fx['users'][0], '', strtotime('2031-07-10 10:05:00'));
        $jobs = [];
        foreach ($fx['users'] as $u) {
            $jobs[] = ['return', $bid, $u];
        }
        $results = t_flow_race_run($jobs, strtotime('2031-07-13 09:00:00'));
        assert_same(1, t_flow_count($results, 'OK'), 'รับคืนสำเร็จครั้งเดียว: ' . json_encode($results, JSON_UNESCAPED_UNICODE));
        assert_same(7, t_flow_count($results, 'REFUSED'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $b = Booking::find($bid);
        assert_money(1200, $b['extra_charge'], 'น้ำมันขาด 3 ขีด 900 + ทำความสะอาด 300 — ไม่ถูกบวกซ้ำ');
        assert_same(2, count(json_decode($b['extra_breakdown'], true)));
        assert_same('returned', $b['status']);
    } finally {
        t_flow_race_cleanup($fx);
    }
}

function test_race_flow_settle_security_twice_refunds_only_once(): void
{
    $fx = t_flow_race_fixture();
    try {
        $bid = $fx['bookings'][0];
        BookingFlowService::pickup($bid, 10000, 8, $fx['users'][0], '', strtotime('2031-07-10 10:05:00'));
        PaymentService::record($bid, $fx['users'][0], 'balance', 'cash', 2000.0, null);
        PaymentService::record($bid, $fx['users'][0], 'security', 'cash', 3000.0, null);
        BookingFlowService::returnCar($bid, ['mileage_in' => '10100', 'fuel_in' => '8', 'returned_at' => '', 'chg' => []], $fx['users'][0], '', strtotime('2031-07-13 09:00:00'));

        $jobs = [];
        foreach ($fx['users'] as $u) {
            $jobs[] = ['settle', $bid, $u];
        }
        $results = t_flow_race_run($jobs);
        assert_same(1, t_flow_count($results, 'OK'), 'ปิดเงินประกันสำเร็จครั้งเดียว: ' . json_encode($results, JSON_UNESCAPED_UNICODE));
        assert_same(7, t_flow_count($results, 'REFUSED'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $refunds = (int) Database::value("SELECT COUNT(*) FROM payments WHERE booking_id = :b AND kind = 'security_refund'", ['b' => $bid]);
        assert_same(1, $refunds, 'คืนเงินประกันครั้งเดียว');
        assert_money(0, Payment::securityHeld($bid));
        assert_money(3000, Database::value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_id = :b AND kind = 'security_refund'", ['b' => $bid]), 'ไม่คืนเกินที่รับไว้');
    } finally {
        t_flow_race_cleanup($fx);
    }
}
