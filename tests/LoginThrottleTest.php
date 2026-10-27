<?php
use App\Services\LoginThrottle;

function test_db_login_throttle_blocks_one_ip_after_limit(): void
{
    $ip = '192.0.2.61';
    assert_true(!LoginThrottle::isBlocked($ip, 'a@x.test'));
    for ($i = 0; $i < LoginThrottle::MAX_PER_IP - 1; $i++) {
        LoginThrottle::recordFailure($ip, 'a' . $i . '@x.test');
    }
    assert_true(!LoginThrottle::isBlocked($ip, 'new@x.test'), 'ยังไม่ถึงขีดจำกัด');
    LoginThrottle::recordFailure($ip, 'z@x.test');
    assert_true(LoginThrottle::isBlocked($ip, 'anything@x.test'), 'IP นี้ถูกระงับกับทุกอีเมล');
    assert_true(!LoginThrottle::isBlocked('192.0.2.62', 'new@x.test'), 'IP อื่นไม่ถูกกระทบ');
}

function test_db_login_throttle_blocks_one_email_across_many_ips(): void
{
    for ($i = 0; $i < LoginThrottle::MAX_PER_EMAIL; $i++) {
        LoginThrottle::recordFailure('198.51.100.' . ($i % 250 + 1), 'Owner@Shop.test');
    }
    assert_true(LoginThrottle::isBlocked('203.0.113.99', 'owner@shop.test'), 'กระจายหลาย IP ก็ถูกระงับ ตัวพิมพ์เล็กใหญ่นับรวม');
    assert_true(LoginThrottle::isBlocked('203.0.113.99', '  OWNER@shop.test '), 'ช่องว่างไม่ช่วยหลบ');
    assert_true(!LoginThrottle::isBlocked('203.0.113.99', 'other@shop.test'), 'อีเมลอื่นไม่ถูกกระทบ');
}

function test_db_login_throttle_ignores_old_failures(): void
{
    $ip = '192.0.2.63';
    for ($i = 0; $i < LoginThrottle::MAX_PER_IP; $i++) {
        LoginThrottle::recordFailure($ip, 'old@x.test');
    }
    assert_true(LoginThrottle::isBlocked($ip, 'old@x.test'));
    \App\Core\Database::run(
        "UPDATE activity_logs SET created_at = DATE_SUB(NOW(), INTERVAL :s SECOND) WHERE action = 'login_failed' AND ip = :ip",
        ['s' => LoginThrottle::WINDOW_SECONDS + 5, 'ip' => $ip]
    );
    assert_true(!LoginThrottle::isBlocked($ip, 'old@x.test'), 'พ้นช่วงเวลาแล้วปลดระงับ');
}
