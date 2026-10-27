<?php
namespace App\Controllers\Admin;

use App\Models\Customer;
use App\Services\Auth;
use App\Services\BookingService;
use App\Services\CustomerDocService;
use App\Services\CustomerService;

/** ฐานลูกค้า — ค้นหา ประวัติการเช่า Blacklist เอกสารแนบ (เก็บในที่เก็บแบบปิด) */
final class CustomerController
{
    public function index(): void
    {
        $q = get_str('q');
        $only = get_str('only') === 'blacklist';
        view('admin/customers/index', [
            'title'  => 'ลูกค้า',
            'q'      => $q,
            'only'   => $only,
            'rows'   => Customer::search($q, $only, 200),
            'totals' => Customer::totals(),
        ], 'layouts/admin');
    }

    public function create(): void
    {
        $this->form(null, [], []);
    }

    public function store(): void
    {
        $in = request_strings($_POST);
        [$data, $errors] = CustomerService::validate($in);
        if ($errors === []) {
            try {
                $id = CustomerService::create($data, (int) Auth::user()['id']);
                flash('success', 'เพิ่มลูกค้าแล้ว — แนบเอกสารได้ที่หน้านี้');
                redirect('/admin/customers/' . $id . '/edit');
            } catch (\Throwable $e) {
                $errors['phone'] = BookingService::publicError($e, 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
            }
        }
        $this->form(null, $in, $errors);
    }

    public function edit(string $id): void
    {
        $this->form($this->findOrFail($id), [], []);
    }

    public function update(string $id): void
    {
        $customer = $this->findOrFail($id);
        $in = request_strings($_POST);
        [$data, $errors] = CustomerService::validate($in);
        if ($errors === []) {
            try {
                CustomerService::update((int) $customer['id'], $data, (int) Auth::user()['id']);
                flash('success', 'บันทึกข้อมูลลูกค้าแล้ว');
                redirect('/admin/customers/' . $customer['id'] . '/edit');
            } catch (\Throwable $e) {
                $errors['phone'] = BookingService::publicError($e, 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
            }
        }
        $this->form($customer, $in, $errors);
    }

    public function destroy(string $id): void
    {
        $customer = $this->findOrFail($id);
        try {
            CustomerService::softDelete((int) $customer['id'], (int) Auth::user()['id']);
            flash('success', 'ลบลูกค้า ' . $customer['full_name'] . ' แล้ว (ประวัติการจองเดิมยังอยู่)');
            redirect('/admin/customers');
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'ลบไม่สำเร็จ'));
            redirect('/admin/customers/' . $customer['id'] . '/edit');
        }
    }

    public function uploadDocument(string $id): void
    {
        $customer = $this->findOrFail($id);
        $files = uploaded_files('doc_file');
        try {
            if (count($files) !== 1) {
                throw new \App\Services\UserError('กรุณาเลือกไฟล์เอกสาร 1 ไฟล์');
            }
            CustomerDocService::add((int) $customer['id'], post('doc_kind'), $files[0], post('doc_note'), (int) Auth::user()['id']);
            flash('success', 'แนบเอกสารแล้ว (เก็บในที่เก็บแบบปิด)');
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'แนบเอกสารไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
        }
        redirect('/admin/customers/' . $customer['id'] . '/edit#docs');
    }

    /** เปิดดูเอกสาร — ต้องล็อกอิน และบันทึกประวัติการเปิด (ตรวจย้อนหลังได้) */
    public function viewDocument(string $id): void
    {
        $doc = ctype_digit($id) ? Customer::findDocument((int) $id) : null;
        if ($doc === null) {
            abort(404);
        }
        CustomerDocService::serve($doc, (int) Auth::user()['id'], client_ip());
    }

    public function deleteDocument(string $id): void
    {
        $doc = ctype_digit($id) ? Customer::findDocument((int) $id) : null;
        if ($doc === null) {
            abort(404);
        }
        try {
            CustomerDocService::delete((int) $doc['id'], (int) Auth::user()['id'], client_ip());
            flash('success', 'ลบเอกสารแล้ว');
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'ลบไม่สำเร็จ'));
        }
        redirect('/admin/customers/' . $doc['customer_id'] . '/edit#docs');
    }

    private function findOrFail(string $id): array
    {
        $c = ctype_digit($id) ? Customer::find((int) $id) : null;
        if ($c === null || $c['deleted_at'] !== null) {
            abort(404);
        }
        return $c;
    }

    private function form(?array $customer, array $in, array $errors): void
    {
        $id = $customer ? (int) $customer['id'] : 0;
        view('admin/customers/form', [
            'title'   => $id ? 'ลูกค้า ' . ($customer['code'] ?: $customer['full_name']) : 'เพิ่มลูกค้า',
            'c'       => $customer,
            'id'      => $id,
            'v'       => $in + ($customer ?: ['type' => 'individual']),
            'errors'  => $errors,
            'history' => $id ? Customer::history($id, 50) : [],
            'cdocs'   => $id ? Customer::documents($id) : [],
            'docKinds' => CustomerDocService::KINDS,
        ], 'layouts/admin');
    }
}
