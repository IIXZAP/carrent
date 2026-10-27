<?php
namespace App\Controllers\Admin;

use App\Models\Booking;
use App\Models\Inspection;
use App\Models\Payment;
use App\Services\Auth;
use App\Services\BookingFlowService;
use App\Services\BookingService;
use App\Services\InspectionService;
use App\Services\LateReturnService;
use App\Services\SecurityDepositService;
use App\Services\UploadService;

/** ขั้นตอนหลังบ้านของการจอง: ยืนยัน → ส่งมอบ → รับคืน → ปิดงาน → ปิดเงินประกัน + ตรวจสภาพรถ */
final class FlowController
{
    // ------------------------------------------------------------
    // ปุ่มสั้นๆ
    // ------------------------------------------------------------

    public function confirm(string $id): void
    {
        $this->act($id, 'ยืนยันการจองแล้ว', static function (array $b): void {
            BookingFlowService::confirm((int) $b['id'], (int) Auth::user()['id'], client_ip());
        });
    }

    public function pickup(string $id): void
    {
        $mileageText = post('mileage_out');
        $mileage = $mileageText === '' ? null : (ctype_digit($mileageText) ? (int) $mileageText : -1);
        $fuelText = post('fuel_out', '8');
        $fuel = ctype_digit($fuelText) ? (int) $fuelText : -1;
        $this->act($id, 'บันทึกการส่งมอบรถแล้ว — ผลตรวจรอบส่งมอบถูกล็อก', static function (array $b) use ($mileage, $fuel): void {
            BookingFlowService::pickup((int) $b['id'], $mileage, $fuel, (int) Auth::user()['id'], client_ip());
        });
    }

    public function complete(string $id): void
    {
        $this->act($id, 'ปิดงานเรียบร้อย', static function (array $b): void {
            BookingFlowService::complete((int) $b['id'], (int) Auth::user()['id'], client_ip());
        });
    }

    // ------------------------------------------------------------
    // รับคืนรถ (2 ขั้น: กรอกค่า → ดูค่าใช้จ่ายที่ระบบแนะนำ → ยืนยัน)
    // ------------------------------------------------------------

    public function returnForm(string $id): void
    {
        $booking = $this->findOrFail($id);
        if ($booking['status'] !== 'picked_up') {
            flash('error', 'รับคืนรถได้เฉพาะการจองที่ส่งมอบรถแล้ว');
            redirect('/admin/bookings/' . $booking['id']);
        }

        $in = request_strings($_GET);
        $preview = null;
        $error = null;
        if (isset($_GET['calc'])) {
            try {
                $preview = BookingFlowService::previewReturn($booking, $in);
            } catch (\Throwable $e) {
                $error = BookingService::publicError($e, 'คำนวณไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
            }
        }
        view('admin/flow/return', [
            'title'   => 'รับคืนรถ ' . $booking['booking_no'],
            'booking' => $booking,
            'out'     => Inspection::get((int) $booking['id'], 'out'),
            'in'      => $in,
            'preview' => $preview,
            'error'   => $error,
            'policy'  => LateReturnService::policyText(),
        ], 'layouts/admin');
    }

    public function returnStore(string $id): void
    {
        $booking = $this->findOrFail($id);
        $in = request_strings($_POST, ['chg']);
        try {
            $result = BookingFlowService::returnCar((int) $booking['id'], $in, (int) Auth::user()['id'], client_ip());
            flash('success', 'บันทึกการรับคืนรถแล้ว' . ($result['extra'] > 0 ? ' — ค่าใช้จ่ายเพิ่ม ฿' . money($result['extra']) : ''));
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'บันทึกการรับคืนไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
            redirect('/admin/bookings/' . $booking['id'] . '/return?' . http_build_query(['calc' => 1] + array_diff_key($in, ['chg' => 1])));
        }
        redirect('/admin/bookings/' . $booking['id']);
    }

    // ------------------------------------------------------------
    // ปิดเงินประกัน
    // ------------------------------------------------------------

    public function securityForm(string $id): void
    {
        $booking = $this->findOrFail($id);
        $held = Payment::securityHeld((int) $booking['id']);
        view('admin/flow/security', [
            'title'   => 'ปิดเงินประกัน ' . $booking['booking_no'],
            'booking' => $booking,
            'held'    => $held,
            'owed'    => SecurityDepositService::amountOwed($booking, Payment::netPaid((int) $booking['id'])),
            'canSettle' => $held > 0 && in_array($booking['status'], ['returned', 'completed', 'cancelled'], true),
        ], 'layouts/admin');
    }

    public function securityStore(string $id): void
    {
        $booking = $this->findOrFail($id);
        try {
            $r = SecurityDepositService::settle((int) $booking['id'], request_strings($_POST), (int) Auth::user()['id'], client_ip());
            flash('success', 'ปิดเงินประกันแล้ว — หักชำระ ฿' . money($r['deduct']) . ' · คืนลูกค้า ฿' . money($r['refund']));
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'ปิดเงินประกันไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
            redirect('/admin/bookings/' . $booking['id'] . '/security');
        }
        redirect('/admin/bookings/' . $booking['id']);
    }

    // ------------------------------------------------------------
    // ตรวจสภาพรถ (รูปถ่าย)
    // ------------------------------------------------------------

    public function inspection(string $id, string $kind): void
    {
        $booking = $this->findOrFail($id);
        $kind = $this->kindOrFail($kind);
        $ins = Inspection::get((int) $booking['id'], $kind);
        view('admin/flow/inspection', [
            'title'     => 'ตรวจสภาพรถ ' . $booking['booking_no'],
            'booking'   => $booking,
            'kind'      => $kind,
            'ins'       => $ins,
            'positions' => InspectionService::positions(),
            'progress'  => InspectionService::progress($ins),
            'block'     => InspectionService::phaseBlock($booking, $kind),
        ], 'layouts/admin');
    }

    public function uploadPhoto(string $id, string $kind): void
    {
        $booking = $this->findOrFail($id);
        $kind = $this->kindOrFail($kind);
        try {
            InspectionService::savePhoto((int) $booking['id'], $kind, post('position'), $_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], post('caption'), (int) Auth::user()['id']);
            flash('success', 'บันทึกรูปแล้ว');
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'บันทึกรูปไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
        }
        redirect('/admin/bookings/' . $booking['id'] . '/inspection/' . $kind);
    }

    public function photo(string $id): void
    {
        $photo = Inspection::photo((int) $id);
        if ($photo === null) {
            abort(404);
        }
        UploadService::serve($photo['file_path']);
    }

    // ------------------------------------------------------------

    /** ทำขั้นตอนแล้วกลับหน้ารายละเอียดการจอง พร้อมข้อความผลลัพธ์ */
    private function act(string $id, string $successMessage, callable $action): void
    {
        $booking = $this->findOrFail($id);
        try {
            $action($booking);
            flash('success', $successMessage);
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'ทำรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
        }
        redirect('/admin/bookings/' . $booking['id']);
    }

    private function findOrFail(string $id): array
    {
        $booking = Booking::find((int) $id);
        if ($booking === null) {
            abort(404);
        }
        return $booking;
    }

    private function kindOrFail(string $kind): string
    {
        if (!in_array($kind, InspectionService::KINDS, true)) {
            abort(404);
        }
        return $kind;
    }
}
