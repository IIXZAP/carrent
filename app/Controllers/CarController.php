<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Models\Car;
use App\Models\CarType;
use App\Services\AbTrackService;
use App\Services\AvailabilityService;
use App\Services\PricingService;

/** รายการรถ + ผลค้นหารถว่าง + รายละเอียดรถพร้อมฟอร์มจอง */
class CarController extends Controller
{

    /** รายการรถ + ผลการค้นหารถว่างตามช่วงวัน */
    public function index(): Response
    {
        // รับได้ทั้งแบบแยกวัน/เวลา (pd,pt,rd,rt) และแบบเดิม (pickup,ret)
        $pd = $this->request->str('pd');
        $pt = $this->request->str('pt', 0, '10:00') ?: '10:00';
        $rd = $this->request->str('rd');
        $rt = $this->request->str('rt', 0, '10:00') ?: '10:00';

        $pickup = $this->request->str('pickup');
        $ret    = $this->request->str('ret');
        if ($pd !== '' && $rd !== '') {
            $pickup = $pd . 'T' . $pt;
            $ret    = $rd . 'T' . $rt;
        }

        $type = $this->request->str('type');
        $type = CarType::exists($type) ? $type : '';

        $searching = $pickup !== '' && $ret !== '';
        $error = null;
        $days  = 0;
        $cars  = [];

        if ($searching) {
            $pickupDb = date('Y-m-d H:i:s', (int) strtotime($pickup));
            $retDb    = date('Y-m-d H:i:s', (int) strtotime($ret));
            $error    = AvailabilityService::validatePeriod($pickupDb, $retDb);
            if (!$error) {
                $cars = AvailabilityService::availableCars($pickupDb, $retDb, $type ?: null);
                $days = PricingService::rentalDays($pickupDb, $retDb);
            }
        } else {
            $cars = Car::allPublic($type ?: null);
        }

        return $this->view('public/cars', [
            'title'     => $searching ? 'รถว่างช่วง ' . thai_date($pickup) . ' – ' . thai_date($ret) : 'รถเช่าของเรา',
            'searching' => $searching,
            'error'     => $error,
            'days'      => $days,
            'cars'      => $cars,
            'pickup'    => $pickup,
            'ret'       => $ret,
            // ค่าเริ่มต้นของแถบค้นหา
            'sbPick'    => $pickup ?: date('Y-m-d\TH:i', strtotime('tomorrow 10:00')),
            'sbRet'     => $ret ?: date('Y-m-d\TH:i', strtotime('tomorrow +1 day 10:00')),
            'sbType'    => $type,
        ]);
    }

    /** รายละเอียดรถ + ฟอร์มจอง (คิดราคาสดผ่าน /api/quote) */
    public function show(int $id): Response
    {
        $car = Car::findPublic($id);
        if (!$car) {
            return $this->notFound('ไม่พบรถคันนี้', 'รถอาจถูกนำออกจากระบบแล้ว');
        }
        AbTrackService::trackOnce('view_car', (int) $car['id']);

        $pickup = $this->request->str('pickup') ?: date('Y-m-d\TH:i', strtotime('tomorrow 10:00'));
        $ret    = $this->request->str('ret')    ?: date('Y-m-d\TH:i', strtotime('tomorrow +1 day 10:00'));
        $pickDb = date('Y-m-d H:i:s', (int) strtotime($pickup));
        $retDb  = date('Y-m-d H:i:s', (int) strtotime($ret));

        $periodError = AvailabilityService::validatePeriod($pickDb, $retDb);
        $available   = $periodError ? false : AvailabilityService::isCarAvailable($id, $pickDb, $retDb);
        $q           = PricingService::quote($car, $pickDb, $retDb, [], 0.0, null, 0.0);

        return $this->view('public/car', [
            'title'       => $car['brand'] . ' ' . $car['model'],
            'desc'        => 'เช่า ' . $car['brand'] . ' ' . $car['model'] . ' เริ่มต้น ' . money0($car['price_day']) . ' บาท/วัน ' . ($car['segment'] ?: ''),
            'car'         => $car,
            'carName'     => $car['brand'] . ' ' . $car['model'],
            'features'    => json_decode((string) $car['features'], true) ?: [],
            'busy'        => AvailabilityService::busyRanges($id),
            'addonOpts'   => PricingService::addonCatalog($car),
            'pickup'      => $pickup,
            'ret'         => $ret,
            'periodError' => $periodError,
            'available'   => $available,
            'q'           => $q,
        ]);
    }
}
