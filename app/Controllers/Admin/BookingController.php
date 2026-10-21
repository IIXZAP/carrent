<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Inspection;
use App\Models\Payment;
use App\Models\Signature;
use App\Services\AdminBookingService;
use App\Services\BookingActionService;
use App\Services\BookingService;
use App\Services\InspectionService;
use App\Services\PricingService;
use App\Services\SignatureService;

/**
 * หลังบ้าน: รายการจอง / สร้าง–แก้ไข / หน้างาน 1 รายการ (ยืนยัน ส่งมอบ รับคืน ยกเลิก บันทึกเงิน)
 */
class BookingController extends AdminController
{
    // ------------------------------------------------------------
    // รายการ
    // ------------------------------------------------------------
    public function index(): Response
    {
        $r = $this->request;
        $filters = [
            'q'      => $r->str('q'),
            'status' => $r->str('status'),
            'car_id' => $r->int('car_id'),
            'from'   => $r->str('from'),
            'to'     => $r->str('to'),
        ];
        // ยอดเงินรวมเป็นข้อมูลของเจ้าของร้าน — พนักงานเห็นเฉพาะจำนวนรายการ
        $isOwner = is_admin();
        $result  = Booking::search($filters, max(1, $r->int('page', 1)), 25, $isOwner);

        return $this->view('admin/bookings/index', [
            'title'   => 'การจอง',
            'f'       => $filters,
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'pages'   => $result['pages'],
            'page'    => $result['page'],
            'sumTotal' => $result['sum_total'],
            'sumPaid' => $result['sum_paid'],
            'isOwner' => $isOwner,
            'cars'    => Car::options(),
            'query'   => $r->all(),
        ]);
    }

    // ------------------------------------------------------------
    // สร้าง / แก้ไข
    // ------------------------------------------------------------
    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(int $id): Response
    {
        return $this->form($this->find($id));
    }

    public function store(): Response
    {
        try {
            $res = AdminBookingService::save(null, $this->request);
            return $this->done('admin/bookings/' . $res['id'], 'ok', $res['message']);
        } catch (\Throwable $ex) {
            $this->flash('danger', BookingService::publicError($ex, 'บันทึกไม่สำเร็จ กรุณาตรวจข้อมูลแล้วลองใหม่'));
            return $this->form(null, true);
        }
    }

    public function update(int $id): Response
    {
        $b = $this->find($id);
        try {
            $res = AdminBookingService::save($b, $this->request);
            return $this->done('admin/bookings/' . $id, 'ok', $res['message']);
        } catch (\Throwable $ex) {
            $this->flash('danger', BookingService::publicError($ex, 'บันทึกไม่สำเร็จ กรุณาตรวจข้อมูลแล้วลองใหม่'));
            // โหลดข้อมูลล่าสุดมาแสดง (กรณีมีคนแก้จากหน้าอื่น)
            return $this->form($this->find($id), true);
        }
    }

    /** @param bool $withOld เติมค่าที่ผู้ใช้เพิ่งกรอก (เมื่อบันทึกไม่ผ่าน) ทับค่าจากฐานข้อมูล */
    private function form(?array $b, bool $withOld = false): Response
    {
        $r      = $this->request;
        $id     = $b ? (int) $b['id'] : 0;
        $locked = BookingService::isLocked($b);
        $sel    = $b ?: [];

        if ($withOld) {
            foreach (['car_id', 'pickup_place', 'return_place', 'contact_name', 'contact_phone', 'contact_email',
                      'contact_line', 'note', 'discount', 'deposit_amount', 'security_deposit'] as $k) {
                if ($r->has($k)) {
                    $sel[$k] = $r->str($k);
                }
            }
        }
        $selAddons = $withOld ? (array) $r->arr('addons') : ($b ? (json_decode((string) $b['addons'], true) ?: []) : []);
        $pickRaw   = $withOld && $r->str('pickup_at') !== '' ? $r->str('pickup_at') : null;
        $retRaw    = $withOld && $r->str('return_at') !== '' ? $r->str('return_at') : null;

        $defPick = $pickRaw ?? ($b ? date('Y-m-d\TH:i', strtotime($b['pickup_at'])) : date('Y-m-d\TH:i', strtotime('tomorrow 10:00')));
        $defRet  = $retRaw ?? ($b ? date('Y-m-d\TH:i', strtotime($b['return_at'])) : date('Y-m-d\TH:i', strtotime('tomorrow +1 day 10:00')));

        // การจองเดิม: ยึดตามที่บันทึกไว้ · การจองใหม่: ยึดตามตั้งค่าร้าน
        $vatOn = $withOld ? $r->has('vat') : ($b ? (float) $b['vat_rate'] > 0 : setting('vat_enabled') === '1');

        return $this->view('admin/bookings/form', [
            'title'     => $id ? 'แก้ไขการจอง ' . $b['booking_no'] : 'สร้างการจองใหม่',
            'b'         => $b,
            'id'        => $id,
            'locked'    => $locked,
            'sel'       => $sel,
            'selAddons' => $selAddons,
            'defPick'   => $defPick,
            'defRet'    => $defRet,
            'vatOn'     => $vatOn,
            'cars'      => Car::active(),
            'deliveryKm' => $b ? rtrim(rtrim(number_format(PricingService::bookingDeliveryKm($b), 1, '.', ''), '0'), '.') : '0',
            'tiersText' => PricingService::deliveryTiersText(),
        ]);
    }

    // ------------------------------------------------------------
    // หน้างาน 1 รายการ
    // ------------------------------------------------------------
    public function show(int $id): Response
    {
        $b = Booking::findWithCar($id);
        if (!$b) {
            $this->missing('ไม่พบการจองนี้');
        }
        [$sl, $sc] = booking_status_label($b['status']);
        [$pl, $pc] = payment_status_label($b['payment_status']);
        $due     = (float) $b['grand_total'] + (float) $b['extra_charge'];
        $balance = $due - (float) $b['paid_amount'];

        $inspOut = Inspection::get($id, 'out');
        $inspIn  = Inspection::get($id, 'in');
        $car     = Car::find((int) $b['car_id']) ?: [];

        return $this->view('admin/bookings/show', [
            'title'      => 'การจอง ' . $b['booking_no'],
            'b'          => $b,
            'car'        => $car,
            'id'         => $id,
            'payments'   => Payment::forBooking($id),
            'customer'   => $b['customer_id'] ? Customer::find((int) $b['customer_id']) : null,
            'docs'       => Document::forBooking($id),
            'breakdown'  => json_decode((string) $b['rate_breakdown'], true) ?: [],
            'sl' => $sl, 'sc' => $sc, 'pl' => $pl, 'pc' => $pc,
            'due'        => $due,
            'balance'    => $balance,
            'held'       => Payment::securityHeld($id),   // เงินประกันที่ร้านยังถืออยู่
            'xLines'     => Booking::extraLines($b),
            'sigs'       => ['contract' => Signature::active($id, 'contract'), 'return' => Signature::active($id, 'return')],
            'sigVoided'  => Signature::voided($id),
            'hasLine'    => (bool) \App\Services\LineService::userForBooking($b),
            'inspOut'    => $inspOut,
            'inspIn'     => $inspIn,
            'progOut'    => InspectionService::progress($inspOut),
            'progIn'     => InspectionService::progress($inspIn),
            'mapOut'     => InspectionService::photoMap($inspOut),
            'mapIn'      => InspectionService::photoMap($inspIn),
            'nowLocal'   => date('Y-m-d\TH:i'),
        ]);
    }

    /** การกระทำบนหน้างาน (act = confirm|pickup|return|extend|security_settle|sign_send|sign_void|complete|cancel|payment|note) */
    public function action(int $id): Response
    {
        $b   = Booking::findWithCar($id);
        if (!$b) {
            $this->missing('ไม่พบการจองนี้');
        }
        $car = Car::find((int) $b['car_id']) ?: [];
        try {
            [$tone, $msg] = BookingActionService::perform($this->request->str('act'), $b, $car, $this->request);
            $this->flash($tone, $msg);
        } catch (\Throwable $ex) {
            $this->flash('danger', BookingService::publicError($ex, 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
        }
        return $this->redirect('admin/bookings/' . $id);
    }

    /**
     * ตัวอย่างการคิดเงิน (เรียกด้วย fetch จากหน้างาน) — ตอบเป็น JSON
     *   /preview/return?km=&fuel=&at=   ค่าใช้จ่ายเพิ่มตอนคืนรถ ณ เวลานี้
     *   /preview/extend?ret=            ค่าเช่าเพิ่มถ้าเลื่อนวันคืน
     * ใช้ฟังก์ชันเดียวกับตอนบันทึกจริง ตัวเลขที่เห็นกับที่บันทึกจึงตรงกันเสมอ
     */
    public function preview(int $id, string $type): Response
    {
        $b = Booking::findWithCar($id);
        if (!$b) {
            return $this->json(['ok' => false, 'error' => 'not found'], 404);
        }
        $car = Car::find((int) $b['car_id']) ?: [];
        $r   = $this->request;

        if ($type === 'return') {
            $km   = $r->str('km') !== '' ? max(0, $r->int('km')) : null;
            $fuel = $r->str('fuel') !== '' ? max(0, min(8, $r->int('fuel'))) : null;
            $ts   = $r->str('at') !== '' ? strtotime($r->str('at')) : false;
            $at   = date('Y-m-d H:i:s', $ts !== false ? min($ts, time()) : time());
            return $this->json(['ok' => true] + PricingService::calcReturnCharges($b, $car, $at, $km, $fuel));
        }

        $q = PricingService::extensionQuote($b, $car, $r->str('ret'));
        if ($q['ok']) {
            $q['new_return_text'] = thai_date($q['new_return'], true);
        }
        return $this->json($q);
    }

    private function find(int $id): array
    {
        $b = Booking::find($id);
        if (!$b) {
            $this->flash('danger', 'ไม่พบการจอง');
            $this->halt($this->redirect('admin/bookings'));
        }
        return $b;
    }
}
