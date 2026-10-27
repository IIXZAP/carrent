<?php
namespace App\Services;

/**
 * เครื่องคิดราคาเช่า — ฟังก์ชันล้วน ไม่เรียก DB (ค่าตั้งต่างๆ ส่งเข้ามาเป็น $rules)
 *
 * กฎ (port จากระบบเดิม ตรวจด้วย tests/golden):
 *   - จำนวนวัน = ceil((ชั่วโมงทั้งหมด − ผ่อนผัน 2 ชม.) / 24) ขั้นต่ำ 1 วัน
 *   - แตกเป็น เดือน(30) / สัปดาห์(7) / วัน แล้วเลือกชุดที่ถูกที่สุด ไม่มีทางแพงกว่ารายวันล้วน
 *   - price_week / price_month เป็น NULL (หรือ 0) = ใช้ราคารายวัน × 7 / × 30
 *   - ยอดเงินปัดเป็นสตางค์ทุกขั้น เพื่อให้ตรงกับ CHECK ของตาราง bookings
 *       subtotal = rental + addon − discount ; grand_total = subtotal + vat
 */
final class PricingService
{
    public const GRACE_HOURS   = 2;
    public const DAYS_IN_WEEK  = 7;
    public const DAYS_IN_MONTH = 30;

    /** บริการที่คิดตามระยะทาง ไม่ใช่ราคาในตาราง addons */
    public const DELIVERY_CODES = ['delivery', 'return_delivery'];

    /** $graceHours = ผ่อนผันคืนช้า (settings.late_grace_hours — ค่าเดียวกับที่ใช้คิดค่าปรับคืนช้า) */
    public static function rentalDays(string $pickup, string $return, int $graceHours = self::GRACE_HOURS): int
    {
        $start = strtotime($pickup);
        $end   = strtotime($return);
        if ($start === false || $end === false || $end <= $start) {
            return 0;
        }
        $hours = ($end - $start) / 3600;
        $days  = (int) ceil(max(0, $hours - max(0, $graceHours)) / 24);
        return max(1, $days);
    }

    /**
     * ค่าเช่าสุทธิของรถ 1 คันตามจำนวนวัน
     * @return array{total:float,lines:array,avg_day:float}
     */
    public static function calcRental(array $car, int $days): array
    {
        if ($days <= 0) {
            return ['total' => 0.0, 'lines' => [], 'avg_day' => 0.0];
        }

        $perDay   = (float) $car['price_day'];
        $perWeek  = self::positiveOr($car['price_week'] ?? null, $perDay * self::DAYS_IN_WEEK);
        $perMonth = self::positiveOr($car['price_month'] ?? null, $perDay * self::DAYS_IN_MONTH);

        [$months, $weeks, $singleDays] = self::cheapestCombination($days, $perDay, $perWeek, $perMonth);

        $lines = [];
        if ($months > 0) {
            $lines[] = ['label' => 'เหมาเดือน (30 วัน)', 'qty' => $months, 'unit' => $perMonth, 'amount' => round($months * $perMonth, 2)];
        }
        if ($weeks > 0) {
            $lines[] = ['label' => 'เหมาสัปดาห์ (7 วัน)', 'qty' => $weeks, 'unit' => $perWeek, 'amount' => round($weeks * $perWeek, 2)];
        }
        if ($singleDays > 0) {
            $lines[] = ['label' => 'รายวัน', 'qty' => $singleDays, 'unit' => $perDay, 'amount' => round($singleDays * $perDay, 2)];
        }

        $total = round($months * $perMonth + $weeks * $perWeek + $singleDays * $perDay, 2);
        return ['total' => $total, 'lines' => $lines, 'avg_day' => round($total / $days, 2)];
    }

    private static function positiveOr($value, float $fallback): float
    {
        return $value !== null && (float) $value > 0 ? (float) $value : $fallback;
    }

    /**
     * ลองทุกชุด เดือน+สัปดาห์+วัน ที่ครอบคลุมจำนวนวัน แล้วเลือกยอดต่ำสุด
     * @return array{0:int,1:int,2:int} [เดือน, สัปดาห์, วัน]
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

    /**
     * คิดบริการเสริม
     * @param array $catalog  code => [name, unit('trip'|'day'), price]  (จากตาราง addons)
     * @param array $selected code => จำนวน   เช่น ['child_seat' => 2, 'delivery' => 1]
     * @param float $deliveryFee ค่าส่งต่อเที่ยว (จาก DeliveryFeeService)
     */
    public static function calcAddons(array $car, array $catalog, array $selected, int $days, float $km, float $deliveryFee): array
    {
        $lines = [];
        $total = 0.0;

        foreach ($catalog as $code => $addon) {
            $qty = (int) ($selected[$code] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $isDelivery = in_array($code, self::DELIVERY_CODES, true);
            $perDay     = $addon['unit'] === 'day';

            if ($isDelivery) {
                $price = $deliveryFee;
            } elseif ($code === 'driver' && $car['driver_fee_day'] !== null) {
                $price = (float) $car['driver_fee_day'];
            } else {
                $price = (float) $addon['price'];
            }

            // ราคา 0 ของบริการทั่วไปไม่ต้องขึ้นบิล ; ค่าส่งฟรียังต้องขึ้นให้ลูกค้าเห็นว่าได้บริการ
            if ($price <= 0 && !$isDelivery) {
                continue;
            }

            $units  = $perDay ? $qty * $days : $qty;
            $amount = round($units * $price, 2);
            $total += $amount;

            $lines[] = [
                'key'    => $code,
                'label'  => self::addonLabel($addon['name'], $perDay, $days, $isDelivery, $km, $amount),
                'qty'    => $units,
                'unit'   => $price,
                'amount' => $amount,
            ];
        }
        return ['total' => round($total, 2), 'lines' => $lines];
    }

    /** ป้ายชื่อบริการเสริมบนใบเสนอราคา/ใบจอง (ใช้ซ้ำตอนพนักงานปรับค่าส่ง) */
    public static function addonLabel(string $name, bool $perDay, int $days, bool $isDelivery, float $km, float $amount): string
    {
        $label = $name . ($perDay ? " ({$days} วัน)" : '');
        if ($isDelivery && $km > 0) {
            $label .= ' — ' . rtrim(rtrim(number_format($km, 1), '0'), '.') . ' กม.';
        }
        if ($isDelivery && $amount <= 0) {
            $label .= ' (ในระยะฟรี)';
        }
        return $label;
    }

    /**
     * ใบเสนอราคาของการจอง
     *
     * $rules: vat_enabled(bool) vat_rate booking_deposit deposit_percent security_deposit_default
     *         free_km delivery_tiers(array จาก parseTiers) grace_hours(ไม่ใส่ = 2)
     * ถ้า out_of_range = true แปลว่าเลือกส่ง/รับรถถึงที่แต่ไกลเกินระยะที่ตั้งราคาไว้ → ห้ามรับจอง
     */
    public static function quote(array $car, array $catalog, string $pickup, string $return, array $selected, float $discount, float $km, array $rules): array
    {
        $days   = self::rentalDays($pickup, $return, (int) ($rules['grace_hours'] ?? self::GRACE_HOURS));
        $rental = self::calcRental($car, $days);

        $deliveryFee = DeliveryFeeService::feeForKm($km, (float) $rules['free_km'], $rules['delivery_tiers']);
        $wantsDelivery = false;
        foreach (self::DELIVERY_CODES as $code) {
            $wantsDelivery = $wantsDelivery || (int) ($selected[$code] ?? 0) > 0;
        }
        $outOfRange = $wantsDelivery && $deliveryFee === null;
        // เลือกส่ง/รับรถถึงที่ต้องบอกระยะทาง ไม่งั้นค่าส่งจะกลายเป็น 0 (ในระยะฟรี) โดยที่ไม่มีใครรู้ว่าจริงๆ ไกลแค่ไหน
        $needsKm = $wantsDelivery && $km <= 0;

        $addon = self::calcAddons($car, $catalog, $selected, $days, $km, $deliveryFee ?? 0.0);

        // ส่วนลดเกินยอดไม่ได้ (ไม่งั้น subtotal ติดลบ ผิด CHECK ของตาราง)
        $gross      = round($rental['total'] + $addon['total'], 2);
        $discount   = min(max(0.0, round($discount, 2)), $gross);
        $subtotal   = round($gross - $discount, 2);
        $vatRate    = $rules['vat_enabled'] ? (float) $rules['vat_rate'] : 0.0;
        $vatAmount  = round($subtotal * $vatRate / 100, 2);
        $grandTotal = round($subtotal + $vatAmount, 2);

        $bookingDeposit  = self::bookingDeposit($car, $grandTotal, $rules);
        $securityDeposit = self::securityDeposit($car, $rules);
        $balanceOnPickup = round(max(0, $grandTotal - $bookingDeposit), 2);

        return [
            'days'              => $days,
            'rental_lines'      => $rental['lines'],
            'rental_amount'     => $rental['total'],
            'avg_day'           => $rental['avg_day'],
            'addon_lines'       => $addon['lines'],
            'addon_amount'      => $addon['total'],
            'discount'          => $discount,
            'subtotal'          => $subtotal,
            'vat_rate'          => $vatRate,
            'vat_amount'        => $vatAmount,
            'grand_total'       => $grandTotal,
            'deposit_amount'    => $bookingDeposit,
            'security_deposit'  => $securityDeposit,
            // วันรับรถจ่าย = ค่าเช่าที่เหลือหลังหักมัดจำ + เงินประกัน (ประกันคืนเมื่อคืนรถ)
            'balance_on_pickup' => $balanceOnPickup,
            'due_on_pickup'     => round($balanceOnPickup + $securityDeposit, 2),
            'delivery_km'       => $km,
            'out_of_range'      => $outOfRange,
            'needs_km'          => $needsKm,
        ];
    }

    /** มัดจำจอง (หักจากค่าเช่า ไม่ใช่เก็บเพิ่ม): ตามรถ → ค่ากลางร้าน → % ของยอดรวม ; ไม่เกินยอดรวม */
    private static function bookingDeposit(array $car, float $grandTotal, array $rules): float
    {
        $deposit = (float) $car['booking_deposit'];
        if ($deposit <= 0) {
            $deposit = (float) $rules['booking_deposit'];
        }
        if ($deposit <= 0) {
            $deposit = round($grandTotal * (float) $rules['deposit_percent'] / 100, 0);
        }
        return round(min($deposit, $grandTotal), 2);
    }

    /** เงินประกันความเสียหาย (คืนเมื่อคืนรถ): ตามรถ → ค่ากลางร้าน */
    private static function securityDeposit(array $car, array $rules): float
    {
        if ((float) $car['security_deposit'] > 0) {
            return (float) $car['security_deposit'];
        }
        return (float) $rules['security_deposit_default'];
    }
}
