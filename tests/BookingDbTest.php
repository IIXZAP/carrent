<?php
use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\AdminBookingService;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\UserError;
use App\Services\WebBookingService;

/** ตั้งค่ากฎให้คงที่ในเทสต์ (ถูก rollback พร้อมเทสต์) */
function t_booking_settings(): void
{
    Setting::set('booking_gap_hours', '4');
    Setting::set('vat_enabled', '0');
    Setting::set('booking_deposit', '1000');
    Setting::set('security_deposit_default', '3000');
    Setting::set('late_grace_hours', '2');
    Setting::set('open_time', '08:00');
    Setting::set('close_time', '20:00');
    Setting::set('booking_lead_hours', '3');
    Setting::set('min_rental_days', '1');
}

function t_contact(string $phone = '0811111111', string $name = 'สมชาย ทดสอบ'): array
{
    return ['name' => $name, 'phone' => $phone, 'email' => '', 'line' => ''];
}

function t_book(int $carId, string $pickup = '2030-06-10 10:00:00', string $return = '2030-06-13 10:00:00', array $contact = [], array $opt = []): int
{
    return BookingService::createBooking($carId, $pickup, $return, $contact ?: t_contact(), $opt);
}

function t_row(string $sql, array $params = []): array
{
    return Database::one($sql, $params) ?? [];
}

// ---------- บันทึกครบถ้วนและตัวเลขถูกต้อง ----------

function test_db_booking_saves_everything(): void
{
    t_booking_settings();
    $car = t_make_car();
    Database::run('UPDATE cars SET price_day = 1000, security_deposit = 5000, booking_deposit = 1500 WHERE id = :id', ['id' => $car]);

    $id = t_book($car, '2030-06-10 10:00:00', '2030-06-13 10:00:00', [], ['addons' => ['child_seat' => 1], 'source' => 'web']);
    $b = Booking::find($id);

    assert_true(preg_match('/^BK\d{6}-\d{3}$/', $b['booking_no']) === 1, 'รูปแบบเลขที่จอง: ' . $b['booking_no']);
    assert_same('pending', $b['status']);
    assert_same('unpaid', $b['payment_status']);
    assert_same('web', $b['source']);
    assert_same(3, (int) $b['rental_days']);
    assert_money(3000, $b['rental_amount']);
    assert_money(450, $b['addon_amount']);
    assert_money(3450, $b['grand_total']);
    assert_money(1500, $b['deposit_amount']);
    assert_money(5000, $b['security_deposit']);
    assert_same('0811111111', $b['contact_phone']);

    $addons = Booking::addons($id);
    assert_same(1, count($addons));
    $sum = 0.0;
    foreach ($addons as $a) {
        $sum += (float) $a['amount'];
    }
    assert_money($b['addon_amount'], $sum, 'ผลรวมบริการเสริม = bookings.addon_amount');
    assert_true(json_decode($b['rate_breakdown'], true) !== null, 'rate_breakdown เป็น JSON');

    $customer = Customer::find((int) $b['customer_id']);
    assert_true(preg_match('/^CUS-\d{5}$/', $customer['code']) === 1, 'รหัสลูกค้า');
    assert_same('สมชาย ทดสอบ', $customer['full_name']);
}

function test_db_booking_blocks_the_car_afterwards(): void
{
    t_booking_settings();
    $car = t_make_car();
    assert_true(AvailabilityService::isCarAvailable($car, '2030-06-10 10:00:00', '2030-06-13 10:00:00'));
    t_book($car);
    assert_true(!AvailabilityService::isCarAvailable($car, '2030-06-11 10:00:00', '2030-06-12 10:00:00'));
}

// ---------- ไม่ว่าง = ไม่เหลืออะไรค้างใน DB ----------

function test_db_unavailable_booking_is_refused_and_rolled_back(): void
{
    t_booking_settings();
    $car = t_make_car();
    t_book($car);

    $customers = (int) Database::value('SELECT COUNT(*) FROM customers');
    $bookings  = (int) Database::value('SELECT COUNT(*) FROM bookings WHERE car_id = :c', ['c' => $car]);
    $thrown = null;
    try {
        t_book($car, '2030-06-11 10:00:00', '2030-06-12 10:00:00', t_contact('0822222222', 'คนที่สอง'));
    } catch (UserError $e) {
        $thrown = $e;
    }
    assert_true($thrown !== null, 'ต้อง throw UserError');
    assert_same($bookings, (int) Database::value('SELECT COUNT(*) FROM bookings WHERE car_id = :c', ['c' => $car]));
    assert_same($customers, (int) Database::value('SELECT COUNT(*) FROM customers'), 'ลูกค้าใหม่ต้องไม่ถูกสร้างค้างไว้');
    assert_same(null, Customer::findByPhone('0822222222'));
}

function test_db_unknown_or_deleted_car_is_refused(): void
{
    t_booking_settings();
    $deleted = t_make_car();
    Database::run('UPDATE cars SET deleted_at = NOW() WHERE id = :id', ['id' => $deleted]);
    foreach ([999999999, $deleted] as $carId) {
        $refused = false;
        try {
            t_book($carId);
        } catch (UserError $e) {
            $refused = true;
        }
        assert_true($refused, "รถ $carId");
    }
}

// ---------- ลูกค้า / เลขที่จอง ----------

function test_db_same_phone_reuses_customer(): void
{
    t_booking_settings();
    $one = Booking::find(t_book(t_make_car(), '2030-07-01 10:00:00', '2030-07-02 10:00:00'));
    $two = Booking::find(t_book(t_make_car(), '2030-07-05 10:00:00', '2030-07-06 10:00:00'));
    assert_same($one['customer_id'], $two['customer_id']);
}

function test_db_booking_numbers_are_sequential_and_unique(): void
{
    t_booking_settings();
    $numbers = [];
    for ($i = 0; $i < 4; $i++) {
        $numbers[] = Booking::find(t_book(t_make_car(), '2030-08-01 10:00:00', '2030-08-02 10:00:00', t_contact('08' . str_repeat((string) $i, 8))))['booking_no'];
    }
    assert_same(4, count(array_unique($numbers)));
    $last = array_map(static fn(string $n): int => (int) substr($n, -3), $numbers);
    assert_same([$last[0], $last[0] + 1, $last[0] + 2, $last[0] + 3], $last, 'เลขต่อเนื่อง');
}

function test_db_blacklisted_customer_is_refused_unless_staff_overrides(): void
{
    t_booking_settings();
    $cid = Customer::create('คนมีปัญหา', '0833333333', null, null);
    Database::run('UPDATE customers SET is_blacklist = 1 WHERE id = :id', ['id' => $cid]);

    $refused = false;
    try {
        t_book(t_make_car(), '2030-09-01 10:00:00', '2030-09-02 10:00:00', t_contact('0833333333'));
    } catch (UserError $e) {
        $refused = true;
    }
    assert_true($refused, 'blacklist จองไม่ได้');

    $id = BookingService::createBooking(t_make_car(), '2030-09-01 10:00:00', '2030-09-02 10:00:00', t_contact('0833333333'), [], false);
    assert_true($id > 0, 'พนักงานตัดสินใจจองให้ได้');
}

// ---------- ราคา: ส่วนลด / VAT / มัดจำที่พนักงานกรอกเอง ----------

function test_db_staff_overrides_discount_vat_and_deposits(): void
{
    t_booking_settings();
    $car = t_make_car();
    $id = t_book($car, '2030-06-10 10:00:00', '2030-06-12 10:00:00', [], [
        'discount' => 200, 'vat' => true, 'deposit_override' => 99999.0, 'security_override' => 0.0,
    ]);
    $b = Booking::find($id);
    assert_money(2000, $b['rental_amount']);
    assert_money(200, $b['discount']);
    assert_money(1800, $b['subtotal']);
    assert_money(126, $b['vat_amount'], 'VAT 7%');
    assert_money(1926, $b['grand_total']);
    assert_money(1926, $b['deposit_amount'], 'มัดจำไม่เกินยอดรวม');
    assert_money(0, $b['security_deposit'], 'พนักงานตั้งเงินประกัน 0 ได้');
}

function test_db_delivery_out_of_range_is_refused(): void
{
    t_booking_settings();
    $refused = false;
    try {
        t_book(t_make_car(), '2030-06-10 10:00:00', '2030-06-12 10:00:00', [], ['addons' => ['delivery' => 1], 'km' => 500]);
    } catch (UserError $e) {
        $refused = true;
    }
    assert_true($refused);
}

// ---------- ยกเลิก ----------

function test_db_cancel_is_atomic_and_frees_the_car(): void
{
    t_booking_settings();
    $car = t_make_car();
    $id = t_book($car);
    assert_true(!AvailabilityService::isCarAvailable($car, '2030-06-10 10:00:00', '2030-06-13 10:00:00'));

    assert_true(Booking::cancel($id, 'ลูกค้าเปลี่ยนใจ'), 'ครั้งแรกสำเร็จ');
    assert_true(!Booking::cancel($id, 'ซ้ำ'), 'ครั้งที่สองต้องไม่สำเร็จ (สถานะเปลี่ยนไปแล้ว)');
    assert_same('cancelled', Booking::find($id)['status']);
    assert_same('ลูกค้าเปลี่ยนใจ', Booking::find($id)['cancel_reason']);
    assert_true(AvailabilityService::isCarAvailable($car, '2030-06-10 10:00:00', '2030-06-13 10:00:00'), 'รถว่างอีกครั้ง');

    $out = t_book(t_make_car());
    Database::run("UPDATE bookings SET status = 'picked_up' WHERE id = :id", ['id' => $out]);
    assert_true(!Booking::cancel($out, 'x'), 'รับรถไปแล้วยกเลิกไม่ได้');
}

// ---------- ฝั่งเว็บ ----------

function t_web_input(int $carId, array $override = []): array
{
    return $override + [
        'car_id' => $carId, 'pickup' => '2030-06-10T10:00', 'return' => '2030-06-13T10:00', 'addons' => [], 'km' => '',
        'name' => 'ลูกค้าเว็บ', 'phone' => '081-555-1234', 'email' => '', 'line' => '',
        'pick_type' => 'shop', 'ret_type' => 'shop', 'place_pick' => '', 'place_ret' => '', 'note' => '', 'accept' => true,
    ];
}

function test_db_web_booking_success_logs_ip(): void
{
    t_booking_settings();
    $car = t_make_car();
    $r = WebBookingService::create(t_web_input($car), '203.0.113.5');
    assert_same([], $r['errors']);
    assert_same('web', $r['booking']['source']);
    assert_same('0815551234', $r['booking']['contact_phone'], 'เบอร์ถูก normalize');
    assert_same(1, ActivityLog::countByIpSince('booking_created', '203.0.113.5', '2000-01-01 00:00:00'));
}

function test_db_web_booking_validation_errors(): void
{
    t_booking_settings();
    $car = t_make_car();
    $cases = [
        'ไม่ยอมรับเงื่อนไข' => ['accept' => false],
        'ชื่อสั้น'          => ['name' => 'ก'],
        'เบอร์ผิด'          => ['phone' => '12345'],
        'อีเมลผิด'          => ['email' => 'not-an-email'],
        'ไม่ใส่วัน'         => ['pickup' => ''],
        'นอกเวลาทำการ'      => ['pickup' => '2030-06-10T23:00'],
        'คืนก่อนรับ'        => ['return' => '2030-06-09T10:00'],
    ];
    foreach ($cases as $label => $override) {
        $r = WebBookingService::create(t_web_input($car, $override), '203.0.113.9');
        assert_true($r['errors'] !== [] && $r['booking'] === null, $label);
    }
    assert_same(0, (int) Database::value('SELECT COUNT(*) FROM bookings WHERE car_id = :c', ['c' => $car]), 'ไม่มีอะไรถูกบันทึก');
}

function test_db_web_booking_limits_unpaid_per_phone(): void
{
    t_booking_settings();
    for ($i = 0; $i < WebBookingService::MAX_UNPAID_PER_PHONE; $i++) {
        t_book(t_make_car(), '2030-10-0' . ($i + 1) . ' 10:00:00', '2030-10-0' . ($i + 1) . ' 18:00:00', t_contact('0815551234'));
    }
    $r = WebBookingService::create(t_web_input(t_make_car()), '203.0.113.7');
    assert_true($r['errors'] !== [], 'ค้างมัดจำครบจำนวนแล้ว จองใหม่ไม่ได้');
    assert_true(strpos($r['errors'][0], 'ยังไม่ได้ชำระมัดจำ') !== false);
}

function test_db_web_booking_limits_per_ip(): void
{
    t_booking_settings();
    for ($i = 0; $i < WebBookingService::MAX_PER_IP_PER_HOUR; $i++) {
        ActivityLog::log('booking_created', 'bookings', null, 'x', null, '198.51.100.1');
    }
    $r = WebBookingService::create(t_web_input(t_make_car()), '198.51.100.1');
    assert_true($r['errors'] !== [] && strpos($r['errors'][0], 'หลายรายการ') !== false);
    $other = WebBookingService::create(t_web_input(t_make_car(), ['phone' => '0899990000']), '198.51.100.2');
    assert_same([], $other['errors'], 'IP อื่นไม่ถูกกระทบ');
}

function test_db_web_booking_blacklisted_phone_refused(): void
{
    t_booking_settings();
    $cid = Customer::create('คนมีปัญหา', '0815551234', null, null);
    Database::run('UPDATE customers SET is_blacklist = 1 WHERE id = :id', ['id' => $cid]);
    $r = WebBookingService::create(t_web_input(t_make_car()), '203.0.113.8');
    assert_true($r['errors'] !== [] && $r['booking'] === null);
}

// ---------- ฝั่งหลังบ้าน ----------

function test_db_admin_booking_create(): void
{
    t_booking_settings();
    $user = Database::insert("INSERT INTO users (name, email, password_hash, role) VALUES ('t', :e, 'x', 'staff')", ['e' => 't' . bin2hex(random_bytes(4)) . '@x.test']);
    $car = t_make_car();

    $bad = AdminBookingService::create(['car_id' => $car, 'name' => '', 'phone' => 'x', 'pickup_at' => '', 'return_at' => ''], $user, '192.0.2.77');
    assert_true($bad['errors'] !== [] && $bad['id'] === null);

    $ok = AdminBookingService::create([
        'car_id' => $car, 'name' => 'ลูกค้าโทรมา', 'phone' => '+66 82 000 1111', 'pickup_at' => '2030-06-10T06:00', 'return_at' => '2030-06-12T23:00',
        'source' => 'phone', 'deposit_amount' => '', 'security_deposit' => '2,500',
    ], $user, '192.0.2.77');
    assert_same([], $ok['errors']);
    $b = Booking::find($ok['id']);
    assert_same('phone', $b['source']);
    assert_same($user, (int) $b['created_by']);
    assert_same('0820001111', $b['contact_phone']);
    assert_money(2500, $b['security_deposit'], 'พนักงานกรอกเงินประกันเอง');
    assert_money(1000, $b['deposit_amount'], 'มัดจำเว้นว่าง = ตามระบบ');
    assert_same(1, ActivityLog::countByIpSince('booking_created', '192.0.2.77', '2000-01-01 00:00:00'));

    $again = AdminBookingService::create([
        'car_id' => $car, 'name' => 'ซ้อน', 'phone' => '0829999999', 'pickup_at' => '2030-06-11T10:00', 'return_at' => '2030-06-12T10:00',
    ], $user, '192.0.2.77');
    assert_true($again['errors'] !== [], 'จองซ้อนช่วงเดิมไม่ได้แม้เป็นพนักงาน');
}
