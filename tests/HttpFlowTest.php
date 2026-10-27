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

function test_http_signing_flow_customer_link_to_admin_void(): void
{
    $fx = t_http_flow_fixture();
    $bid = $fx['booking'];
    $sigFiles = [];
    try {
        $admin = [];
        t_http_login($fx, $admin);

        // หน้าสัญญาหลังบ้านออกลิงก์ให้
        $page = t_http('GET', "/admin/bookings/$bid/contract", [], $admin);
        assert_same(200, $page['status']);
        assert_true(preg_match('#value="(http://[^"]+/sign\?[^"]+)"#', html_entity_decode($page['body']), $m) === 1, 'ต้องมีลิงก์เซ็นให้คัดลอก');
        $link = html_entity_decode($m[1]);
        $path = '/sign?' . parse_url($link, PHP_URL_QUERY);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
        assert_same(32, strlen($q['t']));

        // ลิงก์ผิด/ไม่มีรหัส = 404 เหมือนกันหมด (ไม่บอกว่าเลขที่จองมีจริงหรือไม่)
        $customer = [];
        foreach ([
            '/sign', '/sign?no=' . $q['no'], '/sign?no=' . $q['no'] . '&t=' . str_repeat('0', 32), '/sign?no=ZZZ&t=' . $q['t'],
            '/sign?no=' . $q['no'] . '&t=' . $q['t'] . '&k=hack', '/sign?no[]=1&t[]=2',
        ] as $bad) {
            assert_same(404, t_http('GET', $bad, [], $customer)['status'], "ต้อง 404: $bad");
        }

        // ลูกค้าเปิดลิงก์: ส่วนหัวห้ามแคช/ห้ามค้นหา ข้อมูลที่แสดงถูก escape
        Database::run('UPDATE bookings SET contact_name = :n WHERE id = :id', ['n' => '<img src=x onerror=alert(1)>', 'id' => $bid]);
        $r = t_http('GET', $path, [], $customer);
        assert_same(200, $r['status']);
        assert_true(str_contains(strtolower(implode(',', $r['headers']['cache-control'] ?? [])), 'no-store'), 'ต้องไม่แคชหน้าเซ็น');
        assert_true(str_contains(implode(',', $r['headers']['x-robots-tag'] ?? []), 'noindex'));
        assert_true(str_contains(implode(',', $r['headers']['referrer-policy'] ?? []), 'no-referrer'));
        assert_true(strpos($r['body'], '<img src=x') === false, 'ชื่อที่ผู้ใช้กรอกต้องถูก escape');
        assert_true(strpos($r['body'], 'ทรัพย์สินที่เช่า') !== false && strpos($r['body'], 'PDPA') !== false);
        Database::run("UPDATE bookings SET contact_name = 'ลูกค้าเทสต์' WHERE id = :id", ['id' => $bid]);
        $r = t_http('GET', $path, [], $customer);
        preg_match('/name="seen" value="([0-9a-f]{64})"/', $r['body'], $sm);
        $csrf = t_http_csrf($path, $customer);
        $seen = $sm[1];
        $png = t_sig_png();

        // POST ที่ไม่มี CSRF ถูกปฏิเสธ
        $fields = ['no' => $q['no'], 'k' => 'contract', 't' => $q['t'], 'seen' => $seen, 'signature' => $png, 'name' => 'ลูกค้าเทสต์', 'accept_terms' => '1', 'consent' => '1'];
        assert_same(419, t_http('POST', '/sign', ['form' => $fields], $customer)['status']);

        // ไม่ติ๊กยอมรับ → กลับมาที่ฟอร์มพร้อมข้อความ และไม่มีลายเซ็น
        $r = t_http('POST', '/sign', ['form' => ['_csrf' => $csrf, 'accept_terms' => ''] + $fields], $customer);
        assert_same(200, $r['status']);
        assert_true(strpos($r['body'], 'ติ๊กยืนยัน') !== false);
        assert_same(0, (int) Database::value('SELECT COUNT(*) FROM signatures WHERE booking_id = :b', ['b' => $bid]));

        // เซ็นจริง
        $r = t_http('POST', '/sign', ['form' => ['_csrf' => $csrf] + $fields], $customer);
        assert_same(302, $r['status'], 'เซ็นสำเร็จต้อง redirect (กด refresh แล้วไม่ส่งซ้ำ)');
        $sig = Database::one('SELECT * FROM signatures WHERE booking_id = :b', ['b' => $bid]);
        $sigFiles[] = $sig['file_path'];
        assert_true($sig !== null && $sig['voided_at'] === null);
        assert_same('127.0.0.1', $sig['ip']);
        $done = t_http('GET', $path, [], $customer);
        assert_true(strpos($done['body'], 'ลงนามเรียบร้อยแล้ว') !== false && strpos($done['body'], \App\Services\SignatureService::ref($sig)) !== false);
        assert_true(strpos($done['body'], 'sign-form') === false, 'เซ็นแล้วต้องไม่มีฟอร์มเซ็นอีก');

        // เซ็นซ้ำไม่ได้
        $again = t_http('POST', '/sign', ['form' => ['_csrf' => $csrf] + $fields], $customer);
        assert_same(200, $again['status']);
        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM signatures WHERE booking_id = :b', ['b' => $bid]));

        // รูปลายเซ็น: แอดมินดูได้ ลูกค้า/คนนอกดูไม่ได้ และเปิดไฟล์ตรงไม่ได้
        $img = t_http('GET', '/admin/signatures/' . $sig['id'] . '/image', [], $admin);
        assert_same(200, $img['status']);
        assert_true(str_contains(implode(',', $img['headers']['content-type'] ?? []), 'image/png'));
        assert_same(302, t_http('GET', '/admin/signatures/' . $sig['id'] . '/image', [], $customer)['status']);
        assert_same(404, t_http('GET', '/storage/private/' . $sig['file_path'], [], $customer)['status']);
        assert_same(404, t_http('GET', '/sign/photo/1?no=' . $q['no'] . '&t=' . $q['t'], [], $customer)['status'], 'รูปที่ไม่ใช่ของใบนี้ต้องเปิดไม่ได้');

        // พิมพ์สัญญามีลายเซ็นและรหัสอ้างอิง
        $print = t_http('GET', "/admin/bookings/$bid/contract/print?kind=contract", [], $admin);
        assert_same(200, $print['status']);
        assert_true(strpos($print['body'], \App\Services\SignatureService::ref($sig)) !== false && strpos($print['body'], '/admin/signatures/' . $sig['id'] . '/image') !== false);

        // แก้ข้อมูลหลังเซ็น → หน้าหลังบ้านเตือน
        Database::run('UPDATE bookings SET security_deposit = 7777 WHERE id = :id', ['id' => $bid]);
        $warn = t_http('GET', "/admin/bookings/$bid/contract", [], $admin);
        assert_true(strpos($warn['body'], 'ข้อมูลถูกแก้ไขหลังลูกค้าลงนาม') !== false && strpos($warn['body'], '7,777') !== false);

        // ยกเลิกเพื่อเซ็นใหม่ (เหตุผลสั้นไปถูกปฏิเสธ) แล้วลูกค้าเซ็นใหม่ได้
        $token = t_http_csrf("/admin/bookings/$bid/contract", $admin);
        t_http('POST', '/admin/signatures/' . $sig['id'] . '/void', ['form' => ['_csrf' => $token, 'reason' => 'x']], $admin);
        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM signatures WHERE booking_id = :b AND voided_at IS NULL', ['b' => $bid]));
        t_http('POST', '/admin/signatures/' . $sig['id'] . '/void', ['form' => ['_csrf' => $token, 'reason' => 'ข้อมูลเปลี่ยน']], $admin);
        assert_same(0, (int) Database::value('SELECT COUNT(*) FROM signatures WHERE booking_id = :b AND voided_at IS NULL', ['b' => $bid]));
        $r = t_http('GET', $path, [], $customer);
        assert_true(strpos($r['body'], 'sign-form') !== false, 'ยกเลิกแล้วลูกค้าเซ็นใหม่ได้');
        preg_match('/name="seen" value="([0-9a-f]{64})"/', $r['body'], $sm2);
        assert_true($sm2[1] !== $seen, 'ข้อมูลเปลี่ยน (เงินประกัน) hash ที่ลูกค้าเห็นต้องเปลี่ยนตาม');
        $csrf2 = t_http_csrf($path, $customer);
        // ส่งด้วย hash เก่า (ลูกค้าค้างหน้าเดิม) → ถูกปฏิเสธให้ตรวจใหม่
        $stale = t_http('POST', '/sign', ['form' => ['_csrf' => $csrf2, 'seen' => $seen] + $fields], $customer);
        assert_same(200, $stale['status']);
        assert_true(strpos($stale['body'], 'เปลี่ยนแปลง') !== false);
        $ok = t_http('POST', '/sign', ['form' => ['_csrf' => $csrf2, 'seen' => $sm2[1]] + $fields], $customer);
        assert_same(302, $ok['status']);
        foreach (Database::all('SELECT file_path FROM signatures WHERE booking_id = :b', ['b' => $bid]) as $row) {
            $sigFiles[] = $row['file_path'];
        }
        assert_same(2, (int) Database::value('SELECT COUNT(*) FROM signatures WHERE booking_id = :b', ['b' => $bid]));
    } finally {
        foreach (Database::all('SELECT file_path FROM signatures WHERE booking_id = :b', ['b' => $bid]) as $row) {
            $sigFiles[] = $row['file_path'];
        }
        foreach (array_unique($sigFiles) as $f) {
            \App\Services\UploadService::delete($f);
        }
        Database::run('DELETE FROM signatures WHERE booking_id = :b', ['b' => $bid]);
        Database::run("DELETE FROM activity_logs WHERE action IN ('signature_signed', 'signature_voided')");
        t_http_flow_cleanup($fx);
    }
}

function test_http_every_page_renders_without_php_warnings(): void
{
    $fx = t_http_flow_fixture();
    $bid = $fx['booking'];
    try {
        $anon = [];
        $car = $fx['car'];
        $pickup = date('Y-m-d\TH:i', strtotime('+10 days 10:00'));
        $return = date('Y-m-d\TH:i', strtotime('+12 days 10:00'));
        foreach (['/', '/cars', "/cars/$car", '/how-to', '/faq', '/contact', '/terms', '/track', '/admin/login', '/nope',
                  '/cars?pd=' . substr($pickup, 0, 10) . '&pt=10:00&rd=' . substr($return, 0, 10) . '&rt=10:00',
                  "/cars/$car?pickup=$pickup&return=$return",
                  "/booking?car=$car&pickup=$pickup&return=$return"] as $path) {
            $r = t_http('GET', $path, [], $anon);
            assert_true(in_array($r['status'], [200, 404], true), "$path ตอบ {$r['status']}");
            assert_true($path === '/nope' || $r['status'] === 200, "$path ต้อง 200");
            assert_true(strpos($r['body'], '<html') !== false, "$path ต้องเป็นหน้า HTML เต็ม");
        }
        // ผลค้นหาแสดงราคารวมของช่วงที่เลือก
        $search = t_http('GET', '/cars?pd=' . substr($pickup, 0, 10) . '&pt=10:00&rd=' . substr($return, 0, 10) . '&rt=10:00', [], $anon);
        assert_true(strpos($search['body'], 'พบรถว่าง') !== false, 'ผลค้นหาต้องบอกจำนวนรถว่าง');
        // วันคืนก่อนวันรับ = แจ้งผิดพลาด ไม่ 500
        assert_same(200, t_http('GET', '/cars?pd=2031-01-10&pt=10:00&rd=2031-01-09&rt=10:00', [], $anon)['status']);

        $admin = [];
        t_http_login($fx, $admin);
        foreach (['/admin', '/admin/bookings', '/admin/bookings?status=pending&q=x', '/admin/bookings/create', "/admin/bookings/$bid", "/admin/bookings/$bid/contract",
                  "/admin/bookings/$bid/inspection/out", "/admin/bookings/$bid/security", '/admin/cars', '/admin/cars/create', "/admin/cars/$car/edit",
                  '/admin/payments', '/admin/documents', '/admin/documents?type=invoice', '/admin/documents/create', "/admin/documents/create?booking=$bid&type=invoice",
                  '/admin/settings', '/admin/customers', '/admin/customers?only=blacklist', '/admin/customers?q=%25', '/admin/customers/create', '/admin/calendar', '/admin/calendar?m=2031-09', '/admin/calendar?m=zzz', '/admin/calendar?m[]=1'] as $path) {
            $r = t_http('GET', $path, [], $admin);
            assert_same(200, $r['status'], "$path ตอบ {$r['status']}");
            assert_true(strpos($r['body'], 'class="flex min-h-screen"') !== false || strpos($r['body'], 'min-h-screen') !== false, "$path ต้องอยู่ในเลย์เอาต์หลังบ้าน");
        }
        $problems = t_http_php_problems();
        assert_same([], $problems, "มี PHP warning/notice ระหว่างเรนเดอร์หน้า:\n" . implode("\n", array_slice($problems, 0, 5)));
    } finally {
        t_http_flow_cleanup($fx);
    }
}

function test_http_calendar_block_and_unblock(): void
{
    $fx = t_http_flow_fixture();
    $car = $fx['car'];
    try {
        $admin = [];
        t_http_login($fx, $admin);
        $csrf = t_http_csrf('/admin/calendar?m=2031-11', $admin);

        // เดือนที่มีการจองของ fixture (ก.ย. 2031) แสดงเลขที่จอง
        $page = t_http('GET', '/admin/calendar?m=2031-09', [], $admin);
        assert_true(strpos($page['body'], 'BK') !== false && strpos($page['body'], 'กันยายน 2574') !== false, 'ปฏิทินแสดงการจองและเดือนพ.ศ.');

        // ปิดรถทับการจอง → ปฏิเสธ
        t_http('POST', '/admin/calendar/block', ['form' => ['_csrf' => $csrf, 'm' => '2031-09', 'car_id' => (string) $car, 'start_at' => '2031-09-11T00:00', 'end_at' => '2031-09-11T20:00', 'reason' => 'x']], $admin);
        assert_same(0, (int) Database::value('SELECT COUNT(*) FROM car_blocks WHERE car_id = :c', ['c' => $car]));

        // ช่วงว่าง → ปิดได้
        $r = t_http('POST', '/admin/calendar/block', ['form' => ['_csrf' => $csrf, 'm' => '2031-11', 'car_id' => (string) $car, 'start_at' => '2031-11-05T08:00', 'end_at' => '2031-11-07T18:00', 'reason' => 'เช็กระยะ']], $admin);
        assert_same(302, $r['status']);
        assert_true(str_contains($r['headers']['location'][0], 'm=2031-11'));
        $block = Database::one('SELECT * FROM car_blocks WHERE car_id = :c', ['c' => $car]);
        assert_true($block !== null && $block['reason'] === 'เช็กระยะ');
        $shown = t_http('GET', '/admin/calendar?m=2031-11', [], $admin);
        assert_true(strpos($shown['body'], 'เช็กระยะ') !== false);

        // ลูกค้าเห็นว่าช่วงนั้นรถไม่ว่าง
        $anon = [];
        $car1 = t_http('GET', "/cars/$car?pickup=2031-11-06T10:00&return=2031-11-06T18:00", [], $anon);
        assert_true(strpos($car1['body'], 'ไม่ว่าง') !== false);

        // ต้องล็อกอิน + ลบ
        $anonStatus = t_http('POST', '/admin/calendar/unblock', ['form' => ['_csrf' => $csrf, 'block_id' => (string) $block['id']]], $anon)['status'];
        assert_true(in_array($anonStatus, [302, 419], true), "ตอบ $anonStatus");
        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM car_blocks WHERE car_id = :c', ['c' => $car]), 'คนที่ไม่ได้ล็อกอินลบไม่ได้');
        t_http('POST', '/admin/calendar/unblock', ['form' => ['_csrf' => $csrf, 'block_id' => (string) $block['id'], 'm' => '2031-11']], $admin);
        assert_same(0, (int) Database::value('SELECT COUNT(*) FROM car_blocks WHERE car_id = :c', ['c' => $car]));
    } finally {
        Database::run('DELETE FROM car_blocks WHERE car_id = :c', ['c' => $car]);
        t_http_flow_cleanup($fx);
    }
}

function test_http_car_images_upload_cover_gallery_and_public_page(): void
{
    $fx = t_http_flow_fixture();
    $car = $fx['car'];
    $files = [];
    $made = [];
    try {
        $admin = [];
        t_http_login($fx, $admin);
        $csrf = t_http_csrf("/admin/cars/$car/edit", $admin);
        foreach (['a', 'b', 'c'] as $n) {
            $f = t_img_file('jpg', 640, 480);
            $files[$n] = $f['tmp_name'];
        }
        $txt = tempnam(sys_get_temp_dir(), 'car');
        file_put_contents($txt, '<?php echo "x";');

        // ฟอร์มแสดงแผงรูป
        $form = t_http('GET', "/admin/cars/$car/edit", [], $admin);
        assert_true(strpos($form['body'], 'id="images"') !== false && strpos($form['body'], 'name="images[]"') !== false);

        // รูปปก
        $r = t_http('POST', "/admin/cars/$car/cover", ['form' => ['_csrf' => $csrf], 'files' => ['cover' => [$files['a'], 'รถ.jpg', 'image/jpeg']]], $admin);
        assert_same(302, $r['status']);
        $cover = Database::value('SELECT cover_image FROM cars WHERE id = :c', ['c' => $car]);
        $made[] = $cover;
        assert_true(is_string($cover) && preg_match('#^cars/[0-9a-f]{32}\.(webp|jpg)$#', $cover) === 1, 'ตั้งปกแล้ว: ' . var_export($cover, true));

        // ไฟล์ที่ไม่ใช่รูปถูกปฏิเสธ (ปกเดิมอยู่)
        t_http('POST', "/admin/cars/$car/cover", ['form' => ['_csrf' => $csrf], 'files' => ['cover' => [$txt, 'evil.jpg', 'image/jpeg']]], $admin);
        assert_same($cover, Database::value('SELECT cover_image FROM cars WHERE id = :c', ['c' => $car]));

        // แกลเลอรีหลายไฟล์ในคำขอเดียว: รูปที่ผ่านบันทึก รูปเสียถูกข้ามพร้อมข้อความ
        t_http('POST', "/admin/cars/$car/images", ['form' => ['_csrf' => $csrf], 'file_list' => [
            ['images[]', $files['b'], 'b.jpg', 'image/jpeg'], ['images[]', $txt, 'bad.jpg', 'image/jpeg'], ['images[]', $files['c'], 'c.jpg', 'image/jpeg'],
        ]], $admin);
        $gallery = Database::all('SELECT * FROM car_images WHERE car_id = :c ORDER BY sort_order', ['c' => $car]);
        assert_same(2, count($gallery), 'รูปที่ผ่านบันทึก 2 รูป ไฟล์ปลอมถูกข้าม');
        foreach ($gallery as $g) {
            $made[] = $g['path'];
        }
        $page = t_http('GET', "/admin/cars/$car/edit", [], $admin);
        assert_true(strpos($page['body'], 'bad.jpg') !== false, 'บอกว่าไฟล์ไหนไม่ผ่าน');

        // หน้าลูกค้าเห็นปก + แกลเลอรี และไฟล์เปิดได้จริงเป็นรูป
        $anon = [];
        $pub = t_http('GET', "/cars/$car", [], $anon);
        assert_true(substr_count($pub['body'], '/uploads/cars/') >= 3, 'ปก + แกลเลอรีในหน้ารถ');
        assert_true(strpos($pub['body'], 'id="thumbs"') !== false);
        $img = t_http('GET', '/uploads/' . $cover, [], $anon);
        assert_same(200, $img['status']);
        assert_true(str_contains(implode(',', $img['headers']['content-type'] ?? []), 'image/'), 'รูปปกต้องส่งเป็นรูป');

        // ลบ: ต้องล็อกอิน + ลบแล้วไฟล์หาย
        $anonDel = t_http('POST', '/admin/car-images/' . $gallery[0]['id'] . '/delete', ['form' => ['_csrf' => $csrf]], $anon)['status'];
        assert_true(in_array($anonDel, [302, 419], true));
        assert_same(2, (int) Database::value('SELECT COUNT(*) FROM car_images WHERE car_id = :c', ['c' => $car]));
        t_http('POST', '/admin/car-images/' . $gallery[0]['id'] . '/delete', ['form' => ['_csrf' => $csrf]], $admin);
        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM car_images WHERE car_id = :c', ['c' => $car]));
        assert_same(null, \App\Services\CarImageService::absPath($gallery[0]['path']));
        assert_same(404, t_http('POST', '/admin/car-images/999999/delete', ['form' => ['_csrf' => $csrf]], $admin)['status']);
        t_http('POST', "/admin/cars/$car/cover/delete", ['form' => ['_csrf' => $csrf]], $admin);
        assert_same(null, Database::value('SELECT cover_image FROM cars WHERE id = :c', ['c' => $car]));

        // ฟอร์มเพิ่มรถใหม่ → ไปหน้าแก้ไขเพื่อเพิ่มรูปต่อ
        $new = t_http('POST', '/admin/cars', ['form' => ['_csrf' => $csrf, 'plate' => 'IMG-' . bin2hex(random_bytes(2)), 'brand' => 'T', 'model' => 'M', 'car_type_code' => 'sedan',
            'seats' => '5', 'gear' => 'auto', 'fuel' => 'petrol', 'status' => 'available', 'price_day' => '1000', 'security_deposit' => '0', 'booking_deposit' => '0']], $admin);
        assert_same(302, $new['status']);
        assert_true(preg_match('#/admin/cars/(\d+)/edit$#', $new['headers']['location'][0], $m) === 1);
        Database::run('DELETE FROM cars WHERE id = :c', ['c' => (int) $m[1]]);
    } finally {
        foreach (Database::all('SELECT path FROM car_images WHERE car_id = :c', ['c' => $car]) as $row) {
            $made[] = $row['path'];
        }
        $made[] = Database::value('SELECT cover_image FROM cars WHERE id = :c', ['c' => $car]);
        foreach (array_filter(array_unique($made)) as $p) {
            $full = \App\Services\CarImageService::absPath((string) $p);
            if ($full) {
                @unlink($full);
            }
        }
        foreach ($files as $f) {
            @unlink($f);
        }
        @unlink($txt ?? '');
        t_http_flow_cleanup($fx);
    }
}

function test_http_customers_create_edit_documents_and_permissions(): void
{
    $fx = t_http_flow_fixture();
    $created = [];
    $files = [];
    $staffEmail = 'staff' . bin2hex(random_bytes(3)) . '@test.local';
    $staffId = Database::insert("INSERT INTO users (name, email, password_hash, role) VALUES ('staff', :e, :h, 'staff')", ['e' => $staffEmail, 'h' => password_hash('password-1234', PASSWORD_DEFAULT)]);
    try {
        $admin = [];
        t_http_login($fx, $admin);
        $csrf = t_http_csrf('/admin/customers/create', $admin);

        // รายการ + ฟอร์ม
        $list = t_http('GET', '/admin/customers', [], $admin);
        assert_same(200, $list['status']);
        assert_true(strpos($list['body'], 'ลูกค้าทั้งหมด') !== false);

        // เพิ่มลูกค้า
        $phone = '08' . random_int(10000000, 99999999);
        $form = ['_csrf' => $csrf, 'type' => 'individual', 'full_name' => 'ลูกค้า เอชทีทีพี', 'phone' => $phone, 'email' => 'x@y.co'];
        $r = t_http('POST', '/admin/customers', ['form' => $form], $admin);
        assert_same(302, $r['status']);
        assert_true(preg_match('#/admin/customers/(\d+)/edit$#', $r['headers']['location'][0], $m) === 1);
        $cid = (int) $m[1];
        $created[] = $cid;

        // เบอร์ซ้ำ → กลับฟอร์มพร้อมข้อความ ไม่ 500 ไม่สร้างซ้ำ
        $dup = t_http('POST', '/admin/customers', ['form' => ['full_name' => 'อีกคน'] + $form], $admin);
        assert_same(200, $dup['status']);
        assert_true(strpos($dup['body'], 'เบอร์นี้มีลูกค้าอยู่แล้ว') !== false);
        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM customers WHERE phone = :p', ['p' => $phone]));
        // ช่องผิด → ข้อความที่ช่อง (ค่าแปลกในอาร์เรย์ก็ไม่ 500)
        $bad = t_http('POST', '/admin/customers', ['form' => ['_csrf' => $csrf, 'full_name' => '', 'phone' => 'abc', 'email' => ['x']]], $admin);
        assert_same(200, $bad['status']);
        assert_true(strpos($bad['body'], 'เบอร์โทรไม่ถูกต้อง') !== false);

        // แก้ไข
        $edit = t_http('GET', "/admin/customers/$cid/edit", [], $admin);
        assert_same(200, $edit['status']);
        assert_true(strpos($edit['body'], 'ลูกค้า เอชทีทีพี') !== false && strpos($edit['body'], 'id="docs"') !== false);
        $upd = t_http('POST', "/admin/customers/$cid", ['form' => ['full_name' => 'ชื่อใหม่'] + $form], $admin);
        assert_same(302, $upd['status']);
        assert_same('ชื่อใหม่', Database::value('SELECT full_name FROM customers WHERE id = :i', ['i' => $cid]));
        assert_same(404, t_http('GET', '/admin/customers/999999/edit', [], $admin)['status']);
        assert_same(404, t_http('GET', '/admin/customers/abc/edit', [], $admin)['status']);

        // ค้นหา
        $found = t_http('GET', '/admin/customers?q=' . urlencode($phone), [], $admin);
        assert_true(strpos($found['body'], 'ชื่อใหม่') !== false);

        // เอกสาร: อัปโหลด รูป + PDF + ไฟล์ปลอม
        $png = t_cdoc_file('png');
        $pdf = t_cdoc_file('pdf');
        $evil = t_cdoc_file('txt');
        $files = [$png['tmp_name'], $pdf['tmp_name'], $evil['tmp_name']];
        foreach ([[$png, 'บัตร.png', 'image/png'], [$pdf, 'a.pdf', 'application/pdf'], [$evil, 'evil.png', 'image/png']] as [$f, $n, $mime]) {
            t_http('POST', "/admin/customers/$cid/documents", ['form' => ['_csrf' => $csrf, 'doc_kind' => 'id_card', 'doc_note' => 'ทดสอบ'], 'files' => ['doc_file' => [$f['tmp_name'], $n, $mime]]], $admin);
        }
        $docs = Database::all('SELECT * FROM customer_documents WHERE customer_id = :c ORDER BY id', ['c' => $cid]);
        assert_same(2, count($docs), 'รูปกับ PDF ผ่าน ไฟล์ปลอมไม่ผ่าน');
        $created = array_merge($created);

        // เปิดดูเอกสาร: ล็อกอินแล้วได้ชนิดถูกต้อง + header กันเดา/แคช + บันทึกประวัติ ; คนนอกไม่ได้
        $view = t_http('GET', '/admin/customer-docs/' . $docs[0]['id'], [], $admin);
        assert_same(200, $view['status']);
        assert_true(str_contains(implode(',', $view['headers']['content-type'] ?? []), 'image/png'));
        assert_true(str_contains(implode(',', $view['headers']['x-content-type-options'] ?? []), 'nosniff'));
        assert_true(str_contains(strtolower(implode(',', $view['headers']['cache-control'] ?? [])), 'no-store'));
        $pdfView = t_http('GET', '/admin/customer-docs/' . $docs[1]['id'], [], $admin);
        assert_true(str_contains(implode(',', $pdfView['headers']['content-type'] ?? []), 'application/pdf'));
        assert_true((int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'customer_document_viewed'") >= 2, 'บันทึกประวัติการเปิดดู');
        $anon = [];
        assert_same(302, t_http('GET', '/admin/customer-docs/' . $docs[0]['id'], [], $anon)['status'], 'ไม่ล็อกอินต้องถูกส่งไปหน้าล็อกอิน');
        assert_same(404, t_http('GET', '/admin/customer-docs/999999', [], $admin)['status']);
        assert_same(404, t_http('GET', '/storage/private/' . $docs[0]['file_path'], [], $anon)['status'], 'เปิดไฟล์ตรงไม่ได้');

        // พนักงาน (ไม่ใช่เจ้าของร้าน) ลบเอกสาร/ลูกค้าไม่ได้ แต่แนบ/เปิดดูได้
        $staff = [];
        $tok = t_http_csrf('/admin/login', $staff);
        t_http('POST', '/admin/login', ['form' => ['_csrf' => $tok, 'email' => $staffEmail, 'password' => 'password-1234']], $staff);
        $stCsrf = t_http_csrf("/admin/customers/$cid/edit", $staff);
        assert_same(200, t_http('GET', '/admin/customer-docs/' . $docs[0]['id'], [], $staff)['status']);
        assert_same(403, t_http('POST', '/admin/customer-docs/' . $docs[0]['id'] . '/delete', ['form' => ['_csrf' => $stCsrf]], $staff)['status']);
        assert_same(403, t_http('POST', "/admin/customers/$cid/delete", ['form' => ['_csrf' => $stCsrf]], $staff)['status']);
        $edStaff = t_http('GET', "/admin/customers/$cid/edit", [], $staff);
        assert_true(strpos($edStaff['body'], 'ลบลูกค้า') === false && strpos($edStaff['body'], '/delete') === false, 'พนักงานไม่เห็นปุ่มลบ');

        // เจ้าของร้านลบเอกสาร → ไฟล์หาย ; สร้างการจองจากหน้าลูกค้าเติมข้อมูลให้
        $rel = $docs[0]['file_path'];
        t_http('POST', '/admin/customer-docs/' . $docs[0]['id'] . '/delete', ['form' => ['_csrf' => $csrf]], $admin);
        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM customer_documents WHERE customer_id = :c', ['c' => $cid]));
        assert_same(null, \App\Services\CustomerDocService::absPath($rel));
        $prefill = t_http('GET', "/admin/bookings/create?customer=$cid", [], $admin);
        assert_true(strpos($prefill['body'], 'value="' . $phone . '"') !== false && strpos($prefill['body'], 'ชื่อใหม่') !== false, 'เติมชื่อ/เบอร์จากลูกค้า');

        // ลบลูกค้า (ไม่มีการจอง) → หายจากรายการ เบอร์ใช้ซ้ำได้
        t_http('POST', "/admin/customers/$cid/delete", ['form' => ['_csrf' => $csrf]], $admin);
        assert_true(Database::value('SELECT deleted_at FROM customers WHERE id = :i', ['i' => $cid]) !== null);
        assert_same(404, t_http('GET', "/admin/customers/$cid/edit", [], $admin)['status']);
    } finally {
        foreach (Database::all('SELECT file_path FROM customer_documents WHERE customer_id IN (' . implode(',', array_map('intval', $created ?: [0])) . ')') as $row) {
            $full = \App\Services\CustomerDocService::absPath($row['file_path']);
            if ($full) {
                @unlink($full);
            }
        }
        foreach ($created as $c) {
            Database::run('DELETE FROM customers WHERE id = :i', ['i' => $c]);
        }
        foreach ($files as $f) {
            @unlink($f);
        }
        Database::run('DELETE FROM users WHERE id = :u', ['u' => $staffId]);
        Database::run("DELETE FROM activity_logs WHERE action LIKE 'customer_%'");
        t_http_flow_cleanup($fx);
    }
}
