<?php
namespace App\Controllers;

use App\Core\Session;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\TrackService;
use App\Services\UploadService;
use App\Services\UserError;

/** ลูกค้าตรวจสอบการจองด้วย เลขที่จอง + เบอร์โทร แล้วแจ้งโอนเงินได้ */
final class TrackController
{
    public function index(): void
    {
        if (Session::get('track_booking_id') !== null) {
            redirect('/track/view');
        }
        view('track/index', ['title' => 'ตรวจสอบการจอง', 'no' => '']);
    }

    public function lookup(): void
    {
        $ip = client_ip();
        $no = post('booking_no');
        if (TrackService::isBlocked($ip)) {
            http_response_code(429);
            flash('error', 'ค้นหาไม่พบหลายครั้งติดกัน กรุณาลองใหม่ภายหลัง หรือติดต่อร้านโดยตรง');
            view('track/index', ['title' => 'ตรวจสอบการจอง', 'no' => $no]);
            return;
        }

        $booking = TrackService::find($no, post('phone'), $ip);
        if ($booking === null) {
            // ข้อความเดียวกันทั้งกรณีไม่มีเลขที่จอง/เบอร์ไม่ตรง (ไม่บอกว่าเลขที่จองมีอยู่จริงหรือไม่)
            flash('error', 'ไม่พบการจอง กรุณาตรวจเลขที่จองและเบอร์โทรที่ใช้จอง');
            view('track/index', ['title' => 'ตรวจสอบการจอง', 'no' => $no]);
            return;
        }
        Session::set('track_booking_id', (int) $booking['id']);
        redirect('/track/view');
    }

    public function view(): void
    {
        $booking = $this->sessionBooking();
        $id = (int) $booking['id'];
        $due = (float) $booking['grand_total'] + (float) $booking['extra_charge'];
        $paid = Payment::netPaid($id);
        view('track/view', [
            'title'     => 'การจอง ' . $booking['booking_no'],
            'booking'   => $booking,
            'addons'    => Booking::addons($id),
            'payments'  => Payment::forBooking($id),
            'paid'      => $paid,
            'remaining' => max(0.0, round($due - $paid, 2)),
            'canPay'    => !in_array($booking['status'], ['cancelled', 'completed'], true),
            'deadline'  => \App\Services\BookingService::expiryDeadline($booking),
        ]);
    }

    public function slip(): void
    {
        $booking = $this->sessionBooking();
        $amount = PaymentService::parseAmount(post('amount'));
        if ($amount === null) {
            flash('error', 'กรุณาระบุจำนวนเงินที่โอนให้ถูกต้อง');
            redirect('/track/view');
        }
        try {
            PaymentService::submitSlip($booking, $_FILES['slip'] ?? ['error' => UPLOAD_ERR_NO_FILE], $amount, post('paid_at'), post('note'));
        } catch (\Throwable $e) {
            flash('error', \App\Services\BookingService::publicError($e, 'แจ้งโอนไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
            redirect('/track/view');
        }
        flash('success', 'ส่งหลักฐานการโอนแล้ว ร้านจะตรวจสอบและยืนยันให้โดยเร็ว');
        redirect('/track/view');
    }

    /** รูปสลิปของการจองนี้เท่านั้น (ตรวจจาก session) */
    public function slipFile(string $id): void
    {
        $booking = $this->sessionBooking();
        $payment = Payment::find((int) $id);
        if ($payment === null || (int) $payment['booking_id'] !== (int) $booking['id'] || empty($payment['slip_file'])) {
            abort(404);
        }
        UploadService::serve($payment['slip_file']);
    }

    public function leave(): void
    {
        Session::forget('track_booking_id');
        redirect('/track');
    }

    /** การจองที่ลูกค้ายืนยันตัวตนแล้วในเซสชันนี้ — ไม่มี = กลับไปหน้าค้นหา */
    private function sessionBooking(): array
    {
        $id = Session::get('track_booking_id');
        $booking = is_int($id) ? Booking::find($id) : null;
        if ($booking === null) {
            Session::forget('track_booking_id');
            redirect('/track');
        }
        return $booking;
    }
}
