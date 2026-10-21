<?php

namespace App\Services;

use App\Models\Booking;

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
 */
final class PricingService
{
    public const GRACE_HOURS   = 2;   // ผ่อนผันคืนช้า (ชั่วโมง)
    public const DAYS_IN_WEEK  = 7;
    public const DAYS_IN_MONTH = 30;

    /** นับจำนวนวันเช่าจากช่วงเวลา */
    public static function rentalDays(string $pickup, string $return): int
    {
        $a = strtotime($pickup);
        $b = strtotime($return);
        if ($a === false || $b === false || $b <= $a) {
            return 0;
        }
        $hours = ($b - $a) / 3600;
        $days  = (int) ceil(max(0, $hours - self::GRACE_HOURS) / 24);
        return max(1, $days);
    }

    /**
     * คำนวณค่าเช่าสุทธิของรถ 1 คัน ตามจำนวนวัน
     * @return array{total:float,lines:array,avg_day:float}
     */
    public static function calcRental(array $car, int $days): array
    {
        $day   = (float) $car['price_day'];
        $week  = (float) $car['price_week']  > 0 ? (float) $car['price_week']  : $day * self::DAYS_IN_WEEK;
        $month = (float) $car['price_month'] > 0 ? (float) $car['price_month'] : $day * self::DAYS_IN_MONTH;

        if ($days <= 0) {
            return ['total' => 0.0, 'lines' => [], 'avg_day' => 0.0];
        }

        // ลองทุกแบบของ เดือน + สัปดาห์ + วัน ที่ครอบคลุมจำนวนวันเช่า แล้วเลือกยอดที่ถูกที่สุด
        // เริ่มจากรายวันล้วน — เหมาสัปดาห์/เดือนจะถูกเลือกก็ต่อเมื่อถูกกว่าจริง
        // ผลคือราคาไม่มีทางลดลงเมื่อเช่าสั้นลง และไม่มีทางแพงกว่าการเหมาเดือนเต็ม
        $best  = [0, 0, $days];
        $total = $days * $day;
        for ($nM = 0; $nM <= intdiv($days + self::DAYS_IN_MONTH - 1, self::DAYS_IN_MONTH); $nM++) {
            $left = max(0, $days - $nM * self::DAYS_IN_MONTH);
            for ($nW = 0; $nW <= intdiv($left + self::DAYS_IN_WEEK - 1, self::DAYS_IN_WEEK); $nW++) {
                $nD   = max(0, $left - $nW * self::DAYS_IN_WEEK);
                $cost = $nM * $month + $nW * $week + $nD * $day;
                if ($cost < $total - 0.004) {
                    $total = $cost;
                    $best  = [$nM, $nW, $nD];
                }
            }
        }
        [$nM, $nW, $nD] = $best;
        $lines = [];
        if ($nM > 0) {
            $lines[] = ['label' => 'เหมาเดือน (30 วัน)', 'qty' => $nM, 'unit' => $month, 'amount' => $nM * $month];
        }
        if ($nW > 0) {
            $lines[] = ['label' => 'เหมาสัปดาห์ (7 วัน)', 'qty' => $nW, 'unit' => $week, 'amount' => $nW * $week];
        }
        if ($nD > 0) {
            $lines[] = ['label' => 'รายวัน', 'qty' => $nD, 'unit' => $day, 'amount' => $nD * $day];
        }

        return [
            'total'   => round($total, 2),
            'lines'   => $lines,
            'avg_day' => $days > 0 ? round($total / $days, 2) : 0.0,
        ];
    }

    // ------------------------------------------------------------
    // ค่าส่ง–รับรถถึงที่ ตามระยะทางจากกรุงเทพ
    // ------------------------------------------------------------
    /**
     * ตารางค่าเดินทางรับ–ส่งรถ ตามระยะทางจากกรุงเทพ
     *
     * 30 กม. แรกไม่คิดเงิน ส่วนที่เกินคิดเหมาเป็นช่วง
     * เก็บเป็นข้อความในตั้งค่า รูปแบบ "ถึงกม.:ราคา" คั่นด้วย , เพื่อให้แก้ได้เองโดยไม่ต้องแตะโค้ด
     *   ตัวอย่าง: 30:0,50:500,80:1000,120:1500,200:2500
     * ระยะที่เกินช่วงสุดท้าย ให้ติดต่อเจ้าหน้าที่ (คืน null)
     */
    public static function deliveryTiers(): array
    {
        $raw = (string) setting('delivery_tiers', '30:0,50:500,80:1000,120:1500,200:2500');
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $bits = explode(':', trim($part));
            if (count($bits) !== 2) {
                continue;
            }
            $km = (float) trim($bits[0]);
            $fe = (float) trim($bits[1]);
            if ($km > 0) {
                $out[] = ['max_km' => $km, 'fee' => $fe];
            }
        }
        usort($out, static fn($a, $b) => $a['max_km'] <=> $b['max_km']);
        return $out;
    }

    /** ระยะทางที่ส่งฟรี (กม.) */
    public static function freeDeliveryKm(): float
    {
        return (float) setting('free_delivery_km', '30');
    }

    /** ค่าเดินทางสำหรับระยะทางนี้ (เที่ยวเดียว) — null = เกินระยะที่ให้บริการ ต้องคุยกับเจ้าหน้าที่ */
    public static function deliveryFeeForKm(float $km): ?float
    {
        if ($km <= self::freeDeliveryKm()) {
            return 0.0;
        }
        foreach (self::deliveryTiers() as $t) {
            if ($km <= $t['max_km']) {
                return $t['fee'];
            }
        }
        return null;
    }

    /** ลูกค้าเลือกบริการส่ง/รับรถถึงที่ แต่ระยะทางเกินพื้นที่ที่ตั้งราคาไว้ — ต้องให้เจ้าหน้าที่เสนอราคาเอง */
    public static function deliveryOutOfRange(array $addons, float $km): bool
    {
        return (!empty($addons['delivery']) || !empty($addons['return_delivery'])) && self::deliveryFeeForKm($km) === null;
    }

    /** ระยะทางส่งรถของการจอง — การจองก่อนเวอร์ชัน 5 ไม่ได้เก็บไว้ ให้ย้อนหาจากค่าส่งที่คิดไปแล้ว */
    public static function bookingDeliveryKm(array $b): float
    {
        if (isset($b['delivery_km']) && $b['delivery_km'] !== null && $b['delivery_km'] !== '') {
            return (float) $b['delivery_km'];
        }
        $bd = json_decode((string) ($b['rate_breakdown'] ?? ''), true) ?: [];
        foreach (($bd['addons'] ?? []) as $l) {
            if (in_array($l['key'] ?? '', ['delivery', 'return_delivery'], true) && (float) ($l['unit'] ?? 0) > 0) {
                foreach (self::deliveryTiers() as $t) {
                    if (abs($t['fee'] - (float) $l['unit']) < 0.01) {
                        return (float) $t['max_km'];
                    }
                }
            }
        }
        return 0.0;
    }

    /** ข้อความอธิบายตารางค่าส่ง ใช้แสดงในหน้าเว็บและเงื่อนไข */
    public static function deliveryTiersText(): string
    {
        $free = (int) self::freeDeliveryKm();
        $out  = ["ไม่เกิน {$free} กม. ไม่มีค่าใช้จ่าย"];
        $prev = $free;
        foreach (self::deliveryTiers() as $t) {
            if ($t['max_km'] <= $free) {
                continue;
            }
            $out[] = sprintf('%d–%d กม. %s บาท', $prev + 1, (int) $t['max_km'], number_format($t['fee']));
            $prev  = (int) $t['max_km'];
        }
        $out[] = "เกิน {$prev} กม. กรุณาติดต่อเจ้าหน้าที่";
        return implode(' · ', $out);
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
        $lines = [];
        $total = 0.0;

        // ค่าส่งรถคิดตามระยะทาง ไม่ใช่ค่าคงที่ต่อคันอีกต่อไป
        $deliveryFee = self::deliveryFeeForKm($km) ?? 0.0;

        $catalog = [
            'delivery'        => ['ส่งรถถึงที่', $deliveryFee, false],
            'return_delivery' => ['รับรถคืนถึงที่', $deliveryFee, false],
            'driver'          => ['คนขับรถ', (float) $car['driver_fee_day'], true],
            'child_seat'      => ['คาร์ซีทเด็ก', 150.0, true],
            'insurance_plus'  => ['ประกันเสริม ลดค่าเสียหายส่วนแรก', 300.0, true],
            'gps'             => ['GPS / Pocket WiFi', 100.0, true],
        ];

        $isDelivery = ['delivery' => true, 'return_delivery' => true];

        foreach ($catalog as $key => [$label, $price, $perDay]) {
            $qty = (int) ($addons[$key] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            // ค่าส่งรถ 0 บาท (อยู่ในระยะฟรี) ยังต้องขึ้นในใบเสนอราคา
            // เพื่อให้ลูกค้าเห็นว่าได้บริการนี้ และเห็นว่าไม่มีค่าใช้จ่าย
            if ($price <= 0 && !isset($isDelivery[$key])) {
                continue;
            }
            $units  = $perDay ? $qty * $days : $qty;
            $amount = $units * $price;
            $total += $amount;

            $text = $label . ($perDay ? " ({$days} วัน)" : '');
            if (isset($isDelivery[$key]) && $km > 0) {
                $text .= ' — ' . rtrim(rtrim(number_format($km, 1), '0'), '.') . ' กม.';
            }
            if (isset($isDelivery[$key]) && $amount <= 0) {
                $text .= ' (ในระยะฟรี)';
            }

            $lines[] = [
                'key'    => $key,
                'label'  => $text,
                'qty'    => $units,
                'unit'   => $price,
                'amount' => $amount,
            ];
        }

        return ['total' => round($total, 2), 'lines' => $lines];
    }

    // ------------------------------------------------------------
    // ใบเสนอราคาของการจอง
    // ------------------------------------------------------------
    public static function quote(array $car, string $pickup, string $return, array $addons = [], float $discount = 0.0, ?bool $vat = null, float $km = 0.0): array
    {
        $days = self::rentalDays($pickup, $return);
        $rent = self::calcRental($car, $days);
        $add  = self::calcAddons($car, $addons, $days, $km);

        $subtotal = max(0, $rent['total'] + $add['total'] - $discount);

        $vatOn   = $vat === null ? (setting('vat_enabled', '0') === '1') : $vat;
        $vatRate = $vatOn ? (float) setting('vat_rate', '7') : 0.0;
        $vatAmt  = round($subtotal * $vatRate / 100, 2);
        $grand   = round($subtotal + $vatAmt, 2);

        // มัดจำการจอง: ค่าคงที่ต่อคัน ถ้าไม่ได้ตั้งไว้ใช้ค่ากลาง ถ้ายังไม่มีอีกค่อยคิดเป็น %
        // มัดจำก้อนนี้ "หักจากค่าเช่า" ไม่ใช่เก็บเพิ่ม — จ่ายตอนจองเพื่อล็อกรถ
        $depBook = (float) $car['deposit_booking'];
        if ($depBook <= 0) {
            $depBook = (float) setting('booking_deposit', '1000');
        }
        if ($depBook <= 0) {
            $depBook = round($grand * (float) setting('deposit_percent', '20') / 100, 0);
        }
        $depBook = min($depBook, $grand);   // มัดจำต้องไม่เกินยอดค่าเช่า

        $security = (float) $car['deposit'] > 0
            ? (float) $car['deposit']
            : (float) setting('security_deposit_default', '3000');

        return [
            'days'              => $days,
            'rental_lines'      => $rent['lines'],
            'rental_amount'     => $rent['total'],
            'avg_day'           => $rent['avg_day'],
            'addon_lines'       => $add['lines'],
            'addon_amount'      => $add['total'],
            'discount'          => round($discount, 2),
            'subtotal'          => round($subtotal, 2),
            'vat_rate'          => $vatRate,
            'vat_amount'        => $vatAmt,
            'grand_total'       => $grand,
            'deposit_amount'    => $depBook,
            'security_deposit'  => $security,
            // ยอดที่ต้องจ่ายวันรับรถ = ค่าเช่าที่เหลือหลังหักมัดจำ + เงินประกัน
            'balance_on_pickup' => round(max(0, $grand - $depBook), 2),
            'due_on_pickup'     => round(max(0, $grand - $depBook) + $security, 2),
            'delivery_km'       => $km,
        ];
    }

    /** ใบเสนอราคาว่างๆ (ช่วงเวลาไม่ผ่านการตรวจ) — ให้กล่องสรุปราคาแสดงผลได้โดยไม่ error */
    public static function emptyQuote(): array
    {
        return ['days' => 0, 'rental_lines' => [], 'addon_lines' => [], 'discount' => 0, 'subtotal' => 0,
                'vat_rate' => 0, 'vat_amount' => 0, 'grand_total' => 0, 'deposit_amount' => 0,
                'security_deposit' => 0, 'avg_day' => 0];
    }

    // ------------------------------------------------------------
    // คืนรถช้า / น้ำมัน / ระยะทางเกิน
    // ------------------------------------------------------------
    /** เงื่อนไขคืนรถช้า — ค่าเดียวกับที่แสดงในหน้าเงื่อนไข คำถามที่พบบ่อย และสัญญาเช่า */
    public static function latePolicy(): array
    {
        return [
            'grace'    => max(0, (int) setting('late_grace_hours', (string) self::GRACE_HOURS)),
            'full_day' => max(1, (int) setting('late_full_day_hours', '6')),
            'per_hour' => max(0, (float) setting('late_fee_per_hour', '200')),
        ];
    }

    /**
     * ข้อความเงื่อนไขคืนรถช้า — ใช้ที่หน้าเงื่อนไข วิธีเช่า คำถามที่พบบ่อย และสัญญา
     * ข้อความมาจากค่าเดียวกับที่ระบบใช้คิดเงินจริง จึงไม่มีทางที่หน้าเว็บบอกอย่างหนึ่งแต่ระบบคิดอีกอย่าง
     * @return array{grace:string, fee:string}
     */
    public static function latePolicyText(): array
    {
        $p = self::latePolicy();
        return [
            'grace' => 'ผ่อนผันคืนช้าได้ไม่เกิน ' . $p['grace'] . ' ชั่วโมง',
            'fee'   => 'เกิน ' . $p['grace'] . ' ชั่วโมง คิดค่าปรับชั่วโมงละ ' . money0($p['per_hour'])
                     . ' บาท เกิน ' . $p['full_day'] . ' ชั่วโมงคิดเป็นค่าเช่าเพิ่ม 1 วันเต็ม',
        ];
    }

    /** ค่าน้ำมันที่ขาด ต่อ 1 ขีด (1/8 ถัง) */
    public static function fuelFeePerLevel(): float
    {
        return max(0, (float) setting('fuel_fee_per_level', '300'));
    }

    /**
     * คำนวณค่าใช้จ่ายเพิ่มตอนคืนรถจากข้อมูลจริง — เป็น "ยอดแนะนำ" ที่พนักงานปรับหรือยกเว้นได้
     *
     *   คืนช้า   ไม่เกินเวลาผ่อนผัน → ไม่คิด
     *            เกินผ่อนผันแต่ไม่ถึงเกณฑ์เต็มวัน → ชั่วโมงที่เกินผ่อนผัน × ค่าปรับต่อชั่วโมง
     *            เกินเกณฑ์เต็มวัน → ค่าเช่ารายวัน × จำนวนวัน (ปัดขึ้น)
     *   น้ำมัน   ขีดที่ขาดจากตอนรับรถ × ค่าน้ำมันต่อขีด
     *   ระยะทาง  ส่วนที่เกิน (กม.ฟรีต่อวัน × จำนวนวันเช่า) × ค่ากิโลเมตรเกิน (ถ้าตั้งจำกัดระยะไว้)
     *
     * @return array{lines: array<int,array{key:string,label:string,detail:string,amount:float}>, total: float}
     */
    public static function calcReturnCharges(array $b, array $car, string $returnedAt, ?int $kmIn, ?int $fuelIn): array
    {
        $lines = [];
        $pol   = self::latePolicy();

        // ---------- คืนช้า ----------
        // ใช้กติกาเดียวกันซ้ำกับเศษของวัน: ช้า 26 ชม. = 1 วัน + เศษ 2 ชม. (อยู่ในผ่อนผัน) → คิด 1 วัน ไม่ใช่ 2 วัน
        $lateMin = (int) floor((strtotime($returnedAt) - strtotime((string) $b['return_at'])) / 60);
        if ($lateMin > $pol['grace'] * 60) {
            $h = intdiv($lateMin, 60);
            $m = $lateMin % 60;
            $lateTxt = ($h >= 24 ? intdiv($h, 24) . ' วัน ' . ($h % 24) . ' ชม.' : $h . ' ชม.') . ($m ? ' ' . $m . ' นาที' : '');

            $days  = intdiv($lateMin, 1440);
            $rem   = $lateMin % 1440;
            $hours = 0;
            if ($rem > $pol['full_day'] * 60) {
                $days++;
            } elseif ($rem > $pol['grace'] * 60) {
                $hours = (int) ceil($rem / 60) - $pol['grace'];
            }
            $parts = [];
            if ($days > 0) {
                $parts[] = 'ค่าเช่าเพิ่ม ' . $days . ' วัน × ' . money0($car['price_day']) . ' บาท';
            }
            if ($hours > 0) {
                $parts[] = ($days > 0 ? 'เศษ ' : 'ผ่อนผัน ' . $pol['grace'] . ' ชม. คิด ') . $hours . ' ชม. × ' . money0($pol['per_hour']) . ' บาท';
            }
            // ค่าปรับรายชั่วโมงของเศษวันต้องไม่เกินค่าเช่า 1 วัน — ไม่งั้นคืนช้า 5 ชม. อาจแพงกว่าคืนช้า 7 ชม. (ที่คิดเต็มวัน)
            $hourly = min($hours * $pol['per_hour'], (float) $car['price_day']);
            $amount = round($days * (float) $car['price_day'] + $hourly, 2);
            if ($amount > 0) {
                $lines[] = ['key' => 'late', 'label' => 'คืนรถช้า ' . $lateTxt, 'detail' => implode(' + ', $parts), 'amount' => $amount];
            }
        }

        // ---------- น้ำมันขาด ----------
        $fuelOut = $b['fuel_out'] !== null && $b['fuel_out'] !== '' ? (int) $b['fuel_out'] : null;
        $perLv   = self::fuelFeePerLevel();
        if ($fuelOut !== null && $fuelIn !== null && $fuelIn < $fuelOut && $perLv > 0) {
            $lv = $fuelOut - $fuelIn;
            $lines[] = [
                'key'    => 'fuel',
                'label'  => 'น้ำมันขาด ' . $lv . ' ขีด',
                'detail' => 'รับรถ ' . $fuelOut . '/8 คืนรถ ' . $fuelIn . '/8 · ขีดละ ' . money0($perLv) . ' บาท',
                'amount' => round($lv * $perLv, 2),
            ];
        }

        // ---------- ระยะทางเกิน ----------
        $freeKm = (int) setting('free_km_per_day', '0');
        $kmOut  = (int) ($b['mileage_out'] ?? 0);
        if ($freeKm > 0 && $kmOut > 0 && $kmIn !== null && $kmIn > $kmOut) {
            $allow = $freeKm * max(1, (int) $b['rental_days']);
            $over  = ($kmIn - $kmOut) - $allow;
            $perKm = max(0, (float) setting('over_km_fee', '4'));
            if ($over > 0 && $perKm > 0) {
                $lines[] = [
                    'key'    => 'km',
                    'label'  => 'ระยะทางเกิน ' . number_format($over) . ' กม.',
                    'detail' => 'ใช้ไป ' . number_format($kmIn - $kmOut) . ' กม. จากที่รวมในค่าเช่า ' . number_format($allow) . ' กม. · กม.ละ ' . money0($perKm) . ' บาท',
                    'amount' => round($over * $perKm, 2),
                ];
            }
        }

        return ['lines' => $lines, 'total' => round(array_sum(array_column($lines, 'amount')), 2)];
    }

    // ------------------------------------------------------------
    // เช่าต่อ (เลื่อนวันคืน)
    // ------------------------------------------------------------
    /**
     * คิดเงินกรณีเช่าต่อ (เลื่อนวันคืนออกไป)
     *
     * คิดเฉพาะส่วนต่าง: (ค่าเช่า + บริการเสริมรายวัน ของจำนวนวันใหม่) − (ของจำนวนวันเดิม)
     * ค่าส่งรถถึงที่เป็นค่าต่อเที่ยว ไม่เปลี่ยนตามจำนวนวัน จึงไม่ถูกนำมาคิด
     * เช่ายาวขึ้นแล้วเข้าเรตสัปดาห์/เดือน ส่วนต่างจะถูกกว่ารายวันเอง
     *
     * @return array{ok:bool,error:?string,days_old:int,days_new:int,rental_delta:float,addon_delta:float,delta:float,vat:float,charge:float,new_total:float}
     */
    public static function extensionQuote(array $b, array $car, string $newReturn): array
    {
        $out = ['ok' => false, 'error' => null, 'days_old' => (int) $b['rental_days'], 'days_new' => 0,
                'rental_delta' => 0.0, 'addon_delta' => 0.0, 'delta' => 0.0, 'vat' => 0.0, 'charge' => 0.0,
                'new_total' => (float) $b['grand_total']];

        $ts = strtotime($newReturn);
        if ($ts === false) {
            $out['error'] = 'รูปแบบวัน–เวลาไม่ถูกต้อง';
            return $out;
        }
        $newReturn = date('Y-m-d H:i:s', $ts);
        if ($ts <= strtotime((string) $b['return_at'])) {
            $out['error'] = 'วันคืนใหม่ต้องหลังกำหนดคืนเดิม (' . thai_date($b['return_at'], true) . ')';
            return $out;
        }
        // นอกเวลาทำการ: ไม่ห้าม (พนักงานอาจตกลงกับลูกค้าเป็นกรณีพิเศษ เหมือนตอนสร้างการจองจากหลังบ้าน) แต่เตือนให้เห็นก่อนบันทึก
        $warning = AvailabilityService::validateServiceTime($newReturn, Booking::placeType($b['return_place'] ?? ($b['pickup_place'] ?? null)), 'คืนรถ');
        // ช่วงที่ขอเพิ่มต้องไม่ชนการจองถัดไปของรถคันนี้ (นับเวลาพักรถด้วย)
        if (!AvailabilityService::isCarAvailable((int) $b['car_id'], (string) $b['return_at'], $newReturn, (int) $b['id'])) {
            $next = Booking::nextPickupAfter((int) $b['car_id'], (int) $b['id'], (string) $b['return_at']);
            $out['error'] = 'รถคันนี้ไม่ว่างถึงเวลาที่ขอ'
                . ($next ? ' — มีการจองถัดไปรับรถ ' . thai_date($next['pickup_at'], true) . ' และต้องเว้นเวลาพักรถ ' . AvailabilityService::gapHours() . ' ชม.' : '');
            return $out;
        }

        $addons  = json_decode((string) ($b['addons'] ?? ''), true) ?: [];
        $daysOld = self::rentalDays((string) $b['pickup_at'], (string) $b['return_at']);
        $daysNew = self::rentalDays((string) $b['pickup_at'], $newReturn);
        $rentalDelta = self::calcRental($car, $daysNew)['total'] - self::calcRental($car, $daysOld)['total'];
        $addonDelta  = self::calcAddons($car, $addons, $daysNew)['total'] - self::calcAddons($car, $addons, $daysOld)['total'];
        $delta = round(max(0, $rentalDelta) + max(0, $addonDelta), 2);
        $vat   = round($delta * (float) $b['vat_rate'] / 100, 2);

        return [
            'ok' => true, 'error' => null, 'days_old' => $daysOld, 'days_new' => $daysNew,
            'rental_delta' => round(max(0, $rentalDelta), 2), 'addon_delta' => round(max(0, $addonDelta), 2),
            'delta' => $delta, 'vat' => $vat, 'charge' => round($delta + $vat, 2),
            'new_total' => round((float) $b['grand_total'] + $delta + $vat, 2),
            'new_return' => $newReturn,
            'warning' => $warning ?: null,
        ];
    }
}
