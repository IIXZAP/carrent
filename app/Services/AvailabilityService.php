<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Booking;
use App\Models\Car;
use App\Models\CarBlock;

/**
 * ตรวจรถว่าง + ตรวจช่วงเวลารับ–คืนรถ
 */
final class AvailabilityService
{
    /**
     * ระยะเวลาพักรถระหว่างงาน (ชั่วโมง)
     * ใช้ล้างรถ เติมน้ำมัน ตรวจสภาพ ถ่ายรูป และเผื่อลูกค้าคืนช้า
     */
    public static function gapHours(): int
    {
        return max(0, (int) setting('booking_gap_hours', '4'));
    }

    /** ขยายช่วงที่ตรวจออกไปหัวท้ายตามเวลาพักรถ — จองติดกันเป๊ะไม่ได้ */
    private static function paddedRange(string $pickup, string $return): array
    {
        $gap = self::gapHours();
        if ($gap === 0) {
            return [$pickup, $return];
        }
        return [
            date('Y-m-d H:i:s', strtotime($pickup) - $gap * 3600),
            date('Y-m-d H:i:s', strtotime($return) + $gap * 3600),
        ];
    }

    /**
     * รถคันนี้ว่างในช่วงเวลานี้หรือไม่
     *
     * ชนกันเมื่อ (start < existing_end) AND (end > existing_start)
     * โดยขยายช่วงที่ตรวจออกไปหัวท้ายตามเวลาพักรถ
     *
     * $ignoreBookingId ใช้ตอน "เช่าต่อ" หรือแอดมินแก้ใบจองเดิม
     * เพื่อไม่ให้ใบจองของตัวเองมาบล็อกตัวเอง
     */
    public static function isCarAvailable(int $carId, string $pickup, string $return, ?int $ignoreBookingId = null): bool
    {
        if (Car::status($carId) !== 'available') {
            return false;
        }
        [$from, $to] = self::paddedRange($pickup, $return);

        if (Booking::conflictCount($carId, $from, $to, $ignoreBookingId) > 0) {
            return false;
        }
        // รถที่ยังไม่ได้กดคืน และเลยกำหนดคืนมาแล้ว = ยังอยู่กับลูกค้ารายเดิม
        // ต้องถือว่าไม่ว่างจนกว่าจะกดรับคืนจริง ไม่งั้นจะปล่อยให้จองซ้อนกับรถที่ไม่อยู่
        if (Booking::overdueOutCount($carId, $ignoreBookingId) > 0) {
            return false;
        }
        return CarBlock::overlapCount($carId, $from, $to) === 0;
    }

    /** รายการรถที่ว่างทั้งหมดในช่วงเวลา (พร้อมราคาที่คิดแล้ว) */
    public static function availableCars(string $pickup, string $return, ?string $carType = null): array
    {
        // ใช้กติกาเดียวกับ isCarAvailable — เผื่อเวลาพักรถ และกันรถที่ยังไม่ได้กดคืน
        [$from, $to] = self::paddedRange($pickup, $return);

        $sql = "SELECT c.* FROM cars c
                WHERE c.status = 'available'
                  AND NOT EXISTS (
                    SELECT 1 FROM bookings b
                    WHERE b.car_id = c.id
                      AND b.status IN ('pending','confirmed','picked_up')
                      AND b.pickup_at < :ret AND b.return_at > :pick
                  )
                  AND NOT EXISTS (
                    SELECT 1 FROM bookings o
                    WHERE o.car_id = c.id AND o.status = 'picked_up' AND o.return_at < NOW()
                  )
                  AND NOT EXISTS (
                    SELECT 1 FROM car_blocks k
                    WHERE k.car_id = c.id AND k.start_at < :ret2 AND k.end_at > :pick2
                  )";
        $p = [':pick' => $from, ':ret' => $to, ':pick2' => $from, ':ret2' => $to];
        if ($carType) {
            $sql .= ' AND c.car_type = :ct';
            $p[':ct'] = $carType;
        }
        $sql .= ' ORDER BY c.sort_order, c.price_day';

        $cars = Database::all($sql, $p);
        $days = PricingService::rentalDays($pickup, $return);
        $result = [];
        foreach ($cars as $car) {
            $quote = PricingService::calcRental($car, $days);
            $car['_days']    = $days;
            $car['_total']   = $quote['total'];
            $car['_avg_day'] = $quote['avg_day'];
            $car['_lines']   = $quote['lines'];
            $result[] = $car;
        }
        return $result;
    }

    /** ช่วงวันที่ถูกจองของรถคันหนึ่ง (ใช้แสดงในหน้ารายละเอียดรถ) */
    public static function busyRanges(int $carId, ?string $from = null, ?string $to = null): array
    {
        return Booking::busyRanges(
            $carId,
            $from ?: date('Y-m-d'),
            $to ?: date('Y-m-d', strtotime('+6 months'))
        );
    }

    // ------------------------------------------------------------
    // เวลาทำการ
    // ------------------------------------------------------------
    /**
     * ช่วงเวลาที่รับ–คืนรถได้ ตามประเภทจุดนัด
     * 'airport' = สนามบิน เปิดกว้างกว่าเพราะเที่ยวบินมีทั้งเช้ามืดและดึก นอกนั้นยึดเวลาทำการของร้าน
     */
    public static function serviceWindow(string $placeType = 'shop'): array
    {
        if ($placeType === 'airport') {
            return [
                'open'  => setting('airport_open_time', '05:00'),
                'close' => setting('airport_close_time', '24:00'),
                'label' => 'สนามบิน',
            ];
        }
        return [
            'open'  => setting('open_time', '08:00'),
            'close' => setting('close_time', '20:00'),
            'label' => 'จุดรับ–คืนทั่วไป',
        ];
    }

    /** แปลง "HH:MM" เป็นจำนวนนาทีจากเที่ยงคืน — รองรับ 24:00 = สิ้นวัน */
    public static function hhmmToMinutes(string $t): int
    {
        [$h, $m] = array_pad(explode(':', trim($t)), 2, '0');
        return ((int) $h) * 60 + (int) $m;
    }

    /** เวลานี้อยู่ในช่วงที่รับ–คืนรถได้หรือไม่ — คืน null ถ้าผ่าน / ข้อความถ้าไม่ผ่าน */
    public static function validateServiceTime(string $datetime, string $placeType, string $what): ?string
    {
        $w     = self::serviceWindow($placeType);
        $open  = self::hhmmToMinutes($w['open']);
        $close = self::hhmmToMinutes($w['close']);
        $mins  = (int) date('H', strtotime($datetime)) * 60 + (int) date('i', strtotime($datetime));

        if ($mins < $open || $mins > $close) {
            return sprintf('%sได้เฉพาะเวลา %s–%s น. (%s)', $what, $w['open'], $w['close'], $w['label']);
        }
        return null;
    }

    /** ตรวจความถูกต้องของช่วงเวลาที่ลูกค้าเลือก — คืน null ถ้าผ่าน */
    public static function validatePeriod(string $pickup, string $return, string $pickType = 'shop', string $retType = 'shop'): ?string
    {
        $a = strtotime($pickup);
        $b = strtotime($return);
        if (!$a || !$b) {
            return 'รูปแบบวันที่ไม่ถูกต้อง';
        }
        if ($b <= $a) {
            return 'วันคืนรถต้องหลังวันรับรถ';
        }

        // ช่วงเวลาทำการ — สนามบินกว้างกว่าจุดอื่น
        $pickupError = self::validateServiceTime($pickup, $pickType, 'รับรถ');
        if ($pickupError) {
            return $pickupError;
        }
        $returnError = self::validateServiceTime($return, $retType, 'คืนรถ');
        if ($returnError) {
            return $returnError;
        }

        $leadHours = (int) setting('booking_lead_hours', '3');
        if ($a < time() + $leadHours * 3600) {
            return "กรุณาจองล่วงหน้าอย่างน้อย {$leadHours} ชั่วโมง";
        }
        $minDays = (int) setting('min_rental_days', '1');
        $days    = PricingService::rentalDays($pickup, $return);
        if ($days < $minDays) {
            return "ระยะเวลาเช่าขั้นต่ำ {$minDays} วัน";
        }
        if ($days > 365) {
            return 'ระยะเวลาเช่าเกิน 1 ปี กรุณาติดต่อเจ้าหน้าที่';
        }
        return null;
    }
}
