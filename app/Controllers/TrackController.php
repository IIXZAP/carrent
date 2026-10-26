<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\UploadService;

/** ตรวจสอบสถานะการจอง + อัปโหลดสลิป (ยืนยันตัวตนด้วย เลขที่จอง + เบอร์โทร) */
class TrackController extends Controller
{
    /** หาการจองจากเลขที่ + เบอร์โทร คืน [การจอง|null, ข้อความผิดพลาด|null] */
    private function lookup(string $no, string $phone): array
    {
        if ($no === '' || $phone === '') {
            return [null, null];
        }
        // กันไล่เดา: ค้นหาไม่พบเกิน 20 ครั้งใน 15 นาทีจาก IP เดียวกัน ให้พักก่อน
        if (ActivityLog::trackFailsByIp($this->request->ip()) >= 20) {
            return [null, 'ค้นหาผิดหลายครั้งเกินไป กรุณารอ 15 นาที หรือติดต่อร้าน ' . setting('phone')];
        }
        $b = Booking::findByNoWithCar($no);
        // เทียบเบอร์แบบตัวเลขล้วนทั้งสองฝั่ง (รองรับ +66 / ขีด / เว้นวรรค) และต้องมีอย่างน้อย 9 หลัก
        if ($b && !phone_match($b['contact_phone'], $phone)) {
            $b = null;
        }
        if (!$b) {
            ActivityLog::log('track_fail', null, null, $no);
        }
        return [$b, $b ? null : 'ไม่พบการจองที่ตรงกับเลขที่และเบอร์โทรศัพท์นี้'];
    }

    public function index(): Response
    {
        $no    = $this->request->str('no');
        $phone = $this->request->str('phone');
        [$b, $err] = $this->lookup($no, $phone);

        $payments = $b ? Payment::forBooking((int) $b['id']) : [];
        return $this->view('public/track', [
            'no'       => $no,
            'phone'    => $phone,
            'b'        => $b,
            'err'      => $err,
            'payments' => $payments,
            'extras'   => $b ? Booking::extraLines($b, true) : [],
            'limit'    => UploadService::limitBytes(),
        ]);
    }

    /** แจ้งโอนเงิน (อัปโหลดสลิป) */
    public function slip(): Response
    {
        $r     = $this->request;
        $no    = $r->str('no');
        $phone = $r->str('phone');
        $back  = 'track?no=' . urlencode($no) . '&phone=' . urlencode($phone);
        [$b] = $this->lookup($no, $phone);
        if (!$b) {
            return $this->redirect($back);
        }
        try {
            // ใช้ฟังก์ชันเดียวกับหน้า LINE — ตรวจสถานะ จำกัดยอด ตรวจไฟล์ และแจ้งร้าน ในที่เดียว
            if (Payment::pendingCountForBooking((int) $b['id']) >= 3) {
                throw new \RuntimeException('มีสลิปรอตรวจสอบอยู่แล้ว 3 รายการ กรุณารอเจ้าหน้าที่ตรวจก่อนแจ้งเพิ่ม');
            }
            $paidAt = $r->str('paid_at');
            $paidTs = $paidAt !== '' ? strtotime($paidAt) : false;
            PaymentService::submitSlip(
                $b,
                $r->file('slip'),
                round($r->float('amount'), 2),
                $paidTs !== false ? date('Y-m-d H:i:s', $paidTs) : null,
                $r->str('note', 250)
            );
            $this->flash('ok', 'รับแจ้งการโอนเรียบร้อย เจ้าหน้าที่จะตรวจสอบและยืนยันให้เร็วที่สุด');
        } catch (\Throwable $ex) {
            $this->flash('danger', BookingService::publicError($ex, 'แจ้งโอนไม่สำเร็จ กรุณาลองใหม่อีกครั้ง หรือติดต่อร้าน ' . setting('phone')));
        }
        return $this->redirect($back);
    }
}
