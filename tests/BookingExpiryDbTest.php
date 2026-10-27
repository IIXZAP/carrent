<?php
use App\Core\Database;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\CronService;
use App\Services\PaymentService;

/** การจองเว็บที่สร้างเมื่อ $hoursAgo ชั่วโมงก่อน (pending / unpaid) */
function t_expiry_booking(int $hoursAgo, string $source = 'web', string $phone = '0811111111', ?int $carId = null): array
{
    t_booking_settings();
    Setting::set('pending_expire_hours', '24');
    $carId ??= t_make_car();
    $id = BookingService::createBooking($carId, '2030-06-10 10:00:00', '2030-06-13 10:00:00', t_contact($phone), ['source' => $source]);
    Database::run('UPDATE bookings SET created_at = DATE_SUB(NOW(), INTERVAL :h HOUR) WHERE id = :id', ['h' => $hoursAgo, 'id' => $id]);
    return Booking::find($id);
}

function t_status(array $b): string
{
    return Booking::find((int) $b['id'])['status'];
}

function test_db_expiry_cancels_old_unpaid_web_booking_and_frees_the_car(): void
{
    $b = t_expiry_booking(25);
    assert_true(!AvailabilityService::isCarAvailable((int) $b['car_id'], '2030-06-10 10:00:00', '2030-06-13 10:00:00'), 'ระหว่างค้างอยู่รถไม่ว่าง');

    $cancelled = BookingService::expireUnpaid(24);
    assert_true(in_array($b['booking_no'], $cancelled, true), 'รายงานเลขที่จองที่ถูกยกเลิก');
    $after = Booking::find((int) $b['id']);
    assert_same('cancelled', $after['status']);
    assert_true(strpos($after['cancel_reason'], '24') !== false, 'เหตุผลบอกจำนวนชั่วโมง: ' . $after['cancel_reason']);
    assert_true(AvailabilityService::isCarAvailable((int) $b['car_id'], '2030-06-10 10:00:00', '2030-06-13 10:00:00'), 'ยกเลิกแล้วรถว่างอีกครั้ง');
    assert_same([], array_intersect([$b['booking_no']], BookingService::expireUnpaid(24)), 'รันซ้ำไม่ยกเลิกซ้ำ');
}

function test_db_expiry_leaves_recent_bookings_alone(): void
{
    $recent = t_expiry_booking(23);
    $edge = t_expiry_booking(1, 'web', '0822222222');
    BookingService::expireUnpaid(24);
    assert_same('pending', t_status($recent), '23 ชม. ยังไม่ถึง 24');
    assert_same('pending', t_status($edge));
}

function test_db_expiry_never_cancels_staff_created_bookings(): void
{
    $ids = [];
    foreach (['phone', 'line', 'admin'] as $i => $source) {
        $ids[$source] = t_expiry_booking(100, $source, '08333333' . $i . '0');
    }
    BookingService::expireUnpaid(24);
    foreach ($ids as $source => $b) {
        assert_same('pending', t_status($b), "การจองที่พนักงานสร้าง ($source) ต้องไม่ถูกยกเลิกอัตโนมัติ");
    }
}

function test_db_expiry_skips_bookings_with_slip_waiting_for_review(): void
{
    $b = t_expiry_booking(100);
    Payment::insert(['booking_id' => (int) $b['id'], 'kind' => 'deposit', 'amount' => 1000, 'status' => 'pending']);
    BookingService::expireUnpaid(24);
    assert_same('pending', t_status($b), 'มีสลิปรอตรวจ ห้ามยกเลิก (ลูกค้าจ่ายแล้ว แค่ร้านยังไม่ได้ตรวจ)');
}

function test_db_expiry_cancels_booking_whose_only_slip_was_rejected(): void
{
    $b = t_expiry_booking(100);
    Payment::insert(['booking_id' => (int) $b['id'], 'kind' => 'deposit', 'amount' => 1000, 'status' => 'rejected', 'note' => 'ยอดไม่ตรง']);
    BookingService::expireUnpaid(24);
    assert_same('cancelled', t_status($b), 'สลิปถูกปฏิเสธและไม่จ่ายใหม่ ยกเลิกได้');
}

function test_db_expiry_never_touches_paid_or_confirmed_bookings(): void
{
    $b = t_expiry_booking(100);
    PaymentService::record((int) $b['id'], t_user(), 'deposit', 'cash', 1000.0, null);
    assert_same('confirmed', t_status($b));
    BookingService::expireUnpaid(24);
    assert_same('confirmed', t_status($b));

    $partial = t_expiry_booking(100, 'web', '0844444444');
    PaymentService::record((int) $partial['id'], t_user(), 'deposit', 'cash', 100.0, null);   // จ่ายบางส่วน (ยังไม่ครบมัดจำ)
    BookingService::expireUnpaid(24);
    assert_same('pending', t_status($partial), 'จ่ายบางส่วนแล้ว ไม่ยกเลิกอัตโนมัติ');
}

function test_db_expiry_is_off_when_hours_is_zero(): void
{
    $b = t_expiry_booking(1000);
    Setting::set('pending_expire_hours', '0');
    assert_same([], CronService::run(), 'ปิดไว้ = ไม่ทำอะไรและไม่รายงาน');
    assert_same('pending', t_status($b));
    assert_same([], BookingService::expireUnpaid(0));
}

function test_db_cron_runs_expiry_and_reports(): void
{
    $b = t_expiry_booking(30);
    $lines = CronService::run();
    assert_same('cancelled', t_status($b));
    assert_true(count(array_filter($lines, static fn(string $l): bool => strpos($l, $b['booking_no']) !== false || strpos($l, 'auto-cancelled') !== false)) >= 1, 'สรุปผลมีการยกเลิก: ' . json_encode($lines));
}

function test_db_expiry_deadline_shown_only_when_it_applies(): void
{
    $b = t_expiry_booking(2);
    $deadline = BookingService::expiryDeadline($b);
    assert_same(date('Y-m-d H:i:s', strtotime($b['created_at']) + 24 * 3600), $deadline, 'created_at + 24 ชม.');

    assert_same(null, BookingService::expiryDeadline(Booking::find((int) t_expiry_booking(2, 'phone', '0855555555')['id'])), 'พนักงานสร้าง ไม่มีกำหนด');

    Payment::insert(['booking_id' => (int) $b['id'], 'kind' => 'deposit', 'amount' => 1000, 'status' => 'pending']);
    assert_same(null, BookingService::expiryDeadline(Booking::find((int) $b['id'])), 'มีสลิปรอตรวจ ไม่มีกำหนด');

    Setting::set('pending_expire_hours', '0');
    $c = t_expiry_booking(2, 'web', '0866666666');
    Setting::set('pending_expire_hours', '0');
    assert_same(null, BookingService::expiryDeadline($c), 'ปิดไว้ ไม่มีกำหนด');
}

function test_db_expiry_leaves_inconsistent_paid_status_for_a_human(): void
{
    // สถานะบอกว่าจ่ายแล้ว แต่ไม่มีรายการเงินรองรับ (ข้อมูลไม่สอดคล้อง) — ไม่ควรยกเลิกเองโดยอัตโนมัติ
    $b = t_expiry_booking(100);
    Database::run("UPDATE bookings SET payment_status = 'deposit_paid' WHERE id = :id", ['id' => $b['id']]);
    BookingService::expireUnpaid(24);
    assert_same('pending', t_status($b));
}
