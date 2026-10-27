<?php
use App\Core\Database;
use App\Models\Booking;
use App\Models\CarBlock;
use App\Services\CalendarService;
use App\Services\CarBlockService;
use App\Services\UserError;

function t_block(int $car, string $from, string $to, string $reason = 'เข้าศูนย์'): int
{
    return CarBlockService::create($car, $from, $to, $reason, null);
}

// ---------- เดือน ----------

function test_calendar_month_normalization(): void
{
    assert_same('2031-07', CalendarService::normalizeMonth('2031-07'));
    foreach (['', 'abc', '2031-13', '2031-00', '2031-7', '99999-01', "2031-07\n", '2031-07-01'] as $bad) {
        assert_same(date('Y-m'), CalendarService::normalizeMonth($bad), "ต้องถอยกลับเป็นเดือนนี้: " . json_encode($bad));
    }
}

function test_calendar_neighbour_months_cross_year(): void
{
    $m = CalendarService::month('2031-01');
    assert_same('2030-12', $m['prev']);
    assert_same('2031-02', $m['next']);
    assert_same(31, $m['daysIn']);
    assert_same(28, CalendarService::month('2031-02')['daysIn']);
    assert_same(29, CalendarService::month('2032-02')['daysIn']);
    assert_same('2032-01', CalendarService::month('2031-12')['next']);
}

// ---------- ช่องในปฏิทิน ----------

function test_db_calendar_booking_fills_its_days_with_label_on_first(): void
{
    $car = t_make_car();
    $b = t_make_booking($car, '2031-07-10 10:00:00', '2031-07-13 10:00:00', 'confirmed');
    $grid = CalendarService::month('2031-07')['grid'][$car];
    assert_same([10, 11, 12, 13], array_keys($grid));
    assert_same('confirmed', $grid[10]['kind']);
    assert_true($grid[10]['label'] !== '' && $grid[11]['label'] === '', 'ชื่อบนวันแรกเท่านั้น');
    assert_true(str_contains($grid[12]['link'], '/admin/bookings/' . $b));
}

function test_db_calendar_booking_spanning_months_is_clipped_per_month(): void
{
    $car = t_make_car();
    t_make_booking($car, '2031-07-30 10:00:00', '2031-08-02 10:00:00', 'confirmed');
    $jul = CalendarService::month('2031-07')['grid'][$car];
    assert_same([30, 31], array_keys($jul));
    $aug = CalendarService::month('2031-08')['grid'][$car];
    assert_same([1, 2], array_keys($aug));
    assert_true($jul[30]['label'] !== '' && $aug[1]['label'] === '', 'ป้ายชื่ออยู่เดือนที่เริ่มเท่านั้น');
    assert_true(!isset(CalendarService::month('2031-09')['grid'][$car]));
}

function test_db_calendar_cancelled_bookings_are_not_shown(): void
{
    $car = t_make_car();
    t_make_booking($car, '2031-07-10 10:00:00', '2031-07-12 10:00:00', 'cancelled');
    assert_true(!isset(CalendarService::month('2031-07')['grid'][$car]));
}

function test_db_calendar_blocks_are_shown_and_never_hide_a_booking(): void
{
    $car = t_make_car();
    t_block($car, '2031-07-05 08:00:00', '2031-07-07 18:00:00', 'ซ่อมช่วงล่าง');
    $grid = CalendarService::month('2031-07')['grid'][$car];
    assert_same([5, 6, 7], array_keys($grid));
    assert_same('block', $grid[5]['kind']);
    assert_same('ซ่อมช่วงล่าง', $grid[5]['label']);
}

function test_db_calendar_only_lists_active_cars(): void
{
    $a = t_make_car();
    $gone = t_make_car('inactive');
    $deleted = t_make_car();
    Database::run('UPDATE cars SET deleted_at = NOW() WHERE id = :i', ['i' => $deleted]);
    $ids = array_column(CalendarService::month('2031-07')['cars'], 'id');
    assert_true(in_array($a, array_map('intval', $ids), true));
    assert_true(!in_array($deleted, array_map('intval', $ids), true), 'รถที่ลบแล้วต้องไม่ขึ้น');
}

// ---------- ปิดรถ ----------

function test_db_block_create_and_availability_reflects_it(): void
{
    $car = t_make_car();
    $id = t_block($car, '2031-07-05 08:00:00', '2031-07-07 18:00:00');
    assert_true($id > 0);
    assert_same(1, CarBlock::overlapCount($car, '2031-07-06 00:00:00', '2031-07-06 12:00:00'));
    assert_true(!\App\Services\AvailabilityService::isCarAvailable($car, '2031-07-06 10:00:00', '2031-07-06 20:00:00'));
}

function test_db_block_refused_over_existing_booking_naming_it(): void
{
    $car = t_make_car();
    $b = t_make_booking($car, '2031-07-10 10:00:00', '2031-07-12 10:00:00', 'confirmed');
    $no = Booking::find($b)['booking_no'];
    $e = t_expect_refused(fn() => t_block($car, '2031-07-11 00:00:00', '2031-07-11 23:00:00'), 'ปิดรถทับการจอง');
    assert_true(str_contains($e->getMessage(), $no), 'ต้องบอกเลขที่จองที่ชน: ' . $e->getMessage());
    assert_same(0, (int) Database::value('SELECT COUNT(*) FROM car_blocks WHERE car_id = :c', ['c' => $car]));
}

function test_db_block_allowed_back_to_back_and_over_cancelled_or_returned(): void
{
    $car = t_make_car();
    t_make_booking($car, '2031-07-10 10:00:00', '2031-07-12 10:00:00', 'confirmed');
    t_block($car, '2031-07-12 10:00:00', '2031-07-13 10:00:00');    // เริ่มทันทีที่การจองจบ
    t_block($car, '2031-07-09 10:00:00', '2031-07-10 10:00:00');    // จบทันทีที่การจองเริ่ม
    $car2 = t_make_car();
    t_make_booking($car2, '2031-07-10 10:00:00', '2031-07-12 10:00:00', 'cancelled');
    t_make_booking($car2, '2031-07-10 10:00:00', '2031-07-12 10:00:00', 'completed');
    t_block($car2, '2031-07-10 00:00:00', '2031-07-13 00:00:00');
    assert_same(1, (int) Database::value('SELECT COUNT(*) FROM car_blocks WHERE car_id = :c', ['c' => $car2]));
}

function test_db_block_refused_over_picked_up_booking(): void
{
    $car = t_make_car();
    t_make_booking($car, '2031-07-10 10:00:00', '2031-07-12 10:00:00', 'picked_up');
    t_expect_refused(fn() => t_block($car, '2031-07-11 00:00:00', '2031-07-11 12:00:00'), 'รถอยู่กับลูกค้า');
}

function test_db_block_validation(): void
{
    $car = t_make_car();
    t_expect_refused(fn() => t_block($car, '2031-07-07 10:00:00', '2031-07-07 10:00:00'), 'เริ่ม = จบ');
    t_expect_refused(fn() => t_block($car, '2031-07-08 10:00:00', '2031-07-07 10:00:00'), 'จบก่อนเริ่ม');
    t_expect_refused(fn() => t_block($car, 'ไม่ใช่วันที่', '2031-07-07 10:00:00'), 'วันที่เสีย');
    t_expect_refused(fn() => t_block(99999999, '2031-07-07 10:00:00', '2031-07-08 10:00:00'), 'ไม่มีรถ');
    $deleted = t_make_car();
    Database::run('UPDATE cars SET deleted_at = NOW() WHERE id = :i', ['i' => $deleted]);
    t_expect_refused(fn() => t_block($deleted, '2031-07-07 10:00:00', '2031-07-08 10:00:00'), 'รถที่ลบแล้ว');
    t_expect_refused(fn() => t_block($car, '2031-07-07 10:00:00', '2031-07-08 10:00:00', str_repeat('ก', 251)), 'เหตุผลยาวเกิน');
    assert_same(0, (int) Database::value('SELECT COUNT(*) FROM car_blocks WHERE car_id = :c', ['c' => $car]));
}

function test_db_block_default_reason_when_blank(): void
{
    $car = t_make_car();
    $id = t_block($car, '2031-07-07 10:00:00', '2031-07-08 10:00:00', '   ');
    assert_same('ปิดการใช้งาน', Database::value('SELECT reason FROM car_blocks WHERE id = :i', ['i' => $id]));
}

function test_db_block_remove(): void
{
    $car = t_make_car();
    $id = t_block($car, '2031-07-07 10:00:00', '2031-07-08 10:00:00');
    CarBlockService::remove($id, null);
    assert_same(0, CarBlock::overlapCount($car, '2031-07-07 00:00:00', '2031-07-09 00:00:00'));
    t_expect_refused(fn() => CarBlockService::remove($id, null), 'ลบซ้ำ');
}

function test_db_block_upcoming_lists_future_only_with_plate(): void
{
    $car = t_make_car();
    $past = t_block($car, '2001-01-01 10:00:00', '2001-01-02 10:00:00');
    $future = t_block($car, '2038-01-01 10:00:00', '2038-01-02 10:00:00');
    $ids = array_map('intval', array_column(CarBlock::upcoming(500), 'id'));
    assert_true(in_array($future, $ids, true) && !in_array($past, $ids, true));
    assert_true(isset(CarBlock::upcoming(1)[0]['plate']));
}
