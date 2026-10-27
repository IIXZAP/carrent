<?php
use App\Controllers\Admin\SettingController;
use App\Core\Database;

function t_setting_ok(string $type, array $values): void
{
    foreach ($values as $v) {
        assert_same(null, SettingController::validate($type, $v), "$type '$v' ควรผ่าน");
    }
}

function t_setting_bad(string $type, array $values): void
{
    foreach ($values as $v) {
        assert_true(SettingController::validate($type, $v) !== null, "$type '$v' ควรถูกปฏิเสธ");
    }
}

function test_setting_percent(): void
{
    t_setting_ok('percent', ['0', '7', '7.5', '7.25', '100']);
    t_setting_bad('percent', ['', '-1', '100.01', '1000', '7000', 'abc', '7.555', '7,5']);
}

function test_setting_hours_days_km(): void
{
    t_setting_ok('hours', ['0', '3', '24', '720']);
    t_setting_bad('hours', ['', '-1', '721', '1.5', 'x']);
    t_setting_ok('days', ['1', '30', '365']);
    t_setting_bad('days', ['', '0', '366', '-1', '1.5']);
    t_setting_ok('km', ['0', '30', '2000']);
    t_setting_bad('km', ['', '2001', '-1', 'x']);
}

function test_setting_time_money_tiers(): void
{
    t_setting_ok('time', ['08:00', '00:00', '23:59', '24:00']);
    t_setting_bad('time', ['', '8:00', '25:00', '08:60', '24:01', 'abc']);
    t_setting_ok('money', ['0', '1000', '1,000', '1000.50']);
    t_setting_bad('money', ['', '-5', 'abc', '1000.555', '99999999.99']);
    t_setting_ok('tiers', ['30:0,50:500', '30:0, 50:500 ,80:1000']);
    t_setting_bad('tiers', ['', 'ขยะ', ':::', '0:5']);
}

function test_setting_every_field_has_a_known_type_and_fits_its_column(): void
{
    $known = ['text', 'textarea', 'time', 'int', 'money', 'bool', 'tiers', 'percent', 'hours', 'days', 'km'];
    foreach (SettingController::FIELDS as $key => [$label, $type]) {
        assert_true(in_array($type, $known, true), "$key มีชนิดที่ไม่รู้จัก: $type");
    }
    // อัตรา VAT ที่ฟอร์มรับได้สูงสุดต้องบันทึกลงคอลัมน์ bookings.vat_rate ได้จริง (เคยรับ 1000 แต่คอลัมน์รับได้ไม่เกิน 999.99)
    assert_same('percent', SettingController::FIELDS['vat_rate'][1]);
    $max = (float) Database::value("SELECT NUMERIC_PRECISION - NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'vat_rate'");
    assert_true(pow(10, $max) > 100, 'คอลัมน์ vat_rate ต้องรับ 100 ได้');
}

function test_setting_vat_rate_of_1000_is_refused(): void
{
    // บั๊กที่เจอในรีวิว: กรอก 1000 ผ่านฟอร์ม แล้วการจองทุกใบที่คิด VAT บันทึกไม่ได้
    assert_true(SettingController::validate(SettingController::FIELDS['vat_rate'][1], '1000') !== null);
}

function test_setting_textarea_limit(): void
{
    assert_same(null, SettingController::validate('textarea', str_repeat('ก', 4000)));
    assert_true(SettingController::validate('textarea', str_repeat('ก', 4001)) !== null);
}
