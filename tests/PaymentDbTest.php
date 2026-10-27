<?php
use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\TrackService;
use App\Services\UploadService;
use App\Services\UserError;

/** การจอง 3 วัน × 1,000 = ยอดรวม 3,000 มัดจำ 1,000 เงินประกัน 3,000 (ยังไม่จ่ายอะไร) */
function t_pay_booking(string $phone = '0811111111'): array
{
    t_booking_settings();
    $car = t_make_car();
    Database::run('UPDATE cars SET price_day = 1000, booking_deposit = 1000, security_deposit = 3000 WHERE id = :id', ['id' => $car]);
    return Booking::find(t_book($car, '2030-06-10 10:00:00', '2030-06-13 10:00:00', t_contact($phone)));
}

function t_user(): int
{
    return Database::insert("INSERT INTO users (name, email, password_hash, role) VALUES ('t', :e, 'x', 'staff')", ['e' => 'u' . bin2hex(random_bytes(5)) . '@x.test']);
}

/** แจ้งโอนจริง 1 รายการ (ไฟล์สลิปถูกลบตอนจบเทสต์ผ่าน $GLOBALS) */
function t_slip(array $booking, float $amount): int
{
    $file = t_upload_file();
    $id = PaymentService::submitSlip($booking, $file, $amount, '2030-06-01 09:00:00', 'ทดสอบ', false);
    $GLOBALS['t_slips_to_delete'][] = Payment::find($id)['slip_file'];
    @unlink($file['tmp_name']);
    return $id;
}

function t_slips_cleanup(): void
{
    foreach ($GLOBALS['t_slips_to_delete'] ?? [] as $path) {
        UploadService::delete($path);
    }
    $GLOBALS['t_slips_to_delete'] = [];
}

function t_fresh(array $booking): array
{
    return Booking::find((int) $booking['id']);
}

// ---------- แจ้งโอน ----------

function test_db_submit_slip_creates_pending_payment(): void
{
    $b = t_pay_booking();
    try {
        $id = t_slip($b, 1000);
        $p = Payment::find($id);
        assert_same('pending', $p['status']);
        assert_same('deposit', $p['kind'], 'ยังไม่เคยจ่าย = มัดจำ');
        assert_money(1000, $p['amount']);
        assert_true(UploadService::resolve($p['slip_file']) !== null, 'ไฟล์สลิปถูกเก็บจริง');
        assert_same('unpaid', t_fresh($b)['payment_status'], 'รอตรวจสอบ ยังไม่นับเป็นเงินที่ได้รับ');
        assert_same('pending', t_fresh($b)['status']);
    } finally {
        t_slips_cleanup();
    }
}

function test_db_submit_slip_validation(): void
{
    $b = t_pay_booking();
    try {
        foreach ([0.0, -5.0, 2000001.0] as $amount) {
            $refused = false;
            try {
                PaymentService::submitSlip($b, t_upload_file(), $amount, null, null, false);
            } catch (UserError $e) {
                $refused = true;
            }
            assert_true($refused, "จำนวนเงิน $amount");
        }
        $refused = false;
        try {
            PaymentService::submitSlip($b, ['error' => UPLOAD_ERR_NO_FILE], 100.0, null, null, false);
        } catch (UserError $e) {
            $refused = true;
        }
        assert_true($refused, 'ไม่แนบไฟล์');

        Booking::cancel((int) $b['id'], 'x');
        $refused = false;
        try {
            PaymentService::submitSlip(t_fresh($b), t_upload_file(), 100.0, null, null, false);
        } catch (UserError $e) {
            $refused = true;
        }
        assert_true($refused, 'การจองที่ยกเลิกแล้วแจ้งโอนไม่ได้');
        assert_same(0, count(Payment::forBooking((int) $b['id'])), 'ไม่มีอะไรถูกบันทึก');
    } finally {
        t_slips_cleanup();
    }
}

function test_db_submit_slip_limits_pending_per_booking(): void
{
    $b = t_pay_booking();
    try {
        for ($i = 0; $i < PaymentService::MAX_PENDING_PER_BOOKING; $i++) {
            t_slip($b, 100);
        }
        $refused = false;
        try {
            PaymentService::submitSlip($b, t_upload_file(), 100.0, null, null, false);
        } catch (UserError $e) {
            $refused = true;
        }
        assert_true($refused, 'รอตรวจเกินกำหนด');
    } finally {
        t_slips_cleanup();
    }
}

// ---------- อนุมัติ / ปฏิเสธ ----------

function test_db_approve_full_deposit_confirms_booking(): void
{
    $b = t_pay_booking();
    $user = t_user();
    try {
        $id = t_slip($b, 1000);
        assert_true(PaymentService::approve($id, $user), 'อนุมัติสำเร็จ');
        $after = t_fresh($b);
        assert_same('confirmed', $after['status'], 'มัดจำครบ → ยืนยันอัตโนมัติ');
        assert_same('deposit_paid', $after['payment_status']);
        $p = Payment::find($id);
        assert_same('approved', $p['status']);
        assert_same($user, (int) $p['approved_by']);
        assert_money(1000, Payment::netPaid((int) $b['id']));
    } finally {
        t_slips_cleanup();
    }
}

function test_db_approve_partial_deposit_does_not_confirm(): void
{
    $b = t_pay_booking();
    try {
        $id = t_slip($b, 400);
        assert_true(PaymentService::approve($id, t_user()));
        assert_same('pending', t_fresh($b)['status'], 'มัดจำยังไม่ครบ ยังไม่ยืนยัน');
        assert_same('deposit_paid', t_fresh($b)['payment_status']);

        $second = t_slip($b, 600);
        assert_same('balance', Payment::find($second)['kind'], 'มีเงินเข้าแล้ว รายการถัดไปเป็นส่วนที่เหลือ');
        assert_true(PaymentService::approve($second, t_user()));
        assert_same('confirmed', t_fresh($b)['status'], '400 + 600 = มัดจำครบ');
    } finally {
        t_slips_cleanup();
    }
}

function test_db_approve_full_amount_marks_paid(): void
{
    $b = t_pay_booking();
    try {
        assert_true(PaymentService::approve(t_slip($b, 3000), t_user()));
        assert_same('paid', t_fresh($b)['payment_status']);
        assert_same('confirmed', t_fresh($b)['status']);
    } finally {
        t_slips_cleanup();
    }
}

function test_db_approve_twice_only_first_wins(): void
{
    $b = t_pay_booking();
    try {
        $id = t_slip($b, 1000);
        assert_true(PaymentService::approve($id, t_user()));
        assert_true(!PaymentService::approve($id, t_user()), 'อนุมัติซ้ำไม่ได้');
        assert_true(!PaymentService::reject($id, t_user(), 'x'), 'อนุมัติไปแล้ว ปฏิเสธไม่ได้');
        assert_money(1000, Payment::netPaid((int) $b['id']), 'ยอดไม่ถูกนับซ้ำ');
        assert_true(!PaymentService::approve(999999999, t_user()), 'ไม่พบรายการ');
    } finally {
        t_slips_cleanup();
    }
}

function test_db_reject_keeps_booking_unpaid_and_records_reason(): void
{
    $b = t_pay_booking();
    try {
        $id = t_slip($b, 1000);
        assert_true(PaymentService::reject($id, t_user(), 'ยอดไม่ตรง'));
        $p = Payment::find($id);
        assert_same('rejected', $p['status']);
        assert_same('ยอดไม่ตรง', $p['reject_reason']);
        assert_same('pending', t_fresh($b)['status']);
        assert_same('unpaid', t_fresh($b)['payment_status']);
        assert_true(!PaymentService::approve($id, t_user()), 'ปฏิเสธแล้ว อนุมัติต่อไม่ได้');

        $id2 = t_slip($b, 1000);
        PaymentService::reject($id2, t_user(), '   ');
        assert_same('ข้อมูลไม่ถูกต้อง', Payment::find($id2)['reject_reason'], 'ไม่ใส่เหตุผล = ข้อความมาตรฐาน');
    } finally {
        t_slips_cleanup();
    }
}

// ---------- พนักงานบันทึกรับ/คืนเอง ----------

function test_db_staff_records_cash_immediately_approved(): void
{
    $b = t_pay_booking();
    $user = t_user();
    $id = PaymentService::record((int) $b['id'], $user, 'deposit', 'cash', 1000.0, 'รับสดที่ร้าน');
    $p = Payment::find($id);
    assert_same('approved', $p['status']);
    assert_same($user, (int) $p['approved_by']);
    assert_same('confirmed', t_fresh($b)['status']);
}

function test_db_security_money_is_not_revenue_and_does_not_confirm(): void
{
    $b = t_pay_booking();
    $user = t_user();
    $bid = (int) $b['id'];
    PaymentService::record($bid, $user, 'security', 'cash', 3000.0, null);
    assert_money(0, Payment::netPaid($bid), 'เงินประกันไม่ใช่รายได้');
    assert_money(3000, Payment::securityHeld($bid));
    assert_same('pending', t_fresh($b)['status'], 'รับเงินประกันอย่างเดียวไม่ยืนยันการจอง');
    assert_same('unpaid', t_fresh($b)['payment_status']);

    PaymentService::record($bid, $user, 'security_refund', 'cash', 1000.0, null);
    assert_money(2000, Payment::securityHeld($bid));
    assert_money(0, Payment::netPaid($bid));
}

function test_db_deduct_from_security_pays_balance_and_reduces_held(): void
{
    $b = t_pay_booking();
    $user = t_user();
    $bid = (int) $b['id'];
    PaymentService::record($bid, $user, 'balance', 'cash', 2500.0, null);
    PaymentService::record($bid, $user, 'security', 'cash', 3000.0, null);
    PaymentService::record($bid, $user, 'balance', 'deduct', 500.0, 'หักเงินประกันชำระส่วนที่เหลือ');
    assert_money(3000, Payment::netPaid($bid), 'รายการที่หักจากเงินประกันนับเป็นค่าเช่าที่ชำระ');
    assert_money(2500, Payment::securityHeld($bid), 'เงินประกันที่ร้านถือลดลง');
    assert_same('paid', t_fresh($b)['payment_status']);
}

function test_db_refund_limits(): void
{
    $b = t_pay_booking();
    $user = t_user();
    $bid = (int) $b['id'];
    PaymentService::record($bid, $user, 'deposit', 'cash', 1000.0, null);

    foreach ([['refund', 'cash', 1000.01], ['security_refund', 'cash', 1.0], ['balance', 'deduct', 1.0]] as [$kind, $method, $amount]) {
        $refused = false;
        try {
            PaymentService::record($bid, $user, $kind, $method, $amount, null);
        } catch (UserError $e) {
            $refused = true;
        }
        assert_true($refused, "$kind $method $amount");
    }
    PaymentService::record($bid, $user, 'refund', 'transfer', 1000.0, 'คืนมัดจำ');
    assert_money(0, Payment::netPaid($bid));
    assert_same('refunded', t_fresh($b)['payment_status'], 'คืนครบ = คืนเงินแล้ว');
}

function test_db_cancelled_booking_accepts_refund_but_not_new_rental_money(): void
{
    $b = t_pay_booking();
    $user = t_user();
    $bid = (int) $b['id'];
    PaymentService::record($bid, $user, 'deposit', 'cash', 1000.0, null);
    assert_true(Booking::cancel($bid, 'ลูกค้าเลื่อน'));

    $refused = false;
    try {
        PaymentService::record($bid, $user, 'balance', 'cash', 100.0, null);
    } catch (UserError $e) {
        $refused = true;
    }
    assert_true($refused, 'ยกเลิกแล้วรับค่าเช่าเพิ่มไม่ได้');
    PaymentService::record($bid, $user, 'refund', 'transfer', 1000.0, null);
    assert_same('refunded', t_fresh($b)['payment_status']);
    assert_same('cancelled', t_fresh($b)['status'], 'การคืนเงินไม่เปลี่ยนสถานะการจอง');
}

function test_db_record_validation(): void
{
    $b = t_pay_booking();
    $user = t_user();
    foreach ([['nonsense', 'cash', 100.0], ['deposit', 'nonsense', 100.0], ['deposit', 'cash', 0.0], ['deposit', 'cash', -1.0], ['security', 'deduct', 100.0]] as [$kind, $method, $amount]) {
        $refused = false;
        try {
            PaymentService::record((int) $b['id'], $user, $kind, $method, $amount, null);
        } catch (UserError $e) {
            $refused = true;
        }
        assert_true($refused, "$kind/$method/$amount");
    }
    $refused = false;
    try {
        PaymentService::record(999999999, $user, 'deposit', 'cash', 100.0, null);
    } catch (UserError $e) {
        $refused = true;
    }
    assert_true($refused, 'ไม่มีการจองนี้');
}

// ---------- ตัวเลขใน SQL view ต้องตรงกับโค้ด ----------

function test_db_view_matches_model_formulas(): void
{
    $b = t_pay_booking();
    $user = t_user();
    $bid = (int) $b['id'];
    PaymentService::record($bid, $user, 'deposit', 'cash', 1000.0, null);
    PaymentService::record($bid, $user, 'balance', 'transfer', 1500.0, null);
    PaymentService::record($bid, $user, 'security', 'cash', 3000.0, null);
    PaymentService::record($bid, $user, 'balance', 'deduct', 200.0, null);
    PaymentService::record($bid, $user, 'refund', 'transfer', 300.0, null);
    PaymentService::record($bid, $user, 'security_refund', 'cash', 500.0, null);
    Payment::insert(['booking_id' => $bid, 'kind' => 'deposit', 'amount' => 999, 'status' => 'pending']);
    Payment::insert(['booking_id' => $bid, 'kind' => 'deposit', 'amount' => 888, 'status' => 'rejected']);

    $v = Database::one('SELECT * FROM v_booking_payment WHERE booking_id = :id', ['id' => $bid]);
    assert_money(Payment::netPaid($bid), $v['paid_total'], 'paid_total = netPaid');
    assert_money(2400, $v['paid_total'], '1000 + 1500 + 200 − 300');
    assert_money(Payment::securityHeld($bid), $v['security_held'], 'security_held');
    assert_money(2300, $v['security_held'], '3000 − 500 − 200');
    assert_money(300, $v['refunded_total']);
    assert_money(3000 - 2400, $v['balance_due'], 'ค้างชำระ');
}

// ---------- ค้นหาการจอง (ลูกค้า) ----------

function test_db_track_requires_matching_number_and_phone(): void
{
    $b = t_pay_booking('0812345678');
    $ip = '192.0.2.50';
    assert_same($b['id'], TrackService::find($b['booking_no'], '081-234-5678', $ip)['id'], 'เบอร์รูปแบบอื่นใช้ได้');
    assert_same($b['id'], TrackService::find(' ' . strtolower($b['booking_no']) . ' ', '+66 81 234 5678', $ip)['id'], 'ตัวพิมพ์เล็ก/เว้นวรรค/+66');
    assert_same(0, ActivityLog::countByIpSince('track_failed', $ip, '2000-01-01 00:00:00'), 'สำเร็จไม่นับเป็นล้มเหลว');

    assert_same(null, TrackService::find($b['booking_no'], '0899999999', $ip), 'เบอร์ไม่ตรง');
    assert_same(null, TrackService::find('BK000000-001', '0812345678', $ip), 'ไม่มีเลขที่จองนี้');
    assert_same(null, TrackService::find($b['booking_no'], '', $ip), 'ไม่ใส่เบอร์');
    assert_same(3, ActivityLog::countByIpSince('track_failed', $ip, '2000-01-01 00:00:00'));
}

function test_db_track_blocks_ip_after_too_many_failures(): void
{
    $ip = '192.0.2.51';
    assert_true(!TrackService::isBlocked($ip));
    for ($i = 0; $i < TrackService::MAX_FAILURES - 1; $i++) {
        TrackService::find('BK000000-001', '0800000000', $ip);
    }
    assert_true(!TrackService::isBlocked($ip), 'ยังไม่ถึงขีดจำกัด');
    TrackService::find('BK000000-001', '0800000000', $ip);
    assert_true(TrackService::isBlocked($ip), 'ครบ 20 ครั้งแล้ว');
    assert_true(!TrackService::isBlocked('192.0.2.52'), 'IP อื่นไม่ถูกกระทบ');
}

function test_db_only_rental_money_confirms_a_pending_booking(): void
{
    $b = t_pay_booking();
    $user = t_user();
    $bid = (int) $b['id'];
    PaymentService::record($bid, $user, 'deposit', 'cash', 1000.0, null);
    assert_same('confirmed', t_fresh($b)['status']);

    // จำลองพนักงานย้อนสถานะกลับเป็นรอยืนยัน (เช่นแก้ผิด) แล้วมีรายการที่ไม่ใช่ค่าเช่าเข้ามา → ห้ามยืนยันเอง
    foreach ([['security', 'cash'], ['refund', 'cash'], ['security_refund', 'cash']] as [$kind, $method]) {
        Database::run("UPDATE bookings SET status = 'pending' WHERE id = :id", ['id' => $bid]);
        if ($kind === 'security_refund') {
            PaymentService::record($bid, $user, 'security', 'cash', 500.0, null);
            Database::run("UPDATE bookings SET status = 'pending' WHERE id = :id", ['id' => $bid]);
        }
        $amount = $kind === 'refund' ? 100.0 : 500.0;
        PaymentService::record($bid, $user, $kind, $method, $amount, null);
        assert_same('pending', t_fresh($b)['status'], "$kind ต้องไม่ยืนยันการจอง");
    }
    PaymentService::record($bid, $user, 'balance', 'cash', 100.0, null);
    assert_same('confirmed', t_fresh($b)['status'], 'ค่าเช่าเข้ามาอีก (ยอดสุทธิยังถึงมัดจำ) ยืนยันได้');
}

function test_db_pending_count_matches_status_breakdown(): void
{
    $b = t_pay_booking();
    Payment::insert(['booking_id' => (int) $b['id'], 'kind' => 'deposit', 'amount' => 100, 'status' => 'pending']);
    Payment::insert(['booking_id' => (int) $b['id'], 'kind' => 'deposit', 'amount' => 100, 'status' => 'pending']);
    Payment::insert(['booking_id' => (int) $b['id'], 'kind' => 'deposit', 'amount' => 100, 'status' => 'rejected']);
    assert_same(Payment::countByStatus()['pending'], Payment::pendingCount());
    assert_true(Payment::pendingCount() >= 2);
}
