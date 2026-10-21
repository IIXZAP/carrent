<?php
/**
 * จุดเข้าเดียวของเว็บ (Front Controller)
 * ทุก URL จะวิ่งเข้าไฟล์นี้ก่อน แล้วค่อยแจกไปให้ Controller
 *
 * รันทดลอง:  php -S localhost:8000 index.php
 */

session_start();

// 1) โหลดไฟล์ที่ต้องใช้ (แบบง่ายสุด: require ทีละไฟล์)
require __DIR__ . '/app/helpers.php';
require __DIR__ . '/app/Database.php';
require __DIR__ . '/app/Router.php';
require __DIR__ . '/app/Models/Car.php';
require __DIR__ . '/app/Models/Booking.php';
require __DIR__ . '/app/Controllers/CarController.php';
require __DIR__ . '/app/Controllers/BookingController.php';

// 2) ตารางเส้นทาง: URL → [Controller, เมธอด]
$router = new Router();
$router->get('/', [CarController::class, 'index']);
$router->get('/cars/{id:\d+}', [CarController::class, 'show']);
$router->post('/booking', [BookingController::class, 'store']);
$router->get('/booking/success/{no}', [BookingController::class, 'success']);

// 3) ให้ Router หาว่าใครรับ URL นี้
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$router->dispatch($method, $path);
