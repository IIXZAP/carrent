<?php
namespace App\Controllers;

use App\Core\Session;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\Car;
use App\Services\AvailabilityService;
use App\Services\QuoteService;
use App\Services\WebBookingService;

final class BookingController
{
    /** ฟอร์มจอง: รับรถ/วัน/บริการเสริมมาจากหน้ารถ (GET) แล้วคิดราคาใหม่ฝั่งเซิร์ฟเวอร์ทุกครั้ง */
    public function form(): void
    {
        $car = Car::find((int) ($_GET['car'] ?? 0));
        if ($car === null || $car['status'] !== 'available') {
            abort(404);
        }
        $in = [
            'car_id' => $car['id'],
            'pickup' => (string) ($_GET['pickup'] ?? ''),
            'return' => (string) ($_GET['return'] ?? ''),
            'addons' => is_array($_GET['addons'] ?? null) ? $_GET['addons'] : [],
            'km'     => (string) ($_GET['km'] ?? ''),
            'pick_type' => 'shop', 'ret_type' => 'shop',
        ];
        $this->render($car, $in, []);
    }

    public function store(): void
    {
        $in = $this->readForm();
        $car = Car::find((int) $in['car_id']);
        if ($car === null || $car['status'] !== 'available') {
            abort(404);
        }

        $result = WebBookingService::create($in, client_ip());
        if ($result['errors'] !== []) {
            $this->render($car, $in, $result['errors']);
            return;
        }

        // เก็บเลขที่จองไว้ใน session — หน้าสำเร็จเปิดได้เฉพาะเจ้าของการจอง (เลขที่จองเดาได้)
        Session::set('last_booking_no', $result['booking']['booking_no']);
        Session::set('track_booking_id', (int) $result['booking']['id']);   // เข้าหน้าแจ้งโอนได้ทันที ไม่ต้องกรอกเลขที่จองอีก
        redirect('/booking/success/' . $result['booking']['booking_no']);
    }

    public function success(string $no): void
    {
        if (Session::get('last_booking_no') !== $no) {
            redirect('/');
        }
        $booking = Booking::findByNo($no);
        if ($booking === null) {
            abort(404);
        }
        view('booking/success', [
            'title'   => 'จองสำเร็จ',
            'booking' => $booking,
            'addons'  => Booking::addons((int) $booking['id']),
            'deadline' => \App\Services\BookingService::expiryDeadline($booking),
        ]);
    }

    private function readForm(): array
    {
        $in = [];
        foreach (['car_id', 'pickup', 'return', 'km', 'name', 'phone', 'email', 'line', 'pick_type', 'ret_type', 'place_pick', 'place_ret', 'note'] as $key) {
            $in[$key] = post($key);
        }
        $in['addons'] = is_array($_POST['addons'] ?? null) ? $_POST['addons'] : [];
        $in['accept'] = ($_POST['accept'] ?? '') === '1';
        return $in;
    }

    /** แสดงฟอร์ม + สรุปราคา (ถ้าช่วงเวลาถูกต้อง) */
    private function render(array $car, array $in, array $errors): void
    {
        $pickup = normalize_datetime($in['pickup'] ?? '');
        $return = normalize_datetime($in['return'] ?? '');
        $quote = null;
        $periodProblem = ($pickup === null || $return === null)
            ? 'กรุณาเลือกวัน–เวลารับและคืนรถ'
            : AvailabilityService::validatePeriod($pickup, $return);
        if ($periodProblem === null && !AvailabilityService::isCarAvailable((int) $car['id'], $pickup, $return)) {
            $periodProblem = 'รถคันนี้ไม่ว่างในช่วงเวลาที่เลือก กรุณากลับไปเลือกวันใหม่';
        }

        if ($periodProblem !== null) {
            // ถ้าส่งฟอร์มมาแล้วมีข้อผิดพลาดของฟอร์มเอง (ชื่อ/เบอร์ ฯลฯ) ใช้ข้อความนั้น ไม่ซ้ำกับปัญหาช่วงเวลา
            if ($errors === []) {
                $errors[] = $periodProblem;
            }
        } else {
            $quote = QuoteService::forCar($car, $pickup, $return, $in['addons'], 0.0, (float) ($in['km'] ?? 0));
            if ($quote['needs_km']) {
                $errors[] = 'กรุณาระบุระยะทางส่ง–รับรถ (กม.) เมื่อเลือกบริการส่ง/รับรถถึงที่ — กลับไปที่หน้ารถแล้วกรอกระยะทาง';
                $quote = null;
            } elseif ($quote['out_of_range']) {
                $errors[] = 'พื้นที่ส่ง–รับรถไกลเกินระยะที่ให้บริการ กรุณาติดต่อเจ้าหน้าที่เพื่อขอราคา';
                $quote = null;
            }
        }

        view('booking/form', [
            'title'    => 'จอง ' . $car['brand'] . ' ' . $car['model'],
            'car'      => $car,
            'in'       => $in,
            'pickup'   => $pickup,
            'return'   => $return,
            'quote'    => $quote,
            'errors'   => $errors,
            'selected' => QuoteService::cleanAddons($in['addons'], Addon::activeCatalog()),
        ]);
    }
}
