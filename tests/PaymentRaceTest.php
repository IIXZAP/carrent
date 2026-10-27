<?php
use App\Core\Database;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\BookingService;

/**
 * อนุมัติสลิปพร้อมกันด้วย process จริง (worker commit จริง เทสต์ลบข้อมูลทิ้งเอง)
 * พิสูจน์ว่า "ล็อกแถวการจองก่อนคิดยอด" ทำงาน
 */

/** สร้างการจอง + สลิปรอตรวจ $amounts ใบ (commit จริง) */
function t_pay_race_fixture(array $amounts): array
{
    t_booking_settings_committed();
    $car = t_make_car();
    Database::run('UPDATE cars SET price_day = 1000, booking_deposit = 1000, security_deposit = 0 WHERE id = :id', ['id' => $car]);
    $users = [];
    for ($i = 0; $i < 8; $i++) {   // แอดมินคนละคน (ถ้าใช้คนเดียว ค่าที่ UPDATE ซ้ำจะเท่าเดิมและซ่อนบั๊กอนุมัติซ้ำ)
        $users[] = Database::insert("INSERT INTO users (name, email, password_hash, role) VALUES ('race', :e, 'x', 'staff')", ['e' => 'race' . bin2hex(random_bytes(5)) . '@x.test']);
    }
    $user = $users[0];
    $bid = BookingService::createBooking($car, '2031-07-10 10:00:00', '2031-07-13 10:00:00', ['name' => 'race', 'phone' => '0877770888', 'email' => '', 'line' => ''], ['source' => 'web']);
    $payments = [];
    foreach ($amounts as $amount) {
        $payments[] = Payment::insert(['booking_id' => $bid, 'kind' => 'deposit', 'amount' => $amount, 'status' => 'pending']);
    }
    return ['car' => $car, 'user' => $user, 'users' => $users, 'booking' => $bid, 'payments' => $payments];
}

function t_booking_settings_committed(): void
{
    foreach (['booking_gap_hours' => '4', 'vat_enabled' => '0', 'booking_deposit' => '1000', 'security_deposit_default' => '3000'] as $k => $v) {
        \App\Models\Setting::set($k, $v);   // ค่าเดียวกับค่าตั้งต้น ไม่กระทบข้อมูลอื่น
    }
}

function t_pay_race_cleanup(array $fx): void
{
    Database::run('DELETE FROM payments WHERE booking_id = :b', ['b' => $fx['booking']]);
    Database::run('DELETE FROM booking_addons WHERE booking_id = :b', ['b' => $fx['booking']]);
    Database::run('DELETE FROM bookings WHERE id = :b', ['b' => $fx['booking']]);
    Database::run('DELETE FROM customers WHERE phone = :p', ['p' => '0877770888']);
    Database::run('DELETE FROM cars WHERE id = :c', ['c' => $fx['car']]);
    foreach ($fx['users'] as $userId) {
        Database::run('DELETE FROM users WHERE id = :u', ['u' => $userId]);
    }
}

/** เรียก worker หลายตัวพร้อมกัน ($paymentIds[i] = ใบที่ worker i อนุมัติ) */
function t_pay_race_run(array $paymentIds, array $userIds): array
{
    $startAt = microtime(true) + 1.5;
    $procs = [];
    foreach ($paymentIds as $i => $paymentId) {
        $cmd = [PHP_BINARY, __DIR__ . '/workers/approve.php', (string) $paymentId, sprintf('%.4f', $startAt), (string) $userIds[$i % count($userIds)]];
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

function test_race_pay_same_slip_approved_once(): void
{
    $fx = t_pay_race_fixture([1000]);
    try {
        $results = t_pay_race_run(array_fill(0, 8, $fx['payments'][0]), $fx['users']);
        $approved = count(array_filter($results, static fn(string $r): bool => $r === 'APPROVED'));
        $skipped  = count(array_filter($results, static fn(string $r): bool => $r === 'SKIPPED'));
        assert_same(1, $approved, 'อนุมัติสำเร็จได้ครั้งเดียว: ' . json_encode($results));
        assert_same(7, $skipped, 'ที่เหลือถูกข้าม (ไม่ใช่ error/deadlock): ' . json_encode($results));
        assert_money(1000, Payment::netPaid($fx['booking']), 'ยอดไม่ถูกนับซ้ำ');
        assert_same('confirmed', Booking::find($fx['booking'])['status']);
    } finally {
        t_pay_race_cleanup($fx);
    }
}

function test_race_pay_different_slips_same_booking_status_consistent(): void
{
    // 4 ใบ ใบละ 250 อนุมัติพร้อมกัน → รวม 1,000 = มัดจำครบ ต้องยืนยันการจอง
    // ถ้าไม่ล็อกการจอง แต่ละ process จะเห็นเฉพาะใบของตัวเอง (250 < 1,000) แล้วไม่มีใครยืนยันให้
    $fx = t_pay_race_fixture([250, 250, 250, 250]);
    try {
        $results = t_pay_race_run($fx['payments'], $fx['users']);
        assert_same(4, count(array_filter($results, static fn(string $r): bool => $r === 'APPROVED')), 'อนุมัติครบ 4 ใบ: ' . json_encode($results));
        assert_money(1000, Payment::netPaid($fx['booking']));
        $booking = Booking::find($fx['booking']);
        assert_same('confirmed', $booking['status'], 'มัดจำครบต้องยืนยันการจอง');
        assert_same('deposit_paid', $booking['payment_status']);
    } finally {
        t_pay_race_cleanup($fx);
    }
}
