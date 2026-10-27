<?php
/** ตารางเส้นทางทั้งระบบ: URL → [Controller, method] (+ middleware) */

use App\Controllers\Admin;
use App\Controllers\BookingController;
use App\Controllers\CarController;
use App\Controllers\HomeController;
use App\Controllers\PageController;
use App\Controllers\SignController;
use App\Controllers\TrackController;

// หน้าลูกค้า
$router->get('/', [HomeController::class, 'index']);
$router->get('/cars', [CarController::class, 'index']);
$router->get('/cars/{id}', [CarController::class, 'show']);
$router->get('/how-to', [PageController::class, 'howTo']);
$router->get('/faq', [PageController::class, 'faq']);
$router->get('/contact', [PageController::class, 'contact']);
$router->get('/terms', [PageController::class, 'terms']);
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

// ลูกค้าเซ็นสัญญา/ใบรับคืนผ่านลิงก์ลับ (ไม่ต้องล็อกอิน)
$router->get('/sign', [SignController::class, 'show']);
$router->post('/sign', [SignController::class, 'submit']);
$router->get('/sign/photo/{id}', [SignController::class, 'photo']);

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

// หลังบ้าน: เอกสาร (create ต้องมาก่อน {id})
$router->get('/admin/documents', [Admin\DocumentController::class, 'index'], ['auth']);
$router->get('/admin/documents/create', [Admin\DocumentController::class, 'create'], ['auth']);
$router->post('/admin/documents', [Admin\DocumentController::class, 'store'], ['auth']);
$router->get('/admin/documents/{id}', [Admin\DocumentController::class, 'show'], ['auth']);
$router->get('/admin/documents/{id}/print', [Admin\DocumentController::class, 'print'], ['auth']);
$router->get('/admin/documents/{id}/edit', [Admin\DocumentController::class, 'edit'], ['auth']);
$router->post('/admin/documents/{id}', [Admin\DocumentController::class, 'update'], ['auth']);
$router->post('/admin/documents/{id}/status', [Admin\DocumentController::class, 'status'], ['auth']);

// หลังบ้าน: สัญญาและลายเซ็น (ยกเลิกลายเซ็นได้เฉพาะเจ้าของร้าน)
$router->get('/admin/bookings/{id}/contract', [Admin\ContractController::class, 'show'], ['auth']);
$router->get('/admin/bookings/{id}/contract/print', [Admin\ContractController::class, 'print'], ['auth']);
$router->get('/admin/signatures/{id}/image', [Admin\ContractController::class, 'image'], ['auth']);
$router->post('/admin/signatures/{id}/void', [Admin\ContractController::class, 'void'], ['auth', 'admin']);
