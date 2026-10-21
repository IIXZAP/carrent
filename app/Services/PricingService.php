<?php

namespace App\Services;

/**
 * เครื่องคิดราคาเช่า
 *
 * หลักการคิดราคา (รายวัน + รายสัปดาห์/เดือน):
 *   - นับ "จำนวนวันเช่า" = ceil(ชั่วโมงทั้งหมด / 24) ขั้นต่ำ 1 วัน
 *     มีช่วงผ่อนผัน 2 ชั่วโมง (คืนช้าไม่เกิน 2 ชม. ไม่คิดเพิ่มอีกวัน)
 *   - แตกจำนวนวันเป็น เดือน → สัปดาห์ → วัน แล้วคิดเรตที่ถูกที่สุด
 *     เช่น 40 วัน = 1 เดือน (30) + 1 สัปดาห์ (7) + 3 วัน
 *   - เทียบกับการคิดแบบรายวันล้วน แล้วเลือกยอดที่ "ถูกกว่า" ให้ลูกค้าเสมอ
 *   - ถ้ารถไม่ได้ตั้ง price_week / price_month (เป็น 0) จะ fallback ไปเรตรายวัน
 *
 * ที่เกี่ยวข้อง:
 *   DeliveryFeeService  ค่าส่ง–รับรถถึงที่
 *   LateReturnService   ค่าใช้จ่ายเพิ่มตอนคืนรถ (คืนช้า น้ำมัน ระยะทาง)
 *   ExtendService       ราคาเช่าต่อ
 */
final class PricingService
{
    public const GRACE_HOURS   = 2;   // ผ่อนผันคืนช้า (ชั่วโมง)
    public const DAYS_IN_WEEK  = 7;
    public const DAYS_IN_MONTH = 30;

    // ------------------------------------------------------------
    // ค่าเช่ารถ
    // ------------------------------------------------------------

    /** นับจำนวนวันเช่าจากช่วงเวลา */
    public static function rentalDays(string $pickup, string $return): int
    {
        $start = strtotime($pickup);
        $end   = strtotime($return);
        if ($start === false || $end === false || $end <= $start) {
            return 0;
        }

        $hours = ($end - $start) / 3600;
        $days  = (int) ceil(max(0, $hours - self::GRACE_HOURS) / 24);
        return max(1, $days);
    }

    /**
     * คำนวณค่าเช่าสุทธิของรถ 1 คัน ตามจำนวนวัน
     * @return array{total:float,lines:array,avg_day:float}
     */
    public static function calcRental(array $car, int $days): array
    {
        if ($days <= 0) {
            return ['total' => 0.0, 'lines' => [], 'avg_day' => 0.0];
        }

        $perDay   = (float) $car['price_day'];
        $perWeek  = (float) $car['price_week'] > 0 ? (float) $car['price_week'] : $perDay * self::DAYS_IN_WEEK;
        $perMonth = (float) $car['price_month'] > 0 ? (float) $car['price_month'] : $perDay * self::DAYS_IN_MONTH;

        [$months, $weeks, $singleDays] = self::cheapestCombination($days, $perDay, $perWeek, $perMonth);

        $lines = [];
        if ($months > 0) {
            $lines[] = ['label' => 'เหมาเดือน (30 วัน)', 'qty' => $months, 'unit' => $perMonth, 'amount' => $months * $perMonth];
        }
        if ($weeks > 0) {
            $lines[] = ['label' => 'เหมาสัปดาห์ (7 วัน)', 'qty' => $weeks, 'unit' => $perWeek, 'amount' => $weeks * $perWeek];
        }
        if ($singleDays > 0) {
            $lines[] = ['label' => 'รายวัน', 'qty' => $singleDays, 'unit' => $perDay, 'amount' => $singleDays * $perDay];
        }

        $total = $months * $perMonth + $weeks * $perWeek + $singleDays * $perDay;
        return [
            'total'   => round($total, 2),
            'lines'   => $lines,
            'avg_day' => round($total / $days, 2),
        ];
    }

    /**
     * หาว่าควรเหมากี่เดือน กี่สัปดาห์ กี่วัน ถึงจะถูกที่สุด
     *
     * ลองทุกแบบของ เดือน + สัปดาห์ + วัน ที่ครอบคลุมจำนวนวันเช่า แล้วเลือกยอดต่ำสุด
     * เริ่มจากรายวันล้วน — เหมาสัปดาห์/เดือนจะถูกเลือกก็ต่อเมื่อถูกกว่าจริง
     * ผลคือราคาไม่มีทางลดลงเมื่อเช่าสั้นลง และไม่มีทางแพงกว่าการเหมาเดือนเต็ม
     *
     * @return array{0:int,1:int,2:int} [จำนวนเดือน, จำนวนสัปดาห์, จำนวนวัน]
     */
    private static function cheapestCombination(int $days, float $perDay, float $perWeek, float $perMonth): array
    {
        $best     = [0, 0, $days];
        $bestCost = $days * $perDay;

        $maxMonths = intdiv($days + self::DAYS_IN_MONTH - 1, self::DAYS_IN_MONTH);
        for ($months = 0; $months <= $maxMonths; $months++) {
            $daysLeft = max(0, $days - $months * self::DAYS_IN_MONTH);

            $maxWeeks = intdiv($daysLeft + self::DAYS_IN_WEEK - 1, self::DAYS_IN_WEEK);
            for ($weeks = 0; $weeks <= $maxWeeks; $weeks++) {
                $singleDays = max(0, $daysLeft - $weeks * self::DAYS_IN_WEEK);
                $cost       = $months * $perMonth + $weeks * $perWeek + $singleDays * $perDay;

                if ($cost < $bestCost - 0.004) {
                    $bestCost = $cost;
                    $best     = [$months, $weeks, $singleDays];
                }
            }
        }

        return $best;
    }

    // ------------------------------------------------------------
    // บริการเสริม
    // ------------------------------------------------------------

    /** รายการบริการเสริมที่เลือกได้: key => [ชื่อ, ราคา(-1 = คิดตามระยะทาง), หน่วย] */
    public static function addonCatalog(array $car): array
    {
        return [
            'delivery'        => ['ส่งรถถึงที่', -1.0, 'ครั้ง'],
            'return_delivery' => ['รับรถคืนถึงที่', -1.0, 'ครั้ง'],
            'driver'          => ['คนขับรถ', (float) $car['driver_fee_day'], 'วัน'],
            'child_seat'      => ['คาร์ซีทเด็ก', 150.0, 'วัน'],
            'insurance_plus'  => ['ประกันเสริม ลดค่าเสียหายส่วนแรก', 300.0, 'วัน'],
            'gps'             => ['GPS / Pocket WiFi', 100.0, 'วัน'],
        ];
    }

    /**
     * คิดบริการเสริม
     * $addons = ['delivery'=>1, 'return_delivery'=>1, 'driver'=>1, 'child_seat'=>2, 'insurance_plus'=>1]
     * $km     = ระยะทางจากกรุงเทพ (กม.) ใช้คิดค่าส่ง–รับรถถึงที่
     */
    public static function calcAddons(array $car, array $addons, int $days, float $km = 0.0): array
    {
        // ค่าส่งรถคิดตามระยะทาง ไม่ใช่ค่าคงที่ต่อคัน
        $deliveryFee = DeliveryFeeService::feeForKm($km) ?? 0.0;

        // key => [ชื่อ, ราคาต่อหน่วย, คิดต่อวันหรือไม่, เป็นบริการส่ง–รับรถหรือไม่]
        $catalog = [
            'delivery'        => ['ส่งรถถึงที่', $deliveryFee, false, true],
            'return_delivery' => ['รับรถคืนถึงที่', $deliveryFee, false, true],
            'driver'          => ['คนขับรถ', (float) $car['driver_fee_day'], true, false],
            'child_seat'      => ['คาร์ซีทเด็ก', 150.0, true, false],
            'insurance_plus'  => ['ประกันเสริม ลดค่าเสียหายส่วนแรก', 300.0, true, false],
            'gps'             => ['GPS / Pocket WiFi', 100.0, true, false],
        ];

        $lines = [];
        $total = 0.0;

        foreach ($catalog as $key => [$name, $price, $chargedPerDay, $isDelivery]) {
            $quantity = (int) ($addons[$key] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            // ค่าส่งรถ 0 บาท (อยู่ในระยะฟรี) ยังต้องขึ้นในใบเสนอราคา
            // เพื่อให้ลูกค้าเห็นว่าได้บริการนี้ และเห็นว่าไม่มีค่าใช้จ่าย
            if ($price <= 0 && !$isDelivery) {
                continue;
            }

            $units  = $chargedPerDay ? $quantity * $days : $quantity;
            $amount = $units * $price;
            $total += $amount;

            $lines[] = [
                'key'    => $key,
                'label'  => self::addonLabel($name, $chargedPerDay, $days, $isDelivery, $km, $amount),
                'qty'    => $units,
                'unit'   => $price,
                'amount' => $amount,
            ];
        }

        return ['total' => round($total, 2), 'lines' => $lines];
    }

    private static function addonLabel(string $name, bool $chargedPerDay, int $days, bool $isDelivery, float $km, float $amount): string
    {
        $label = $name . ($chargedPerDay ? " ({$days} วัน)" : '');

        if ($isDelivery && $km > 0) {
            $label .= ' — ' . rtrim(rtrim(number_format($km, 1), '0'), '.') . ' กม.';
        }
        if ($isDelivery && $amount <= 0) {
            $label .= ' (ในระยะฟรี)';
        }
        return $label;
    }

    // ------------------------------------------------------------
    // ใบเสนอราคาของการจอง
    // ------------------------------------------------------------

    public static function quote(array $car, string $pickup, string $return, array $addons = [], float $discount = 0.0, ?bool $vat = null, float $km = 0.0): array
    {
        $days   = self::rentalDays($pickup, $return);
        $rental = self::calcRental($car, $days);
        $addon  = self::calcAddons($car, $addons, $days, $km);

        $subtotal = max(0, $rental['total'] + $addon['total'] - $discount);

        // $vat = null ให้ตามตั้งค่าร้าน
        $vatEnabled = $vat === null ? (setting('vat_enabled', '0') === '1') : $vat;
        $vatRate    = $vatEnabled ? (float) setting('vat_rate', '7') : 0.0;
        $vatAmount  = round($subtotal * $vatRate / 100, 2);
        $grandTotal = round($subtotal + $vatAmount, 2);

        $bookingDeposit  = self::bookingDeposit($car, $grandTotal);
        $securityDeposit = self::securityDeposit($car);
        $balanceOnPickup = round(max(0, $grandTotal - $bookingDeposit), 2);

        return [
            'days'              => $days,
            'rental_lines'      => $rental['lines'],
            'rental_amount'     => $rental['total'],
            'avg_day'           => $rental['avg_day'],
            'addon_lines'       => $addon['lines'],
            'addon_amount'      => $addon['total'],
            'discount'          => round($discount, 2),
            'subtotal'          => round($subtotal, 2),
            'vat_rate'          => $vatRate,
            'vat_amount'        => $vatAmount,
            'grand_total'       => $grandTotal,
            'deposit_amount'    => $bookingDeposit,
            'security_deposit'  => $securityDeposit,
            // ยอดที่ต้องจ่ายวันรับรถ = ค่าเช่าที่เหลือหลังหักมัดจำ + เงินประกัน
            'balance_on_pickup' => $balanceOnPickup,
            'due_on_pickup'     => round($balanceOnPickup + $securityDeposit, 2),
            'delivery_km'       => $km,
        ];
    }

    /**
     * มัดจำการจอง: ค่าคงที่ต่อคัน → ไม่ได้ตั้งไว้ใช้ค่ากลางของร้าน → ยังไม่มีอีกค่อยคิดเป็น %
     * มัดจำก้อนนี้ "หักจากค่าเช่า" ไม่ใช่เก็บเพิ่ม จ่ายตอนจองเพื่อล็อกรถ
     */
    private static function bookingDeposit(array $car, float $grandTotal): float
    {
        $deposit = (float) $car['deposit_booking'];
        if ($deposit <= 0) {
            $deposit = (float) setting('booking_deposit', '1000');
        }
        if ($deposit <= 0) {
            $deposit = round($grandTotal * (float) setting('deposit_percent', '20') / 100, 0);
        }

        // มัดจำต้องไม่เกินยอดค่าเช่า
        return min($deposit, $grandTotal);
    }

    /** เงินประกันความเสียหาย (คืนเมื่อคืนรถ): ตามรถคันนั้น ไม่ได้ตั้งไว้ใช้ค่ากลางของร้าน */
    private static function securityDeposit(array $car): float
    {
        if ((float) $car['deposit'] > 0) {
            return (float) $car['deposit'];
        }
        return (float) setting('security_deposit_default', '3000');
    }

    /** ใบเสนอราคาว่างๆ (ช่วงเวลาไม่ผ่านการตรวจ) — ให้กล่องสรุปราคาแสดงผลได้โดยไม่ error */
    public static function emptyQuote(): array
    {
        return [
            'days' => 0, 'rental_lines' => [], 'addon_lines' => [], 'discount' => 0, 'subtotal' => 0,
            'vat_rate' => 0, 'vat_amount' => 0, 'grand_total' => 0, 'deposit_amount' => 0,
            'security_deposit' => 0, 'avg_day' => 0,
        ];
    }
}
