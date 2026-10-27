<?php
namespace App\Controllers;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\Car;
use App\Services\AvailabilityService;
use App\Services\QuoteService;

final class CarController
{
    public function show(string $id): void
    {
        $car = Car::find((int) $id);
        if ($car === null || $car['status'] !== 'available') {
            abort(404);
        }

        $pickup = normalize_datetime($_GET['pickup'] ?? '');
        $return = normalize_datetime($_GET['return'] ?? '');
        $asked  = ($_GET['pickup'] ?? '') !== '' || ($_GET['return'] ?? '') !== '';
        $rawAddons = is_array($_GET['addons'] ?? null) ? $_GET['addons'] : [];
        $km = max(0.0, (float) ($_GET['km'] ?? 0));

        $error = null;
        $quote = null;
        if ($asked) {
            $error = $this->checkPeriod($car, $pickup, $return);
        }
        if ($asked && $error === null) {
            $quote = QuoteService::forCar($car, $pickup, $return, $rawAddons, 0.0, $km);
            if ($quote['needs_km']) {
                $error = 'กรุณาระบุระยะทางส่ง–รับรถ (กม.) เมื่อเลือกบริการส่ง/รับรถถึงที่';
                $quote = null;
            } elseif ($quote['out_of_range']) {
                $error = 'พื้นที่ส่ง–รับรถไกลเกินระยะที่ให้บริการ กรุณาติดต่อเจ้าหน้าที่เพื่อขอราคา';
                $quote = null;
            }
        }

        $catalog = Addon::activeCatalog();
        view('public/car', [
            'title'    => $car['brand'] . ' ' . $car['model'],
            'car'      => $car,
            'pickup'   => $pickup,
            'return'   => $return,
            'error'    => $error,
            'quote'    => $quote,
            'catalog'  => $catalog,
            'selected' => QuoteService::cleanAddons($rawAddons, $catalog),
            'km'       => $km,
            'busy'     => Booking::busyRanges((int) $car['id'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+3 months'))),
        ]);
    }

    /** ตรวจช่วงเวลา + รถว่างหรือไม่ — คืนข้อความผิดพลาด หรือ null ถ้าผ่าน */
    private function checkPeriod(array $car, ?string $pickup, ?string $return): ?string
    {
        if ($pickup === null || $return === null) {
            return 'กรุณาเลือกวันและเวลารับรถ–คืนรถให้ครบ';
        }
        $error = AvailabilityService::validatePeriod($pickup, $return);
        if ($error !== null) {
            return $error;
        }
        if (!AvailabilityService::isCarAvailable((int) $car['id'], $pickup, $return)) {
            return 'รถคันนี้ไม่ว่างในช่วงเวลาที่เลือก';
        }
        return null;
    }
}
