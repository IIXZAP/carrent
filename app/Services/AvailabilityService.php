<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Car;
use App\Models\CarBlock;
use App\Models\Setting;

/** ตรวจรถว่าง + ตรวจช่วงเวลารับ–คืนรถ */
final class AvailabilityService
{
    /**
     * เวลาพักรถระหว่างงาน (ชั่วโมง) — ล้างรถ เติมน้ำมัน ตรวจสภาพ เผื่อลูกค้าคืนช้า
     * จองติดกันเป๊ะไม่ได้
     */
    public static function gapHours(): int
    {
        return max(0, (int) Setting::get('booking_gap_hours', '4'));
    }

    /** ขยายช่วงที่ตรวจออกหัวท้ายตามเวลาพักรถ */
    public static function paddedRange(string $pickup, string $return): array
    {
        $gap = self::gapHours();
        return [
            date('Y-m-d H:i:s', strtotime($pickup) - $gap * 3600),
            date('Y-m-d H:i:s', strtotime($return) + $gap * 3600),
        ];
    }

    /**
     * รถคันนี้ว่างตลอดช่วงนี้หรือไม่
     * $ignoreBookingId = ใบจองของตัวเอง (ตอนแก้ไข/เช่าต่อ) จะได้ไม่บล็อกตัวเอง
     *
     * หมายเหตุ: ตอนสร้างการจองจริงต้องเรียกใน transaction หลัง Car::lockForUpdate()
     * ไม่งั้นสองคำขอพร้อมกันอาจผ่านการตรวจทั้งคู่
     */
    public static function isCarAvailable(int $carId, string $pickup, string $return, ?int $ignoreBookingId = null): bool
    {
        if (Car::statusOf($carId) !== 'available') {
            return false;
        }
        [$from, $to] = self::paddedRange($pickup, $return);

        if (Booking::conflictCount($carId, $from, $to, $ignoreBookingId) > 0) {
            return false;
        }
        // เลยกำหนดคืนแล้วแต่ยังไม่กดรับคืน = รถยังไม่อยู่ ห้ามปล่อยจองซ้อน
        if (Booking::overdueOutCount($carId, $ignoreBookingId) > 0) {
            return false;
        }
        return CarBlock::overlapCount($carId, $from, $to) === 0;
    }

    /** รถที่ว่างทั้งหมดในช่วงนี้ พร้อมค่าเช่าที่คิดแล้ว (_total, _avg_day, _days) */
    public static function availableCars(string $pickup, string $return, ?string $typeCode = null): array
    {
        [$from, $to] = self::paddedRange($pickup, $return);
        $days = PricingService::rentalDays($pickup, $return);

        $result = [];
        foreach (Car::availableBetween($from, $to, $typeCode) as $car) {
            $rental = PricingService::calcRental($car, $days);
            $car['_days']    = $days;
            $car['_total']   = $rental['total'];
            $car['_avg_day'] = $rental['avg_day'];
            $result[] = $car;
        }
        return $result;
    }

    // ------------------------------------------------------------
    // เวลาทำการ + ตรวจช่วงเวลาที่ลูกค้าเลือก
    // ------------------------------------------------------------

    /** กฎการจองจาก settings (แยกเพื่อให้ตรวจช่วงเวลาทดสอบได้โดยไม่ต้องใช้ DB) */
    public static function rules(): array
    {
        return [
            'open_time'          => Setting::get('open_time', '08:00'),
            'close_time'         => Setting::get('close_time', '20:00'),
            'airport_open_time'  => Setting::get('airport_open_time', '05:00'),
            'airport_close_time' => Setting::get('airport_close_time', '24:00'),
            'lead_hours'         => (int) Setting::get('booking_lead_hours', '3'),
            'min_days'           => (int) Setting::get('min_rental_days', '1'),
        ];
    }

    /** ช่วงเวลารับ–คืนที่ทำได้ — 'airport' เปิดกว้างกว่า (เที่ยวบินเช้ามืด/ดึก) */
    public static function serviceWindow(string $placeType, array $rules): array
    {
        if ($placeType === 'airport') {
            return ['open' => $rules['airport_open_time'], 'close' => $rules['airport_close_time'], 'label' => 'สนามบิน'];
        }
        return ['open' => $rules['open_time'], 'close' => $rules['close_time'], 'label' => 'จุดรับ–คืนทั่วไป'];
    }

    /** "HH:MM" → นาทีจากเที่ยงคืน (24:00 = สิ้นวัน) */
    public static function hhmmToMinutes(string $time): int
    {
        [$h, $m] = array_pad(explode(':', trim($time)), 2, '0');
        return ((int) $h) * 60 + (int) $m;
    }

    /**
     * ตรวจช่วงเวลาที่ลูกค้าเลือก — null = ผ่าน, ข้อความ = ไม่ผ่าน
     * $now / $rules ใส่เองได้เพื่อทดสอบ (ปกติใช้เวลาปัจจุบันและค่าจาก settings)
     */
    public static function validatePeriod(
        string $pickup,
        string $return,
        string $pickType = 'shop',
        string $retType = 'shop',
        ?int $now = null,
        ?array $rules = null
    ): ?string {
        $rules ??= self::rules();
        $now   ??= time();

        $a = strtotime($pickup);
        $b = strtotime($return);
        if (!$a || !$b) {
            return 'รูปแบบวันที่ไม่ถูกต้อง';
        }
        if ($b <= $a) {
            return 'วันคืนรถต้องหลังวันรับรถ';
        }

        $error = self::validateServiceTime($pickup, $pickType, 'รับรถ', $rules)
              ?? self::validateServiceTime($return, $retType, 'คืนรถ', $rules);
        if ($error !== null) {
            return $error;
        }

        if ($a < $now + $rules['lead_hours'] * 3600) {
            return "กรุณาจองล่วงหน้าอย่างน้อย {$rules['lead_hours']} ชั่วโมง";
        }
        $days = PricingService::rentalDays($pickup, $return);
        if ($days < $rules['min_days']) {
            return "ระยะเวลาเช่าขั้นต่ำ {$rules['min_days']} วัน";
        }
        if ($days > 365) {
            return 'ระยะเวลาเช่าเกิน 1 ปี กรุณาติดต่อเจ้าหน้าที่';
        }
        return null;
    }

    private static function validateServiceTime(string $datetime, string $placeType, string $what, array $rules): ?string
    {
        $window = self::serviceWindow($placeType, $rules);
        $open   = self::hhmmToMinutes($window['open']);
        $close  = self::hhmmToMinutes($window['close']);
        $ts     = strtotime($datetime);
        $mins   = (int) date('H', $ts) * 60 + (int) date('i', $ts);

        if ($mins < $open || $mins > $close) {
            return sprintf('%sได้เฉพาะเวลา %s–%s น. (%s)', $what, $window['open'], $window['close'], $window['label']);
        }
        return null;
    }
}
