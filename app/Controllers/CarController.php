<?php
namespace App\Controllers;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\Car;
use App\Services\AvailabilityService;
use App\Services\QuoteService;

final class CarController
{
    /** รายการรถ + ค้นหารถว่างตามช่วงวัน (ไม่เลือกวัน = แสดงรถทั้งหมด) */
    public function index(): void
    {
        [$pickup, $return, $asked] = request_period();
        $type = get_str('type');
        $error = null;
        $days = 0;
        $cars = [];
        if (!$asked) {
            $cars = Car::allPublic();
            if ($type !== '') {
                $cars = array_values(array_filter($cars, static fn(array $c): bool => $c['car_type_code'] === $type));
            }
        } elseif ($pickup === null || $return === null) {
            $error = 'กรุณาเลือกวันและเวลารับรถ–คืนรถให้ครบ';
        } else {
            $error = AvailabilityService::validatePeriod($pickup, $return);
            if ($error === null) {
                $cars = AvailabilityService::availableCars($pickup, $return, $type);
                $days = $cars ? (int) $cars[0]['_days'] : \App\Services\PricingService::rentalDays($pickup, $return, (int) \App\Models\Setting::get('late_grace_hours', '2'));
            }
        }
        view('public/cars', [
            'title'     => 'รถเช่าของเรา',
            'searching' => $asked && $error === null,
            'error'     => $error,
            'days'      => $days,
            'cars'      => $cars,
            'pickup'    => $pickup,
            'ret'       => $return,
            'sbPick'    => $pickup ? input_datetime($pickup) : date('Y-m-d\TH:i', strtotime('tomorrow 10:00')),
            'sbRet'     => $return ? input_datetime($return) : date('Y-m-d\TH:i', strtotime('tomorrow +1 day 10:00')),
            'sbType'    => $type,
        ]);
    }

    public function show(string $id): void
    {
        $car = Car::find((int) $id);
        if ($car === null || $car['status'] !== 'available') {
            abort(404);
        }

        [$pickup, $return, $asked] = request_period();
        $rawAddons = get_array('addons');
        $km = max(0.0, (float) get_str('km', '0'));

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
            'images'   => Car::images((int) $car['id']),
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
