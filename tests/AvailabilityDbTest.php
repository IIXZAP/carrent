<?php
use App\Core\Database;
use App\Models\Setting;
use App\Services\AvailabilityService;

// ---------- ตัวช่วยสร้างข้อมูลทดสอบ (ทุกเทสต์ test_db_* ถูก rollback ให้เอง) ----------

function t_make_car(string $status = 'available', string $plate = ''): int
{
    $plate = $plate !== '' ? $plate : 'T-' . bin2hex(random_bytes(4));
    return Database::insert(
        "INSERT INTO cars (plate, brand, model, car_type_code, price_day, status) VALUES (:p, 'Test', 'Car', 'sedan', 1000, :s)",
        ['p' => $plate, 's' => $status]
    );
}

function t_make_booking(int $carId, string $pickup, string $return, string $status = 'confirmed'): int
{
    static $n = 0;
    $n++;
    return Database::insert(
        "INSERT INTO bookings (booking_no, car_id, contact_name, contact_phone, pickup_at, return_at, status)
         VALUES (:no, :car, 'ทดสอบ', '0800000000', :p, :r, :s)",
        ['no' => 'TST' . getmypid() . '-' . $n, 'car' => $carId, 'p' => $pickup, 'r' => $return, 's' => $status]
    );
}

function t_make_block(int $carId, string $start, string $end): void
{
    Database::run('INSERT INTO car_blocks (car_id, start_at, end_at) VALUES (:c, :s, :e)', ['c' => $carId, 's' => $start, 'e' => $end]);
}

function t_ids(array $cars): array
{
    $ids = array_map(static fn(array $c): int => (int) $c['id'], $cars);
    sort($ids);
    return $ids;
}

// ---------- เทสต์ ----------

function test_db_available_when_no_bookings(): void
{
    Setting::set('booking_gap_hours', '4');
    $car = t_make_car();
    assert_true(AvailabilityService::isCarAvailable($car, '2030-02-10 10:00:00', '2030-02-12 10:00:00'));
}

function test_db_overlapping_booking_blocks(): void
{
    Setting::set('booking_gap_hours', '0');
    $car = t_make_car();
    t_make_booking($car, '2030-02-10 10:00:00', '2030-02-12 10:00:00');

    assert_true(!AvailabilityService::isCarAvailable($car, '2030-02-11 10:00:00', '2030-02-13 10:00:00'), 'ทับท้าย');
    assert_true(!AvailabilityService::isCarAvailable($car, '2030-02-09 10:00:00', '2030-02-10 12:00:00'), 'ทับหัว');
    assert_true(!AvailabilityService::isCarAvailable($car, '2030-02-10 12:00:00', '2030-02-11 12:00:00'), 'อยู่ข้างใน');
    assert_true(!AvailabilityService::isCarAvailable($car, '2030-02-09 10:00:00', '2030-02-13 10:00:00'), 'ครอบทั้งช่วง');
}

function test_db_back_to_back_allowed_only_with_no_gap(): void
{
    $car = t_make_car();
    t_make_booking($car, '2030-02-10 10:00:00', '2030-02-12 10:00:00');

    Setting::set('booking_gap_hours', '0');
    assert_true(AvailabilityService::isCarAvailable($car, '2030-02-12 10:00:00', '2030-02-14 10:00:00'), 'ติดกันเป๊ะ ถ้า gap = 0');

    Setting::set('booking_gap_hours', '4');
    assert_true(!AvailabilityService::isCarAvailable($car, '2030-02-12 10:00:00', '2030-02-14 10:00:00'), 'ติดกันเป๊ะ ไม่ได้เมื่อ gap = 4 ชม.');
    assert_true(!AvailabilityService::isCarAvailable($car, '2030-02-12 13:59:00', '2030-02-14 10:00:00'), 'ห่างไม่ถึง 4 ชม.');
    assert_true(AvailabilityService::isCarAvailable($car, '2030-02-12 14:00:00', '2030-02-14 10:00:00'), 'ห่างครบ 4 ชม.');
    assert_true(AvailabilityService::isCarAvailable($car, '2030-02-08 10:00:00', '2030-02-10 06:00:00'), 'จบก่อนใบจองเดิมเริ่ม 4 ชม.');
    assert_true(!AvailabilityService::isCarAvailable($car, '2030-02-08 10:00:00', '2030-02-10 06:01:00'), 'จบก่อนใบจองเดิมไม่ถึง 4 ชม.');
}

function test_db_cancelled_and_finished_bookings_do_not_block(): void
{
    Setting::set('booking_gap_hours', '0');
    $car = t_make_car();
    t_make_booking($car, '2030-02-10 10:00:00', '2030-02-12 10:00:00', 'cancelled');
    t_make_booking($car, '2030-02-10 10:00:00', '2030-02-12 10:00:00', 'completed');
    t_make_booking($car, '2030-02-10 10:00:00', '2030-02-12 10:00:00', 'returned');
    assert_true(AvailabilityService::isCarAvailable($car, '2030-02-10 10:00:00', '2030-02-12 10:00:00'));
}

function test_db_every_active_status_blocks(): void
{
    Setting::set('booking_gap_hours', '0');
    foreach (['pending', 'confirmed', 'picked_up'] as $status) {
        $car = t_make_car();
        t_make_booking($car, '2030-02-10 10:00:00', '2030-02-12 10:00:00', $status);
        assert_true(!AvailabilityService::isCarAvailable($car, '2030-02-11 10:00:00', '2030-02-11 18:00:00'), $status);
    }
}

function test_db_car_block_blocks(): void
{
    Setting::set('booking_gap_hours', '0');
    $car = t_make_car();
    t_make_block($car, '2030-02-10 00:00:00', '2030-02-15 00:00:00');
    assert_true(!AvailabilityService::isCarAvailable($car, '2030-02-14 10:00:00', '2030-02-16 10:00:00'));
    assert_true(AvailabilityService::isCarAvailable($car, '2030-02-15 00:00:00', '2030-02-16 10:00:00'));
}

function test_db_unavailable_car_status_or_deleted(): void
{
    foreach (['maintenance', 'inactive'] as $status) {
        $car = t_make_car($status);
        assert_true(!AvailabilityService::isCarAvailable($car, '2030-02-10 10:00:00', '2030-02-12 10:00:00'), $status);
    }
    $deleted = t_make_car();
    Database::run('UPDATE cars SET deleted_at = NOW() WHERE id = :id', ['id' => $deleted]);
    assert_true(!AvailabilityService::isCarAvailable($deleted, '2030-02-10 10:00:00', '2030-02-12 10:00:00'), 'ลบแล้ว');
    assert_true(!AvailabilityService::isCarAvailable(999999999, '2030-02-10 10:00:00', '2030-02-12 10:00:00'), 'ไม่มีรถคันนี้');
}

function test_db_overdue_car_still_out_is_unavailable(): void
{
    Setting::set('booking_gap_hours', '0');
    $car = t_make_car();
    // รับรถไปแล้ว เลยกำหนดคืนไปนานแล้ว แต่ยังไม่กดรับคืน → ห้ามปล่อยจองช่วงอนาคต
    t_make_booking($car, '2020-01-01 10:00:00', '2020-01-03 10:00:00', 'picked_up');
    assert_true(!AvailabilityService::isCarAvailable($car, '2030-02-10 10:00:00', '2030-02-12 10:00:00'));

    $returned = t_make_car();
    t_make_booking($returned, '2020-01-01 10:00:00', '2020-01-03 10:00:00', 'returned');
    assert_true(AvailabilityService::isCarAvailable($returned, '2030-02-10 10:00:00', '2030-02-12 10:00:00'));
}

function test_db_ignore_own_booking(): void
{
    Setting::set('booking_gap_hours', '4');
    $car = t_make_car();
    $own = t_make_booking($car, '2030-02-10 10:00:00', '2030-02-12 10:00:00');
    assert_true(!AvailabilityService::isCarAvailable($car, '2030-02-10 10:00:00', '2030-02-13 10:00:00'));
    assert_true(AvailabilityService::isCarAvailable($car, '2030-02-10 10:00:00', '2030-02-13 10:00:00', $own), 'เช่าต่อใบจองตัวเอง');

    $overdue = t_make_car();
    $ownOverdue = t_make_booking($overdue, '2020-01-01 10:00:00', '2020-01-03 10:00:00', 'picked_up');
    assert_true(AvailabilityService::isCarAvailable($overdue, '2030-02-10 10:00:00', '2030-02-12 10:00:00', $ownOverdue));
}

function test_db_available_cars_list_matches_is_car_available(): void
{
    Setting::set('booking_gap_hours', '4');
    $free     = t_make_car();
    $booked   = t_make_car();
    $blocked  = t_make_car();
    $maint    = t_make_car('maintenance');
    $overdue  = t_make_car();
    $adjacent = t_make_car();
    t_make_booking($booked, '2030-03-10 10:00:00', '2030-03-12 10:00:00');
    t_make_block($blocked, '2030-03-11 00:00:00', '2030-03-11 23:00:00');
    t_make_booking($overdue, '2020-01-01 10:00:00', '2020-01-03 10:00:00', 'picked_up');
    t_make_booking($adjacent, '2030-03-08 10:00:00', '2030-03-11 08:00:00');   // จบห่างจากที่ขอเริ่ม 2 ชม. < gap

    $pickup = '2030-03-11 10:00:00';
    $return = '2030-03-13 10:00:00';
    $listed = t_ids(AvailabilityService::availableCars($pickup, $return));

    $mine = [$free, $booked, $blocked, $maint, $overdue, $adjacent];
    foreach ($mine as $id) {
        assert_same(
            AvailabilityService::isCarAvailable($id, $pickup, $return),
            in_array($id, $listed, true),
            "รถ $id: รายการรถว่างกับ isCarAvailable ต้องตรงกัน"
        );
    }
    assert_true(in_array($free, $listed, true));
    assert_true(!in_array($booked, $listed, true));
    assert_true(!in_array($adjacent, $listed, true));
}

function test_db_available_cars_filter_by_type_and_price(): void
{
    Setting::set('booking_gap_hours', '4');
    $car = t_make_car();
    Database::run("UPDATE cars SET car_type_code = 'suv', price_day = 1000, price_week = 6000 WHERE id = :id", ['id' => $car]);

    $suv = AvailabilityService::availableCars('2030-04-01 10:00:00', '2030-04-08 10:00:00', 'suv');
    $mine = array_values(array_filter($suv, static fn(array $c): bool => (int) $c['id'] === $car));
    assert_same(1, count($mine));
    assert_same(7, $mine[0]['_days']);
    assert_money(6000, $mine[0]['_total'], 'เหมาสัปดาห์');

    $sedan = AvailabilityService::availableCars('2030-04-01 10:00:00', '2030-04-08 10:00:00', 'sedan');
    assert_true(!in_array($car, t_ids($sedan), true));
}

function test_db_booking_check_constraints_accept_quote_amounts(): void
{
    // เงินที่ PricingService คำนวณต้องผ่าน CHECK ของตาราง bookings (subtotal/grand_total) จริง
    $car = t_make_car();
    $q = \App\Services\PricingService::quote(
        ['price_day' => 999.5, 'price_week' => null, 'price_month' => null, 'security_deposit' => 0.0, 'booking_deposit' => 0.0, 'driver_fee_day' => null],
        ['child_seat' => ['name' => 'คาร์ซีท', 'unit' => 'day', 'price' => 150.0]],
        '2030-05-01 10:00:00', '2030-05-04 10:00:00', ['child_seat' => 1], 123.45, 0.0,
        t_rules(['vat_enabled' => true])
    );
    Database::insert(
        "INSERT INTO bookings (booking_no, car_id, contact_name, contact_phone, pickup_at, return_at, rental_days,
                               rental_amount, addon_amount, discount, subtotal, vat_rate, vat_amount, grand_total)
         VALUES ('TSTCHK', :car, 'x', '0', '2030-05-01 10:00:00', '2030-05-04 10:00:00', :days, :ra, :aa, :d, :st, :vr, :va, :gt)",
        ['car' => $car, 'days' => $q['days'], 'ra' => $q['rental_amount'], 'aa' => $q['addon_amount'], 'd' => $q['discount'],
         'st' => $q['subtotal'], 'vr' => $q['vat_rate'], 'va' => $q['vat_amount'], 'gt' => $q['grand_total']]
    );
    assert_true(true);
}
