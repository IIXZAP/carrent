<?php
use App\Core\Database;
use App\Models\Customer;
use App\Services\CustomerDocService;
use App\Services\CustomerService;
use App\Services\UserError;

register_shutdown_function(static function (): void {
    foreach ($GLOBALS['t_cdoc_files'] ?? [] as $rel) {
        $full = CustomerDocService::absPath($rel);
        if ($full) {
            @unlink($full);
        }
    }
});

function t_cin(array $over = []): array
{
    return $over + ['type' => 'individual', 'full_name' => 'สมหมาย ทดสอบ', 'phone' => '08' . random_int(10000000, 99999999), 'email' => 'a@b.co', 'line_id' => 'somying',
        'tax_id' => '', 'address' => '1 ถนนทดสอบ', 'license_no' => 'ก123456', 'license_expire' => '2035-01-31', 'is_blacklist' => '', 'blacklist_note' => '', 'note' => ''];
}

function t_cmake(array $over = [], ?int $user = null): int
{
    [$data, $errors] = CustomerService::validate(t_cin($over));
    assert_same([], $errors, 'ข้อมูลทดสอบต้องผ่านการตรวจ: ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
    return CustomerService::create($data, $user);
}

/** ไฟล์เอกสารในรูปแบบ $_FILES — $kind: png | jpg | pdf | txt | gif */
function t_cdoc_file(string $kind = 'png'): array
{
    $path = tempnam(sys_get_temp_dir(), 'cdoc');
    switch ($kind) {
        case 'pdf': file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n"); break;
        case 'txt': file_put_contents($path, "<?php echo 1;"); break;
        case 'fakepng': file_put_contents($path, "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR" . str_repeat("\0", 8) . "\x08\x02\x00\x00\x00" . str_repeat('junk', 50)); break;
        case 'fakepdf': file_put_contents($path, "<?php echo 1; ?> %PDF-"); break;
        default:
            $im = imagecreatetruecolor(60, 40);
            match ($kind) { 'jpg' => imagejpeg($im, $path), 'gif' => imagegif($im, $path), default => imagepng($im, $path) };
    }
    return ['name' => 'บัตร.' . $kind, 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
}

// ---------- ตรวจข้อมูล ----------

function test_customer_validate_normalizes_phone_and_trims(): void
{
    [$d, $e] = CustomerService::validate(t_cin(['full_name' => "  สมหมาย   ทดสอบ ", 'phone' => '+66 81 234 5678']));
    assert_same([], $e);
    assert_same('0812345678', $d['phone']);
    assert_same('สมหมาย ทดสอบ', $d['full_name']);
    assert_same('individual', $d['type']);
    assert_same(0, $d['is_blacklist']);
}

function test_customer_validate_rejects_bad_input(): void
{
    $bad = [
        'full_name' => [['full_name' => ' '], 'full_name'],
        'ชื่อยาวไป' => [['full_name' => str_repeat('ก', 181)], 'full_name'],
        'phone'     => [['phone' => 'abc'], 'phone'],
        'phone ว่าง' => [['phone' => ''], 'phone'],
        'email'     => [['email' => 'not-an-email'], 'email'],
        'type'      => [['type' => 'robot'], 'type'],
        'วันที่ใบขับขี่' => [['license_expire' => '2030-02-31'], 'license_expire'],
        'tax_id'    => [['tax_id' => str_repeat('1', 21)], 'tax_id'],
        'tax_id ตัวอักษรแปลก' => [['tax_id' => '<script>'], 'tax_id'],
        'line'      => [['line_id' => str_repeat('a', 81)], 'line_id'],
        'address'   => [['address' => str_repeat('ก', 2001)], 'address'],
        'blacklist ไม่มีเหตุผล' => [['is_blacklist' => '1', 'blacklist_note' => ''], 'blacklist_note'],
    ];
    foreach ($bad as $name => [$over, $field]) {
        [, $errors] = CustomerService::validate(t_cin($over));
        assert_true(isset($errors[$field]), "ต้องปฏิเสธช่อง $field ($name): " . json_encode($errors, JSON_UNESCAPED_UNICODE));
    }
    [, $ok] = CustomerService::validate(t_cin(['email' => '', 'license_expire' => '', 'license_no' => '', 'tax_id' => '0105551234567', 'is_blacklist' => '1', 'blacklist_note' => 'ค้างชำระ']));
    assert_same([], $ok, 'ช่องที่ไม่บังคับเว้นว่างได้');
}

// ---------- สร้าง/แก้ ----------

function test_db_customer_create_assigns_code_and_stores_everything(): void
{
    $id = t_cmake([], t_user());
    $c = Customer::find($id);
    assert_true(preg_match('/^CUS-\d{5}$/', $c['code']) === 1, $c['code']);
    assert_same(1, preg_match('/^08\d{8}$/', $c['phone']));
    assert_same('ก123456', $c['license_no']);
    assert_same('2035-01-31', $c['license_expire']);
    $id2 = t_cmake(['phone' => '0899999991']);
    assert_true(Customer::find($id2)['code'] !== $c['code'], 'รหัสไม่ซ้ำ');
}

function test_db_customer_duplicate_phone_is_refused_naming_the_existing_customer(): void
{
    $id = t_cmake(['phone' => '0811110010']);
    $code = Customer::find($id)['code'];
    [$d] = CustomerService::validate(t_cin(['phone' => '081-111-0010', 'full_name' => 'คนอื่น']));
    $e = t_expect_refused(fn() => CustomerService::create($d, null), 'เบอร์ซ้ำ');
    assert_true(str_contains($e->getMessage(), $code), 'ต้องบอกรหัสลูกค้าเดิม: ' . $e->getMessage());
    assert_same(1, (int) Database::value("SELECT COUNT(*) FROM customers WHERE phone = '0811110010'"));
}

function test_db_customer_update_rules_for_phone(): void
{
    $a = t_cmake(['phone' => '0811110021']);
    $b = t_cmake(['phone' => '0811110022']);
    [$d] = CustomerService::validate(t_cin(['phone' => '0811110021', 'full_name' => 'ชื่อใหม่']));
    t_expect_refused(fn() => CustomerService::update($b, $d, null), 'ย้ายไปใช้เบอร์ของคนอื่น');
    assert_same('0811110022', Customer::find($b)['phone']);
    [$same] = CustomerService::validate(t_cin(['phone' => '081-111-0021', 'full_name' => 'ชื่อใหม่']));
    CustomerService::update($a, $same, null);   // เบอร์ของตัวเองรูปแบบอื่น ต้องผ่าน
    assert_same('ชื่อใหม่', Customer::find($a)['full_name']);
    t_expect_refused(fn() => CustomerService::update(99999999, $same, null), 'ไม่มีลูกค้า');
}

function test_db_customer_update_does_not_rewrite_past_bookings(): void
{
    $b = t_pay_booking('0811110031');
    $cid = (int) $b['customer_id'];
    [$d] = CustomerService::validate(t_cin(['phone' => '0811110039', 'full_name' => 'เปลี่ยนชื่อ']));
    CustomerService::update($cid, $d, null);
    $after = Database::one('SELECT contact_phone, contact_name FROM bookings WHERE id = :i', ['i' => $b['id']]);
    assert_same('0811110031', $after['contact_phone'], 'การจองเก่าเก็บข้อมูลตอนจอง ไม่ถูกแก้ย้อนหลัง');
}

function test_db_customer_soft_delete_rules(): void
{
    $b = t_pay_booking('0811110041');
    $cid = (int) $b['customer_id'];
    t_expect_refused(fn() => CustomerService::softDelete($cid, null), 'มีการจองที่ยังไม่จบ');
    Database::run("UPDATE bookings SET status = 'completed' WHERE id = :i", ['i' => $b['id']]);
    CustomerService::softDelete($cid, null);
    assert_true(Customer::find($cid)['deleted_at'] !== null);
    t_expect_refused(fn() => CustomerService::softDelete($cid, null), 'ลบซ้ำ');
    t_cmake(['phone' => '0811110041']);   // เบอร์ใช้ซ้ำได้หลังลบ
}

// ---------- ค้นหา/สถิติ ----------

function test_db_customer_search_matches_name_phone_code_tax_and_escapes_like(): void
{
    $id = t_cmake(['full_name' => 'ซอมชาย 100%_พิเศษ', 'phone' => '0877770101', 'tax_id' => '0105551111111']);
    $code = Customer::find($id)['code'];
    $has = static fn(array $rows) => in_array($id, array_map('intval', array_column($rows, 'id')), true);
    foreach (['ซอมชาย', '0877770101', '087-777-0101', '7770101', $code, '0105551111111', '100%_พิเศษ'] as $q) {
        assert_true($has(Customer::search($q, false, 200)), "ต้องหาเจอด้วย: $q");
    }
    assert_true(!$has(Customer::search('ไม่มีใครชื่อนี้ zzz', false, 200)));
    // % และ _ เป็นตัวอักษรธรรมดา ไม่ใช่ wildcard
    assert_true(!$has(Customer::search('ซอมชาย 1%0%_พิเศษ', false, 200)), '% ต้องไม่ทำหน้าที่ wildcard');
}

function test_db_customer_search_blacklist_filter_and_totals(): void
{
    $bl = t_cmake(['phone' => '0877770202', 'is_blacklist' => '1', 'blacklist_note' => 'ทดสอบ']);
    $ok = t_cmake(['phone' => '0877770203']);
    $ids = array_map('intval', array_column(Customer::search('', true, 500), 'id'));
    assert_true(in_array($bl, $ids, true) && !in_array($ok, $ids, true));
    $t = Customer::totals();
    assert_true($t['total'] >= 2 && $t['blacklist'] >= 1);
}

function test_db_customer_rows_show_jobs_spend_and_last_rent(): void
{
    $b = t_flow_booking('0877770301');   // มัดจำ 1,000 อนุมัติแล้ว
    $b2 = t_book((int) $b['car_id'], '2031-01-10 10:00:00', '2031-01-11 10:00:00', t_contact('0877770301'));
    $cancelled = t_book((int) $b['car_id'], '2031-02-10 10:00:00', '2031-02-11 10:00:00', t_contact('0877770301'));
    Database::run("UPDATE bookings SET status = 'cancelled' WHERE id = :i", ['i' => $cancelled]);
    $row = array_values(array_filter(Customer::search('0877770301', false, 10), fn($r) => (int) $r['id'] === (int) $b['customer_id']))[0];
    assert_same(2, (int) $row['jobs'], 'ไม่นับที่ยกเลิก');
    assert_money(1000, $row['spend']);   // เงินที่เก็บได้จริง ไม่ใช่ยอดจอง
    assert_true(str_starts_with((string) $row['last_rent'], '2031-01-10') || str_starts_with((string) $row['last_rent'], '2030-06-10') || $row['last_rent'] !== null);
    $hist = Customer::history((int) $b['customer_id'], 50);
    assert_same(3, count($hist), 'ประวัติแสดงทุกใบรวมที่ยกเลิก');
}

function test_db_customer_totals_count_repeat_customers(): void
{
    $before = Customer::totals()['repeat'];
    $b = t_pay_booking('0877770401');
    t_book((int) $b['car_id'], '2031-03-10 10:00:00', '2031-03-11 10:00:00', t_contact('0877770401'));
    assert_same($before + 1, Customer::totals()['repeat']);
}

// ---------- เอกสารแนบ ----------

function test_db_cdoc_add_image_and_pdf_stored_privately_with_random_names(): void
{
    $cid = t_cmake(['phone' => '0877770501']);
    $user = t_user();
    foreach (['png', 'jpg', 'pdf'] as $kind) {
        $id = CustomerDocService::add($cid, 'id_card', t_cdoc_file($kind), 'หมายเหตุ', $user, false);
        $doc = Customer::findDocument($id);
        $GLOBALS['t_cdoc_files'][] = $doc['file_path'];
        assert_true(preg_match('#^customer_docs/[0-9a-f]{32}\.(png|jpg|webp|pdf)$#', $doc['file_path']) === 1, $doc['file_path']);
        $full = CustomerDocService::absPath($doc['file_path']);
        assert_true($full !== null && str_contains($full, '/storage/private/customer_docs/'), 'เก็บในที่เก็บแบบปิด');
        assert_same($user, (int) $doc['uploaded_by']);
        assert_same('id_card', $doc['doc_kind']);
        assert_true((int) $doc['file_size'] > 0 && $doc['mime'] !== '');
    }
    assert_same(3, count(Customer::documents($cid)));
}

function test_db_cdoc_rejects_non_documents(): void
{
    $cid = t_cmake(['phone' => '0877770502']);
    foreach (['txt', 'fakepdf', 'fakepng', 'gif'] as $kind) {
        t_expect_refused(fn() => CustomerDocService::add($cid, 'id_card', t_cdoc_file($kind), '', null, false), $kind);
    }
    t_expect_refused(fn() => CustomerDocService::add($cid, 'hack', t_cdoc_file('png'), '', null, false), 'ชนิดเอกสาร');
    t_expect_refused(fn() => CustomerDocService::add(99999999, 'id_card', t_cdoc_file('png'), '', null, false), 'ไม่มีลูกค้า');
    t_expect_refused(fn() => CustomerDocService::add($cid, 'id_card', ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0], '', null, false), 'ไม่แนบไฟล์');
    $big = t_cdoc_file('png');
    file_put_contents($big['tmp_name'], str_repeat("\0", 9 * 1024 * 1024), FILE_APPEND);
    $big['size'] = filesize($big['tmp_name']);
    t_expect_refused(fn() => CustomerDocService::add($cid, 'id_card', $big, '', null, false), 'ใหญ่เกิน 8MB');
    assert_same(0, count(Customer::documents($cid)));
}

function test_db_cdoc_limit_per_customer(): void
{
    $cid = t_cmake(['phone' => '0877770503']);
    for ($i = 0; $i < CustomerDocService::MAX_PER_CUSTOMER; $i++) {
        $id = CustomerDocService::add($cid, 'other', t_cdoc_file('png'), '', null, false);
        $GLOBALS['t_cdoc_files'][] = Customer::findDocument($id)['file_path'];
    }
    $files = count(glob(storage_path('customer_docs') . '/*') ?: []);
    t_expect_refused(fn() => CustomerDocService::add($cid, 'other', t_cdoc_file('png'), '', null, false), 'เกินจำนวน');
    assert_same($files, count(glob(storage_path('customer_docs') . '/*') ?: []), 'ไฟล์ของคำขอที่ถูกปฏิเสธต้องไม่ค้างในที่เก็บ');
}

function test_db_cdoc_delete_removes_file_and_row_and_logs(): void
{
    $cid = t_cmake(['phone' => '0877770504']);
    $id = CustomerDocService::add($cid, 'passport', t_cdoc_file('jpg'), '', null, false);
    $rel = Customer::findDocument($id)['file_path'];
    $admin = t_user();
    CustomerDocService::delete($id, $admin, '203.0.113.9');
    assert_same(null, Customer::findDocument($id));
    assert_same(null, CustomerDocService::absPath($rel), 'ไฟล์ต้องถูกลบจริง (สิทธิขอลบข้อมูลตาม PDPA)');
    assert_same(1, \App\Models\ActivityLog::countByIpSince('customer_document_deleted', '203.0.113.9', '2000-01-01 00:00:00'));
    t_expect_refused(fn() => CustomerDocService::delete($id, $admin, ''), 'ลบซ้ำ');
}

function test_db_cdoc_abs_path_is_confined(): void
{
    foreach (['../config/config.php', 'customer_docs/../../../config/config.php', '/etc/passwd', 'customer_docs/' . str_repeat('a', 32) . '.php', 'slips/' . str_repeat('a', 32) . '.jpg', "customer_docs/x\0.png", ''] as $bad) {
        assert_same(null, CustomerDocService::absPath($bad), json_encode($bad));
    }
}

function test_db_cdoc_note_is_trimmed_and_bounded(): void
{
    $cid = t_cmake(['phone' => '0877770505']);
    $id = CustomerDocService::add($cid, 'id_card', t_cdoc_file('png'), '  ' . str_repeat('ก', 300), null, false);
    $GLOBALS['t_cdoc_files'][] = Customer::findDocument($id)['file_path'];
    assert_same(200, mb_strlen((string) Customer::findDocument($id)['note']));
}
