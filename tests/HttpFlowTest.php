<?php
use App\Core\Database;
use App\Models\Booking;
use App\Models\Inspection;
use App\Models\Payment;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\UploadService;

/** เทสต์ผ่าน HTTP จริงของขั้นตอนหลังบ้านทั้งสาย — ข้อมูลที่เซิร์ฟเวอร์ต้องเห็นถูก commit จริงและลบทิ้งใน finally */

function t_http_flow_fixture(): array
{
    t_booking_settings_committed();
    $car = t_make_car();
    Database::run('UPDATE cars SET price_day = 1000, booking_deposit = 1000, security_deposit = 3000 WHERE id = :id', ['id' => $car]);
    $email = 'http-flow' . bin2hex(random_bytes(3)) . '@test.local';
    $admin = t_http_admin($email);
    $bid = BookingService::createBooking($car, '2031-09-10 10:00:00', '2031-09-13 10:00:00', ['name' => 'ลูกค้าเทสต์', 'phone' => '0877770555', 'email' => '', 'line' => ''], ['source' => 'web']);
    PaymentService::record($bid, $admin, 'deposit', 'cash', 1000.0, null);
    return ['car' => $car, 'admin' => $admin, 'email' => $email, 'booking' => $bid];
}

function t_http_flow_cleanup(array $fx): void
{
    $bid = $fx['booking'];
    foreach (Database::all('SELECT p.file_path FROM inspection_photos p JOIN inspections i ON i.id = p.inspection_id WHERE i.booking_id = :b', ['b' => $bid]) as $row) {
        UploadService::delete($row['file_path']);
    }
    Database::run('DELETE FROM inspection_photos WHERE inspection_id IN (SELECT id FROM inspections WHERE booking_id = :b)', ['b' => $bid]);
    Database::run('DELETE FROM inspections WHERE booking_id = :b', ['b' => $bid]);
    Database::run('DELETE FROM payments WHERE booking_id = :b', ['b' => $bid]);
    Database::run('DELETE FROM booking_addons WHERE booking_id = :b', ['b' => $bid]);
    Database::run('DELETE FROM bookings WHERE id = :b', ['b' => $bid]);
    Database::run("DELETE FROM customers WHERE phone = '0877770555'");
    Database::run('DELETE FROM cars WHERE id = :c', ['c' => $fx['car']]);
    Database::run('DELETE FROM users WHERE id = :u', ['u' => $fx['admin']]);
    Database::run("DELETE FROM activity_logs WHERE action IN ('login_failed', 'login')");
}

function t_http_login(array $fx, array &$cookies): void
{
    $token = t_http_csrf('/admin/login', $cookies);
    $r = t_http('POST', '/admin/login', ['form' => ['_csrf' => $token, 'email' => $fx['email'], 'password' => 'password-1234']], $cookies);
    assert_same(302, $r['status'], 'ล็อกอินไม่สำเร็จ');
}

function test_http_full_rental_flow_from_pickup_to_security_refund(): void
{
    $fx = t_http_flow_fixture();
    $bid = $fx['booking'];
    $png = tempnam(sys_get_temp_dir(), 'png');
    imagepng(imagecreatetruecolor(30, 20), $png);
    try {
        $cookies = [];
        t_http_login($fx, $cookies);
        $page = t_http('GET', "/admin/bookings/$bid", [], $cookies);
        assert_same(200, $page['status']);
        assert_true(strpos($page['body'], 'ขั้นตอนงาน') !== false && strpos($page['body'], 'ส่งมอบรถ') !== false, 'หน้าการจองมีแผงขั้นตอนและปุ่มส่งมอบ');

        // ถ่ายรูปรอบส่งมอบ (อัปโหลดจริง)
        $token = t_http_csrf("/admin/bookings/$bid/inspection/out", $cookies);
        $up = t_http('POST', "/admin/bookings/$bid/inspection/out", ['form' => ['_csrf' => $token, 'position' => 'front', 'caption' => 'หน้ารถ'], 'files' => ['photo' => [$png, 'front.png', 'image/png']]], $cookies);
        assert_same(302, $up['status'], 'อัปโหลดรูป (status ' . $up['status'] . ' body: ' . json_encode(substr($up['body'], 0, 400), JSON_UNESCAPED_UNICODE) . ' headers: ' . json_encode($up['headers']) . ')');
        $out = Inspection::get($bid, 'out');
        assert_same(1, count($out['photos']), 'รูปถูกบันทึก');
        $photoId = (int) $out['photos'][0]['id'];

        // รูปเปิดดูได้เฉพาะแอดมินที่ล็อกอิน
        $anon = [];
        assert_same(302, t_http('GET', "/admin/inspection-photos/$photoId", [], $anon)['status'], 'ไม่ล็อกอินดูรูปไม่ได้');
        $img = t_http('GET', "/admin/inspection-photos/$photoId", [], $cookies);
        assert_same(200, $img['status']);
        assert_same('image/png', $img['headers']['content-type'][0]);

        // ส่งมอบ: ไม่มี CSRF = 419 ; ปกติ = สำเร็จ ; ซ้ำ = ข้อความ ไม่ใช่ 500
        assert_same(419, t_http('POST', "/admin/bookings/$bid/pickup", ['form' => ['mileage_out' => '10000', 'fuel_out' => '8']], $cookies)['status']);
        $token = t_http_csrf("/admin/bookings/$bid", $cookies);
        $pick = t_http('POST', "/admin/bookings/$bid/pickup", ['form' => ['_csrf' => $token, 'mileage_out' => '10000', 'fuel_out' => '8']], $cookies);
        assert_same(302, $pick['status'], 'ส่งมอบ');
        assert_same('picked_up', Booking::find($bid)['status']);
        $again = t_http('POST', "/admin/bookings/$bid/pickup", ['form' => ['_csrf' => $token, 'mileage_out' => '10000', 'fuel_out' => '8']], $cookies);
        assert_same(302, $again['status'], 'ส่งมอบซ้ำไม่ใช่ 500');
        $detail = t_http('GET', "/admin/bookings/$bid", [], $cookies);
        assert_true(strpos($detail['body'], 'ทำรายการนี้ไม่ได้ เพราะการจองอยู่ในสถานะ') !== false, 'ส่งมอบซ้ำได้ข้อความอธิบาย');
        // ผลตรวจรอบส่งมอบถูกล็อก — ถ่ายรูปเพิ่มไม่ได้
        $late = t_http('POST', "/admin/bookings/$bid/inspection/out", ['form' => ['_csrf' => $token, 'position' => 'rear'], 'files' => ['photo' => [$png, 'rear.png', 'image/png']]], $cookies);
        assert_same(302, $late['status'], 'ถ่ายรูปหลังล็อก');
        assert_same(1, count(Inspection::get($bid, 'out')['photos']), 'ล็อกแล้ว รูปไม่เพิ่ม');

        // รับคืน: ดูค่าใช้จ่ายที่ระบบแนะนำก่อน (คืนช้า + น้ำมันขาด) แล้วยืนยัน
        $preview = t_http('GET', "/admin/bookings/$bid/return?" . http_build_query(['calc' => 1, 'mileage_in' => '10300', 'fuel_in' => '5']), [], $cookies);
        assert_same(200, $preview['status']);
        assert_true(strpos($preview['body'], 'น้ำมันขาด 3 ขีด') !== false, 'พรีวิวแสดงน้ำมันขาด');
        $bad = t_http('GET', "/admin/bookings/$bid/return?" . http_build_query(['calc' => 1, 'mileage_in' => ['x'], 'fuel_in' => ['y']]), [], $cookies);
        assert_true($bad['status'] < 500, 'ค่าแปลกในพรีวิวไม่ใช่ 500');
        $token = t_http_csrf("/admin/bookings/$bid/return?" . http_build_query(['calc' => 1, 'mileage_in' => '10300', 'fuel_in' => '5']), $cookies);

        // เวลารับคืนอยู่ในอนาคต (การจองทดสอบอยู่ปี 2031 แต่เวลาจริงคือตอนนี้) ต้องถูกปฏิเสธ
        $refused = t_http('POST', "/admin/bookings/$bid/return", ['form' => ['_csrf' => $token, 'mileage_in' => '10300', 'fuel_in' => '5', 'returned_at' => '2031-09-13T09:00']], $cookies);
        assert_same(302, $refused['status'], 'รับคืนเวลาอนาคต');
        assert_same('picked_up', Booking::find($bid)['status'], 'เวลารับคืนอยู่ในอนาคต ต้องไม่บันทึก');

        // ไม่ระบุเวลา = ตอนนี้ (รถคืนก่อนกำหนดเพราะการจองอยู่ปี 2031) → มีเฉพาะน้ำมันขาด + ค่าใช้จ่ายอื่น
        $ok = t_http('POST', "/admin/bookings/$bid/return", ['form' => ['_csrf' => $token, 'mileage_in' => '10300', 'fuel_in' => '5', 'chg' => ['fuel' => ['on' => '1', 'amount' => '900']], 'other_label' => 'ทำความสะอาด', 'other_amount' => '300']], $cookies);
        assert_same(302, $ok['status'], 'รับคืน');
        $after = Booking::find($bid);
        assert_same('returned', $after['status']);
        assert_money(1200, $after['extra_charge']);
        assert_true(Inspection::get($bid, 'in')['locked_at'] !== null, 'ผลตรวจรอบรับคืนถูกล็อก');

        // ลูกค้าเห็นค่าใช้จ่ายเพิ่มในหน้าตรวจสอบการจอง
        $custCookies = [];
        $trackToken = t_http_csrf('/track', $custCookies);
        t_http('POST', '/track', ['form' => ['_csrf' => $trackToken, 'booking_no' => $after['booking_no'], 'phone' => '0877770555']], $custCookies);
        $track = t_http('GET', '/track/view', [], $custCookies);
        assert_true(strpos($track['body'], 'ค่าใช้จ่ายเพิ่มตอนรับคืนรถ') !== false && strpos($track['body'], 'ทำความสะอาด') !== false, 'ลูกค้าเห็นค่าใช้จ่ายเพิ่ม');

        // ปิดเงินประกัน: รับค่าเช่าครบ + เงินประกัน แล้วหักค่าใช้จ่ายเพิ่มจากเงินประกัน คืนส่วนที่เหลือ
        PaymentService::record($bid, $fx['admin'], 'balance', 'cash', 2000.0, null);
        PaymentService::record($bid, $fx['admin'], 'security', 'cash', 3000.0, null);
        $secToken = t_http_csrf("/admin/bookings/$bid/security", $cookies);
        $settle = t_http('POST', "/admin/bookings/$bid/security", ['form' => ['_csrf' => $secToken, 'deduct' => '1200', 'refund_method' => 'cash']], $cookies);
        assert_same(302, $settle['status'], 'ปิดเงินประกัน');
        assert_money(0, Payment::securityHeld($bid), 'ปิดเงินประกันแล้ว');
        assert_same('paid', Booking::find($bid)['payment_status']);
        $twice = t_http('POST', "/admin/bookings/$bid/security", ['form' => ['_csrf' => $secToken, 'deduct' => '', 'refund_method' => 'cash']], $cookies);
        assert_same(302, $twice['status'], 'ปิดซ้ำไม่ใช่ 500');
        assert_same(1, (int) Database::value("SELECT COUNT(*) FROM payments WHERE booking_id = :b AND kind = 'security_refund'", ['b' => $bid]), 'คืนเงินประกันครั้งเดียว');

        // ปิดงาน
        $done = t_http('POST', "/admin/bookings/$bid/complete", ['form' => ['_csrf' => $secToken]], $cookies);
        assert_same(302, $done['status'], 'ปิดงาน');
        assert_same('completed', Booking::find($bid)['status']);
    } finally {
        @unlink($png);
        t_http_flow_cleanup($fx);
    }
}

function test_http_flow_pages_require_login_and_reject_unknown_inspection_kind(): void
{
    $fx = t_http_flow_fixture();
    $bid = $fx['booking'];
    try {
        $anon = [];
        foreach (["/admin/bookings/$bid/return", "/admin/bookings/$bid/security", "/admin/bookings/$bid/inspection/out"] as $path) {
            $r = t_http('GET', $path, [], $anon);
            assert_same(302, $r['status'], "$path ต้องเด้งไปหน้าล็อกอิน");
        }
        $cookies = [];
        t_http_login($fx, $cookies);
        assert_same(404, t_http('GET', "/admin/bookings/$bid/inspection/sideways", [], $cookies)['status'], 'รอบที่ไม่รู้จัก');
        assert_same(200, t_http('GET', "/admin/bookings/$bid/inspection/in", [], $cookies)['status']);
        assert_same(302, t_http('GET', "/admin/bookings/$bid/return", [], $cookies)['status'], 'ยังไม่ได้ส่งมอบ เข้าหน้ารับคืนไม่ได้ (เด้งกลับพร้อมข้อความ)');
        assert_same(200, t_http('GET', "/admin/bookings/$bid/security", [], $cookies)['status'], 'หน้าปิดเงินประกันเปิดได้แต่ไม่ให้กดปิด');
    } finally {
        t_http_flow_cleanup($fx);
    }
}

function test_http_documents_create_from_booking_print_and_status(): void
{
    $fx = t_http_flow_fixture();
    $bid = $fx['booking'];
    try {
        $cookies = [];
        t_http_login($fx, $cookies);
        $b = Booking::find($bid);

        // ฟอร์มเติมจากการจอง
        $form = t_http('GET', "/admin/documents/create?booking=$bid&type=invoice", [], $cookies);
        assert_same(200, $form['status']);
        assert_true(strpos($form['body'], 'ค่าเช่ารถ') !== false, 'ฟอร์มมีรายการค่าเช่าจากการจอง');

        // สร้างจริงด้วยค่าที่ฟอร์มส่ง
        $token = t_http_csrf('/admin/documents/create', $cookies);
        $post = ['_csrf' => $token, 'doc_type' => 'invoice', 'booking_id' => (string) $bid, 'issue_date' => '2031-09-01', 'bill_name' => 'ลูกค้าเทสต์',
            'discount' => '0', 'vat_rate' => '0', 'wht_rate' => '0',
            'items' => [['description' => 'ค่าเช่ารถ', 'qty' => '3', 'unit' => 'วัน', 'price' => '1000'], ['description' => '', 'qty' => '1', 'unit' => '', 'price' => '']]];
        $r = t_http('POST', '/admin/documents', ['form' => $post], $cookies);
        assert_same(302, $r['status'], 'สร้างเอกสารแล้วต้อง redirect');
        $loc = $r['headers']['location'][0] ?? '';
        assert_true(preg_match('#/admin/documents/(\d+)$#', $loc, $m) === 1, "location=$loc");
        $docId = (int) $m[1];

        $show = t_http('GET', "/admin/documents/$docId", [], $cookies);
        assert_same(200, $show['status']);
        assert_true(strpos($show['body'], '3,000.00') !== false, 'แสดงยอด');
        $print = t_http('GET', "/admin/documents/$docId/print", [], $cookies);
        assert_same(200, $print['status']);
        assert_true(strpos($print['body'], 'ใบแจ้งหนี้') !== false && strpos($print['body'], 'window.print') !== false);

        // ตัวเลขสุทธิต้องไม่หลุดเป็น "ว่าง" ถ้า post ผิด → แสดงฟอร์มกลับพร้อมข้อความ ไม่ 500
        $bad = t_http('POST', '/admin/documents', ['form' => ['_csrf' => $token, 'doc_type' => 'invoice', 'issue_date' => '2031-09-01', 'bill_name' => 'x', 'items' => [['description' => 'a', 'qty' => '1', 'price' => 'abc']]]], $cookies);
        assert_same(200, $bad['status']);
        assert_true(strpos($bad['body'], 'ต้องเป็นตัวเลข') !== false);

        // items เป็นข้อความธรรมดา (ไม่ใช่อาเรย์) ต้องไม่ทำให้ 500
        $weird = t_http('POST', '/admin/documents', ['form' => ['_csrf' => $token, 'doc_type' => 'invoice', 'issue_date' => '2031-09-01', 'bill_name' => 'x', 'items' => 'zzz']], $cookies);
        assert_true($weird['status'] === 200, 'items ผิดรูปแบบต้องตอบฟอร์มกลับ ไม่ 500 (ได้ ' . $weird['status'] . ')');

        // เปลี่ยนสถานะ
        $r = t_http('POST', "/admin/documents/$docId/status", ['form' => ['_csrf' => $token, 'status' => 'paid']], $cookies);
        assert_same(302, $r['status']);
        assert_same('paid', Database::value('SELECT status FROM documents WHERE id = :i', ['i' => $docId]));
        $edit = t_http('GET', "/admin/documents/$docId/edit", [], $cookies);
        assert_same(302, $edit['status'], 'จ่ายแล้วแก้ไม่ได้');

        // ต้องล็อกอิน
        $anon = [];
        assert_same(302, t_http('GET', "/admin/documents/$docId/print", [], $anon)['status']);
        assert_same(404, t_http('GET', '/admin/documents/999999', [], $cookies)['status']);
        assert_same(404, t_http('GET', '/admin/documents/abc', [], $cookies)['status']);
    } finally {
        Database::run('DELETE FROM documents WHERE booking_id = :b', ['b' => $bid]);
        Database::run("DELETE FROM documents WHERE doc_no LIKE 'IV3109-%'");
        Database::run("DELETE FROM sequences WHERE name = 'doc_invoice' AND period = '3109'");
        t_http_flow_cleanup($fx);
    }
}
