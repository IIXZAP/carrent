<?php

namespace App\Services;

/**
 * เงื่อนไขและการคิดค่าใช้จ่ายเพิ่มตอนรับคืนรถ: คืนช้า / น้ำมันขาด / ระยะทางเกิน
 *
 * ผลที่ได้เป็น "ยอดแนะนำ" พนักงานปรับจำนวนหรือยกเว้นได้ในฟอร์มรับคืนรถ
 */
final class LateReturnService
{
    private const MINUTES_PER_DAY = 1440;

    /** เงื่อนไขคืนรถช้า — ค่าเดียวกับที่แสดงในหน้าเงื่อนไข คำถามที่พบบ่อย และสัญญาเช่า */
    public static function policy(): array
    {
        return [
            'grace'    => PricingService::graceHours(),
            'full_day' => max(1, (int) setting('late_full_day_hours', '6')),
            'per_hour' => max(0, (float) setting('late_fee_per_hour', '200')),
        ];
    }

    /**
     * ข้อความเงื่อนไขคืนรถช้า ใช้ที่หน้าเงื่อนไข วิธีเช่า คำถามที่พบบ่อย และสัญญา
     * ข้อความมาจากค่าเดียวกับที่ระบบใช้คิดเงินจริง จึงไม่มีทางที่หน้าเว็บบอกอย่างหนึ่งแต่ระบบคิดอีกอย่าง
     * @return array{grace:string, fee:string}
     */
    public static function policyText(): array
    {
        $policy = self::policy();

        return [
            'grace' => 'ผ่อนผันคืนช้าได้ไม่เกิน ' . $policy['grace'] . ' ชั่วโมง',
            'fee'   => 'เกิน ' . $policy['grace'] . ' ชั่วโมง คิดค่าปรับชั่วโมงละ ' . money0($policy['per_hour'])
                     . ' บาท เกิน ' . $policy['full_day'] . ' ชั่วโมงคิดเป็นค่าเช่าเพิ่ม 1 วันเต็ม',
        ];
    }

    /** ค่าน้ำมันที่ขาด ต่อ 1 ขีด (1/8 ถัง) */
    public static function fuelFeePerLevel(): float
    {
        return max(0, (float) setting('fuel_fee_per_level', '300'));
    }

    /**
     * @param array    $booking    การจอง
     * @param array    $car        รถ
     * @param string   $returnedAt เวลารับคืนจริง (Y-m-d H:i:s)
     * @param int|null $mileageIn  เลขไมล์ตอนคืน
     * @param int|null $fuelIn     น้ำมันตอนคืน (0–8)
     * @return array{lines: array<int,array{key:string,label:string,detail:string,amount:float}>, total: float}
     */
    public static function calculate(array $booking, array $car, string $returnedAt, ?int $mileageIn, ?int $fuelIn): array
    {
        $lines = [];

        foreach ([
            self::lateLine($booking, $car, $returnedAt),
            self::fuelLine($booking, $fuelIn),
            self::mileageLine($booking, $mileageIn),
        ] as $line) {
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return ['lines' => $lines, 'total' => round(array_sum(array_column($lines, 'amount')), 2)];
    }

    // ------------------------------------------------------------
    // คืนช้า
    //   ไม่เกินเวลาผ่อนผัน → ไม่คิด
    //   เกินผ่อนผันแต่ไม่ถึงเกณฑ์เต็มวัน → ชั่วโมงที่เกินผ่อนผัน × ค่าปรับต่อชั่วโมง
    //   เกินเกณฑ์เต็มวัน → ค่าเช่ารายวัน × จำนวนวัน (ปัดขึ้น)
    // ------------------------------------------------------------
    private static function lateLine(array $booking, array $car, string $returnedAt): ?array
    {
        $policy      = self::policy();
        $minutesLate = (int) floor((strtotime($returnedAt) - strtotime((string) $booking['return_at'])) / 60);
        if ($minutesLate <= $policy['grace'] * 60) {
            return null;
        }

        // ใช้กติกาเดียวกันซ้ำกับเศษของวัน: ช้า 26 ชม. = 1 วัน + เศษ 2 ชม. (อยู่ในผ่อนผัน) → คิด 1 วัน ไม่ใช่ 2 วัน
        $fullDays         = intdiv($minutesLate, self::MINUTES_PER_DAY);
        $remainingMinutes = $minutesLate % self::MINUTES_PER_DAY;
        $extraHours       = 0;

        if ($remainingMinutes > $policy['full_day'] * 60) {
            $fullDays++;
        } elseif ($remainingMinutes > $policy['grace'] * 60) {
            $extraHours = (int) ceil($remainingMinutes / 60) - $policy['grace'];
        }

        $pricePerDay = (float) $car['price_day'];
        // ค่าปรับรายชั่วโมงของเศษวันต้องไม่เกินค่าเช่า 1 วัน
        // ไม่งั้นคืนช้า 5 ชม. อาจแพงกว่าคืนช้า 7 ชม. (ที่คิดเต็มวัน)
        $hourlyFee = min($extraHours * $policy['per_hour'], $pricePerDay);
        $amount    = round($fullDays * $pricePerDay + $hourlyFee, 2);
        if ($amount <= 0) {
            return null;
        }

        return [
            'key'    => 'late',
            'label'  => 'คืนรถช้า ' . self::lateTimeText($minutesLate),
            'detail' => self::lateDetailText($fullDays, $extraHours, $pricePerDay, $policy),
            'amount' => $amount,
        ];
    }

    /** เช่น "3 ชม. 20 นาที" หรือ "1 วัน 2 ชม." */
    private static function lateTimeText(int $minutesLate): string
    {
        $hours   = intdiv($minutesLate, 60);
        $minutes = $minutesLate % 60;

        $text = $hours >= 24 ? intdiv($hours, 24) . ' วัน ' . ($hours % 24) . ' ชม.' : $hours . ' ชม.';
        return $minutes > 0 ? $text . ' ' . $minutes . ' นาที' : $text;
    }

    private static function lateDetailText(int $fullDays, int $extraHours, float $pricePerDay, array $policy): string
    {
        $parts = [];
        if ($fullDays > 0) {
            $parts[] = 'ค่าเช่าเพิ่ม ' . $fullDays . ' วัน × ' . money0($pricePerDay) . ' บาท';
        }
        if ($extraHours > 0) {
            $prefix  = $fullDays > 0 ? 'เศษ ' : 'ผ่อนผัน ' . $policy['grace'] . ' ชม. คิด ';
            $parts[] = $prefix . $extraHours . ' ชม. × ' . money0($policy['per_hour']) . ' บาท';
        }
        return implode(' + ', $parts);
    }

    // ------------------------------------------------------------
    // น้ำมันขาด: ขีดที่ขาดจากตอนรับรถ × ค่าน้ำมันต่อขีด
    // ------------------------------------------------------------
    private static function fuelLine(array $booking, ?int $fuelIn): ?array
    {
        $hasFuelOut = $booking['fuel_out'] !== null && $booking['fuel_out'] !== '';
        $feePerLevel = self::fuelFeePerLevel();
        if (!$hasFuelOut || $fuelIn === null || $feePerLevel <= 0) {
            return null;
        }

        $fuelOut = (int) $booking['fuel_out'];
        if ($fuelIn >= $fuelOut) {
            return null;
        }

        $missing = $fuelOut - $fuelIn;
        return [
            'key'    => 'fuel',
            'label'  => 'น้ำมันขาด ' . $missing . ' ขีด',
            'detail' => 'รับรถ ' . $fuelOut . '/8 คืนรถ ' . $fuelIn . '/8 · ขีดละ ' . money0($feePerLevel) . ' บาท',
            'amount' => round($missing * $feePerLevel, 2),
        ];
    }

    // ------------------------------------------------------------
    // ระยะทางเกิน: ส่วนที่เกิน (กม.ฟรีต่อวัน × จำนวนวันเช่า) × ค่ากิโลเมตรเกิน (ถ้าตั้งจำกัดระยะไว้)
    // ------------------------------------------------------------
    private static function mileageLine(array $booking, ?int $mileageIn): ?array
    {
        $freeKmPerDay = (int) setting('free_km_per_day', '0');
        $mileageOut   = (int) ($booking['mileage_out'] ?? 0);
        if ($freeKmPerDay <= 0 || $mileageOut <= 0 || $mileageIn === null || $mileageIn <= $mileageOut) {
            return null;
        }

        $feePerKm = max(0, (float) setting('over_km_fee', '4'));
        $allowed  = $freeKmPerDay * max(1, (int) $booking['rental_days']);
        $used     = $mileageIn - $mileageOut;
        $over     = $used - $allowed;
        if ($over <= 0 || $feePerKm <= 0) {
            return null;
        }

        return [
            'key'    => 'km',
            'label'  => 'ระยะทางเกิน ' . number_format($over) . ' กม.',
            'detail' => 'ใช้ไป ' . number_format($used) . ' กม. จากที่รวมในค่าเช่า ' . number_format($allowed) . ' กม. · กม.ละ ' . money0($feePerKm) . ' บาท',
            'amount' => round($over * $feePerKm, 2),
        ];
    }
}
