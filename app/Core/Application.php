<?php

namespace App\Core;

/**
 * ตัวขับเคลื่อนระบบ: รับคำขอ → ส่งให้ Router → ส่งผลลัพธ์กลับ
 */
final class Application
{
    public static function run(): void
    {
        $request = Request::capture();
        $router  = new Router();

        $routes = require APP_PATH . '/routes.php';
        $routes($router);

        try {
            $response = $router->dispatch($request);
        } catch (HttpException $e) {
            $response = $e->response();
        }
        $response->send();
    }
}
