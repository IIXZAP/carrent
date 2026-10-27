<?php
namespace App\Controllers;

use App\Models\Car;
use App\Models\CarType;
use App\Services\AvailabilityService;

final class HomeController
{
    /** หน้าแรก: รถทั้งหมด หรือ (ถ้าเลือกวันรับ–คืน) เฉพาะรถที่ว่างพร้อมราคา */
    public function index(): void
    {
        $pickup = normalize_datetime(get_str('pickup'));
        $return = normalize_datetime(get_str('return'));
        $type   = get_str('type');
        $searching = get_str('pickup') !== '' || get_str('return') !== '' || isset($_GET['pickup']) || isset($_GET['return']);

        $error = null;
        $cars  = [];
        if (!$searching) {
            $cars = Car::allPublic();
        } elseif ($pickup === null || $return === null) {
            $error = 'กรุณาเลือกวันและเวลารับรถ–คืนรถให้ครบ';
        } else {
            $error = AvailabilityService::validatePeriod($pickup, $return);
            if ($error === null) {
                $cars = AvailabilityService::availableCars($pickup, $return, $type);
            }
        }

        view('public/home', [
            'title'     => 'เช่ารถ',
            'cars'      => $cars,
            'types'     => CarType::all(),
            'pickup'    => $pickup,
            'return'    => $return,
            'type'      => $type,
            'searching' => $searching,
            'error'     => $error,
        ]);
    }
}
