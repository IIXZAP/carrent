<?php
/**
 * ตารางเส้นทางทั้งระบบ: URL → [Controller, method]
 * (เพิ่มหน้าใหม่ = เพิ่มบรรทัดที่นี่ + เมธอดใน controller + ไฟล์ view)
 */

use App\Controllers\ApiController;
use App\Controllers\BookingController;
use App\Controllers\CarController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\InstallController;
use App\Controllers\LiffController;
use App\Controllers\LineWebhookController;
use App\Controllers\PageController;
use App\Controllers\SignController;
use App\Controllers\SitemapController;
use App\Controllers\TrackController;
use App\Controllers\Admin;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthMiddleware;

return static function (Router $r): void {
    // ============================================================
    // หน้าเว็บลูกค้า
    // ============================================================
    $r->get('/', [HomeController::class, 'index']);
    $r->get('/cars', [CarController::class, 'index']);
    $r->get('/cars/{id:\d+}', [CarController::class, 'show']);
    $r->post('/booking', [BookingController::class, 'store']);
    $r->get('/booking/success', [BookingController::class, 'success']);
    $r->get('/track', [TrackController::class, 'index']);
    $r->post('/track', [TrackController::class, 'slip']);
    $r->get('/how-to', [PageController::class, 'howTo']);
    $r->get('/faq', [PageController::class, 'faq']);
    $r->get('/contact', [PageController::class, 'contact']);
    $r->get('/terms', [PageController::class, 'terms']);
    $r->get('/sign', [SignController::class, 'show']);
    $r->post('/sign', [SignController::class, 'store']);
    $r->get('/sitemap.xml', [SitemapController::class, 'index']);
    $r->get('/health', [HealthController::class, 'index']);
    $r->get('/check', [InstallController::class, 'index']);

    // ---------- LINE ----------
    $r->get('/liff', [LiffController::class, 'index']);
    // LIFF API ตรวจ CSRF เอง (คำขอ "session" มาก่อนที่จะมีโทเคน จึงตรวจที่มาของคำขอแทน)
    $r->post('/liff/api', [LiffController::class, 'api'])->noCsrf();
    $r->post('/line/webhook', [LineWebhookController::class, 'handle'])->noCsrf();
    $r->get('/line/webhook', [LineWebhookController::class, 'notPost']);

    // ---------- API ภายในหน้าเว็บ ----------
    $r->get('/api/quote', [ApiController::class, 'quote']);
    // รับสัญญาณสถิติแบบไม่ระบุตัวตน (sendBeacon ส่งโทเคนไม่ได้) — รับเฉพาะชื่อขั้นตอนที่กำหนดไว้
    $r->post('/api/track', [ApiController::class, 'track'])->noCsrf();

    // ============================================================
    // ที่อยู่เดิม (.php) ที่ลูกค้าอาจบันทึกไว้ หรืออยู่ในข้อความ LINE ที่เคยส่งไปแล้ว
    // ============================================================
    $r->legacy('/index.php', '/');
    $r->legacy('/cars.php', '/cars');
    $r->legacy('/booking-success.php', '/booking/success');
    $r->legacy('/track.php', '/track');
    $r->legacy('/how-to.php', '/how-to');
    $r->legacy('/faq.php', '/faq');
    $r->legacy('/contact.php', '/contact');
    $r->legacy('/terms.php', '/terms');
    $r->legacy('/sign.php', '/sign');
    $r->legacy('/liff/index.php', '/liff');
    $r->legacy('/sitemap.php', '/sitemap.xml');
    $r->legacy('/health.php', '/health');
    $r->legacy('/check.php', '/check');
    $r->get('/car.php', static function (Request $req): Response {
        $id = $req->int('id');
        return Response::redirect($id > 0 ? 'cars/' . $id : 'cars', 301);
    });
    // ปลายทางที่ระบบภายนอกเรียกด้วย POST (เปลี่ยนเส้นทางไม่ได้) — คงที่อยู่เดิมไว้
    $r->post('/line/webhook.php', [LineWebhookController::class, 'handle'])->noCsrf();
    $r->post('/liff/api.php', [LiffController::class, 'api'])->noCsrf();

    // ============================================================
    // หลังบ้าน
    // ============================================================
    $r->group(['prefix' => '/admin'], static function (Router $r): void {
        $r->get('/login', [Admin\AuthController::class, 'showLogin']);
        $r->post('/login', [Admin\AuthController::class, 'login']);
        $r->get('/logout', [Admin\AuthController::class, 'logout']);

        // ---------- เจ้าหน้าที่และเจ้าของร้าน ----------
        $r->group(['middleware' => [AuthMiddleware::class]], static function (Router $r): void {
            $r->get('/', static fn() => Response::redirect('admin/dashboard'));
            $r->get('/dashboard', [Admin\DashboardController::class, 'index']);

            // การจอง
            $r->get('/bookings', [Admin\BookingController::class, 'index']);
            $r->get('/bookings/new', [Admin\BookingController::class, 'create']);
            $r->post('/bookings/new', [Admin\BookingController::class, 'store']);
            $r->get('/bookings/{id:\d+}', [Admin\BookingController::class, 'show']);
            $r->post('/bookings/{id:\d+}/action', [Admin\BookingController::class, 'action']);
            $r->get('/bookings/{id:\d+}/preview/{type:return|extend}', [Admin\BookingController::class, 'preview']);
            $r->get('/bookings/{id:\d+}/edit', [Admin\BookingController::class, 'edit']);
            $r->post('/bookings/{id:\d+}/edit', [Admin\BookingController::class, 'update']);
            $r->get('/bookings/{id:\d+}/contract', [Admin\ContractController::class, 'show']);
            $r->get('/bookings/{id:\d+}/inspection/{kind:out|in}', [Admin\InspectionController::class, 'show']);
            $r->post('/bookings/{id:\d+}/inspection/{kind:out|in}', [Admin\InspectionController::class, 'save']);
            $r->post('/bookings/{id:\d+}/inspection/{kind:out|in}/photo', [Admin\InspectionController::class, 'photo']);

            $r->get('/calendar', [Admin\CalendarController::class, 'index']);
            $r->post('/calendar/block', [Admin\CalendarController::class, 'block']);
            $r->post('/calendar/unblock', [Admin\CalendarController::class, 'unblock']);

            $r->get('/payments', [Admin\PaymentController::class, 'index']);
            $r->post('/payments/approve', [Admin\PaymentController::class, 'approve']);
            $r->post('/payments/reject', [Admin\PaymentController::class, 'reject']);

            // รถ
            $r->get('/cars', [Admin\CarController::class, 'index']);
            $r->post('/cars/status', [Admin\CarController::class, 'status']);
            $r->get('/cars/new', [Admin\CarController::class, 'create']);
            $r->post('/cars/new', [Admin\CarController::class, 'store']);
            $r->get('/cars/{id:\d+}/edit', [Admin\CarController::class, 'edit']);
            $r->post('/cars/{id:\d+}/edit', [Admin\CarController::class, 'update']);

            // ลูกค้า
            $r->get('/customers', [Admin\CustomerController::class, 'index']);
            $r->get('/customers/new', [Admin\CustomerController::class, 'create']);
            $r->post('/customers/new', [Admin\CustomerController::class, 'store']);
            $r->get('/customers/{id:\d+}/edit', [Admin\CustomerController::class, 'edit']);
            $r->post('/customers/{id:\d+}/edit', [Admin\CustomerController::class, 'update']);
            $r->get('/customer-files/{id:\d+}', [Admin\CustomerController::class, 'file']);
            $r->get('/customers/{id:\d+}/legacy/{kind:id_card|license}', [Admin\CustomerController::class, 'legacyFile']);

            $r->get('/maintenance', [Admin\MaintenanceController::class, 'index']);
            $r->post('/maintenance/add', [Admin\MaintenanceController::class, 'add']);
            $r->post('/maintenance/done', [Admin\MaintenanceController::class, 'complete']);
            $r->post('/maintenance/skip', [Admin\MaintenanceController::class, 'skip']);

            // เอกสาร (ใบเสนอราคา / ใบแจ้งหนี้ / ใบเสร็จ)
            $r->get('/documents', [Admin\DocumentController::class, 'index']);
            $r->post('/documents/status', [Admin\DocumentController::class, 'status']);
            $r->get('/documents/new', [Admin\DocumentController::class, 'create']);
            $r->post('/documents/new', [Admin\DocumentController::class, 'store']);
            $r->get('/documents/{id:\d+}/edit', [Admin\DocumentController::class, 'edit']);
            $r->post('/documents/{id:\d+}/edit', [Admin\DocumentController::class, 'update']);
            $r->get('/documents/{id:\d+}/print', [Admin\DocumentController::class, 'print']);

            // ---------- เฉพาะเจ้าของร้าน ----------
            $r->group(['middleware' => [AdminMiddleware::class]], static function (Router $r): void {
                $r->get('/reports', [Admin\ReportController::class, 'index']);
                $r->get('/monthly-report', [Admin\MonthlyReportController::class, 'index']);
                $r->post('/monthly-report', [Admin\MonthlyReportController::class, 'saveNote']);
                $r->get('/ab-report', [Admin\AbReportController::class, 'index']);

                $r->get('/line', [Admin\LineController::class, 'index']);
                $r->post('/line', [Admin\LineController::class, 'update']);
                $r->get('/settings', [Admin\SettingController::class, 'index']);
                $r->post('/settings', [Admin\SettingController::class, 'update']);
                $r->get('/users', [Admin\UserController::class, 'index']);
                $r->post('/users/save', [Admin\UserController::class, 'save']);
                $r->post('/users/delete', [Admin\UserController::class, 'delete']);
                $r->get('/backups', [Admin\BackupController::class, 'index']);
                $r->get('/backups/download', [Admin\BackupController::class, 'download']);
                $r->post('/backups', [Admin\BackupController::class, 'update']);
                $r->get('/health', [Admin\HealthController::class, 'index']);
                $r->post('/health', [Admin\HealthController::class, 'migrateDocs']);
            });
        });
    });

    // ที่อยู่หลังบ้านแบบเดิม — พาไปหน้าใหม่ที่ตรงกัน
    $adminMap = [
        'login' => 'login', 'logout' => 'logout', 'dashboard' => 'dashboard', 'bookings' => 'bookings',
        'calendar' => 'calendar', 'payments' => 'payments', 'cars' => 'cars', 'customers' => 'customers',
        'maintenance' => 'maintenance', 'documents' => 'documents', 'reports' => 'reports',
        'monthly-report' => 'monthly-report', 'ab-report' => 'ab-report', 'line' => 'line',
        'settings' => 'settings', 'users' => 'users', 'backups' => 'backups', 'health' => 'health',
    ];
    foreach ($adminMap as $old => $new) {
        $r->legacy('/admin/' . $old . '.php', '/admin/' . $new);
    }
    $r->get('/admin/booking-view.php', static fn(Request $q) => Response::redirect($q->int('id') ? 'admin/bookings/' . $q->int('id') : 'admin/bookings', 301));
    $r->get('/admin/booking-edit.php', static fn(Request $q) => Response::redirect($q->int('id') ? 'admin/bookings/' . $q->int('id') . '/edit' : 'admin/bookings/new', 301));
    $r->get('/admin/car-edit.php', static fn(Request $q) => Response::redirect($q->int('id') ? 'admin/cars/' . $q->int('id') . '/edit' : 'admin/cars/new', 301));
    $r->get('/admin/customer-edit.php', static fn(Request $q) => Response::redirect($q->int('id') ? 'admin/customers/' . $q->int('id') . '/edit' : 'admin/customers/new', 301));
    $r->get('/admin/contract-print.php', static fn(Request $q) => Response::redirect('admin/bookings/' . $q->int('id') . '/contract', 301));
    $r->get('/admin/document-print.php', static fn(Request $q) => Response::redirect('admin/documents/' . $q->int('id') . '/print', 301));
    $r->get('/admin/document-edit.php', static function (Request $q): Response {
        return Response::redirect($q->int('id')
            ? 'admin/documents/' . $q->int('id') . '/edit'
            : 'admin/documents/new?' . http_build_query(array_filter(['type' => $q->str('type'), 'booking_id' => $q->int('booking_id') ?: null])), 301);
    });
    $r->get('/admin/inspection.php', static function (Request $q): Response {
        return Response::redirect('admin/bookings/' . $q->int('booking') . '/inspection/' . ($q->str('kind') === 'in' ? 'in' : 'out'), 301);
    });
};
