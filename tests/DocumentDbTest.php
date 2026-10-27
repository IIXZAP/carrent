<?php
use App\Core\Database;
use App\Models\Booking;
use App\Services\DocumentService;
use App\Services\UserError;

function t_doc_items(): array
{
    return [
        ['description' => 'ค่าเช่ารถ', 'qty' => '3', 'unit' => 'วัน', 'price' => '1000'],
        ['description' => 'ที่นั่งเด็ก', 'qty' => '1', 'unit' => '', 'price' => '300.50'],
    ];
}

function t_doc_input(array $over = []): array
{
    return $over + ['doc_type' => 'quotation', 'issue_date' => '2030-06-01', 'bill_name' => 'บริษัท ทดสอบ จำกัด', 'items' => t_doc_items(),
        'discount' => '0', 'vat_rate' => '0', 'wht_rate' => '0'];
}

function test_doc_totals_plain(): void
{
    $t = DocumentService::totals(t_doc_items(), 0, 0, 0);
    assert_money(3300.50, $t['subtotal']);
    assert_money(0, $t['vat']);
    assert_money(3300.50, $t['grand']);
}

function test_doc_totals_vat_wht_discount(): void
{
    // (3300.50 − 300.50) = 3000 → VAT 7% = 210 → WHT 3% = 90 → 3000 + 210 − 90 = 3120
    $t = DocumentService::totals(t_doc_items(), 300.50, 7, 3);
    assert_money(3000.00, $t['net']);
    assert_money(210.00, $t['vat']);
    assert_money(90.00, $t['wht']);
    assert_money(3120.00, $t['grand']);
}

function test_doc_totals_rounding_per_line_and_vat(): void
{
    // ราคา 33.335 ถูกปัดเป็น 33.34 (เท่ากับที่เก็บใน DECIMAL(10,2)) แล้วจึงคูณจำนวน → กระดาษคูณกลับได้ตรงทุกบรรทัด
    $t = DocumentService::totals([['description' => 'x', 'qty' => '3', 'price' => '33.335']], 0, 7, 0);
    assert_money(33.34, $t['items'][0]['price']);
    assert_money(100.02, $t['subtotal']);
    assert_money(7.00, $t['vat']);       // 100.02 × 7% = 7.0014
    assert_money(107.02, $t['grand']);
}

function test_doc_totals_rejects_bad_input(): void
{
    $bad = [
        'ไม่มีรายการ'       => [[], 0, 0, 0],
        'จำนวนเป็น 0'        => [[['description' => 'x', 'qty' => '0', 'price' => '10']], 0, 0, 0],
        'ราคาติดลบ'         => [[['description' => 'x', 'qty' => '1', 'price' => '-1']], 0, 0, 0],
        'ราคาไม่ใช่ตัวเลข'   => [[['description' => 'x', 'qty' => '1', 'price' => 'abc']], 0, 0, 0],
        'ส่วนลดเกินยอด'      => [[['description' => 'x', 'qty' => '1', 'price' => '10']], 10.01, 0, 0],
        'ส่วนลดติดลบ'        => [[['description' => 'x', 'qty' => '1', 'price' => '10']], -1, 0, 0],
        'VAT เกิน 100'      => [[['description' => 'x', 'qty' => '1', 'price' => '10']], 0, 101, 0],
        'WHT ติดลบ'         => [[['description' => 'x', 'qty' => '1', 'price' => '10']], 0, 0, -1],
    ];
    foreach ($bad as $name => $args) {
        $thrown = false;
        try {
            DocumentService::totals(...$args);
        } catch (UserError $e) {
            $thrown = true;
        }
        assert_true($thrown, "ต้องปฏิเสธ: $name");
    }
}

function test_db_doc_create_numbers_and_items(): void
{
    $u = t_user();
    $id = DocumentService::create(t_doc_input(), $u);
    $doc = DocumentService::get($id);
    assert_true(preg_match('/^QT\d{4}-\d{4}$/', $doc['doc_no']) === 1, 'เลขที่ ' . $doc['doc_no']);
    assert_same(2, count($doc['items']));
    assert_same(1, (int) $doc['items'][0]['line_no']);
    assert_money(3300.50, $doc['subtotal']);
    assert_same('draft', $doc['status']);

    $id2 = DocumentService::create(t_doc_input(['doc_type' => 'invoice']), $u);
    assert_true(str_starts_with(DocumentService::get($id2)['doc_no'], 'IV'));
    $id3 = DocumentService::create(t_doc_input(), $u);
    // เลขรันแยกตามประเภท+เดือน และเพิ่มทีละ 1
    $n1 = (int) substr($doc['doc_no'], -4);
    $n3 = (int) substr(DocumentService::get($id3)['doc_no'], -4);
    assert_same($n1 + 1, $n3);
}

function test_db_doc_create_rejects_unknown_type_and_bad_date(): void
{
    foreach ([t_doc_input(['doc_type' => 'hack']), t_doc_input(['issue_date' => '2030-13-40']), t_doc_input(['bill_name' => ''])] as $in) {
        $thrown = false;
        try {
            DocumentService::create($in, t_user());
        } catch (UserError $e) {
            $thrown = true;
        }
        assert_true($thrown);
    }
}

function test_db_doc_totals_match_db_checks_and_edit_replaces_items(): void
{
    $u = t_user();
    $id = DocumentService::create(t_doc_input(['vat_rate' => '7', 'wht_rate' => '3', 'discount' => '300.50']), $u);
    $doc = DocumentService::get($id);
    assert_money(3120.00, $doc['grand_total']);
    DocumentService::update($id, t_doc_input(['items' => [['description' => 'เหลือรายการเดียว', 'qty' => '2', 'price' => '50']]]));
    $doc = DocumentService::get($id);
    assert_same(1, count($doc['items']));
    assert_money(100, $doc['grand_total']);
}

function test_db_doc_from_booking_matches_booking_totals(): void
{
    $b = t_pay_booking();
    $input = DocumentService::inputFromBooking((int) $b['id'], 'quotation');
    $id = DocumentService::create($input, t_user());
    $doc = DocumentService::get($id);
    assert_same((int) $b['id'], (int) $doc['booking_id']);
    assert_money($b['subtotal'], $doc['subtotal'] - $doc['discount'], 'ยอดก่อน VAT ต้องตรงกับการจอง');
    assert_money($b['vat_amount'], $doc['vat_amount']);
    assert_money($b['grand_total'], $doc['grand_total']);
    assert_true(count($doc['items']) >= 1);
}

function test_db_doc_from_booking_with_vat_and_discount(): void
{
    t_booking_settings();
    $car = t_make_car();
    Database::run('UPDATE cars SET price_day = 1000, booking_deposit = 1000, security_deposit = 3000 WHERE id = :id', ['id' => $car]);
    $id = Booking::find(t_book($car, '2030-06-10 10:00:00', '2030-06-13 10:00:00', t_contact('0822222222'), ['discount' => 100, 'vat' => true]))['id'];
    $b = Booking::find((int) $id);
    assert_true((float) $b['vat_amount'] > 0);
    $doc = DocumentService::get(DocumentService::create(DocumentService::inputFromBooking((int) $b['id'], 'invoice'), t_user()));
    assert_money($b['vat_amount'], $doc['vat_amount']);
    assert_money($b['grand_total'], $doc['grand_total']);
    assert_money($b['discount'], $doc['discount']);
}

function test_db_doc_invoice_includes_extra_charges_quotation_does_not(): void
{
    $b = t_pay_booking();
    Database::run("UPDATE bookings SET extra_charge = 200, extra_breakdown = :j WHERE id = :id",
        ['j' => json_encode([['key' => 'late', 'label' => 'ค่าคืนช้า', 'amount' => 200.0]], JSON_UNESCAPED_UNICODE), 'id' => $b['id']]);
    $q = DocumentService::inputFromBooking((int) $b['id'], 'quotation');
    $i = DocumentService::inputFromBooking((int) $b['id'], 'invoice');
    assert_same(count($q['items']) + 1, count($i['items']));
    assert_same('ค่าคืนช้า', end($i['items'])['description']);
}

function test_db_doc_status_transitions(): void
{
    $u = t_user();
    $id = DocumentService::create(t_doc_input(), $u);
    DocumentService::setStatus($id, 'sent');
    assert_same('sent', DocumentService::get($id)['status']);
    DocumentService::setStatus($id, 'void');
    // ยกเลิกแล้วไม่กลับมาแก้/เปลี่ยนสถานะอีก (เลขที่เอกสารที่ออกไปแล้วต้องไม่หายจากบัญชี)
    foreach ([fn() => DocumentService::setStatus($id, 'sent'), fn() => DocumentService::update($id, t_doc_input())] as $f) {
        $thrown = false;
        try {
            $f();
        } catch (UserError $e) {
            $thrown = true;
        }
        assert_true($thrown);
    }
}

function test_db_doc_paid_document_cannot_be_edited(): void
{
    $id = DocumentService::create(t_doc_input(), t_user());
    DocumentService::setStatus($id, 'paid');
    $thrown = false;
    try {
        DocumentService::update($id, t_doc_input(['bill_name' => 'แก้ย้อนหลัง']));
    } catch (UserError $e) {
        $thrown = true;
    }
    assert_true($thrown, 'เอกสารที่จ่ายแล้วต้องแก้ไม่ได้');
}
