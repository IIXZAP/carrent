<?php
/** ตารางเส้นทางทั้งระบบ: URL → [Controller, method] (+ middleware) */

use App\Controllers\Admin;
use App\Controllers\BookingController;
use App\Controllers\CarController;
use App\Controllers\HomeController;
use App\Controllers\TrackController;

// หน้าลูกค้า
$router->get('/', [HomeController::class, 'index']);
$router->get('/cars/{id}', [CarController::class, 'show']);
$router->get('/booking', [BookingController::class, 'form']);
$router->post('/booking', [BookingController::class, 'store']);
$router->get('/booking/success/{no}', [BookingController::class, 'success']);

// ลูกค้าตรวจสอบการจอง + แจ้งโอน (ยืนยันตัวตนด้วยเลขที่จอง + เบอร์โทร ต่อด้วย session)
$router->get('/track', [TrackController::class, 'index']);
$router->post('/track', [TrackController::class, 'lookup']);
$router->get('/track/view', [TrackController::class, 'view']);
$router->post('/track/slip', [TrackController::class, 'slip']);
$router->get('/track/slip/{id}', [TrackController::class, 'slipFile']);
$router->post('/track/leave', [TrackController::class, 'leave']);

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

// หลังบ้าน: การจอง (create ต้องมาก่อน {id} เพราะ Router ใช้เส้นแรกที่ตรง)
$router->get('/admin/bookings', [Admin\BookingController::class, 'index'], ['auth']);
$router->get('/admin/bookings/create', [Admin\BookingController::class, 'create'], ['auth']);
$router->post('/admin/bookings', [Admin\BookingController::class, 'store'], ['auth']);
$router->get('/admin/bookings/{id}', [Admin\BookingController::class, 'show'], ['auth']);
$router->post('/admin/bookings/{id}/cancel', [Admin\BookingController::class, 'cancel'], ['auth']);
$router->post('/admin/bookings/{id}/delivery', [Admin\BookingController::class, 'adjustDelivery'], ['auth']);

// หลังบ้าน: ชำระเงิน / ตั้งค่าร้าน
$router->get('/admin/payments', [Admin\PaymentController::class, 'index'], ['auth']);
$router->post('/admin/payments/{id}/approve', [Admin\PaymentController::class, 'approve'], ['auth']);
$router->post('/admin/payments/{id}/reject', [Admin\PaymentController::class, 'reject'], ['auth']);
$router->get('/admin/payments/{id}/slip', [Admin\PaymentController::class, 'slip'], ['auth']);
$router->post('/admin/bookings/{id}/payments', [Admin\PaymentController::class, 'record'], ['auth']);
$router->get('/admin/settings', [Admin\SettingController::class, 'index'], ['auth', 'admin']);
$router->post('/admin/settings', [Admin\SettingController::class, 'save'], ['auth', 'admin']);

// หลังบ้าน: ขั้นตอนส่งมอบ–รับคืน–ปิดเงินประกัน + ตรวจสภาพรถ
$router->post('/admin/bookings/{id}/confirm', [Admin\FlowController::class, 'confirm'], ['auth']);
$router->post('/admin/bookings/{id}/pickup', [Admin\FlowController::class, 'pickup'], ['auth']);
$router->post('/admin/bookings/{id}/complete', [Admin\FlowController::class, 'complete'], ['auth']);
$router->get('/admin/bookings/{id}/return', [Admin\FlowController::class, 'returnForm'], ['auth']);
$router->post('/admin/bookings/{id}/return', [Admin\FlowController::class, 'returnStore'], ['auth']);
$router->get('/admin/bookings/{id}/security', [Admin\FlowController::class, 'securityForm'], ['auth']);
$router->post('/admin/bookings/{id}/security', [Admin\FlowController::class, 'securityStore'], ['auth']);
$router->get('/admin/bookings/{id}/inspection/{kind}', [Admin\FlowController::class, 'inspection'], ['auth']);
$router->post('/admin/bookings/{id}/inspection/{kind}', [Admin\FlowController::class, 'uploadPhoto'], ['auth']);
$router->get('/admin/inspection-photos/{id}', [Admin\FlowController::class, 'photo'], ['auth']);
