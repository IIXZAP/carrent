<?php
use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Setting;
use App\Services\AdminBookingService;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\UserError;

/** การจอง 3 วัน × 1,000 + ส่งรถ (ไป-กลับ) ที่ $km กม. */
function t_delivery_booking(float $km = 40.0, array $addons = ['delivery' => 1, 'return_delivery' => 1], array $opt = []): array
{
    t_booking_settings();
    $car = t_make_car();
    Database::run('UPDATE cars SET price_day = 1000, booking_deposit = 1000, security_deposit = 0 WHERE id = :id', ['id' => $car]);
    return Booking::find(t_book($car, '2030-06-10 10:00:00', '2030-06-13 10:00:00', [], $opt + ['addons' => $addons, 'km' => $km]));
}

function t_delivery_lines(array $b): array
{
    return Booking::addons((int) $b['id']);
}

// ---------- ต้องกรอกระยะเมื่อเลือกส่งรถ ----------

function test_quote_flags_missing_distance_when_delivery_selected(): void
{
    $args = [t_car(), t_catalog(), '2030-01-10 10:00:00', '2030-01-11 10:00:00'];
    foreach ([0.0, -5.0] as $km) {
        assert_true(PricingService::quote(...$args, ...[['delivery' => 1], 0.0, $km, t_rules()])['needs_km'], "ส่งรถ km=$km");
        assert_true(PricingService::quote(...$args, ...[['return_delivery' => 1], 0.0, $km, t_rules()])['needs_km'], "รับคืน km=$km");
    }
    assert_true(PricingService::quote(...$args, ...[['delivery' => 1], 0.0, 0.1, t_rules()])['needs_km'] === false, 'มีระยะแล้ว');
    assert_true(PricingService::quote(...$args, ...[[], 0.0, 0.0, t_rules()])['needs_km'] === false, 'ไม่ได้เลือกส่งรถ ไม่ต้องกรอก');
    assert_true(PricingService::quote(...$args, ...[['gps' => 1], 0.0, 0.0, t_rules()])['needs_km'] === false, 'บริการอื่นไม่เกี่ยว');
}

function test_db_booking_refuses_delivery_without_distance(): void
{
    t_booking_settings();
    foreach ([0.0, 0.0] as $km) {
        $refused = false;
        try {
            t_book(t_make_car(), '2030-06-10 10:00:00', '2030-06-12 10:00:00', [], ['addons' => ['delivery' => 1], 'km' => $km]);
        } catch (UserError $e) {
            $refused = strpos($e->getMessage(), 'ระยะทาง') !== false;
        }
        assert_true($refused, 'เลือกส่งรถแต่ไม่กรอกระยะ ต้องถูกปฏิเสธด้วยข้อความที่บอกให้กรอกระยะ');
    }
    assert_true(t_book(t_make_car(), '2030-07-10 10:00:00', '2030-07-12 10:00:00', [], ['addons' => ['delivery' => 1], 'km' => 10]) > 0, 'ระยะในรัศมีฟรี กรอกแล้วจองได้');
}

function test_db_admin_booking_also_requires_distance_for_delivery(): void
{
    t_booking_settings();
    $user = t_user();
    $r = AdminBookingService::create([
        'car_id' => t_make_car(), 'name' => 'x', 'phone' => '0811111111', 'pickup_at' => '2030-06-10T10:00', 'return_at' => '2030-06-12T10:00',
        'addons' => ['delivery' => 1], 'km' => '',
    ], $user, '192.0.2.70');
    assert_true($r['errors'] !== [] && $r['id'] === null);
}

function test_db_booking_stores_the_distance_the_customer_gave(): void
{
    $b = t_delivery_booking(40.0);
    assert_money(40, $b['delivery_km']);
    assert_money(1000, $b['addon_amount'], 'ส่ง+รับ 40 กม. = 500 × 2');
}

// ---------- พนักงานปรับค่าส่ง ----------

function test_db_staff_adjusts_delivery_fee_and_totals_follow(): void
{
    $b = t_delivery_booking(40.0);
    $user = t_user();
    $before = (float) $b['grand_total'];
    assert_money(4000, $before, '3,000 + 1,000');

    BookingService::adjustDelivery((int) $b['id'], null, 800.0, 'ส่งไกลกว่าที่ลูกค้าแจ้ง', $user, '192.0.2.71');
    $after = Booking::find((int) $b['id']);
    assert_money(1600, $after['addon_amount'], '800 × 2 เที่ยว');
    assert_money(4600, $after['subtotal']);
    assert_money(4600, $after['grand_total']);
    foreach (t_delivery_lines($after) as $line) {
        assert_money(800, $line['unit_price']);
        assert_money(800, $line['amount']);
    }
    // ผลรวมแถวบริการเสริมต้องตรงกับยอดในใบจองเสมอ
    $sum = 0.0;
    foreach (t_delivery_lines($after) as $line) {
        $sum += (float) $line['amount'];
    }
    assert_money($sum, $after['addon_amount']);
    assert_same(1, ActivityLog::countByIpSince('delivery_adjusted', '192.0.2.71', '2000-01-01 00:00:00'), 'บันทึกใครแก้');
}

function test_db_adjust_delivery_to_free_and_label_follows(): void
{
    $b = t_delivery_booking(40.0);
    BookingService::adjustDelivery((int) $b['id'], null, 0.0, 'ลูกค้าประจำ ส่งฟรี', t_user(), '192.0.2.72');
    $after = Booking::find((int) $b['id']);
    assert_money(0, $after['addon_amount']);
    assert_money(3000, $after['grand_total']);
    $labels = array_column(t_delivery_lines($after), 'name');
    assert_true(strpos($labels[0], 'ฟรี') !== false, 'ป้ายบอกว่าฟรี: ' . $labels[0]);
    assert_true(strpos($labels[0], '40') !== false, 'ยังบอกระยะ');
}

function test_db_adjust_delivery_by_distance_uses_the_tier_table(): void
{
    $b = t_delivery_booking(40.0);
    BookingService::adjustDelivery((int) $b['id'], 70.0, null, 'ระยะจริง 70 กม.', t_user(), '192.0.2.73');
    $after = Booking::find((int) $b['id']);
    assert_money(70, $after['delivery_km']);
    assert_money(2000, $after['addon_amount'], '51–80 กม. = 1,000 × 2');
}

function test_db_adjust_delivery_recomputes_vat_and_payment_status(): void
{
    $b = t_delivery_booking(40.0, ['delivery' => 1, 'return_delivery' => 1], ['vat' => true]);
    assert_money(280, $b['vat_amount'], '7% ของ 4,000');
    $user = t_user();
    PaymentService::record((int) $b['id'], $user, 'balance', 'cash', (float) $b['grand_total'], null);
    assert_same('paid', Booking::find((int) $b['id'])['payment_status']);

    BookingService::adjustDelivery((int) $b['id'], null, 1000.0, 'ปรับ', $user, '192.0.2.74');
    $after = Booking::find((int) $b['id']);
    assert_money(5000, $after['subtotal'], '3,000 + 2,000');
    assert_money(350, $after['vat_amount']);
    assert_money(5350, $after['grand_total']);
    assert_same('deposit_paid', $after['payment_status'], 'ยอดรวมเพิ่ม จ่ายไว้ไม่ครบแล้ว');
}

function test_db_adjust_delivery_keeps_totals_consistent_with_db_checks(): void
{
    $b = t_delivery_booking(40.0, ['delivery' => 1], ['discount' => 3400.0, 'vat' => true]);   // ส่วนลดเกือบเท่ายอดรวม
    BookingService::adjustDelivery((int) $b['id'], null, 0.0, 'ลดค่าส่งเหลือฟรี', t_user(), '192.0.2.75');
    $after = Booking::find((int) $b['id']);
    assert_money((float) $after['rental_amount'] + (float) $after['addon_amount'] - (float) $after['discount'], $after['subtotal'], 'subtotal = rental + addon − discount');
    assert_money((float) $after['subtotal'] + (float) $after['vat_amount'], $after['grand_total'], 'grand = subtotal + vat');
    assert_true((float) $after['subtotal'] >= 0, 'ส่วนลดต้องไม่เกินยอดรวมหลังปรับ');
    assert_true((float) $after['deposit_amount'] <= (float) $after['grand_total'] + 0.001, 'มัดจำไม่เกินยอดรวม');
}

function test_db_adjust_delivery_validation(): void
{
    $b = t_delivery_booking(40.0);
    $user = t_user();
    $bad = [
        'ไม่ใส่เหตุผล'          => [null, 800.0, ''],
        'เหตุผลสั้นเกิน'        => [null, 800.0, 'x'],
        'ค่าส่งติดลบ'           => [null, -1.0, 'ปรับ'],
        'ค่าส่งสูงเกิน'         => [null, 100000.0, 'ปรับ'],
        'ไม่ใส่ทั้งระยะและค่าส่ง' => [null, null, 'ปรับ'],
        'ระยะเกินตารางค่าส่ง'    => [500.0, null, 'ปรับ'],
    ];
    foreach ($bad as $label => [$km, $fee, $reason]) {
        $refused = false;
        try {
            BookingService::adjustDelivery((int) $b['id'], $km, $fee, $reason, $user, '192.0.2.76');
        } catch (UserError $e) {
            $refused = true;
        }
        assert_true($refused, $label);
    }
    assert_money(1000, Booking::find((int) $b['id'])['addon_amount'], 'ไม่มีอะไรเปลี่ยน');
}

function test_db_adjust_delivery_only_before_pickup_and_only_when_booked_with_delivery(): void
{
    $user = t_user();
    $noDelivery = t_delivery_booking(0.0, []);
    $refused = false;
    try {
        BookingService::adjustDelivery((int) $noDelivery['id'], null, 500.0, 'ปรับ', $user, '192.0.2.77');
    } catch (UserError $e) {
        $refused = true;
    }
    assert_true($refused, 'ไม่ได้จองบริการส่งรถ ปรับค่าส่งไม่ได้');

    foreach (['picked_up', 'returned', 'completed', 'cancelled'] as $status) {
        $b = t_delivery_booking(40.0);
        Database::run('UPDATE bookings SET status = :s WHERE id = :id', ['s' => $status, 'id' => $b['id']]);
        $refused = false;
        try {
            BookingService::adjustDelivery((int) $b['id'], null, 500.0, 'ปรับ', $user, '192.0.2.77');
        } catch (UserError $e) {
            $refused = true;
        }
        assert_true($refused, "สถานะ $status ปรับไม่ได้");
    }
    $confirmed = t_delivery_booking(40.0);
    Database::run("UPDATE bookings SET status = 'confirmed' WHERE id = :id", ['id' => $confirmed['id']]);
    BookingService::adjustDelivery((int) $confirmed['id'], null, 500.0, 'ปรับ', $user, '192.0.2.77');
    assert_money(1000, Booking::find((int) $confirmed['id'])['addon_amount'], 'ยืนยันแล้วแต่ยังไม่รับรถ ปรับได้ (500 × 2)');
}
