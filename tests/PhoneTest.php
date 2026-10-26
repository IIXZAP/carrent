<?php
use App\Services\Phone;

function test_phone_normalize_thai_formats(): void
{
    foreach (['081-234-5678', '081 234 5678', '0812345678', '(081) 234.5678', '+66 81 234 5678', '+66812345678', '0066812345678', '66812345678'] as $input) {
        assert_same('0812345678', Phone::normalize($input), $input);
    }
    assert_same('021234567', Phone::normalize('02-123-4567'), 'เบอร์บ้าน 9 หลัก');
}

function test_phone_normalize_keeps_foreign_numbers_as_digits(): void
{
    assert_same('14155552671', Phone::normalize('+1 (415) 555-2671'));
}

function test_phone_normalize_rejects_bad_input(): void
{
    foreach (['', 'abc', '12345', '0812345678x', '081-234-5678; DROP TABLE', '1234567890123456'] as $input) {
        assert_same('', Phone::normalize($input), "'$input'");
    }
}

function test_phone_display(): void
{
    assert_same('081-234-5678', Phone::display('0812345678'));
    assert_same('021234567', Phone::display('021234567'), 'รูปแบบอื่นแสดงตามเดิม');
}
