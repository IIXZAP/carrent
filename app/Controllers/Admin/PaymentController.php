<?php
namespace App\Controllers\Admin;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\Auth;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\UploadService;

final class PaymentController
{
    public function index(): void
    {
        $status = in_array(get_str('status'), ['pending', 'approved', 'rejected', 'all'], true) ? get_str('status') : 'pending';
        view('admin/payments/index', [
            'title'    => 'ชำระเงิน',
            'payments' => Payment::listByStatus($status),
            'counts'   => Payment::countByStatus(),
            'status'   => $status,
        ], 'layouts/admin');
    }

    public function approve(string $id): void
    {
        $ok = PaymentService::approve((int) $id, (int) Auth::user()['id'], client_ip());
        flash($ok ? 'success' : 'error', $ok ? 'อนุมัติแล้ว' : 'รายการนี้ถูกตรวจสอบไปแล้ว');
        $this->back((int) $id);
    }

    public function reject(string $id): void
    {
        $ok = PaymentService::reject((int) $id, (int) Auth::user()['id'], post('reason'), client_ip());
        flash($ok ? 'success' : 'error', $ok ? 'ปฏิเสธรายการแล้ว' : 'รายการนี้ถูกตรวจสอบไปแล้ว');
        $this->back((int) $id);
    }

    public function slip(string $id): void
    {
        $payment = Payment::find((int) $id);
        if ($payment === null || empty($payment['slip_file'])) {
            abort(404);
        }
        UploadService::serve($payment['slip_file']);
    }

    /** พนักงานบันทึกรับ/คืนเงินเอง (อนุมัติทันที) */
    public function record(string $bookingId): void
    {
        $booking = Booking::find((int) $bookingId);
        if ($booking === null) {
            abort(404);
        }
        $amount = PaymentService::parseAmount(post('amount'));
        if ($amount === null) {
            flash('error', 'จำนวนเงินไม่ถูกต้อง');
            redirect('/admin/bookings/' . $booking['id']);
        }
        try {
            PaymentService::record((int) $booking['id'], (int) Auth::user()['id'], post('kind'), post('method'), $amount, post('note'), client_ip());
            flash('success', 'บันทึกรายการเงินแล้ว');
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
        }
        redirect('/admin/bookings/' . $booking['id']);
    }

    /** กลับหน้าเดิม — whitelist เฉพาะ 2 ปลายทาง (ไม่รับ URL จากผู้ใช้ กัน open redirect) */
    private function back(int $paymentId): void
    {
        $payment = Payment::find($paymentId);
        if (post('from') === 'booking' && $payment !== null) {
            redirect('/admin/bookings/' . $payment['booking_id']);
        }
        redirect('/admin/payments');
    }
}
