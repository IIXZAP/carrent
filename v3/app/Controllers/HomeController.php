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
        $pickup = normalize_datetime($_GET['pickup'] ?? '');
        $return = normalize_datetime($_GET['return'] ?? '');
        $type   = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
        $searching = ($_GET['pickup'] ?? '') !== '' || ($_GET['return'] ?? '') !== '';

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
