<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\Payment;
use App\Services\PaymentService;

/** ตรวจสลิป / อนุมัติการชำระเงิน (อนุมัติ–ปฏิเสธเป็น POST เท่านั้น กัน prefetch และสแกนเนอร์ยิงซ้ำ) */
class PaymentController extends AdminController
{
    public function index(): Response
    {
        $filter = $this->request->str('status', 0, 'pending');
        $filter = in_array($filter, ['pending', 'approved', 'rejected', 'all'], true) ? $filter : 'pending';

        return $this->view('admin/payments', [
            'title'      => 'ชำระเงิน',
            'filter'     => $filter,
            'rows'       => Payment::listByStatus($filter, 200),
            'cntPending' => Payment::pendingCount(),
            'sumPending' => Payment::pendingSum(),
            // รายได้วันนี้ใช้นิยามเดียวกับรายงาน: ไม่นับเงินประกัน และหักรายการคืนเงิน
            'sumToday'   => Payment::revenueCollected(date('Y-m-d'), date('Y-m-d')),
        ]);
    }

    public function approve(): Response
    {
        if (PaymentService::approve($this->request->int('payment_id'), (int) $this->userId())) {
            $this->flash('ok', 'อนุมัติการชำระเงินแล้ว');
        } else {
            $this->flash('warn', 'รายการนี้ถูกอนุมัติหรือปฏิเสธไปแล้ว (หรือไม่พบรายการ) กรุณาตรวจสถานะล่าสุด');
        }
        return $this->redirect($this->backPath());
    }

    public function reject(): Response
    {
        $reason = $this->request->str('reason', 250);
        if (PaymentService::reject($this->request->int('payment_id'), (int) $this->userId(), $reason)) {
            $this->flash('warn', 'ปฏิเสธสลิปแล้ว');
        } else {
            $this->flash('warn', 'รายการนี้ถูกอนุมัติหรือปฏิเสธไปแล้ว (หรือไม่พบรายการ) กรุณาตรวจสถานะล่าสุด');
        }
        return $this->redirect($this->backPath());
    }

    /** กลับหน้าที่ส่งมา (เฉพาะ path ภายใน admin เท่านั้น กัน open redirect) */
    private function backPath(): string
    {
        $back = $this->request->str('back');
        return preg_match('#^admin/[A-Za-z0-9/_-]*$#', $back) ? $back : 'admin/payments';
    }
}
