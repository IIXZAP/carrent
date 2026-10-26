<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Document;

/** เอกสาร — ใบเสนอราคา / ใบแจ้งหนี้ / ใบเสร็จ (ดึงรายการจากการจองอัตโนมัติ แก้ไขเพิ่มได้ พิมพ์เป็น PDF จากเบราว์เซอร์) */
class DocumentController extends AdminController
{
    private const TYPE_LABEL = ['quotation' => 'ใบเสนอราคา', 'invoice' => 'ใบแจ้งหนี้', 'receipt' => 'ใบเสร็จรับเงิน', 'contract' => 'สัญญาเช่า'];

    public function index(): Response
    {
        $type = $this->request->str('type');
        return $this->view('admin/documents/index', [
            'title'       => 'เอกสาร',
            'type'        => in_array($type, Document::TYPES, true) ? $type : '',
            'rows'        => Document::listByType($type, 200),
            'typeLabel'   => self::TYPE_LABEL,
            'statusLabel' => [
                'draft' => ['ร่าง', 'muted'], 'sent' => ['ส่งแล้ว', 'info'], 'accepted' => ['ลูกค้าตอบรับ', 'primary'],
                'paid' => ['ชำระแล้ว', 'ok'], 'void' => ['ยกเลิก', 'danger'],
            ],
            'sumQuote'    => Document::pendingQuotationSum(),
        ]);
    }

    public function status(): Response
    {
        $st = $this->request->str('status');
        $id = $this->request->int('id');
        $st = in_array($st, Document::STATUSES, true) ? $st : 'draft';
        Document::setStatus($id, $st);
        ActivityLog::log('document_status', 'documents', $id, $st);
        return $this->done('admin/documents', 'ok', 'อัปเดตสถานะเอกสารแล้ว');
    }

    public function create(): Response
    {
        $type = $this->request->str('type', 0, 'quotation');
        return $this->form(null, in_array($type, Document::TYPES, true) ? $type : 'quotation', $this->request->int('booking_id'));
    }

    public function edit(int $id): Response
    {
        $doc = $this->find($id);
        return $this->form($doc, $doc['doc_type'], (int) $doc['booking_id']);
    }

    public function store(): Response
    {
        $type = $this->request->str('type', 0, 'quotation');
        return $this->save(null, in_array($type, Document::TYPES, true) ? $type : 'quotation', $this->request->int('booking_id'));
    }

    public function update(int $id): Response
    {
        $doc = $this->find($id);
        return $this->save($doc, $doc['doc_type'], (int) $doc['booking_id']);
    }

    public function print(int $id): Response
    {
        $doc = $this->find($id);
        return $this->view('admin/documents/print', [
            'title'     => (self::TYPE_LABEL[$doc['doc_type']] ?? $doc['doc_type']) . ' ' . $doc['doc_no'],
            'doc'       => $doc,
            'id'        => $id,
            'booking'   => $doc['booking_id'] ? Booking::findForDocument((int) $doc['booking_id']) : null,
            'items'     => json_decode((string) $doc['items'], true) ?: [],
            'typeLabel' => self::TYPE_LABEL,
            'typeEn'    => ['quotation' => 'QUOTATION', 'invoice' => 'INVOICE', 'receipt' => 'RECEIPT', 'contract' => 'CONTRACT'],
        ]);
    }

    // ------------------------------------------------------------

    private function find(int $id): array
    {
        $doc = Document::find($id);
        if (!$doc) {
            $this->halt($this->done('admin/documents', 'danger', 'ไม่พบเอกสาร'));
        }
        return $doc;
    }

    /** @param array<string,mixed> $old ค่าที่เพิ่งกรอก (เมื่อบันทึกไม่ผ่าน) */
    private function form(?array $doc, string $type, int $bookingId, array $old = []): Response
    {
        $booking  = $bookingId ? Booking::findForDocument($bookingId) : null;
        $customer = null;
        if ($doc && $doc['customer_id']) {
            $customer = Customer::find((int) $doc['customer_id']);
        } elseif ($booking && $booking['customer_id']) {
            $customer = Customer::find((int) $booking['customer_id']);
        }

        $items = $doc ? (json_decode((string) $doc['items'], true) ?: []) : [];
        if (!$items && $booking) {
            $items = $this->itemsFromBooking($booking);
        }
        if (!$items) {
            $items = [['desc' => '', 'qty' => 1, 'unit' => 'วัน', 'price' => 0, 'amount' => 0]];
        }

        $v = $doc ?: [
            'issue_date'   => date('Y-m-d'),
            'due_date'     => date('Y-m-d', strtotime('+7 days')),
            'bill_name'    => $customer['full_name'] ?? ($booking['contact_name'] ?? ''),
            'bill_tax_id'  => $customer['tax_id'] ?? '',
            'bill_address' => $customer['address'] ?? '',
            'discount'     => (float) ($booking['discount'] ?? 0),
            'vat_rate'     => (float) ($booking['vat_rate'] ?? (setting('vat_enabled') === '1' ? (float) setting('vat_rate', '7') : 0)),
            'wht_rate'     => 0,
            'note'         => '',
        ];
        foreach (['issue_date', 'due_date', 'bill_name', 'bill_tax_id', 'bill_address', 'discount', 'vat_rate', 'wht_rate', 'note'] as $k) {
            if (array_key_exists($k, $old)) {
                $v[$k] = $old[$k];
            }
        }

        return $this->view('admin/documents/form', [
            'title'    => ($doc ? 'แก้ไข' : 'ออก') . self::TYPE_LABEL[$type],
            'doc'      => $doc,
            'id'       => $doc ? (int) $doc['id'] : 0,
            'type'     => $type,
            'typeName' => self::TYPE_LABEL[$type],
            'booking'  => $booking,
            'customer' => $customer,
            'items'    => $items,
            'v'        => $v,
        ]);
    }

    /** สร้างรายการเอกสารจากการจองอัตโนมัติ */
    private function itemsFromBooking(array $booking): array
    {
        $items   = [];
        $bd      = json_decode((string) $booking['rate_breakdown'], true) ?: [];
        $carName = $booking['brand'] . ' ' . $booking['model'] . ' (' . $booking['plate'] . ')';
        foreach (($bd['rental'] ?? []) as $l) {
            $items[] = ['desc' => 'ค่าเช่ารถ ' . $carName . ' — ' . $l['label'], 'qty' => (float) $l['qty'],
                        'unit' => 'หน่วย', 'price' => (float) $l['unit'], 'amount' => (float) $l['amount']];
        }
        foreach (($bd['addons'] ?? []) as $l) {
            $items[] = ['desc' => $l['label'], 'qty' => (float) $l['qty'], 'unit' => 'หน่วย',
                        'price' => (float) $l['unit'], 'amount' => (float) $l['amount']];
        }
        // ค่าใช้จ่ายเพิ่มตอนคืนรถ — แยกบรรทัดตามรายการที่เก็บจริง (รายการที่ยกเว้นไม่ขึ้นในเอกสาร)
        // ยอดค่าใช้จ่ายเพิ่มเป็นยอดที่เก็บจากลูกค้าจริง (รวมภาษีแล้ว) — การจองที่คิด VAT ต้องถอด VAT ออกก่อนลงเป็นรายการ
        // ไม่งั้นเอกสารจะบวก VAT ซ้ำ ทำให้ยอดในใบเสร็จมากกว่ายอดที่เก็บจริง
        $xRate = (float) ($booking['vat_rate'] ?? 0);
        foreach (Booking::extraLines($booking, true) as $l) {
            $net = $xRate > 0 ? round($l['amount'] / (1 + $xRate / 100), 2) : $l['amount'];
            $items[] = ['desc' => trim($l['label'] . ($l['detail'] !== '' ? ' (' . $l['detail'] . ')' : '')), 'qty' => 1,
                        'unit' => 'รายการ', 'price' => $net, 'amount' => $net];
        }
        return $items;
    }

    private function save(?array $doc, string $type, int $bookingId): Response
    {
        $r  = $this->request;
        $id = $doc ? (int) $doc['id'] : 0;
        try {
            $items = [];
            $descs = (array) ($r->post('desc') ?? []);
            $qtys  = (array) ($r->post('qty') ?? []);
            $prices = (array) ($r->post('price') ?? []);
            $units = (array) ($r->post('unit') ?? []);
            foreach ($descs as $i => $d) {
                $d = trim((string) $d);
                if ($d === '') {
                    continue;
                }
                $qty   = (float) ($qtys[$i] ?? 1);
                $price = (float) ($prices[$i] ?? 0);
                $items[] = ['desc' => $d, 'qty' => $qty, 'unit' => trim((string) ($units[$i] ?? 'รายการ')),
                            'price' => $price, 'amount' => round($qty * $price, 2)];
            }
            if (!$items) {
                throw new \RuntimeException('กรุณาใส่รายการอย่างน้อย 1 บรรทัด');
            }

            $subtotal = array_sum(array_column($items, 'amount'));
            $discount = $r->float('discount');
            $net      = max(0, $subtotal - $discount);
            $vatRate  = $r->float('vat_rate');
            $vatAmt   = round($net * $vatRate / 100, 2);
            $whtRate  = $r->float('wht_rate');
            $whtAmt   = round($net * $whtRate / 100, 2);
            $grand    = round($net + $vatAmt - $whtAmt, 2);

            $data = [
                'doc_type'     => $type,
                'booking_id'   => $bookingId ?: null,
                'customer_id'  => $r->int('customer_id') ?: null,
                'issue_date'   => $r->str('issue_date') ?: date('Y-m-d'),
                'due_date'     => $r->str('due_date') ?: null,
                'bill_name'    => $r->str('bill_name', 200),
                'bill_tax_id'  => $r->str('bill_tax_id', 30) ?: null,
                'bill_address' => $r->str('bill_address', 500) ?: null,
                'items'        => json_encode($items, JSON_UNESCAPED_UNICODE),
                'subtotal'     => $subtotal,
                'discount'     => $discount,
                'vat_rate'     => $vatRate,
                'vat_amount'   => $vatAmt,
                'wht_rate'     => $whtRate,
                'wht_amount'   => $whtAmt,
                'grand_total'  => $grand,
                'note'         => $r->str('note', 1000) ?: null,
            ];

            if ($id) {
                Document::updateById($id, $data);
                ActivityLog::log('document_updated', 'documents', $id);
                $this->flash('ok', 'บันทึกเอกสารแล้ว');
            } else {
                $data['doc_no']     = Document::nextNumber($type);
                $data['status']     = 'draft';
                $data['created_by'] = $this->userId();
                $id = Document::insert($data);
                ActivityLog::log('document_created', 'documents', $id, $data['doc_no']);
                $this->flash('ok', 'สร้างเอกสาร ' . $data['doc_no'] . ' แล้ว');
            }
            return $this->redirect('admin/documents/' . $id . '/print');
        } catch (\Throwable $ex) {
            $this->flash('danger', $ex instanceof \RuntimeException && !($ex instanceof \PDOException)
                ? $ex->getMessage() : 'บันทึกไม่สำเร็จ กรุณาตรวจข้อมูลแล้วลองใหม่');
            return $this->form($doc, $type, $bookingId, $r->all());
        }
    }
}
