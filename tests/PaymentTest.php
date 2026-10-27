<?php
use App\Services\PaymentService;

function test_payment_parse_amount(): void
{
    assert_same(1234.5, PaymentService::parseAmount('1,234.50'));
    assert_same(500.0, PaymentService::parseAmount(' 500 '));
    assert_same(0.25, PaymentService::parseAmount('0.25'));
    foreach (['', '0', '0.00', '-5', 'abc', '12.345', '1e3', '2000000.01', '99999999', '1,2,3x'] as $bad) {
        assert_same(null, PaymentService::parseAmount($bad), "'$bad'");
    }
    assert_same(2000000.0, PaymentService::parseAmount('2000000'));
}

function test_payment_status_for(): void
{
    assert_same('unpaid', PaymentService::statusFor(0.0, 3000.0, 0));
    assert_same('deposit_paid', PaymentService::statusFor(1000.0, 3000.0, 0));
    assert_same('paid', PaymentService::statusFor(3000.0, 3000.0, 0));
    assert_same('paid', PaymentService::statusFor(2999.995, 3000.0, 0), 'เศษสตางค์ไม่ทำให้ค้าง');
    assert_same('paid', PaymentService::statusFor(3500.0, 3000.0, 0), 'จ่ายเกิน');
    assert_same('refunded', PaymentService::statusFor(0.0, 3000.0, 1), 'ยกเลิกแล้วคืนมัดจำครบ ต้องเป็น "คืนเงินแล้ว" ไม่ใช่ "ยังไม่ชำระ"');
    assert_same('deposit_paid', PaymentService::statusFor(500.0, 3000.0, 1), 'คืนบางส่วน ยังมีเงินอยู่');
    assert_same('refunded', PaymentService::statusFor(-100.0, 3000.0, 1), 'คืนเกิน (ไม่ควรเกิด) ยังแสดงเป็นคืนเงิน');
}

function test_payment_parse_amount_zero_only_when_allowed(): void
{
    assert_same(null, PaymentService::parseAmount('0'));
    assert_same(0.0, PaymentService::parseAmount('0', true), 'ค่าส่งฟรี = 0 ได้');
    assert_same(0.0, PaymentService::parseAmount('0.00', true));
    assert_same(null, PaymentService::parseAmount('-1', true));
    assert_same(null, PaymentService::parseAmount('abc', true));
}
