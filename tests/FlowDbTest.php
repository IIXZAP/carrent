<?php
use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Inspection;
use App\Models\Payment;
use App\Services\BookingFlowService;
use App\Services\PaymentService;
use App\Services\SecurityDepositService;
use App\Services\UserError;

const T_PICKUP_AT = '2030-06-10 10:05:00';
const T_RETURN_DUE = '2030-06-13 10:00:00';

function t_now(string $datetime): int
{
    return strtotime($datetime);
}

/** การจอง 3 วัน ยืนยันแล้ว (จ่ายมัดจำ 1,000) ยอดรวม 3,000 เงินประกัน 3,000 */
function t_flow_booking(string $phone = '0811111111'): array
{
    $b = t_pay_booking($phone);
    PaymentService::record((int) $b['id'], t_user(), 'deposit', 'cash', 1000.0, null);
    return Booking::find((int) $b['id']);
}

function t_picked_up(array $b, int $mileage = 10000, int $fuel = 8): array
{
    BookingFlowService::pickup((int) $b['id'], $mileage, $fuel, t_user(), '', t_now(T_PICKUP_AT));
    return Booking::find((int) $b['id']);
}

function t_return_input(array $override = []): array
{
    return $override + ['mileage_in' => '10200', 'fuel_in' => '8', 'returned_at' => '', 'chg' => [], 'other_label' => '', 'other_amount' => '', 'damage_note' => ''];
}

function t_expect_refused(callable $fn, string $label): UserError
{
    try {
        $fn();
    } catch (UserError $e) {
        return $e;
    }
    throw new RuntimeException("ต้องถูกปฏิเสธ: $label");
}

// ---------- ยืนยัน ----------

function test_db_confirm_only_from_pending(): void
{
    $b = t_pay_booking();
    BookingFlowService::confirm((int) $b['id'], t_user());
    assert_same('confirmed', Booking::find((int) $b['id'])['status']);
    t_expect_refused(fn() => BookingFlowService::confirm((int) $b['id'], t_user()), 'ยืนยันซ้ำ');
}

// ---------- ส่งมอบ ----------

function test_db_pickup_records_handover_and_locks_the_inspection(): void
{
    $b = t_flow_booking();
    $user = t_user();
    BookingFlowService::pickup((int) $b['id'], 10000, 8, $user, '192.0.2.90', t_now(T_PICKUP_AT));

    $after = Booking::find((int) $b['id']);
    assert_same('picked_up', $after['status']);
    assert_same(T_PICKUP_AT, $after['picked_up_at']);
    $ins = Inspection::get((int) $b['id'], 'out');
    assert_same(10000, (int) $ins['mileage']);
    assert_same(8, (int) $ins['fuel_level']);
    assert_true($ins['locked_at'] !== null, 'ผลตรวจรอบส่งมอบถูกล็อก — สภาพรถวันส่งมอบต้องนิ่ง');
    assert_same($user, (int) $ins['locked_by']);
    assert_same(10000, (int) Car::find((int) $b['car_id'])['mileage'], 'เลขไมล์รถอัปเดต');
    assert_same(1, ActivityLog::countByIpSince('booking_pickup', '192.0.2.90', '2000-01-01 00:00:00'));
}

function test_db_pickup_allowed_from_pending_too_but_not_twice(): void
{
    $b = t_pay_booking();
    BookingFlowService::pickup((int) $b['id'], null, 6, t_user(), '', t_now(T_PICKUP_AT));
    assert_same('picked_up', Booking::find((int) $b['id'])['status']);
    assert_same(6, (int) Inspection::get((int) $b['id'], 'out')['fuel_level']);
    t_expect_refused(fn() => BookingFlowService::pickup((int) $b['id'], 10000, 8, t_user(), '', t_now(T_PICKUP_AT)), 'ส่งมอบซ้ำ');
}

function test_db_pickup_refused_in_other_statuses(): void
{
    foreach (['cancelled', 'returned', 'completed'] as $status) {
        $b = t_pay_booking();
        Database::run('UPDATE bookings SET status = :s WHERE id = :id', ['s' => $status, 'id' => $b['id']]);
        t_expect_refused(fn() => BookingFlowService::pickup((int) $b['id'], 1, 8, t_user(), '', t_now(T_PICKUP_AT)), $status);
    }
}

function test_db_pickup_validation(): void
{
    $b = t_flow_booking();
    foreach ([-1, 9, 100] as $fuel) {
        t_expect_refused(fn() => BookingFlowService::pickup((int) $b['id'], 1000, $fuel, t_user(), '', t_now(T_PICKUP_AT)), "น้ำมัน $fuel");
    }
    t_expect_refused(fn() => BookingFlowService::pickup((int) $b['id'], -5, 8, t_user(), '', t_now(T_PICKUP_AT)), 'ไมล์ติดลบ');
    assert_same('confirmed', Booking::find((int) $b['id'])['status'], 'ไม่มีอะไรเปลี่ยน');
    assert_same(null, Inspection::get((int) $b['id'], 'out'), 'ไม่สร้างใบตรวจค้าง');
}

function test_db_pickup_keeps_mileage_noted_earlier_on_the_inspection_when_form_is_blank(): void
{
    $b = t_flow_booking();
    Inspection::ensure((int) $b['id'], 'out', t_user());
    Inspection::setReadings((int) $b['id'], 'out', 12345, null);
    BookingFlowService::pickup((int) $b['id'], null, 7, t_user(), '', t_now(T_PICKUP_AT));
    $ins = Inspection::get((int) $b['id'], 'out');
    assert_same(12345, (int) $ins['mileage'], 'ใช้เลขไมล์ที่บันทึกไว้ตอนถ่ายรูป');
    assert_same(7, (int) $ins['fuel_level']);
}

function test_db_pickup_refused_while_the_car_is_still_out_with_another_customer(): void
{
    $first = t_flow_booking('0811111111');
    $carId = (int) $first['car_id'];
    $second = Booking::find(t_book($carId, '2030-07-10 10:00:00', '2030-07-12 10:00:00', t_contact('0822222222')));
    PaymentService::record((int) $second['id'], t_user(), 'deposit', 'cash', 1000.0, null);

    t_picked_up($first);
    $e = t_expect_refused(fn() => BookingFlowService::pickup((int) $second['id'], 1, 8, t_user(), '', t_now('2030-07-10 10:00:00')), 'รถยังอยู่กับลูกค้ารายอื่น');
    assert_true(strpos($e->getMessage(), $first['booking_no']) !== false, 'บอกเลขที่จองที่รถยังอยู่: ' . $e->getMessage());

    BookingFlowService::returnCar((int) $first['id'], t_return_input(), t_user(), '', t_now('2030-06-13 09:00:00'));
    BookingFlowService::pickup((int) $second['id'], 10300, 8, t_user(), '', t_now('2030-07-10 10:00:00'));
    assert_same('picked_up', Booking::find((int) $second['id'])['status'], 'คืนแล้วส่งมอบรายถัดไปได้');
}

function test_db_car_mileage_never_goes_backwards(): void
{
    $b = t_flow_booking();
    Database::run('UPDATE cars SET mileage = 50000 WHERE id = :id', ['id' => $b['car_id']]);
    t_picked_up($b, 100);
    assert_same(50000, (int) Car::find((int) $b['car_id'])['mileage']);
}

// ---------- รับคืน + ค่าใช้จ่ายเพิ่ม ----------

function test_db_return_computes_charges_and_updates_everything(): void
{
    $b = t_picked_up(t_flow_booking());
    $bid = (int) $b['id'];
    PaymentService::record($bid, t_user(), 'balance', 'cash', 2000.0, null);   // จ่ายค่าเช่าครบ 3,000
    assert_same('paid', Booking::find($bid)['payment_status']);

    // คืนช้า 3 ชม. 30 นาที + น้ำมันขาด 3 ขีด + วิ่ง 200 กม.
    $result = BookingFlowService::returnCar($bid, t_return_input(['returned_at' => '2030-06-13 13:30', 'fuel_in' => '5', 'damage_note' => 'กันชนมีรอย']), t_user(), '192.0.2.91', t_now('2030-06-13 14:00:00'));

    $keys = array_column($result['lines'], 'key');
    assert_same(['late', 'fuel'], $keys);
    assert_money(400, $result['lines'][0]['amount'], 'ช้า 210 นาที → 4 ชม. − ผ่อนผัน 2 = 2 ชม. × 200');
    assert_money(900, $result['lines'][1]['amount'], '3 ขีด × 300');
    assert_money(1300, $result['extra']);

    $after = Booking::find($bid);
    assert_same('returned', $after['status']);
    assert_same('2030-06-13 13:30:00', $after['returned_at']);
    assert_money(1300, $after['extra_charge']);
    assert_same('กันชนมีรอย', $after['damage_note']);
    assert_true(strpos($after['extra_note'], 'คืนรถช้า') !== false, 'สรุปสั้นๆ: ' . $after['extra_note']);
    $breakdown = json_decode($after['extra_breakdown'], true);
    assert_same(2, count($breakdown));
    assert_money(400, $breakdown[0]['suggested'], 'เก็บยอดแนะนำไว้ตรวจย้อนหลัง');
    assert_same('deposit_paid', $after['payment_status'], 'ยอดรวมเพิ่ม → จ่ายไว้ไม่ครบแล้ว');

    $in = Inspection::get($bid, 'in');
    assert_same(10200, (int) $in['mileage']);
    assert_same(5, (int) $in['fuel_level']);
    assert_true($in['locked_at'] !== null, 'ผลตรวจรอบรับคืนถูกล็อก');
    assert_same(10200, (int) Car::find((int) $b['car_id'])['mileage']);
}

function test_db_return_staff_can_waive_edit_and_add_charges(): void
{
    $b = t_picked_up(t_flow_booking());
    $bid = (int) $b['id'];
    $result = BookingFlowService::returnCar($bid, t_return_input([
        'returned_at' => '2030-06-13 13:30', 'fuel_in' => '5',
        'chg' => ['late' => ['on' => '', 'amount' => '400'], 'fuel' => ['on' => '1', 'amount' => '450']],
        'other_label' => 'ค่าทำความสะอาด', 'other_amount' => '300',
    ]), t_user(), '', t_now('2030-06-13 14:00:00'));

    $byKey = [];
    foreach ($result['lines'] as $line) {
        $byKey[$line['key']] = $line;
    }
    assert_money(0, $byKey['late']['amount'], 'ยกเว้นค่าคืนช้า');
    assert_true($byKey['late']['waived'] === true);
    assert_money(400, $byKey['late']['suggested'], 'ยอดแนะนำยังเก็บไว้');
    assert_money(450, $byKey['fuel']['amount'], 'แก้ยอดน้ำมัน');
    assert_money(900, $byKey['fuel']['suggested']);
    assert_money(300, $byKey['other']['amount']);
    assert_same('ค่าทำความสะอาด', $byKey['other']['label']);
    assert_money(750, Booking::find($bid)['extra_charge'], '0 + 450 + 300');
}

function test_db_return_without_any_charges(): void
{
    $b = t_picked_up(t_flow_booking());
    $result = BookingFlowService::returnCar((int) $b['id'], t_return_input(['returned_at' => '2030-06-13 09:30']), t_user(), '', t_now('2030-06-13 10:00:00'));
    assert_same([], $result['lines']);
    $after = Booking::find((int) $b['id']);
    assert_money(0, $after['extra_charge']);
    assert_same(null, $after['extra_breakdown']);
    assert_same('returned', $after['status']);
}

function test_db_return_validation(): void
{
    $b = t_picked_up(t_flow_booking());
    $id = (int) $b['id'];
    $now = t_now('2030-06-13 10:00:00');
    $cases = [
        'ไมล์คืนน้อยกว่าไมล์ส่งมอบ' => ['mileage_in' => '9000'],
        'ไมล์ไม่ใช่ตัวเลข'        => ['mileage_in' => 'abc'],
        'น้ำมัน 9 ขีด'            => ['fuel_in' => '9'],
        'ไม่ใส่น้ำมัน'            => ['fuel_in' => ''],
        'เวลารับคืนอยู่ในอนาคต'    => ['returned_at' => '2030-06-14 10:00'],
        'เวลารับคืนก่อนส่งมอบ'     => ['returned_at' => '2030-06-10 09:00'],
        'รูปแบบเวลาผิด'           => ['returned_at' => 'เมื่อวาน'],
        'ยอดค่าใช้จ่ายอื่นไม่ถูกต้อง' => ['other_label' => 'x', 'other_amount' => '-5'],
    ];
    foreach ($cases as $label => $override) {
        t_expect_refused(fn() => BookingFlowService::returnCar($id, t_return_input($override), t_user(), '', $now), $label);
    }
    assert_same('picked_up', Booking::find($id)['status'], 'ผิดทุกเคส ไม่มีอะไรเปลี่ยน');
    assert_same(null, Inspection::get($id, 'in'));
}

function test_db_return_only_once_and_only_when_picked_up(): void
{
    $b = t_flow_booking();
    t_expect_refused(fn() => BookingFlowService::returnCar((int) $b['id'], t_return_input(), t_user(), '', t_now(T_RETURN_DUE)), 'ยังไม่ได้ส่งมอบ');
    t_picked_up($b);
    BookingFlowService::returnCar((int) $b['id'], t_return_input(['returned_at' => '2030-06-13 09:00']), t_user(), '', t_now(T_RETURN_DUE));
    t_expect_refused(fn() => BookingFlowService::returnCar((int) $b['id'], t_return_input(['returned_at' => '2030-06-13 09:00']), t_user(), '', t_now(T_RETURN_DUE)), 'รับคืนซ้ำ');
}

function test_db_preview_return_suggests_without_changing_anything(): void
{
    $b = t_picked_up(t_flow_booking());
    $preview = BookingFlowService::previewReturn($b, t_return_input(['returned_at' => '2030-06-13 13:30', 'fuel_in' => '5']), t_now('2030-06-13 14:00:00'));
    assert_same(['late', 'fuel'], array_column($preview['lines'], 'key'));
    assert_same('picked_up', Booking::find((int) $b['id'])['status']);
    assert_same(null, Inspection::get((int) $b['id'], 'in'));
}

// ---------- ปิดงาน ----------

function test_db_complete_only_from_returned(): void
{
    $b = t_picked_up(t_flow_booking());
    t_expect_refused(fn() => BookingFlowService::complete((int) $b['id'], t_user()), 'ยังไม่ได้รับคืน');
    BookingFlowService::returnCar((int) $b['id'], t_return_input(['returned_at' => '2030-06-13 09:00']), t_user(), '', t_now(T_RETURN_DUE));
    BookingFlowService::complete((int) $b['id'], t_user());
    assert_same('completed', Booking::find((int) $b['id'])['status']);
    t_expect_refused(fn() => BookingFlowService::complete((int) $b['id'], t_user()), 'ปิดงานซ้ำ');
}

// ---------- ปิดเงินประกัน ----------

/** คืนรถแล้ว จ่ายค่าเช่าครบ 3,000 + ถือเงินประกัน 3,000 (+ ค่าใช้จ่ายเพิ่ม $extra ที่ยังไม่จ่าย) */
function t_returned_with_security(float $extra = 0.0): array
{
    $b = t_picked_up(t_flow_booking());
    $bid = (int) $b['id'];
    PaymentService::record($bid, t_user(), 'balance', 'cash', 2000.0, null);
    PaymentService::record($bid, t_user(), 'security', 'cash', 3000.0, null);
    $input = t_return_input(['returned_at' => '2030-06-13 09:00']);
    if ($extra > 0) {
        $input['other_label'] = 'ค่าซ่อม';
        $input['other_amount'] = (string) $extra;
    }
    BookingFlowService::returnCar($bid, $input, t_user(), '', t_now(T_RETURN_DUE));
    return Booking::find($bid);
}

function t_settle_input(array $override = []): array
{
    return $override + ['deduct' => '', 'add_amount' => '', 'add_label' => '', 'refund_method' => 'cash', 'refund_note' => ''];
}

function test_db_settle_refunds_the_whole_deposit_when_nothing_is_owed(): void
{
    $b = t_returned_with_security();
    $bid = (int) $b['id'];
    $r = SecurityDepositService::settle($bid, t_settle_input(), t_user(), '192.0.2.92');
    assert_money(0, $r['deduct']);
    assert_money(3000, $r['refund']);
    assert_money(0, Payment::securityHeld($bid), 'ไม่เหลือเงินประกันที่ร้านถือ');
    assert_money(3000, Payment::netPaid($bid), 'การคืนเงินประกันไม่กระทบรายได้');
    $kinds = array_column(Payment::forBooking($bid), 'kind');
    assert_true(in_array('security_refund', $kinds, true));
    t_expect_refused(fn() => SecurityDepositService::settle($bid, t_settle_input(), t_user()), 'ปิดซ้ำ — ไม่มีเงินประกันเหลือ');
}

function test_db_settle_deducts_what_is_owed_and_refunds_the_rest(): void
{
    $b = t_returned_with_security(500.0);    // มีค่าซ่อม 500 ค้างอยู่
    $bid = (int) $b['id'];
    assert_same('deposit_paid', $b['payment_status'], 'ค่าเช่า 3,000 จ่ายแล้ว แต่ค่าซ่อม 500 ยังค้าง');
    $r = SecurityDepositService::settle($bid, t_settle_input(['deduct' => '500']), t_user());
    assert_money(500, $r['deduct']);
    assert_money(2500, $r['refund']);
    assert_money(3500, Payment::netPaid($bid), 'ที่หักจากเงินประกันนับเป็นรายได้');
    assert_money(0, Payment::securityHeld($bid));
    assert_same('paid', Booking::find($bid)['payment_status']);
    $deduct = array_values(array_filter(Payment::forBooking($bid), static fn(array $p): bool => $p['method'] === 'deduct'));
    assert_same(1, count($deduct));
    assert_same('extra', $deduct[0]['kind'], 'มีค่าใช้จ่ายเพิ่ม → บันทึกเป็น extra');
}

function test_db_settle_can_add_a_charge_found_after_return(): void
{
    $b = t_returned_with_security();
    $bid = (int) $b['id'];
    $r = SecurityDepositService::settle($bid, t_settle_input(['add_amount' => '700', 'add_label' => 'ค่าซ่อมกระจก']), t_user());
    assert_money(700, $r['deduct'], 'หักเท่าค่าใช้จ่ายที่เพิ่มเข้า');
    assert_money(2300, $r['refund']);
    $after = Booking::find($bid);
    assert_money(700, $after['extra_charge'], 'ค่าใช้จ่ายเพิ่มถูกบันทึกในใบจอง');
    $lines = json_decode($after['extra_breakdown'], true);
    assert_same('ค่าซ่อมกระจก', $lines[count($lines) - 1]['label']);
    assert_same('paid', $after['payment_status'], 'ค่าซ่อมชำระด้วยเงินประกันแล้ว');
}

function test_db_settle_amounts_are_clamped_and_validated(): void
{
    $b = t_returned_with_security(300.0);
    $bid = (int) $b['id'];
    t_expect_refused(fn() => SecurityDepositService::settle($bid, t_settle_input(['add_amount' => '100', 'add_label' => '']), t_user()), 'เพิ่มค่าใช้จ่ายแต่ไม่บอกว่าค่าอะไร');
    assert_money(3000, Payment::securityHeld($bid), 'ไม่มีอะไรเปลี่ยน');

    // หักเกินยอดค้าง (300) ถูกจำกัดที่ยอดค้าง ; เพิ่มค่าใช้จ่ายเกินเงินประกันถูกจำกัดที่เงินประกัน
    $r = SecurityDepositService::settle($bid, t_settle_input(['deduct' => '99999', 'add_amount' => '99999', 'add_label' => 'ค่าเสียหายหนัก']), t_user());
    assert_money(3000, $r['deduct'], 'หักได้ไม่เกินเงินประกันที่ถือ');
    assert_money(0, $r['refund']);
}

function test_db_settle_only_after_return_or_cancel(): void
{
    $b = t_picked_up(t_flow_booking());
    PaymentService::record((int) $b['id'], t_user(), 'security', 'cash', 3000.0, null);
    t_expect_refused(fn() => SecurityDepositService::settle((int) $b['id'], t_settle_input(), t_user()), 'รถยังอยู่กับลูกค้า');
}

function test_db_settle_for_cancelled_booking_does_not_charge_unpaid_rent(): void
{
    $b = t_pay_booking();
    $bid = (int) $b['id'];
    PaymentService::record($bid, t_user(), 'security', 'cash', 3000.0, null);
    Booking::cancel($bid, 'ลูกค้ายกเลิก');
    $r = SecurityDepositService::settle($bid, t_settle_input(['deduct' => '3000']), t_user());
    assert_money(0, $r['deduct'], 'ยกเลิกแล้ว ค่าเช่าที่ยังไม่จ่ายไม่ใช่หนี้ (ไม่ได้เช่าจริง) จึงหักไม่ได้');
    assert_money(3000, $r['refund']);
}

function test_db_settle_refund_method_whitelist(): void
{
    $b = t_returned_with_security();
    SecurityDepositService::settle((int) $b['id'], t_settle_input(['refund_method' => 'bitcoin', 'refund_note' => 'โอนคืนเข้าบัญชีลูกค้า']), t_user());
    $refund = array_values(array_filter(Payment::forBooking((int) $b['id']), static fn(array $p): bool => $p['kind'] === 'security_refund'));
    assert_same('cash', $refund[0]['method'], 'วิธีที่ไม่รู้จักใช้เงินสด');
    assert_same('โอนคืนเข้าบัญชีลูกค้า', $refund[0]['note']);
}
