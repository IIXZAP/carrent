<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Inspection;
use App\Models\Signature;
use App\Services\InspectionService;

/**
 * พิมพ์สัญญาเช่ารถ (ดึงข้อมูลจากการจองอัตโนมัติ) พร้อมใบแนบสภาพรถ ก (ส่งมอบ) / ข (รับคืน)
 * แก้ข้อความสัญญาให้ตรงกับที่ทนายของร้านตรวจแล้วก่อนใช้งานจริง (ที่ views/partials/contract_terms.php)
 */
class ContractController extends AdminController
{
    public function show(int $id): Response
    {
        $b = Booking::findByIdForContract($id);
        if (!$b) {
            $this->missing('ไม่พบการจอง');
        }
        $inspOut = Inspection::get($id, 'out');
        $inspIn  = Inspection::get($id, 'in');

        return $this->view('admin/contract', [
            'title'   => 'สัญญาเช่ารถ ' . $b['booking_no'],
            'b'       => $b,
            'id'      => $id,
            'cus'     => $b['customer_id'] ? Customer::find((int) $b['customer_id']) : null,
            'carRow'  => ['brand' => $b['brand'], 'model' => $b['model'], 'plate' => $b['plate']],
            // ลายเซ็นอิเล็กทรอนิกส์ของผู้เช่า (ถ้าเซ็นผ่านมือถือแล้ว) — ฝังลงในช่องลงนามของฉบับพิมพ์
            'sigC'    => Signature::active($id, 'contract'),
            'sigR'    => Signature::active($id, 'return'),
            'inspOut' => $inspOut,
            'inspIn'  => $inspIn,
            'posOut'  => InspectionService::photoMap($inspOut),
            'posIn'   => InspectionService::photoMap($inspIn),
            'extraLines' => Booking::extraLines($b),
        ]);
    }
}
