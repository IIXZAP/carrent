<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Services\PrivateFileService;
use App\Services\UploadService;

/** ฐานลูกค้า — ค้นหา ประวัติการเช่า blacklist เอกสารแนบ (เก็บในที่เก็บแบบปิด) */
class CustomerController extends AdminController
{
    public function index(): Response
    {
        $q    = $this->request->str('q');
        $only = $this->request->str('only') === 'blacklist';
        return $this->view('admin/customers/index', [
            'title'  => 'ลูกค้า',
            'q'      => $q,
            'only'   => $only,
            'rows'   => Customer::search($q, $only, 200),
            'totals' => Customer::totals(),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(int $id): Response
    {
        return $this->form($this->find($id));
    }

    public function store(): Response
    {
        return $this->save(null);
    }

    public function update(int $id): Response
    {
        return $this->save($this->find($id));
    }

    /**
     * เปิดดูเอกสารลูกค้าในที่เก็บแบบปิด — ตรวจว่าเข้าระบบแล้ว (middleware) และบันทึกทุกครั้งว่าใครเปิดเอกสารของลูกค้าคนไหน
     * (ตรวจย้อนหลังได้ตามหลัก PDPA)
     */
    public function file(int $id): Response
    {
        $d    = Customer::findDocument($id);
        $full = $d ? PrivateFileService::path($d['file_path']) : null;
        if (!$full) {
            return Response::text('ไม่พบไฟล์เอกสาร', 404);
        }
        ActivityLog::log('document_viewed', 'customer_documents', $id, ['customer_id' => (int) $d['customer_id'], 'kind' => $d['doc_kind']]);
        return PrivateFileService::response($full, 'customer-' . (int) $d['customer_id'] . '-' . $d['doc_kind']);
    }

    /** เอกสารที่อัปโหลดก่อนเวอร์ชัน 5 (ยังอยู่ใน uploads/docs) */
    public function legacyFile(int $id, string $kind): Response
    {
        $col = ['id_card' => 'id_card_file', 'license' => 'license_file'][$kind] ?? null;
        $rel = $col ? Customer::legacyFile($id, $col) : null;
        // เอกสารลูกค้าต้องอยู่ใต้ uploads/docs เท่านั้น — กันใช้หน้านี้เปิดไฟล์อื่นในโฟลเดอร์อัปโหลด
        $full = ($rel && str_starts_with(str_replace('\\', '/', $rel), 'docs/')) ? PrivateFileService::legacyPath($rel) : null;
        if (!$full) {
            return Response::text('ไม่พบไฟล์เอกสาร', 404);
        }
        ActivityLog::log('document_viewed', 'customers', $id, ['legacy' => $col]);
        return PrivateFileService::response($full, 'customer-' . $id . '-' . $kind);
    }

    // ------------------------------------------------------------

    private function find(int $id): array
    {
        $c = Customer::find($id);
        if (!$c) {
            $this->halt($this->done('admin/customers', 'danger', 'ไม่พบลูกค้า'));
        }
        return $c;
    }

    private function form(?array $c, array $old = []): Response
    {
        $id = $c ? (int) $c['id'] : 0;
        PrivateFileService::protectLegacyDocs();
        return $this->view('admin/customers/form', [
            'title'   => $id ? 'ลูกค้า ' . ($c['code'] ?: $c['full_name']) : 'เพิ่มลูกค้า',
            'c'       => $c,
            'id'      => $id,
            'v'       => $old + ($c ?: []),
            'history' => $id ? Customer::history($id, 50) : [],
            'cdocs'   => $id ? Customer::documents($id) : [],
            'spend'   => $id ? Customer::spend($id) : 0.0,
            'docKinds' => PrivateFileService::customerDocKinds(),
            'limit'   => UploadService::limitBytes(),
        ]);
    }

    private function save(?array $c): Response
    {
        $r  = $this->request;
        $id = $c ? (int) $c['id'] : 0;
        try {
            $data = [
                'type'           => $r->str('type') === 'company' ? 'company' : 'individual',
                'full_name'      => $r->str('full_name'),
                'tax_id'         => $r->str('tax_id') ?: null,
                'phone'          => $r->str('phone'),
                'email'          => $r->str('email') ?: null,
                'line_id'        => $r->str('line_id') ?: null,
                'address'        => $r->str('address') ?: null,
                'license_no'     => $r->str('license_no') ?: null,
                'license_expire' => $r->str('license_expire') ?: null,
                'is_blacklist'   => $r->has('is_blacklist') ? 1 : 0,
                'blacklist_note' => $r->str('blacklist_note') ?: null,
                'note'           => $r->str('note') ?: null,
            ];
            if ($data['full_name'] === '' || $data['phone'] === '') {
                throw new \RuntimeException('กรุณากรอกชื่อและเบอร์โทร');
            }
            // เอกสารแนบเก็บในที่เก็บแบบปิด (storage/private) — เปิดผ่านลิงก์ตรงไม่ได้ ต้องเปิดผ่านหลังบ้านเท่านั้น
            // รับไฟล์ก่อนบันทึกข้อมูล: ถ้าไฟล์ไม่ผ่าน (ชนิดผิด/ใหญ่เกิน) จะได้ไม่บันทึกครึ่งๆ กลางๆ
            $doc     = PrivateFileService::storeUpload($r->file('doc_file'), 'docs', 8);
            $kinds   = PrivateFileService::customerDocKinds();
            $docKind = isset($kinds[$r->str('doc_kind')]) ? $r->str('doc_kind') : 'id_card';

            if ($id) {
                Customer::updateById($id, $data);
                ActivityLog::log('customer_updated', 'customers', $id);
                $this->flash('ok', 'บันทึกข้อมูลลูกค้าแล้ว');
            } else {
                $data['code'] = Customer::nextCode();
                $id = Customer::insert($data);
                ActivityLog::log('customer_created', 'customers', $id);
                $this->flash('ok', 'เพิ่มลูกค้า ' . $data['code'] . ' แล้ว');
            }
            if ($doc) {
                $did = Customer::addDocument([
                    'customer_id' => $id,
                    'doc_kind'    => $docKind,
                    'file_path'   => $doc['path'],
                    'mime'        => $doc['mime'],
                    'file_size'   => $doc['size'],
                    'note'        => $r->str('doc_note', 200) ?: null,
                    'uploaded_by' => $this->userId(),
                ]);
                ActivityLog::log('document_uploaded', 'customer_documents', $did, ['customer_id' => $id, 'kind' => $docKind]);
                $this->flash('ok', 'แนบ' . $kinds[$docKind] . 'แล้ว (เก็บในที่เก็บแบบปิด)');
            }
            return $this->redirect('admin/customers/' . $id . '/edit');
        } catch (\Throwable $ex) {
            $this->flash('danger', $ex instanceof \RuntimeException && !($ex instanceof \PDOException)
                ? $ex->getMessage() : 'บันทึกไม่สำเร็จ กรุณาตรวจข้อมูลแล้วลองใหม่');
            return $this->form($c, $r->all());
        }
    }
}
