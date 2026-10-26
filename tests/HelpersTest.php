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
