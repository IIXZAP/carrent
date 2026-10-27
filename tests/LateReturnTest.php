<?php
use App\Services\LateReturnService;

/** กฎค่าปรับจากค่า settings ดิบ (ข้อความ) — ผ่านฟังก์ชันเดียวกับที่ระบบจริงใช้ จึงทดสอบค่าตั้งต้นของแต่ละ key ไปด้วย */
function t_late_rules(array $settings): array
{
    return LateReturnService::rulesFromSettings($settings);
}

function test_late_return_matches_old_system_on_every_golden_case(): void
{
    $golden = json_decode(file_get_contents(__DIR__ . '/golden/late_old.json'), true);
    assert_true(count($golden) > 300, 'ไฟล์เฉลยต้องมีเคสครบ');
    foreach ($golden as $i => $case) {
        $result = LateReturnService::calculate(
            $case['booking'], ['price_day' => $case['price_day']], $case['returned_at'],
            $case['mileage_in'], $case['fuel_in'], t_late_rules($case['settings'])
        );
        $label = "เคส #$i ({$case['kind']}) " . json_encode($case['settings'] + ['returned_at' => $case['returned_at']], JSON_UNESCAPED_UNICODE);
        assert_same(json_encode($case['result'], JSON_UNESCAPED_UNICODE), json_encode($result, JSON_UNESCAPED_UNICODE), $label);
    }
}

function test_late_return_hand_checked_examples(): void
{
    $rules = t_late_rules([]);   // ค่าตั้งต้น: ผ่อนผัน 2 ชม., เต็มวัน 6 ชม., ชม.ละ 200, น้ำมันขีดละ 300
    $booking = ['return_at' => '2030-03-10 10:00:00', 'fuel_out' => 8, 'mileage_out' => 10000, 'rental_days' => 2];
    $car = ['price_day' => 1000.0];
    $at = static fn(int $minutes): string => date('Y-m-d H:i:s', strtotime('2030-03-10 10:00:00') + $minutes * 60);
    $total = static fn(int $minutes): float => LateReturnService::calculate($booking, $car, $at($minutes), 10100, 8, $rules)['total'];

    assert_money(0, $total(-60), 'คืนก่อนเวลา');
    assert_money(0, $total(120), 'พอดีเวลาผ่อนผัน 2 ชม. ไม่คิด');
    assert_money(200, $total(121), 'เกินผ่อนผัน 1 นาที ปัดเป็น 3 ชม. − ผ่อนผัน 2 = 1 ชม. × 200');
    assert_money(800, $total(359), '5 ชม. 59 นาที = 6 ชม. − 2 = 4 ชม. × 200');
    assert_money(1000, $total(361), 'เกินเกณฑ์เต็มวัน 6 ชม. = ค่าเช่า 1 วัน');
    assert_money(1000, $total(1440 + 100), '1 วัน 1 ชม. 40 นาที (เศษอยู่ในผ่อนผัน) = 1 วัน ไม่ใช่ 2 วัน');
    assert_money(3000, $total(1440 * 2 + 400), '2 วัน + เศษ 6 ชม. 40 นาที (เกินเกณฑ์เต็มวัน 6 ชม.) = 3 วัน');
}

function test_late_return_hourly_fee_is_capped_at_one_day(): void
{
    // ค่าปรับรายชั่วโมงของเศษวันต้องไม่เกินค่าเช่า 1 วัน (ไม่งั้นคืนช้า 5 ชม. แพงกว่า 7 ชม. ที่คิดเต็มวัน)
    $rules = t_late_rules(['late_fee_per_hour' => '900']);
    $booking = ['return_at' => '2030-03-10 10:00:00', 'fuel_out' => null, 'mileage_out' => null, 'rental_days' => 1];
    $r = LateReturnService::calculate($booking, ['price_day' => 1000.0], '2030-03-10 15:00:00', null, null, $rules);
    assert_money(1000, $r['total'], '3 ชม. × 900 = 2,700 ถูกจำกัดที่ 1,000');
}

function test_late_return_fuel_and_km_lines(): void
{
    $rules = t_late_rules(['free_km_per_day' => '100', 'over_km_fee' => '4']);
    $booking = ['return_at' => '2030-03-10 10:00:00', 'fuel_out' => 8, 'mileage_out' => 10000, 'rental_days' => 2];
    $r = LateReturnService::calculate($booking, ['price_day' => 1000.0], '2030-03-10 10:00:00', 10500, 5, $rules);
    $byKey = [];
    foreach ($r['lines'] as $line) {
        $byKey[$line['key']] = $line['amount'];
    }
    assert_money(900, $byKey['fuel'], 'ขาด 3 ขีด × 300');
    assert_money(1200, $byKey['km'], 'ใช้ 500 กม. ฟรี 200 กม. (100 × 2 วัน) เกิน 300 × 4');
    assert_money(2100, $r['total']);
    assert_same(['fuel', 'km'], array_keys($byKey));
}
