<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\View;
use App\Models\Car;
use App\Services\AbTrackService;
use App\Services\DeliveryFeeService;
use App\Services\AuthService;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\PricingService;

/** API ภายในหน้าเว็บ: คิดราคาสด + รับสัญญาณสถิติ */
class ApiController extends Controller
{
    /**
     * API คิดราคาสด (ใช้ในหน้ารายละเอียดรถและฟอร์มสร้างการจองหลังบ้าน)
     * GET: car_id, pickup, ret, addons (JSON), km, pick_type, ret_type
     */
    public function quote(): Response
    {
        $r = $this->request;

        $carId  = $r->int('car_id');
        $pickup = $r->str('pickup');
        $ret    = $r->str('ret');
        $addons = json_decode($r->str('addons', 0, '{}'), true);
        $addons = is_array($addons) ? BookingService::cleanAddons($addons) : [];
        $km       = max(0, min(2000, $r->float('km')));
        $pickType = $r->str('pick_type') === 'airport' ? 'airport' : 'shop';
        $retType  = $r->str('ret_type') === 'airport' ? 'airport' : 'shop';

        $car = Car::findPublic($carId);
        if (!$car) {
            return $this->json([
                'available' => false,
                'error'     => 'ไม่พบรถคันนี้',
                'html'      => '<div class="alert alert-danger">ไม่พบรถคันนี้</div>',
            ]);
        }

        $pickDb = ($pickup && strtotime($pickup) !== false) ? date('Y-m-d H:i:s', strtotime($pickup)) : '';
        $retDb  = ($ret && strtotime($ret) !== false)       ? date('Y-m-d H:i:s', strtotime($ret))    : '';

        // โหมดหลังบ้าน: เจ้าหน้าที่จองย้อนหลัง/จองวันนี้ได้ ไม่ต้องติดเงื่อนไขจองล่วงหน้า
        $adminMode = $r->bool('admin') && AuthService::check();
        // "ไม่นับการจองเลขนี้" ใช้ตอนแก้ไขการจองในหลังบ้านเท่านั้น — คนทั่วไปส่งมาจะถูกเมิน
        // (ไม่งั้นใช้ไล่ดูได้ว่าการจองเลขไหนจองรถคันไหนช่วงไหน)
        $ignoreId = $adminMode ? ($r->int('ignore') ?: null) : null;

        if (!$pickDb || !$retDb) {
            $periodError = 'กรุณาเลือกวันรับและวันคืนรถ';
        } elseif ($adminMode) {
            $periodError = strtotime($retDb) <= strtotime($pickDb) ? 'วันคืนรถต้องหลังวันรับรถ' : null;
        } else {
            $periodError = AvailabilityService::validatePeriod($pickDb, $retDb, $pickType, $retType);
        }
        if (!$periodError && !$adminMode && DeliveryFeeService::isOutOfRange($addons, $km)) {
            $periodError = 'ระยะทาง ' . number_format($km) . ' กม. อยู่นอกพื้นที่ที่คิดค่าส่งรถอัตโนมัติ กรุณาติดต่อเจ้าหน้าที่ ' . setting('phone');
        }
        $available = $periodError ? false : AvailabilityService::isCarAvailable($carId, $pickDb, $retDb, $ignoreId);
        $q = $periodError
            ? PricingService::emptyQuote()
            : PricingService::quote($car, $pickDb, $retDb, $addons, 0.0, null, $km);

        $html = View::make('partials/quote_box', ['q' => $q, 'available' => $available, 'periodError' => $periodError]);

        return $this->json([
            'available' => $available,
            'error'     => $periodError ?: ($available ? null : 'รถไม่ว่างในช่วงนี้'),
            'days'      => $q['days'],
            'total'     => $q['grand_total'],
            'deposit'   => $q['deposit_amount'],
            'html'      => $html,
        ]);
    }

    /**
     * รับสัญญาณ "เริ่มกรอกฟอร์ม" จากหน้าเว็บ
     * เป็นขั้นเดียวในกรวยที่ฝั่งเซิร์ฟเวอร์ไม่รู้เอง — ต้องให้เบราว์เซอร์บอก
     * รับเฉพาะชื่อขั้นที่อยู่ในรายการเท่านั้น ไม่รับค่าอื่นใดจากผู้ใช้
     */
    public function track(): Response
    {
        $step  = $this->request->str('step');
        $carId = $this->request->int('car_id') ?: null;

        if (!in_array($step, ['start_form'], true)) {
            return $this->json(['ok' => false], 400)->header('Cache-Control', 'no-store');
        }
        AbTrackService::trackOnce($step, $carId);
        return $this->json(['ok' => true])->header('Cache-Control', 'no-store');
    }
}
