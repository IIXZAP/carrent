<?php
/** request_path ต้องตัดโฟลเดอร์ติดตั้ง (base) เฉพาะที่ขอบ path */

function t_path(string $script, string $uri): string
{
    $_SERVER['SCRIPT_NAME'] = $script;
    $_SERVER['REQUEST_URI'] = $uri;
    return request_path();
}

function test_request_path_without_base(): void
{
    assert_same('/', t_path('/index.php', '/'));
    assert_same('/cars/5', t_path('/index.php', '/cars/5?x=1'));
}

function test_request_path_strips_base_at_boundary(): void
{
    assert_same('/cars/5', t_path('/v3/index.php', '/v3/cars/5'));
    assert_same('/', t_path('/v3/index.php', '/v3'));
    assert_same('/', t_path('/v3/index.php', '/v3/'));
}

function test_request_path_does_not_strip_similar_prefix(): void
{
    // "/v3x/cars" ไม่ใช่ของแอปที่ติดตั้งที่ /v3 — ห้ามตัดเป็น "x/cars"
    assert_same('/v3x/cars', t_path('/v3/index.php', '/v3x/cars'));
}

function test_client_ip_ignores_forwarded_header_unless_trusted(): void
{
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.1';

    $GLOBALS['config']['trust_proxy'] = false;
    assert_same('10.0.0.1', client_ip(), 'ไม่เชื่อ header เมื่อไม่ได้เปิด trust_proxy (กันปลอม IP)');

    $GLOBALS['config']['trust_proxy'] = true;
    assert_same('203.0.113.9', client_ip(), 'เชื่อตัวซ้ายสุดเมื่อเปิด');

    $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
    assert_same('10.0.0.1', client_ip(), 'ค่าที่ไม่ใช่ IP ใช้ไม่ได้');

    $GLOBALS['config']['trust_proxy'] = false;
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
}
