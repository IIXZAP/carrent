<?php
use App\Services\DeliveryFeeService;
use App\Services\PricingService;

/** กฎราคามาตรฐานสำหรับเทสต์ */
function t_rules(array $override = []): array
{
    return $override + [
        'vat_enabled' => false, 'vat_rate' => 7.0,
        'booking_deposit' => 1000.0, 'deposit_percent' => 20.0, 'security_deposit_default' => 3000.0,
        'free_km' => 30.0, 'delivery_tiers' => DeliveryFeeService::parseTiers('30:0,50:500,80:1000,120:1500,200:2500'),
    ];
}

function t_car(array $override = []): array
{
    return $override + [
        'price_day' => 1000.0, 'price_week' => null, 'price_month' => null,
        'security_deposit' => 0.0, 'booking_deposit' => 0.0, 'driver_fee_day' => null,
    ];
}

function t_catalog(): array
{
    return [
        'delivery'        => ['name' => 'ส่งรถถึงที่', 'unit' => 'trip', 'price' => 0.0],
        'return_delivery' => ['name' => 'รับรถคืนถึงที่', 'unit' => 'trip', 'price' => 0.0],
        'driver'          => ['name' => 'คนขับรถ', 'unit' => 'day', 'price' => 0.0],
        'child_seat'      => ['name' => 'คาร์ซีทเด็ก', 'unit' => 'day', 'price' => 150.0],
        'gps'             => ['name' => 'GPS', 'unit' => 'day', 'price' => 100.0],
    ];
}

// ---------- เทียบกับค่าที่ได้จากระบบเดิม (golden) ----------

function test_pricing_rental_matches_old_system(): void
{
    $golden = json_decode(file_get_contents(__DIR__ . '/golden/rental_old.json'), true);
    foreach ($golden['rental'] as $case) {
        [$day, $week, $month] = $case['car'];
        // ระบบเดิมใช้ 0 แทน "ไม่ได้ตั้ง" ; v3 ใช้ NULL
        $car = t_car(['price_day' => (float) $day, 'price_week' => $week > 0 ? (float) $week : null,
                         'price_month' => $month > 0 ? (float) $month : null]);
        $result = PricingService::calcRental($car, $case['days']);
        $label = json_encode($case['car']) . " × {$case['days']} วัน";

        assert_money($case['total'], $result['total'], "ยอดรวม $label");
        $lines = array_map(static fn(array $l): array => [$l['qty'], $l['unit'], $l['amount']], $result['lines']);
        assert_same(json_encode($case['lines']), json_encode($lines), "รายการ $label");
    }
}

function test_pricing_days_match_old_system(): void
{
    $golden = json_decode(file_get_contents(__DIR__ . '/golden/rental_old.json'), true);
    foreach ($golden['days'] as $case) {
        assert_same($case['days'], PricingService::rentalDays($case['pickup'], $case['return']), "{$case['hours']} ชม.");
    }
}

// ---------- กฎที่ต้องไม่ถูกทำลาย ----------

function test_pricing_grace_two_hours(): void
{
    assert_same(1, PricingService::rentalDays('2030-01-10 10:00:00', '2030-01-11 12:00:00'), '26 ชม. = 1 วัน');
    assert_same(2, PricingService::rentalDays('2030-01-10 10:00:00', '2030-01-11 12:30:00'), '26.5 ชม. = 2 วัน');
}

function test_pricing_invalid_range_is_zero_days(): void
{
    assert_same(0, PricingService::rentalDays('2030-01-10 10:00:00', '2030-01-10 10:00:00'));
    assert_same(0, PricingService::rentalDays('2030-01-10 10:00:00', '2030-01-09 10:00:00'));
    assert_same(0, PricingService::rentalDays('abc', 'def'));
}

function test_pricing_never_dearer_than_daily_and_monotonic(): void
{
    $car = t_car(['price_day' => 1000.0, 'price_week' => 6000.0, 'price_month' => 20000.0]);
    $previous = 0.0;
    for ($days = 1; $days <= 120; $days++) {
        $total = PricingService::calcRental($car, $days)['total'];
        assert_true($total <= $days * 1000.0 + 0.001, "$days วัน แพงกว่ารายวันล้วน");
        assert_true($total >= $previous - 0.001, "$days วัน ราคาลดลงเมื่อเช่านานขึ้น");
        $previous = $total;
    }
}

// ---------- บริการเสริม ----------

function test_pricing_addons_per_day_and_per_trip(): void
{
    $addon = PricingService::calcAddons(t_car(), t_catalog(), ['child_seat' => 2, 'gps' => 1], 3, 0.0, 0.0);
    assert_money(2 * 3 * 150 + 1 * 3 * 100, $addon['total'], 'คาร์ซีท 2 ตัว + GPS × 3 วัน');
    assert_same(2, count($addon['lines']));
}

function test_pricing_free_delivery_still_shown_on_quote(): void
{
    $addon = PricingService::calcAddons(t_car(), t_catalog(), ['delivery' => 1], 2, 10.0, 0.0);
    assert_same(1, count($addon['lines']));
    assert_money(0, $addon['total']);
    assert_true(strpos($addon['lines'][0]['label'], 'ในระยะฟรี') !== false);
}

function test_pricing_driver_uses_car_override(): void
{
    $car = t_car(['driver_fee_day' => 800.0]);
    $addon = PricingService::calcAddons($car, t_catalog(), ['driver' => 1], 2, 0.0, 0.0);
    assert_money(1600, $addon['total']);
    $noPrice = PricingService::calcAddons(t_car(), t_catalog(), ['driver' => 1], 2, 0.0, 0.0);
    assert_same([], $noPrice['lines'], 'ไม่ตั้งราคาคนขับ = ไม่ขึ้นบิล');
}

function test_delivery_fee_tiers(): void
{
    $tiers = DeliveryFeeService::parseTiers('50:500,30:0,80:1000');
    assert_same(30.0, $tiers[0]['max_km'], 'เรียงจากใกล้ไปไกล');
    assert_same(0.0, DeliveryFeeService::feeForKm(30.0, 30.0, $tiers));
    assert_same(500.0, DeliveryFeeService::feeForKm(30.1, 30.0, $tiers));
    assert_same(500.0, DeliveryFeeService::feeForKm(50.0, 30.0, $tiers));
    assert_same(1000.0, DeliveryFeeService::feeForKm(80.0, 30.0, $tiers));
    assert_same(null, DeliveryFeeService::feeForKm(80.1, 30.0, $tiers), 'เกินช่วงสุดท้าย = ไม่มีราคา');
    assert_same([], DeliveryFeeService::parseTiers('ขยะ,:,0:5'));
}

// ---------- ใบเสนอราคา ----------

function test_quote_basic_no_vat(): void
{
    $car = t_car(['price_day' => 1000.0, 'security_deposit' => 5000.0, 'booking_deposit' => 1500.0]);
    $q = PricingService::quote($car, t_catalog(), '2030-01-10 10:00:00', '2030-01-13 10:00:00', ['child_seat' => 1], 0.0, 0.0, t_rules());
    assert_same(3, $q['days']);
    assert_money(3000, $q['rental_amount']);
    assert_money(450, $q['addon_amount']);
    assert_money(3450, $q['subtotal']);
    assert_money(0, $q['vat_amount']);
    assert_money(3450, $q['grand_total']);
    assert_money(1500, $q['deposit_amount'], 'มัดจำตามรถ');
    assert_money(5000, $q['security_deposit']);
    assert_money(1950, $q['balance_on_pickup']);
    assert_money(6950, $q['due_on_pickup'], 'วันรับรถจ่าย = ที่เหลือ + เงินประกัน');
}

function test_quote_vat_and_db_check_formulas(): void
{
    // สูตรที่ CHECK ของตาราง bookings บังคับ ต้องเป็นจริงเสมอ แม้มี VAT/ส่วนลดที่ทำให้เกิดเศษสตางค์
    $car = t_car(['price_day' => 999.5]);
    foreach ([0.0, 100.25, 333.33] as $discount) {
        $q = PricingService::quote($car, t_catalog(), '2030-01-10 10:00:00', '2030-01-17 10:00:00',
            ['child_seat' => 1], $discount, 0.0, t_rules(['vat_enabled' => true]));
        assert_money($q['rental_amount'] + $q['addon_amount'] - $q['discount'], $q['subtotal'], 'subtotal');
        assert_money($q['subtotal'] + $q['vat_amount'], $q['grand_total'], 'grand_total');
        assert_money(round($q['subtotal'] * 0.07, 2), $q['vat_amount'], 'VAT 7%');
        assert_same(round($q['grand_total'], 2), $q['grand_total'], 'ปัดสตางค์แล้ว');
    }
}

function test_quote_discount_cannot_exceed_total(): void
{
    $q = PricingService::quote(t_car(), t_catalog(), '2030-01-10 10:00:00', '2030-01-11 10:00:00', [], 99999.0, 0.0, t_rules());
    assert_money(1000, $q['discount'], 'ส่วนลดถูกจำกัดไม่เกินยอด');
    assert_money(0, $q['subtotal']);
    assert_money(0, $q['grand_total']);
    assert_money(0, $q['deposit_amount'], 'มัดจำไม่เกินยอดรวม');
    assert_money(0, $q['rental_amount'] + $q['addon_amount'] - $q['discount'] - $q['subtotal']);
}

function test_quote_deposit_fallbacks(): void
{
    $args = [t_catalog(), '2030-01-10 10:00:00', '2030-01-11 10:00:00', [], 0.0, 0.0];

    $q = PricingService::quote(t_car(['booking_deposit' => 0.0]), ...$args, ...[t_rules()]);
    assert_money(1000, $q['deposit_amount'], 'ไม่ตั้งที่รถ = ค่ากลางร้าน');

    $q = PricingService::quote(t_car(), ...$args, ...[t_rules(['booking_deposit' => 0.0])]);
    assert_money(200, $q['deposit_amount'], 'ไม่มีทั้งสอง = 20% ของยอดรวม');

    $q = PricingService::quote(t_car(['price_day' => 400.0]), ...$args, ...[t_rules()]);
    assert_money(400, $q['deposit_amount'], 'มัดจำไม่เกินยอดรวม');

    $q = PricingService::quote(t_car(), ...$args, ...[t_rules()]);
    assert_money(3000, $q['security_deposit'], 'ไม่ตั้งที่รถ = ค่ากลางร้าน');
}

function test_quote_delivery_out_of_range_flag(): void
{
    $args = [t_car(), t_catalog(), '2030-01-10 10:00:00', '2030-01-11 10:00:00'];
    $far = PricingService::quote(...$args, ...[['delivery' => 1], 0.0, 500.0, t_rules()]);
    assert_true($far['out_of_range'], 'ไกลเกินช่วง + เลือกส่งรถ');

    $farNoDelivery = PricingService::quote(...$args, ...[[], 0.0, 500.0, t_rules()]);
    assert_true($farNoDelivery['out_of_range'] === false, 'ไกลแต่ไม่ได้เลือกส่งรถ');

    $near = PricingService::quote(...$args, ...[['delivery' => 1, 'return_delivery' => 1], 0.0, 40.0, t_rules()]);
    assert_true($near['out_of_range'] === false);
    assert_money(1000, $near['addon_amount'], 'ส่ง+รับ 40 กม. = 500 × 2');
}

// ---------- ผ่อนผันคืนช้าปรับได้ (settings.late_grace_hours) ----------

function test_pricing_grace_hours_is_configurable(): void
{
    $pickup = '2030-01-10 10:00:00';
    $return = '2030-01-11 13:00:00';   // 27 ชม.
    assert_same(2, PricingService::rentalDays($pickup, $return), 'ผ่อนผัน 2 ชม. (ค่าตั้งต้น): 27 ชม. = 2 วัน');
    assert_same(1, PricingService::rentalDays($pickup, $return, 3), 'ผ่อนผัน 3 ชม.: 27 ชม. = 1 วัน');
    assert_same(2, PricingService::rentalDays($pickup, $return, 0), 'ไม่ผ่อนผัน: 27 ชม. = 2 วัน');
    assert_same(1, PricingService::rentalDays($pickup, '2030-01-11 10:00:00', 0), '24 ชม. ครบพอดี = 1 วัน');

    $car = t_car();
    $loose  = PricingService::quote($car, t_catalog(), $pickup, $return, [], 0.0, 0.0, t_rules(['grace_hours' => 3]));
    $normal = PricingService::quote($car, t_catalog(), $pickup, $return, [], 0.0, 0.0, t_rules());
    assert_same(1, $loose['days'], 'quote ใช้ค่าผ่อนผันจาก rules');
    assert_same(2, $normal['days']);
}
