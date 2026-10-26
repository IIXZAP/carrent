<?php
/** ตารางเส้นทางทั้งระบบ: URL → [Controller, method] (+ middleware) */

use App\Controllers\Admin;
use App\Controllers\CarController;
use App\Controllers\HomeController;

// หน้าลูกค้า
$router->get('/', [HomeController::class, 'index']);
$router->get('/cars/{id}', [CarController::class, 'show']);

// หลังบ้าน: เข้าสู่ระบบ
$router->get('/admin/login', [Admin\AuthController::class, 'showLogin']);
$router->post('/admin/login', [Admin\AuthController::class, 'login']);
$router->post('/admin/logout', [Admin\AuthController::class, 'logout'], ['auth']);

// หลังบ้าน: ต้องล็อกอิน
$router->get('/admin', [Admin\DashboardController::class, 'index'], ['auth']);
$router->get('/admin/cars', [Admin\CarController::class, 'index'], ['auth']);
$router->get('/admin/cars/create', [Admin\CarController::class, 'create'], ['auth']);
$router->post('/admin/cars', [Admin\CarController::class, 'store'], ['auth']);
$router->get('/admin/cars/{id}/edit', [Admin\CarController::class, 'edit'], ['auth']);
$router->post('/admin/cars/{id}', [Admin\CarController::class, 'update'], ['auth']);
$router->post('/admin/cars/{id}/delete', [Admin\CarController::class, 'destroy'], ['auth', 'admin']);
