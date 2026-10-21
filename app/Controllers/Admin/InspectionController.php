<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Inspection;
use App\Services\InspectionService;

/**
 * หน้าถ่ายรูปตรวจสภาพรถ — ออกแบบให้ใช้บนมือถือหน้างานเป็นหลัก
 *
 * กดปุ่ม → เปิดกล้องหลังทันที → ย่อรูปในเครื่องก่อนส่ง → อัปทีละใบ
 * การย่อรูปฝั่งเครื่องสำคัญมาก เพราะรูปจากมือถือใบละ 3–5 MB
 * ถ้าส่งดิบๆ บน shared hosting จะช้าและเต็มพื้นที่เร็ว (ย่อแล้วเหลือ ~250–400 KB)
 */
class InspectionController extends AdminController
{
    public function show(int $id, string $kind): Response
    {
        $b = $this->booking($id);
        $ins        = Inspection::get($id, $kind);
        $phaseBlock = InspectionService::phaseBlock($b, $kind);
        $locked     = InspectionService::isLocked($ins) || $phaseBlock !== null;

        // ค่าตั้งต้นของเลขไมล์: ใช้ค่าที่เคยบันทึกไว้ → ค่าจากใบจอง → เลขไมล์ล่าสุดของรถ
        $defaultKm = $ins['mileage']
            ?? ($kind === 'out' ? $b['mileage_out'] : $b['mileage_in'])
            ?? Car::mileage((int) $b['car_id']);
        $defaultFuel = $ins['fuel_level'] ?? ($kind === 'out' ? ($b['fuel_out'] ?? 8) : ($b['fuel_in'] ?? 8));

        return $this->view('admin/inspection', [
            'title'       => 'ตรวจสภาพรถ ' . InspectionService::kindLabel($kind),
            'b'           => $b,
            'bookingId'   => $id,
            'kind'        => $kind,
            'ins'         => $ins,
            'phaseBlock'  => $phaseBlock,
            'locked'      => $locked,
            'map'         => InspectionService::photoMap($ins),
            'prog'        => InspectionService::progress($ins),
            'other'       => Inspection::get($id, $kind === 'out' ? 'in' : 'out'),
            'defaultKm'   => (int) $defaultKm,
            'defaultFuel' => (int) $defaultFuel,
            'positions'   => InspectionService::positions(),
        ]);
    }

    /** บันทึกเลขไมล์ / น้ำมัน / หมายเหตุ (act = save | lock) */
    public function save(int $id, string $kind): Response
    {
        $r    = $this->request;
        $b    = $this->booking($id);
        $back = 'admin/bookings/' . $id . '/inspection/' . $kind;

        if ($why = InspectionService::phaseBlock($b, $kind)) {
            return $this->done($back, 'warn', $why);
        }
        if (InspectionService::isLocked(Inspection::get($id, $kind))) {
            return $this->done($back, 'warn', 'ผลตรวจรอบนี้ถูกล็อกแล้ว แก้ไขไม่ได้');
        }

        $insId = Inspection::ensure($id, $kind, $this->userId());
        Inspection::updateById($insId, [
            'mileage'      => max(0, min(9999999, $r->int('mileage'))) ?: null,
            'fuel_level'   => max(0, min(8, $r->int('fuel_level', 8))),
            'note'         => $r->str('note', 1000) ?: null,
            'inspected_by' => $this->userId(),
            'inspected_at' => date('Y-m-d H:i:s'),
        ]);
        ActivityLog::log('inspection_saved', 'bookings', $id, $kind);

        if ($r->str('act') === 'lock') {
            // ต้องติ๊กรับทราบก่อน — ล็อกแล้วย้อนไม่ได้ จึงไม่ให้กดพลาดด้วยปุ่มเดียว
            if (!$r->bool('lock_ack')) {
                return $this->done($back, 'warn', 'บันทึกแล้ว แต่ยังไม่ได้ล็อก — กรุณาติ๊กรับทราบก่อนกดล็อก');
            }
            InspectionService::lock($id, $kind);
            return $this->done('admin/bookings/' . $id, 'ok', 'บันทึกและล็อกผลตรวจสภาพรถแล้ว');
        }
        return $this->done('admin/bookings/' . $id, 'ok', 'บันทึกผลตรวจสภาพรถแล้ว');
    }

    /** รับรูป 1 ใบ (เรียกด้วย fetch จากหน้าเว็บ) — ตอบกลับเป็น JSON */
    public function photo(int $id, string $kind): Response
    {
        $r = $this->request;
        try {
            $b = Booking::find($id);
            if (!$b) {
                throw new \RuntimeException('ไม่พบการจอง');
            }
            if ($why = InspectionService::phaseBlock($b, $kind)) {
                throw new \RuntimeException($why);
            }
            $pos = $r->str('position');
            if (!isset(InspectionService::positions()[$pos])) {
                throw new \RuntimeException('ไม่รู้จักมุมถ่ายนี้');
            }
            $insId = Inspection::ensure($id, $kind, $this->userId());
            $path  = InspectionService::savePhoto($insId, $pos, $r->file('photo'), $r->str('caption') ?: null);
            if (!$path) {
                throw new \RuntimeException('ไม่ได้รับไฟล์รูป');
            }
            ActivityLog::log('inspection_photo', 'bookings', $id, $kind . ':' . $pos);
            return $this->json(['ok' => true, 'url' => upload_url($path), 'position' => $pos]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    private function booking(int $id): array
    {
        $b = Booking::findWithCar($id);
        if (!$b) {
            $this->missing('ไม่พบการจองนี้');
        }
        return $b;
    }
}
