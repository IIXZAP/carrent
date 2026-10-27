<?php
namespace App\Controllers\Admin;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Document;
use App\Services\Auth;
use App\Services\BookingService;
use App\Services\DocumentService;

/** เอกสาร: ใบเสนอราคา · ใบแจ้งหนี้ · ใบเสร็จ · สัญญา (สร้าง/แก้/พิมพ์/เปลี่ยนสถานะ) */
final class DocumentController
{
    public function index(): void
    {
        $type = get_str('type');
        if (!isset(DocumentService::TYPES[$type])) {
            $type = '';
        }
        view('admin/documents/index', [
            'title' => 'เอกสาร',
            'docs'  => Document::list($type),
            'type'  => $type,
        ], 'layouts/admin');
    }

    /** ฟอร์มสร้างใหม่ — ?booking=ID&type=quotation เติมข้อมูลจากการจอง */
    public function create(): void
    {
        $type = get_str('type', 'quotation');
        $type = isset(DocumentService::TYPES[$type]) ? $type : 'quotation';
        $form = [
            'doc_type' => $type, 'issue_date' => date('Y-m-d'), 'bill_name' => '', 'items' => [['description' => '', 'qty' => '1', 'unit' => '', 'price' => '']],
            'vat_rate' => DocumentService::defaultVatRate(), 'wht_rate' => 0, 'discount' => 0,
        ];
        $bookingId = (int) get_str('booking');
        if ($bookingId > 0) {
            try {
                $form = DocumentService::inputFromBooking($bookingId, $type) + $form;
            } catch (\Throwable $e) {
                flash('error', BookingService::publicError($e));
                redirect('/admin/documents');
            }
        }
        view('admin/documents/form', ['title' => 'สร้างเอกสาร', 'doc' => null, 'form' => $form], 'layouts/admin');
    }

    public function store(): void
    {
        $in = $this->input();
        try {
            $id = DocumentService::create($in, (int) Auth::user()['id']);
            ActivityLog::log('document.create', 'documents', $id, $in['doc_type'], (int) Auth::user()['id'], client_ip());
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'บันทึกเอกสารไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
            view('admin/documents/form', ['title' => 'สร้างเอกสาร', 'doc' => null, 'form' => $in], 'layouts/admin');
            return;
        }
        flash('success', 'สร้างเอกสารแล้ว');
        redirect('/admin/documents/' . $id);
    }

    public function show(string $id): void
    {
        $doc = $this->findOrFail($id);
        view('admin/documents/show', ['title' => $doc['doc_no'], 'doc' => $doc], 'layouts/admin');
    }

    public function print(string $id): void
    {
        $doc = $this->findOrFail($id);
        view('admin/documents/print', ['title' => $doc['doc_no'], 'doc' => $doc, 'shop' => \App\Models\Setting::all()], 'layouts/print');
    }

    public function edit(string $id): void
    {
        $doc = $this->findOrFail($id);
        if (in_array($doc['status'], ['paid', 'void'], true)) {
            flash('error', 'เอกสารที่ชำระแล้วหรือยกเลิกแล้วแก้ไขไม่ได้');
            redirect('/admin/documents/' . $doc['id']);
        }
        view('admin/documents/form', ['title' => 'แก้ไข ' . $doc['doc_no'], 'doc' => $doc, 'form' => $doc], 'layouts/admin');
    }

    public function update(string $id): void
    {
        $doc = $this->findOrFail($id);
        $in = $this->input() + ['doc_type' => $doc['doc_type']];
        try {
            DocumentService::update((int) $doc['id'], $in);
            ActivityLog::log('document.update', 'documents', (int) $doc['id'], $doc['doc_no'], (int) Auth::user()['id'], client_ip());
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'บันทึกเอกสารไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
            view('admin/documents/form', ['title' => 'แก้ไข ' . $doc['doc_no'], 'doc' => $doc, 'form' => $in], 'layouts/admin');
            return;
        }
        flash('success', 'บันทึกแล้ว');
        redirect('/admin/documents/' . $doc['id']);
    }

    public function status(string $id): void
    {
        $doc = $this->findOrFail($id);
        try {
            DocumentService::setStatus((int) $doc['id'], post('status'));
            ActivityLog::log('document.status', 'documents', (int) $doc['id'], $doc['doc_no'] . ' → ' . post('status'), (int) Auth::user()['id'], client_ip());
            flash('success', 'เปลี่ยนสถานะแล้ว');
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'เปลี่ยนสถานะไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
        }
        redirect('/admin/documents/' . $doc['id']);
    }

    /** ค่าจากฟอร์ม: items[i][description|qty|unit|price] เป็นอาเรย์ซ้อนชั้นเดียว */
    private function input(): array
    {
        $in = request_strings($_POST, ['items']);
        $items = [];
        foreach ((array) ($in['items'] ?? []) as $row) {
            if (is_array($row)) {
                $items[] = array_map(static fn($v) => is_string($v) ? $v : '', $row);
            }
        }
        $in['items'] = $items;
        return $in;
    }

    private function findOrFail(string $id): array
    {
        $doc = ctype_digit($id) ? DocumentService::get((int) $id) : null;
        if ($doc === null) {
            abort(404);
        }
        return $doc;
    }
}
