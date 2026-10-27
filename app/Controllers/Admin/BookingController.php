<?php
namespace App\Controllers\Admin;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Payment;
use App\Services\AdminBookingService;
use App\Services\Auth;
use App\Services\BookingService;
use App\Services\BookingStatus;
use App\Services\PaymentService;

final class BookingController
{
    public function index(): void
    {
        $status = array_key_exists(get_str('status'), BookingStatus::STATUS) ? get_str('status') : '';
        $q = get_str('q');
        view('admin/bookings/index', [
            'title'    => 'การจอง',
            'bookings' => Booking::search($status, $q),
            'counts'   => Booking::countByStatus(),
            'status'   => $status,
            'q'        => $q,
        ], 'layouts/admin');
    }

    public function show(string $id): void
    {
        $booking = $this->findOrFail($id);
        view('admin/bookings/show', [
            'title'   => 'การจอง ' . $booking['booking_no'],
            'booking' => $booking,
            'addons'  => Booking::addons((int) $booking['id']),
            'payments'     => Payment::forBooking((int) $booking['id']),
            'netPaid'      => Payment::netPaid((int) $booking['id']),
            'securityHeld' => Payment::securityHeld((int) $booking['id']),
            'deliveryLines' => Booking::deliveryLines((int) $booking['id']),
        ], 'layouts/admin');
    }

    public function adjustDelivery(string $id): void
    {
        $booking = $this->findOrFail($id);
        $kmText = post('km');
        $feeText = post('fee');
        $km = $kmText === '' ? null : (is_numeric($kmText) ? (float) $kmText : -1.0);
        $fee = $feeText === '' ? null : PaymentService::parseAmount($feeText, true);
        if (($feeText !== '' && $fee === null) || ($km !== null && $km < 0)) {
            flash('error', 'ค่าส่งหรือระยะทางไม่ถูกต้อง');
            redirect('/admin/bookings/' . $booking['id']);
        }
        try {
            BookingService::adjustDelivery((int) $booking['id'], $km, $fee, post('reason'), (int) Auth::user()['id'], client_ip());
            flash('success', 'ปรับค่าส่งแล้ว ยอดรวมและสถานะการชำระเงินถูกคำนวณใหม่');
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'ปรับค่าส่งไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
        }
        redirect('/admin/bookings/' . $booking['id']);
    }

    public function create(): void
    {
        $this->form(['source' => 'phone', 'pick_type' => 'shop'], []);
    }

    public function store(): void
    {
        $in = request_strings($_POST, ['addons']);
        $result = AdminBookingService::create($in, (int) Auth::user()['id'], client_ip());
        if ($result['errors'] !== []) {
            $this->form($in, $result['errors']);
            return;
        }
        flash('success', 'สร้างการจองเรียบร้อย');
        redirect('/admin/bookings/' . $result['id']);
    }

    public function cancel(string $id): void
    {
        $booking = $this->findOrFail($id);
        $ok = AdminBookingService::cancel((int) $booking['id'], post('reason'), (int) Auth::user()['id'], client_ip());
        flash($ok ? 'success' : 'error', $ok ? 'ยกเลิกการจองแล้ว รถว่างสำหรับช่วงเวลานี้อีกครั้ง' : 'ยกเลิกไม่ได้ สถานะการจองเปลี่ยนไปแล้ว');
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

    private function form(array $in, array $errors): void
    {
        view('admin/bookings/form', [
            'title'   => 'สร้างการจอง',
            'in'      => $in,
            'errors'  => $errors,
            'cars'    => Car::allForAdmin(),
            'catalog' => Addon::activeCatalog(),
            'warnBlacklist' => isset($in['phone']) && AdminBookingService::isBlacklisted((string) $in['phone']),
        ], 'layouts/admin');
    }
}
