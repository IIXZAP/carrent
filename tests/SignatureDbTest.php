<?php
use App\Core\Database;
use App\Models\Booking;
use App\Models\Setting;
use App\Models\Signature;
use App\Services\SignatureService;
use App\Services\UploadService;

/** รูปลายเซ็นจริงจาก GD (เส้นหมึกสีดำบนพื้นโปร่งใส เหมือนที่ canvas ส่งมา) เป็น data URL */
function t_sig_png(int $w = 400, int $h = 150, bool $ink = true): string
{
    $im = imagecreatetruecolor($w, $h);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    if ($ink) {
        imagealphablending($im, true);
        imagesetthickness($im, 4);
        $black = imagecolorallocate($im, 10, 10, 10);
        for ($i = 0; $i < 6; $i++) {
            imageline($im, 20 + $i * 50, 120, 60 + $i * 50, 20, $black);
            imageline($im, 60 + $i * 50, 20, 90 + $i * 50, 120, $black);
        }
    }
    ob_start();
    imagepng($im);
    $bin = (string) ob_get_clean();
    return 'data:image/png;base64,' . base64_encode($bin);
}

function t_sig_cleanup(): void
{
    foreach ($GLOBALS['t_sig_files'] ?? [] as $path) {
        UploadService::delete($path);
    }
    $GLOBALS['t_sig_files'] = [];
}

/** การจองพร้อมเซ็น + ค่าที่หน้าเซ็นส่งมา (seen = hash ของสิ่งที่ลูกค้าเห็นจริง) */
function t_sig_input(array $booking, string $kind = 'contract', array $over = []): array
{
    $view = SignatureService::view(Booking::find((int) $booking['id']), $kind);
    return $over + ['signature' => t_sig_png(), 'name' => 'สมชาย ใจดี', 'accept_terms' => '1', 'consent' => '1', 'seen' => $view['hash']];
}

function t_sign(array $booking, string $kind = 'contract', array $over = []): int
{
    $id = SignatureService::sign((int) $booking['id'], $kind, t_sig_input($booking, $kind, $over), ['ip' => '192.0.2.77', 'ua' => 'PHPUnit-ish/1.0']);
    $GLOBALS['t_sig_files'][] = Signature::find($id)['file_path'];
    return $id;
}

register_shutdown_function('t_sig_cleanup');

// ---------- รูปลายเซ็น ----------

function test_sig_toPng_accepts_real_drawing_and_returns_png(): void
{
    $png = SignatureService::toPng(t_sig_png());
    $info = getimagesizefromstring($png);
    assert_same(IMAGETYPE_PNG, $info[2]);
    assert_same(400, $info[0]);
}

function test_sig_toPng_flattens_transparent_background_to_white(): void
{
    $im = imagecreatefromstring(SignatureService::toPng(t_sig_png()));
    $c = imagecolorat($im, 2, 2);
    assert_same(255, ($c >> 16) & 255, 'มุมภาพที่เคยโปร่งใสต้องกลายเป็นสีขาว');
}

function test_sig_toPng_rejects_bad_images(): void
{
    $valid = t_sig_png();
    $bin = base64_decode(substr($valid, strlen('data:image/png;base64,')));
    $cases = [
        'ว่างเปล่า'            => '',
        'ไม่ใช่ data url'       => 'hello',
        'jpeg ปลอมเป็น png'     => 'data:image/png;base64,' . base64_encode(str_repeat("\xFF\xD8\xFF", 200)),
        'ชนิด gif'             => 'data:image/gif;base64,' . base64_encode($bin),
        'base64 พัง'           => 'data:image/png;base64,@@@@',
        'กระดาษเปล่า'          => t_sig_png(400, 150, false),
        'เล็กเกินไป'            => t_sig_png(50, 30),
        'ใหญ่เกินไป'            => t_sig_png(2500, 100),
        'PHP ซ่อนในไฟล์'       => 'data:image/png;base64,' . base64_encode("<?php echo 1; ?>" . str_repeat('A', 300)),
    ];
    foreach ($cases as $name => $dataUrl) {
        t_expect_refused(fn() => SignatureService::toPng($dataUrl), $name);
    }
}

function test_sig_toPng_drops_trailing_junk_appended_to_png(): void
{
    $bin = base64_decode(substr(t_sig_png(), strlen('data:image/png;base64,')));
    $evil = 'data:image/png;base64,' . base64_encode($bin . '<?php system($_GET[0]); ?>');
    $out = SignatureService::toPng($evil);
    assert_true(strpos($out, '<?php') === false, 'ข้อมูลแปลกปลอมท้ายไฟล์ต้องไม่รอดจากการเขียนภาพใหม่');
}

// ---------- ลิงก์เซ็น ----------

function test_db_sig_token_is_stable_unique_and_random(): void
{
    $a = t_pay_booking('0811110001');
    $b = t_pay_booking('0811110002');
    $t1 = Booking::signToken((int) $a['id']);
    assert_true(preg_match('/^[0-9a-f]{32}$/', $t1) === 1);
    assert_same($t1, Booking::signToken((int) $a['id']), 'เรียกซ้ำต้องได้รหัสเดิม (ลิงก์ที่ส่งไปแล้วต้องไม่เสีย)');
    assert_true($t1 !== Booking::signToken((int) $b['id']));
}

function test_db_sig_find_by_token_requires_matching_number_and_token(): void
{
    $a = t_pay_booking('0811110003');
    $b = t_pay_booking('0811110004');
    $ta = Booking::signToken((int) $a['id']);
    Booking::signToken((int) $b['id']);   // ใบที่สองมีรหัสของตัวเองแล้ว แต่รหัสของใบแรกต้องใช้กับใบที่สองไม่ได้
    assert_same((int) $a['id'], (int) Booking::findBySignToken($a['booking_no'], $ta)['id']);
    assert_same(null, Booking::findBySignToken($b['booking_no'], $ta), 'รหัสของใบอื่นใช้ไม่ได้');
    assert_same(null, Booking::findBySignToken($a['booking_no'], ''), 'รหัสว่างห้ามผ่าน');
    assert_same(null, Booking::findBySignToken($a['booking_no'], strtoupper($ta)), 'ต้องตรงทุกตัวอักษร');
    assert_same(null, Booking::findBySignToken($a['booking_no'], substr($ta, 0, 31)));
}

// ---------- เซ็นสัญญา ----------

function test_db_sig_sign_contract_stores_evidence(): void
{
    $b = t_pay_booking();
    $id = t_sign($b);
    $sig = Signature::find($id);
    assert_same('contract', $sig['kind']);
    assert_same('สมชาย ใจดี', $sig['signer_name']);
    assert_same('192.0.2.77', $sig['ip']);
    assert_same('PHPUnit-ish/1.0', $sig['user_agent']);
    assert_same(1, (int) $sig['consent_pdpa']);
    assert_same(hash('sha256', $sig['snapshot']), $sig['snapshot_hash']);
    assert_true(SignatureService::intact($sig));
    assert_same(12, strlen(SignatureService::ref($sig)));
    $snap = json_decode($sig['snapshot'], true);
    assert_same($b['booking_no'], $snap['booking_no']);
    assert_true(strpos($snap['terms'], 'ทรัพย์สินที่เช่า') !== false, 'เก็บข้อความสัญญาที่ลูกค้าเห็น');
    assert_true($snap['pdpa_text'] !== '', 'เก็บข้อความ PDPA ที่ลูกค้าเห็น');
    // ไฟล์อยู่ในที่เก็บแบบปิดและเป็น PNG จริง
    $full = UploadService::resolve($sig['file_path']);
    assert_true($full !== null && str_contains($full, '/storage/private/signatures/'));
    assert_same(IMAGETYPE_PNG, getimagesize($full)[2]);
}

function test_db_sig_requires_both_consents_and_a_real_name(): void
{
    $b = t_pay_booking();
    t_expect_refused(fn() => t_sign($b, 'contract', ['accept_terms' => '']), 'ไม่ยอมรับเงื่อนไข');
    t_expect_refused(fn() => t_sign($b, 'contract', ['consent' => '']), 'ไม่ยินยอม PDPA');
    t_expect_refused(fn() => t_sign($b, 'contract', ['name' => ' ']), 'ไม่มีชื่อ');
    t_expect_refused(fn() => t_sign($b, 'contract', ['name' => 'ก']), 'ชื่อสั้นไป');
    t_expect_refused(fn() => t_sign($b, 'contract', ['name' => str_repeat('ก', 151)]), 'ชื่อยาวไป');
    t_expect_refused(fn() => t_sign($b, 'contract', ['signature' => '']), 'ไม่เซ็น');
    assert_same(0, (int) Database::value('SELECT COUNT(*) FROM signatures WHERE booking_id = :b', ['b' => $b['id']]));
}

function test_db_sig_name_whitespace_is_collapsed(): void
{
    $b = t_pay_booking();
    assert_same('สมชาย ใจดี', Signature::find(t_sign($b, 'contract', ['name' => "  สมชาย \n  ใจดี  "]))['signer_name']);
}

function test_db_sig_only_one_active_signature_per_booking_and_kind(): void
{
    $b = t_pay_booking();
    t_sign($b);
    t_expect_refused(fn() => t_sign($b), 'เซ็นซ้ำ');
    assert_same(1, (int) Database::value('SELECT COUNT(*) FROM signatures WHERE booking_id = :b', ['b' => $b['id']]));
}

function test_db_sig_status_gates(): void
{
    $b = t_pay_booking();
    foreach (['cancelled', 'returned', 'completed'] as $status) {
        Database::run('UPDATE bookings SET status = :s WHERE id = :id', ['s' => $status, 'id' => $b['id']]);
        t_expect_refused(fn() => t_sign($b, 'contract'), "สัญญาในสถานะ $status");
    }
    // ใบรับคืนเซ็นได้เฉพาะ returned/completed
    foreach (['pending', 'confirmed', 'picked_up', 'cancelled'] as $status) {
        Database::run('UPDATE bookings SET status = :s WHERE id = :id', ['s' => $status, 'id' => $b['id']]);
        t_expect_refused(fn() => t_sign($b, 'return'), "ใบรับคืนในสถานะ $status");
    }
    Database::run("UPDATE bookings SET status = 'returned' WHERE id = :id", ['id' => $b['id']]);
    assert_true(t_sign($b, 'return') > 0);
    t_expect_refused(fn() => SignatureService::sign((int) $b['id'], 'hack', t_sig_input($b), []), 'ชนิดไม่รู้จัก');
    t_expect_refused(fn() => SignatureService::sign(99999999, 'contract', t_sig_input($b), []), 'ไม่มีการจอง');
}

function test_db_sig_stale_view_is_refused_when_shop_changed_data_meanwhile(): void
{
    $b = t_pay_booking();
    $input = t_sig_input($b);   // ลูกค้าเปิดหน้าเซ็นตอนนี้
    Database::run('UPDATE bookings SET security_deposit = 5000 WHERE id = :id', ['id' => $b['id']]);   // ร้านแก้เงินประกันระหว่างที่ลูกค้ากำลังเซ็น
    $thrown = null;
    try {
        SignatureService::sign((int) $b['id'], 'contract', $input, []);
    } catch (\App\Services\UserError $e) {
        $thrown = $e->getMessage();
    }
    assert_true($thrown !== null && strpos($thrown, 'เปลี่ยนแปลง') !== false, 'ต้องให้ลูกค้าตรวจข้อมูลใหม่ ไม่ใช่รับรองข้อมูลที่ไม่ได้เห็น: ' . var_export($thrown, true));
    assert_same(0, (int) Database::value('SELECT COUNT(*) FROM signatures WHERE booking_id = :b', ['b' => $b['id']]));
}

function test_db_sig_missing_seen_hash_is_refused(): void
{
    $b = t_pay_booking();
    t_expect_refused(fn() => t_sign($b, 'contract', ['seen' => '']), 'ไม่มี hash ที่เห็น');
    t_expect_refused(fn() => t_sign($b, 'contract', ['seen' => str_repeat('a', 64)]), 'hash มั่ว');
}

function test_db_sig_view_hash_changes_with_everything_shown(): void
{
    $b = t_pay_booking();
    $h0 = SignatureService::view(Booking::find((int) $b['id']), 'contract')['hash'];
    assert_same($h0, SignatureService::view(Booking::find((int) $b['id']), 'contract')['hash'], 'ค่าเดิมต้องได้ hash เดิม');
    $mutations = [
        'ยอดรวม'      => fn() => Database::run('UPDATE bookings SET grand_total = grand_total + 1, subtotal = subtotal + 1, rental_amount = rental_amount + 1 WHERE id = :id', ['id' => $b['id']]),
        'วันรับรถ'      => fn() => Database::run("UPDATE bookings SET pickup_at = '2030-06-10 11:00:00' WHERE id = :id", ['id' => $b['id']]),
        'ชื่อผู้เช่า'    => fn() => Database::run("UPDATE bookings SET contact_name = 'คนอื่น' WHERE id = :id", ['id' => $b['id']]),
        'ทะเบียนรถ'    => fn() => Database::run("UPDATE cars SET plate = 'ZZ 9999' WHERE id = :id", ['id' => $b['car_id']]),
        'ข้อความ PDPA' => fn() => Setting::set('pdpa_policy_text', 'ข้อความใหม่ ' . uniqid()),
        'ข้อสัญญาเพิ่ม'  => fn() => Setting::set('contract_extra_terms', 'ห้ามสูบบุหรี่ในรถ'),
    ];
    foreach ($mutations as $name => $mutate) {
        $before = SignatureService::view(Booking::find((int) $b['id']), 'contract')['hash'];
        $mutate();
        $after = SignatureService::view(Booking::find((int) $b['id']), 'contract')['hash'];
        assert_true($before !== $after, "hash ต้องเปลี่ยนเมื่อ $name เปลี่ยน");
    }
}

// ---------- ตรวจการแก้ไขหลังเซ็น ----------

function test_db_sig_changes_detect_edits_after_signing(): void
{
    $b = t_pay_booking();
    $sig = Signature::find(t_sign($b));
    $fresh = fn() => Booking::find((int) $b['id']);
    $car = \App\Models\Car::find((int) $b['car_id']);
    assert_same([], SignatureService::changes($sig, $fresh(), $car), 'ยังไม่แก้ → ไม่มีความต่าง');

    Database::run('UPDATE bookings SET security_deposit = 9999 WHERE id = :id', ['id' => $b['id']]);
    $changes = SignatureService::changes($sig, $fresh(), $car);
    assert_same(1, count($changes));
    assert_same('เงินประกันความเสียหาย', $changes[0]['label']);
    assert_true(strpos($changes[0]['signed'], '3,000') !== false && strpos($changes[0]['now'], '9,999') !== false);
}

function test_db_sig_intact_detects_tampered_snapshot(): void
{
    $b = t_pay_booking();
    $id = t_sign($b);
    Database::run("UPDATE signatures SET snapshot = REPLACE(snapshot, 'สมชาย', 'ปลอม') WHERE id = :id", ['id' => $id]);
    assert_true(!SignatureService::intact(Signature::find($id)), 'แก้สำเนาโดยตรงในฐานข้อมูลต้องถูกจับได้');
}

// ---------- ยกเลิก/เซ็นใหม่ ----------

function test_db_sig_void_keeps_history_and_allows_resign(): void
{
    $b = t_pay_booking();
    $user = t_user();
    $first = t_sign($b);
    t_expect_refused(fn() => SignatureService::void($first, $user, 'ก', '1.1.1.1'), 'เหตุผลสั้นไป');
    SignatureService::void($first, $user, 'ลูกค้าขอแก้ชื่อ', '1.1.1.1');
    $old = Signature::find($first);
    assert_true($old['voided_at'] !== null);
    assert_same($user, (int) $old['voided_by']);
    assert_same('ลูกค้าขอแก้ชื่อ', $old['void_reason']);
    assert_true($old['file_path'] !== '' && UploadService::resolve($old['file_path']) !== null, 'ไฟล์ลายเซ็นที่ยกเลิกต้องยังอยู่');
    assert_same(null, Signature::active((int) $b['id'], 'contract'));

    $second = t_sign($b);
    assert_same($second, (int) Signature::active((int) $b['id'], 'contract')['id']);
    assert_same(2, count(Signature::allFor((int) $b['id'])));
    t_expect_refused(fn() => SignatureService::void($first, $user, 'ยกเลิกซ้ำ', ''), 'ยกเลิกซ้ำ');
}

function test_db_sig_snapshot_is_unchanged_by_void(): void
{
    $b = t_pay_booking();
    $id = t_sign($b);
    $before = Signature::find($id);
    SignatureService::void($id, t_user(), 'ทดสอบ', '');
    $after = Signature::find($id);
    assert_same($before['snapshot'], $after['snapshot']);
    assert_same($before['snapshot_hash'], $after['snapshot_hash']);
    assert_true(SignatureService::intact($after), 'การยกเลิกไม่ทำให้สำเนาเสีย');
}

// ---------- ใบรับคืนรถ ----------

function test_db_sig_return_receipt_includes_return_facts(): void
{
    $b = t_flow_booking();
    $b = t_picked_up($b, 10000, 8);
    \App\Services\BookingFlowService::returnCar((int) $b['id'], ['mileage_in' => '10300', 'fuel_in' => '5', 'returned_at' => '2030-06-13 10:00:00', 'damage_note' => 'รอยขีดเล็กน้อย'], t_user(), '', t_now('2030-06-13 10:30:00'));
    $view = SignatureService::view(Booking::find((int) $b['id']), 'return');
    $labels = array_column($view['fields'], 0);
    foreach (['รับคืนรถเมื่อ', 'เลขไมล์ตอนคืน', 'น้ำมันตอนคืน', 'ค่าใช้จ่ายเพิ่มตอนคืนรถ', 'ความเสียหายที่บันทึก'] as $l) {
        assert_true(in_array($l, $labels, true), "ใบรับคืนต้องมี: $l");
    }
    $values = array_column($view['fields'], 1);
    assert_true(in_array('10,300 กม.', $values, true) && in_array('5/8', $values, true) && in_array('รอยขีดเล็กน้อย', $values, true));
    $sig = Signature::find(t_sign($b, 'return'));
    assert_same('return', $sig['kind']);
    // แก้ค่าใช้จ่ายหลังเซ็น → ถูกจับได้
    Database::run('UPDATE bookings SET extra_charge = extra_charge + 50 WHERE id = :id', ['id' => $b['id']]);
    $labels = array_column(SignatureService::changes($sig, Booking::find((int) $b['id']), \App\Models\Car::find((int) $b['car_id'])), 'label');
    assert_true(in_array('ค่าใช้จ่ายเพิ่มตอนคืนรถ', $labels, true));
}

function test_db_sig_contract_and_return_signatures_are_independent(): void
{
    $b = t_flow_booking();
    t_sign($b, 'contract');
    $b = t_picked_up($b);
    \App\Services\BookingFlowService::returnCar((int) $b['id'], ['mileage_in' => '10100', 'fuel_in' => '8', 'returned_at' => '2030-06-13 10:00:00'], t_user(), '', t_now('2030-06-13 10:30:00'));
    assert_true(t_sign($b, 'return') > 0, 'มีลายเซ็นสัญญาแล้ว ยังเซ็นใบรับคืนได้');
    assert_same(2, count(Signature::allFor((int) $b['id'])));
}

// ---------- ข้อสัญญา ----------

function test_db_sig_terms_use_live_settings_and_booking_facts(): void
{
    $b = t_pay_booking();
    Setting::set('contract_extra_terms', 'ห้ามสูบบุหรี่ในรถ');
    $view = SignatureService::view(Booking::find((int) $b['id']), 'contract');
    foreach (['ทรัพย์สินที่เช่า', 'ห้ามสูบบุหรี่ในรถ', 'การคืนรถล่าช้า', 'ข้อมูลส่วนบุคคล'] as $needle) {
        assert_true(strpos($view['terms_text'], $needle) !== false, "ข้อสัญญาต้องมี: $needle");
    }
    assert_true(strpos($view['terms_html'], '<script') === false);
}

function test_db_sig_terms_escape_html_in_data(): void
{
    $b = t_pay_booking();
    Setting::set('contract_extra_terms', '<script>alert(1)</script>');
    $view = SignatureService::view(Booking::find((int) $b['id']), 'contract');
    assert_true(strpos($view['terms_html'], '<script>alert') === false, 'ข้อความจากตั้งค่าต้องถูก escape');
}
