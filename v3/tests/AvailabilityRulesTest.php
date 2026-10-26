<?php
use App\Services\AvailabilityService;

function t_avail_rules(array $override = []): array
{
    return $override + [
        'open_time' => '08:00', 'close_time' => '20:00',
        'airport_open_time' => '05:00', 'airport_close_time' => '24:00',
        'lead_hours' => 3, 'min_days' => 1,
    ];
}

/** "now" คงที่ = 2030-01-01 09:00 */
function t_avail_now(): int
{
    return strtotime('2030-01-01 09:00:00');
}

function t_avail_check(string $pickup, string $return, string $pt = 'shop', string $rt = 'shop', array $rules = []): ?string
{
    return AvailabilityService::validatePeriod($pickup, $return, $pt, $rt, t_avail_now(), t_avail_rules($rules));
}

function test_period_valid(): void
{
    assert_same(null, t_avail_check('2030-01-05 10:00:00', '2030-01-07 10:00:00'));
}

function test_period_return_must_be_after_pickup(): void
{
    assert_same('วันคืนรถต้องหลังวันรับรถ', t_avail_check('2030-01-05 10:00:00', '2030-01-05 10:00:00'));
    assert_same('วันคืนรถต้องหลังวันรับรถ', t_avail_check('2030-01-05 10:00:00', '2030-01-04 10:00:00'));
    assert_same('รูปแบบวันที่ไม่ถูกต้อง', t_avail_check('xx', 'yy'));
}

function test_period_shop_hours(): void
{
    assert_true(t_avail_check('2030-01-05 07:59:00', '2030-01-07 10:00:00') !== null, 'รับก่อนร้านเปิด');
    assert_same(null, t_avail_check('2030-01-05 08:00:00', '2030-01-07 20:00:00'), 'ขอบเวลาเปิด/ปิดใช้ได้');
    assert_true(t_avail_check('2030-01-05 10:00:00', '2030-01-07 20:01:00') !== null, 'คืนหลังร้านปิด');
}

function test_period_airport_hours_are_wider(): void
{
    assert_same(null, t_avail_check('2030-01-05 05:30:00', '2030-01-07 23:30:00', 'airport', 'airport'));
    assert_true(t_avail_check('2030-01-05 05:30:00', '2030-01-07 23:30:00', 'shop', 'shop') !== null);
    assert_same(null, t_avail_check('2030-01-05 10:00:00', '2030-01-07 23:30:00', 'shop', 'airport'), 'รับที่ร้าน คืนที่สนามบิน');
}

function test_period_lead_time(): void
{
    // now = 09:00, ต้องจองล่วงหน้า 3 ชม. → รับได้เร็วสุด 12:00
    assert_true(t_avail_check('2030-01-01 11:59:00', '2030-01-03 12:00:00') !== null);
    assert_same(null, t_avail_check('2030-01-01 12:00:00', '2030-01-03 12:00:00'));
}

function test_period_min_and_max_days(): void
{
    $rules = ['min_days' => 3];
    assert_true(t_avail_check('2030-01-05 10:00:00', '2030-01-07 10:00:00', 'shop', 'shop', $rules) !== null, '2 วัน < ขั้นต่ำ 3');
    assert_same(null, t_avail_check('2030-01-05 10:00:00', '2030-01-08 10:00:00', 'shop', 'shop', $rules));
    assert_true(t_avail_check('2030-01-05 10:00:00', '2031-02-05 10:00:00') !== null, 'เกิน 1 ปี');
}
