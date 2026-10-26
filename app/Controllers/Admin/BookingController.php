<?php
namespace App\Controllers\Admin;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\Car;
use App\Services\AdminBookingService;
use App\Services\Auth;
use App\Services\BookingStatus;

final class BookingController
{
    public function index(): void
    {
        $status = is_string($_GET['status'] ?? null) && array_key_exists($_GET['status'], BookingStatus::STATUS) ? $_GET['status'] : '';
        $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
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
        ], 'layouts/admin');
    }

    public function create(): void
    {
        $this->form(['source' => 'phone', 'pick_type' => 'shop'], []);
    }

    public function store(): void
    {
        $in = $_POST;
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
