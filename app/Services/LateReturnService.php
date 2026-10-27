<?php
namespace App\Services;

use App\Models\Setting;

/**
 * ค่าใช้จ่ายเพิ่มตอนรับคืนรถ: คืนช้า / น้ำมันขาด / ระยะทางเกิน — ฟังก์ชันล้วน ไม่เรียก DB
 * ผลที่ได้เป็น "ยอดแนะนำ" พนักงานปรับจำนวนหรือยกเว้นได้ตอนรับคืน
 * (port จากระบบเดิม ตรวจด้วย tests/golden/late_old.json — 389 เคส ทั้งตัวเลขและข้อความ)
 */
final class LateReturnService
{
    private const MINUTES_PER_DAY = 1440;

    /** กฎจากค่า settings ดิบ (key => ข้อความ) พร้อมค่าตั้งต้น — แยกจาก DB เพื่อให้ทดสอบได้ */
    public static function rulesFromSettings(array $settings): array
    {
        $get = static fn(string $key, string $default): string => isset($settings[$key]) ? (string) $settings[$key] : $default;
        return [
            'grace'           => max(0, (int) $get('late_grace_hours', '2')),
            'full_day'        => max(1, (int) $get('late_full_day_hours', '6')),
            'per_hour'        => max(0.0, (float) $get('late_fee_per_hour', '200')),
            'fuel_fee'        => max(0.0, (float) $get('fuel_fee_per_level', '300')),
            'free_km_per_day' => (int) $get('free_km_per_day', '0'),
            'over_km_fee'     => max(0.0, (float) $get('over_km_fee', '4')),
        ];
    }

    public static function rules(): array
    {
        return self::rulesFromSettings(Setting::all());
    }

    /** ข้อความเงื่อนไขคืนรถช้า (แสดงลูกค้า/สัญญา) — มาจากค่าเดียวกับที่ใช้คิดเงินจริง */
    public static function policyText(?array $rules = null): array
    {
        $rules ??= self::rules();
        return [
            'grace' => 'ผ่อนผันคืนช้าได้ไม่เกิน ' . $rules['grace'] . ' ชั่วโมง',
            'fee'   => 'เกิน ' . $rules['grace'] . ' ชั่วโมง คิดค่าปรับชั่วโมงละ ' . self::money0($rules['per_hour'])
                     . ' บาท เกิน ' . $rules['full_day'] . ' ชั่วโมงคิดเป็นค่าเช่าเพิ่ม 1 วันเต็ม',
        ];
    }

    /**
     * @param array    $booking    ต้องมี return_at, fuel_out (ขีดตอนส่งมอบ|null), mileage_out (ไมล์ตอนส่งมอบ|null), rental_days
     * @param array    $car        ต้องมี price_day
     * @param string   $returnedAt เวลารับคืนจริง (Y-m-d H:i:s)
     * @param int|null $mileageIn  เลขไมล์ตอนคืน
     * @param int|null $fuelIn     น้ำมันตอนคืน (0–8)
     * @param array    $rules      จาก rules() / rulesFromSettings()
     * @return array{lines: array<int,array{key:string,label:string,detail:string,amount:float}>, total: float}
     */
    public static function calculate(array $booking, array $car, string $returnedAt, ?int $mileageIn, ?int $fuelIn, array $rules): array
    {
        $lines = [];
        foreach ([
            self::lateLine($booking, $car, $returnedAt, $rules),
            self::fuelLine($booking, $fuelIn, $rules),
            self::mileageLine($booking, $mileageIn, $rules),
        ] as $line) {
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        return ['lines' => $lines, 'total' => round(array_sum(array_column($lines, 'amount')), 2)];
    }

    // คืนช้า
    //   ไม่เกินเวลาผ่อนผัน → ไม่คิด
    //   เกินผ่อนผันแต่ไม่ถึงเกณฑ์เต็มวัน → ชั่วโมงที่เกินผ่อนผัน × ค่าปรับต่อชั่วโมง
    //   เกินเกณฑ์เต็มวัน → ค่าเช่ารายวัน × จำนวนวัน (ปัดขึ้น)
    private static function lateLine(array $booking, array $car, string $returnedAt, array $rules): ?array
    {
        $minutesLate = (int) floor((strtotime($returnedAt) - strtotime((string) $booking['return_at'])) / 60);
        if ($minutesLate <= $rules['grace'] * 60) {
            return null;
        }

        // ใช้กติกาเดียวกันซ้ำกับเศษของวัน: ช้า 26 ชม. = 1 วัน + เศษ 2 ชม. (อยู่ในผ่อนผัน) → คิด 1 วัน ไม่ใช่ 2 วัน
        $fullDays         = intdiv($minutesLate, self::MINUTES_PER_DAY);
        $remainingMinutes = $minutesLate % self::MINUTES_PER_DAY;
        $extraHours       = 0;

        if ($remainingMinutes > $rules['full_day'] * 60) {
            $fullDays++;
        } elseif ($remainingMinutes > $rules['grace'] * 60) {
            $extraHours = (int) ceil($remainingMinutes / 60) - $rules['grace'];
        }

        $pricePerDay = (float) $car['price_day'];
        // ค่าปรับรายชั่วโมงของเศษวันต้องไม่เกินค่าเช่า 1 วัน ไม่งั้นคืนช้า 5 ชม. อาจแพงกว่าคืนช้า 7 ชม. (ที่คิดเต็มวัน)
        $hourlyFee = min($extraHours * $rules['per_hour'], $pricePerDay);
        $amount    = round($fullDays * $pricePerDay + $hourlyFee, 2);
        if ($amount <= 0) {
            return null;
        }

        return [
            'key'    => 'late',
            'label'  => 'คืนรถช้า ' . self::lateTimeText($minutesLate),
            'detail' => self::lateDetailText($fullDays, $extraHours, $pricePerDay, $rules),
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

    private static function lateDetailText(int $fullDays, int $extraHours, float $pricePerDay, array $rules): string
    {
        $parts = [];
        if ($fullDays > 0) {
            $parts[] = 'ค่าเช่าเพิ่ม ' . $fullDays . ' วัน × ' . self::money0($pricePerDay) . ' บาท';
        }
        if ($extraHours > 0) {
            $prefix  = $fullDays > 0 ? 'เศษ ' : 'ผ่อนผัน ' . $rules['grace'] . ' ชม. คิด ';
            $parts[] = $prefix . $extraHours . ' ชม. × ' . self::money0($rules['per_hour']) . ' บาท';
        }
        return implode(' + ', $parts);
    }

    // น้ำมันขาด: ขีดที่ขาดจากตอนรับรถ × ค่าน้ำมันต่อขีด
    private static function fuelLine(array $booking, ?int $fuelIn, array $rules): ?array
    {
        $hasFuelOut = $booking['fuel_out'] !== null && $booking['fuel_out'] !== '';
        if (!$hasFuelOut || $fuelIn === null || $rules['fuel_fee'] <= 0) {
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
            'detail' => 'รับรถ ' . $fuelOut . '/8 คืนรถ ' . $fuelIn . '/8 · ขีดละ ' . self::money0($rules['fuel_fee']) . ' บาท',
            'amount' => round($missing * $rules['fuel_fee'], 2),
        ];
    }

    // ระยะทางเกิน: ส่วนที่เกิน (กม.ฟรีต่อวัน × จำนวนวันเช่า) × ค่ากิโลเมตรเกิน (ถ้าตั้งจำกัดระยะไว้)
    private static function mileageLine(array $booking, ?int $mileageIn, array $rules): ?array
    {
        $mileageOut = (int) ($booking['mileage_out'] ?? 0);
        if ($rules['free_km_per_day'] <= 0 || $mileageOut <= 0 || $mileageIn === null || $mileageIn <= $mileageOut) {
            return null;
        }
        $allowed = $rules['free_km_per_day'] * max(1, (int) $booking['rental_days']);
        $used    = $mileageIn - $mileageOut;
        $over    = $used - $allowed;
        if ($over <= 0 || $rules['over_km_fee'] <= 0) {
            return null;
        }
        return [
            'key'    => 'km',
            'label'  => 'ระยะทางเกิน ' . number_format($over) . ' กม.',
            'detail' => 'ใช้ไป ' . number_format($used) . ' กม. จากที่รวมในค่าเช่า ' . number_format($allowed) . ' กม. · กม.ละ ' . self::money0($rules['over_km_fee']) . ' บาท',
            'amount' => round($over * $rules['over_km_fee'], 2),
        ];
    }

    private static function money0($amount): string
    {
        return number_format((float) $amount, 0);
    }
}
