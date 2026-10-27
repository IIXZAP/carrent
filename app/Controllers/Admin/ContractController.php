<?php
namespace App\Controllers\Admin;

use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Signature;
use App\Services\Auth;
use App\Services\BookingService;
use App\Services\SignatureService;
use App\Services\UploadService;

/** สัญญาเช่า/ใบรับคืนรถ ฝั่งหลังบ้าน: ลิงก์ให้ลูกค้าเซ็น · สถานะลายเซ็น · พิมพ์ · ยกเลิกเพื่อเซ็นใหม่ */
final class ContractController
{
    public function show(string $id): void
    {
        $booking = $this->findOrFail($id);
        $car = Car::find((int) $booking['car_id']) ?? [];
        $kinds = [];
        foreach (SignatureService::kinds() as $kind => [$label]) {
            $all = array_values(array_filter(Signature::allFor((int) $booking['id']), static fn($s) => $s['kind'] === $kind));
            $active = Signature::active((int) $booking['id'], $kind);
            $kinds[$kind] = [
                'label'   => $label,
                'blocker' => SignatureService::blocker($booking, $kind),
                'active'  => $active,
                'history' => $all,
                'changes' => $active ? SignatureService::changes($active, $booking, $car) : [],
                'intact'  => $active ? SignatureService::intact($active) : true,
                // สร้างรหัสลิงก์เมื่อมีคนเปิดหน้านี้ครั้งแรกเท่านั้น และเฉพาะชนิดที่เซ็นได้อยู่
                'link'    => $active === null && SignatureService::blocker($booking, $kind) === null ? absolute_url(SignatureService::path($booking, $kind)) : null,
            ];
        }
        view('admin/contract/show', [
            'title'   => 'สัญญา ' . $booking['booking_no'],
            'booking' => $booking,
            'kinds'   => $kinds,
        ], 'layouts/admin');
    }

    public function print(string $id): void
    {
        $booking = $this->findOrFail($id);
        $kind = get_str('kind', 'contract');
        if (!isset(SignatureService::kinds()[$kind])) {
            abort(404);
        }
        $car = Car::find((int) $booking['car_id']) ?? [];
        $active = Signature::active((int) $booking['id'], $kind);
        view('admin/contract/print', [
            'title'    => SignatureService::kinds()[$kind][0] . ' ' . $booking['booking_no'],
            'booking'  => $booking,
            'kind'     => $kind,
            'customer' => Customer::find((int) $booking['customer_id']) ?: [],
            'view'     => SignatureService::view($booking, $kind),
            'active'   => $active,
            'changes'  => $active ? SignatureService::changes($active, $booking, $car) : [],
            'shop'     => Setting::all(),
        ], 'layouts/print');
    }

    /** รูปลายเซ็น (เฉพาะผู้ที่ล็อกอิน — เปิดผ่านลิงก์ตรงของไฟล์ไม่ได้) */
    public function image(string $id): void
    {
        $sig = ctype_digit($id) ? Signature::find((int) $id) : null;
        if ($sig === null) {
            abort(404);
        }
        UploadService::serve($sig['file_path']);
    }

    public function void(string $id): void
    {
        $sig = ctype_digit($id) ? Signature::find((int) $id) : null;
        if ($sig === null) {
            abort(404);
        }
        try {
            SignatureService::void((int) $sig['id'], (int) Auth::user()['id'], post('reason'), client_ip());
            flash('success', 'ยกเลิกลายเซ็นแล้ว — ส่งลิงก์ให้ลูกค้าเซ็นใหม่ได้ (ฉบับเดิมยังเก็บเป็นประวัติ)');
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'ยกเลิกลายเซ็นไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
        }
        redirect('/admin/bookings/' . $sig['booking_id'] . '/contract');
    }

    private function findOrFail(string $id): array
    {
        $booking = ctype_digit($id) ? Booking::find((int) $id) : null;
        if ($booking === null) {
            abort(404);
        }
        return $booking;
    }
}
