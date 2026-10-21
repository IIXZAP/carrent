<?php
/**
 * เครื่องคิดราคาเช่า + ตรวจรถว่าง
 *
 * หลักการคิดราคา (รายวัน + รายสัปดาห์/เดือน):
 *   - นับ "จำนวนวันเช่า" = ceil(ชั่วโมงทั้งหมด / 24) ขั้นต่ำ 1 วัน
 *     มีช่วงผ่อนผัน 2 ชั่วโมง (คืนช้าไม่เกิน 2 ชม. ไม่คิดเพิ่มอีกวัน)
 *   - แตกจำนวนวันเป็น เดือน → สัปดาห์ → วัน แล้วคิดเรตที่ถูกที่สุด
 *     เช่น 40 วัน = 1 เดือน (30) + 1 สัปดาห์ (7) + 3 วัน
 *   - เทียบกับการคิดแบบรายวันล้วน แล้วเลือกยอดที่ "ถูกกว่า" ให้ลูกค้าเสมอ
 *   - ถ้ารถไม่ได้ตั้ง price_week / price_month (เป็น 0) จะ fallback ไปเรตรายวัน
 */
require_once __DIR__ . '/functions.php';

const GRACE_HOURS = 2;   // ผ่อนผันคืนช้า (ชั่วโมง)

/**
 * สูตรนับ "เงินที่เป็นรายได้" จากตาราง payments — ใช้สูตรนี้ทุกที่ ห้ามเขียนซ้ำ
 *   refund                      = คืนค่าเช่าให้ลูกค้า → หักออก
 *   security / security_refund  = เงินประกันรับเข้า–คืนออก → ไม่ใช่รายได้ทั้งคู่
 *   ที่เหลือ (deposit / balance / extra) = รายได้ รวมถึงรายการที่หักจากเงินประกัน (method = deduct)
 */
const PAYMENT_REVENUE_SQL = "CASE WHEN kind = 'refund' THEN -amount WHEN kind IN ('security','security_refund') THEN 0 ELSE amount END";
const DAYS_IN_WEEK  = 7;
const DAYS_IN_MONTH = 30;

/** นับจำนวนวันเช่าจากช่วงเวลา */
function rental_days(string $pickup, string $return): int
{
    $a = strtotime($pickup);
    $b = strtotime($return);
    if ($a === false || $b === false || $b <= $a) {
        return 0;
    }
    $hours = ($b - $a) / 3600;
    $days  = (int) ceil(max(0, $hours - GRACE_HOURS) / 24);
    return max(1, $days);
}

/**
 * คำนวณค่าเช่าสุทธิของรถ 1 คัน ตามจำนวนวัน
 * คืน array: ['total' => , 'lines' => [['label','qty','unit','amount'], ...], 'avg_day' => ]
 */
function calc_rental(array $car, int $days): array
{
    $day   = (float) $car['price_day'];
    $week  = (float) $car['price_week']  > 0 ? (float) $car['price_week']  : $day * DAYS_IN_WEEK;
    $month = (float) $car['price_month'] > 0 ? (float) $car['price_month'] : $day * DAYS_IN_MONTH;

    if ($days <= 0) {
        return ['total' => 0.0, 'lines' => [], 'avg_day' => 0.0];
    }

    // ลองทุกแบบของ เดือน + สัปดาห์ + วัน ที่ครอบคลุมจำนวนวันเช่า แล้วเลือกยอดที่ถูกที่สุด
    // เริ่มจากรายวันล้วน — เหมาสัปดาห์/เดือนจะถูกเลือกก็ต่อเมื่อถูกกว่าจริง
    // ผลคือราคาไม่มีทางลดลงเมื่อเช่าสั้นลง และไม่มีทางแพงกว่าการเหมาเดือนเต็ม
    // (เดิม 27 วันคิดแพงกว่า 30 วัน เพราะเศษสัปดาห์+วันไม่ถูกเทียบกับราคาเหมาเดือน)
    $best = [0, 0, $days];
    $total = $days * $day;
    for ($nM = 0; $nM <= intdiv($days + DAYS_IN_MONTH - 1, DAYS_IN_MONTH); $nM++) {
        $left = max(0, $days - $nM * DAYS_IN_MONTH);
        for ($nW = 0; $nW <= intdiv($left + DAYS_IN_WEEK - 1, DAYS_IN_WEEK); $nW++) {
            $nD   = max(0, $left - $nW * DAYS_IN_WEEK);
            $cost = $nM * $month + $nW * $week + $nD * $day;
            if ($cost < $total - 0.004) {
                $total = $cost;
                $best  = [$nM, $nW, $nD];
            }
        }
    }
    [$nM, $nW, $nD] = $best;
    $lines = [];
    if ($nM > 0) { $lines[] = ['label' => 'เหมาเดือน (30 วัน)',  'qty' => $nM, 'unit' => $month, 'amount' => $nM * $month]; }
    if ($nW > 0) { $lines[] = ['label' => 'เหมาสัปดาห์ (7 วัน)', 'qty' => $nW, 'unit' => $week,  'amount' => $nW * $week]; }
    if ($nD > 0) { $lines[] = ['label' => 'รายวัน',              'qty' => $nD, 'unit' => $day,   'amount' => $nD * $day]; }

    return [
        'total'   => round($total, 2),
        'lines'   => $lines,
        'avg_day' => $days > 0 ? round($total / $days, 2) : 0.0,
    ];
}

/**
 * ตารางค่าเดินทางรับ–ส่งรถ ตามระยะทางจากกรุงเทพ
 *
 * 30 กม. แรกไม่คิดเงิน ส่วนที่เกินคิดเหมาเป็นช่วง
 * เก็บเป็นข้อความในตั้งค่า รูปแบบ "ถึงกม.:ราคา" คั่นด้วย , เพื่อให้แก้ได้เองโดยไม่ต้องแตะโค้ด
 *   ตัวอย่าง: 30:0,50:500,80:1000,120:1500,200:2500
 * ระยะที่เกินช่วงสุดท้าย ให้ติดต่อเจ้าหน้าที่ (คืน null)
 */
function delivery_tiers(): array
{
    $raw  = (string) setting('delivery_tiers', '30:0,50:500,80:1000,120:1500,200:2500');
    $out  = [];
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
function free_delivery_km(): float
{
    return (float) setting('free_delivery_km', '30');
}

/**
 * ค่าเดินทางสำหรับระยะทางนี้ (เที่ยวเดียว)
 * คืน null = เกินระยะที่ให้บริการ ต้องคุยกับเจ้าหน้าที่
 */
function delivery_fee_for_km(float $km): ?float
{
    if ($km <= free_delivery_km()) {
        return 0.0;
    }
    foreach (delivery_tiers() as $t) {
        if ($km <= $t['max_km']) {
            return $t['fee'];
        }
    }
    return null;
}

/** ลูกค้าเลือกบริการส่ง/รับรถถึงที่ แต่ระยะทางเกินพื้นที่ที่ตั้งราคาไว้ — ต้องให้เจ้าหน้าที่เสนอราคาเอง */
function delivery_out_of_range(array $addons, float $km): bool
{
    return (!empty($addons['delivery']) || !empty($addons['return_delivery'])) && delivery_fee_for_km($km) === null;
}

/** ระยะทางส่งรถของการจอง — การจองก่อนเวอร์ชัน 5 ไม่ได้เก็บไว้ ให้ย้อนหาจากค่าส่งที่คิดไปแล้ว */
function booking_delivery_km(array $b): float
{
    if (isset($b['delivery_km']) && $b['delivery_km'] !== null && $b['delivery_km'] !== '') {
        return (float) $b['delivery_km'];
    }
    $bd = json_decode((string) ($b['rate_breakdown'] ?? ''), true) ?: [];
    foreach (($bd['addons'] ?? []) as $l) {
        if (in_array($l['key'] ?? '', ['delivery', 'return_delivery'], true) && (float) ($l['unit'] ?? 0) > 0) {
            foreach (delivery_tiers() as $t) {
                if (abs($t['fee'] - (float) $l['unit']) < 0.01) {
                    return (float) $t['max_km'];
                }
            }
        }
    }
    return 0.0;
}

/** ข้อความอธิบายตารางค่าส่ง ใช้แสดงในหน้าเว็บและเงื่อนไข */
function delivery_tiers_text(): string
{
    $free = (int) free_delivery_km();
    $out  = ["ไม่เกิน {$free} กม. ไม่มีค่าใช้จ่าย"];
    $prev = $free;
    foreach (delivery_tiers() as $t) {
        if ($t['max_km'] <= $free) {
            continue;
        }
        $out[] = sprintf('%d–%d กม. %s บาท', $prev + 1, (int) $t['max_km'], number_format($t['fee']));
        $prev  = (int) $t['max_km'];
    }
    $out[] = "เกิน {$prev} กม. กรุณาติดต่อเจ้าหน้าที่";
    return implode(' · ', $out);
}

/**
 * คิดบริการเสริม
 * $addons = ['delivery'=>1, 'return_delivery'=>1, 'driver'=>1, 'child_seat'=>2, 'insurance_plus'=>1]
 * $km     = ระยะทางจากกรุงเทพ (กม.) ใช้คิดค่าส่ง–รับรถถึงที่
 */
function calc_addons(array $car, array $addons, int $days, float $km = 0.0): array
{
    $lines = [];
    $total = 0.0;

    // ค่าส่งรถคิดตามระยะทาง ไม่ใช่ค่าคงที่ต่อคันอีกต่อไป
    $deliveryFee = delivery_fee_for_km($km) ?? 0.0;

    $catalog = [
        'delivery'        => ['ส่งรถถึงที่',        $deliveryFee, false],
        'return_delivery' => ['รับรถคืนถึงที่',     $deliveryFee, false],
        'driver'          => ['คนขับรถ',            (float) $car['driver_fee_day'], true],
        'child_seat'      => ['คาร์ซีทเด็ก',        150.0, true],
        'insurance_plus'  => ['ประกันเสริม ลดค่าเสียหายส่วนแรก', 300.0, true],
        'gps'             => ['GPS / Pocket WiFi',  100.0, true],
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

/**
 * สรุปใบเสนอราคาของการจอง 1 รายการ
 */
function quote_booking(array $car, string $pickup, string $return, array $addons = [], float $discount = 0.0, ?bool $vat = null, float $km = 0.0): array
{
    $days   = rental_days($pickup, $return);
    $rent   = calc_rental($car, $days);
    $add    = calc_addons($car, $addons, $days, $km);

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
        'days'             => $days,
        'rental_lines'     => $rent['lines'],
        'rental_amount'    => $rent['total'],
        'avg_day'          => $rent['avg_day'],
        'addon_lines'      => $add['lines'],
        'addon_amount'     => $add['total'],
        'discount'         => round($discount, 2),
        'subtotal'         => round($subtotal, 2),
        'vat_rate'         => $vatRate,
        'vat_amount'       => $vatAmt,
        'grand_total'      => $grand,
        'deposit_amount'   => $depBook,
        'security_deposit' => $security,
        // ยอดที่ต้องจ่ายวันรับรถ = ค่าเช่าที่เหลือหลังหักมัดจำ + เงินประกัน
        'balance_on_pickup' => round(max(0, $grand - $depBook), 2),
        'due_on_pickup'     => round(max(0, $grand - $depBook) + $security, 2),
        'delivery_km'       => $km,
    ];
}

// ============================================================
// ตรวจรถว่าง
// ============================================================

/**
 * ระยะเวลาพักรถระหว่างงาน (ชั่วโมง)
 * ใช้ล้างรถ เติมน้ำมัน ตรวจสภาพ ถ่ายรูป และเผื่อลูกค้าคืนช้า
 */
function booking_gap_hours(): int
{
    return max(0, (int) setting('booking_gap_hours', '4'));
}

/**
 * รถคันนี้ว่างในช่วงเวลานี้หรือไม่
 *
 * ชนกันเมื่อ (start < existing_end) AND (end > existing_start)
 * โดยขยายช่วงที่ตรวจออกไปหัวท้ายตามเวลาพักรถ — จองติดกันเป๊ะไม่ได้
 *
 * $ignoreBookingId ใช้ตอน "เช่าต่อ" หรือแอดมินแก้ใบจองเดิม
 * เพื่อไม่ให้ใบจองของตัวเองมาบล็อกตัวเอง
 */
function is_car_available(int $carId, string $pickup, string $return, ?int $ignoreBookingId = null): bool
{
    $car = db_one('SELECT status FROM cars WHERE id = :id', [':id' => $carId]);
    if (!$car || $car['status'] !== 'available') {
        return false;
    }

    // ขยายช่วงที่ตรวจออกไปหัวท้าย เพื่อบังคับให้มีเวลาพักรถคั่นระหว่างงาน
    $gap     = booking_gap_hours();
    $pickChk = $gap > 0 ? date('Y-m-d H:i:s', strtotime($pickup) - $gap * 3600) : $pickup;
    $retChk  = $gap > 0 ? date('Y-m-d H:i:s', strtotime($return) + $gap * 3600) : $return;

    $sql = "SELECT COUNT(*) FROM bookings
            WHERE car_id = :car
              AND status IN ('pending','confirmed','picked_up')
              AND pickup_at < :ret AND return_at > :pick";
    $p = [':car' => $carId, ':pick' => $pickChk, ':ret' => $retChk];
    if ($ignoreBookingId) {
        $sql .= ' AND id <> :ign';
        $p[':ign'] = $ignoreBookingId;
    }
    if ((int) db_val($sql, $p) > 0) {
        return false;
    }

    // รถที่ยังไม่ได้กดคืน และเลยกำหนดคืนมาแล้ว = ยังอยู่กับลูกค้ารายเดิม
    // ต้องถือว่าไม่ว่างจนกว่าจะกดรับคืนจริง ไม่งั้นจะปล่อยให้จองซ้อนกับรถที่ไม่อยู่
    $sqlOver = "SELECT COUNT(*) FROM bookings
                WHERE car_id = :car AND status = 'picked_up' AND return_at < NOW()";
    $pOver   = [':car' => $carId];
    if ($ignoreBookingId) {
        $sqlOver .= ' AND id <> :ign';
        $pOver[':ign'] = $ignoreBookingId;
    }
    if ((int) db_val($sqlOver, $pOver) > 0) {
        return false;
    }

    $blocked = (int) db_val(
        'SELECT COUNT(*) FROM car_blocks WHERE car_id = :car AND start_at < :ret AND end_at > :pick',
        [':car' => $carId, ':pick' => $pickChk, ':ret' => $retChk]
    );
    return $blocked === 0;
}

/** รายการรถที่ว่างทั้งหมดในช่วงเวลา (พร้อมราคาที่คิดแล้ว) */
function available_cars(string $pickup, string $return, ?string $carType = null): array
{
    // ใช้กติกาเดียวกับ is_car_available — เผื่อเวลาพักรถ และกันรถที่ยังไม่ได้กดคืน
    $gap     = booking_gap_hours();
    $pickChk = $gap > 0 ? date('Y-m-d H:i:s', strtotime($pickup) - $gap * 3600) : $pickup;
    $retChk  = $gap > 0 ? date('Y-m-d H:i:s', strtotime($return) + $gap * 3600) : $return;

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
    $p = [':pick' => $pickChk, ':ret' => $retChk, ':pick2' => $pickChk, ':ret2' => $retChk];
    if ($carType) {
        $sql .= ' AND c.car_type = :ct';
        $p[':ct'] = $carType;
    }
    $sql .= ' ORDER BY c.sort_order, c.price_day';

    $cars = db_all($sql, $p);
    $days = rental_days($pickup, $return);
    foreach ($cars as &$c) {
        $q = calc_rental($c, $days);
        $c['_days']    = $days;
        $c['_total']   = $q['total'];
        $c['_avg_day'] = $q['avg_day'];
        $c['_lines']   = $q['lines'];
    }
    return $cars;
}

/** ช่วงวันที่ถูกจองของรถคันหนึ่ง (ใช้วาดปฏิทิน / disable วันในฟอร์ม) */
function car_busy_ranges(int $carId, ?string $from = null, ?string $to = null): array
{
    $from = $from ?: date('Y-m-d');
    $to   = $to   ?: date('Y-m-d', strtotime('+6 months'));

    $rows = db_all(
        "SELECT pickup_at AS start_at, return_at AS end_at, 'booking' AS kind, booking_no AS ref
         FROM bookings
         WHERE car_id = :c AND status IN ('pending','confirmed','picked_up')
           AND return_at >= :f AND pickup_at <= :t
         UNION ALL
         SELECT start_at, end_at, 'block' AS kind, reason AS ref
         FROM car_blocks
         WHERE car_id = :c2 AND end_at >= :f2 AND start_at <= :t2
         ORDER BY start_at",
        [':c' => $carId, ':f' => $from, ':t' => $to, ':c2' => $carId, ':f2' => $from, ':t2' => $to]
    );
    return $rows;
}

/**
 * คำนวณยอดที่ชำระแล้วและสถานะการชำระเงินของการจองใหม่จากรายการเงินที่อนุมัติแล้ว
 *
 * เงินประกัน (security / security_refund) ไม่นับเป็นค่าเช่าที่ชำระ
 * และไม่แตะ bookings.security_deposit — ช่องนั้นคือ "เงินประกันตามเงื่อนไข" ที่ตกลงกับลูกค้า
 * ยอดที่ร้านถืออยู่จริงให้เรียก security_held()
 * (เดิมเขียนทับด้วยยอดที่รับจริง: ลูกค้าวางบางส่วน 1,000 จาก 3,000 แล้วข้อความเตือนและสัญญาจะกลายเป็น 1,000)
 */
function recalc_booking_payment(int $bookingId): void
{
    $paid = (float) db_val(
        'SELECT COALESCE(SUM(' . PAYMENT_REVENUE_SQL . "), 0)
         FROM payments WHERE booking_id = :b AND status = 'approved'",
        [':b' => $bookingId]
    );
    $bk = db_one('SELECT grand_total, extra_charge FROM bookings WHERE id = :b', [':b' => $bookingId]);
    if (!$bk) {
        return;
    }
    $due = (float) $bk['grand_total'] + (float) $bk['extra_charge'];

    // คืนเงินค่าเช่าครบแล้ว (เช่น ยกเลิกแล้วคืนมัดจำ) ยอดสุทธิเป็น 0 — ต้องเป็น "คืนเงินแล้ว" ไม่ใช่ "ยังไม่ชำระ"
    $refunds = (int) db_val("SELECT COUNT(*) FROM payments WHERE booking_id = :b AND status = 'approved' AND kind = 'refund'", [':b' => $bookingId]);

    if ($paid < 0 || ($refunds > 0 && $paid <= 0.001)) $status = 'refunded';
    elseif ($paid <= 0.001)                   $status = 'unpaid';
    elseif ($paid >= $due - 0.01)             $status = 'paid';
    else                                      $status = 'deposit_paid';

    db_update('bookings', ['paid_amount' => round($paid, 2), 'payment_status' => $status], 'id', $bookingId);
}

/** ตรวจความถูกต้องของช่วงเวลาที่ลูกค้าเลือก */
/**
 * ช่วงเวลาที่รับ–คืนรถได้ ตามประเภทจุดนัด
 *
 * 'airport' = สนามบิน เปิดกว้างกว่าเพราะเที่ยวบินมีทั้งเช้ามืดและดึก
 * นอกนั้นยึดเวลาทำการของร้าน
 */
function service_window(string $placeType = 'shop'): array
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
function hhmm_to_min(string $t): int
{
    [$h, $m] = array_pad(explode(':', trim($t)), 2, '0');
    return ((int) $h) * 60 + (int) $m;
}

/**
 * เวลานี้อยู่ในช่วงที่รับ–คืนรถได้หรือไม่
 * คืน null ถ้าผ่าน / คืนข้อความถ้าไม่ผ่าน
 */
function validate_service_time(string $datetime, string $placeType, string $what): ?string
{
    $w     = service_window($placeType);
    $open  = hhmm_to_min($w['open']);
    $close = hhmm_to_min($w['close']);
    $mins  = (int) date('H', strtotime($datetime)) * 60 + (int) date('i', strtotime($datetime));

    if ($mins < $open || $mins > $close) {
        return sprintf('%sได้เฉพาะเวลา %s–%s น. (%s)',
            $what, $w['open'], $w['close'], $w['label']);
    }
    return null;
}

function validate_period(string $pickup, string $return, string $pickType = 'shop', string $retType = 'shop'): ?string
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
    if ($msg = validate_service_time($pickup, $pickType, 'รับรถ')) {
        return $msg;
    }
    if ($msg = validate_service_time($return, $retType, 'คืนรถ')) {
        return $msg;
    }

    $leadHours = (int) setting('booking_lead_hours', '3');
    if ($a < time() + $leadHours * 3600) {
        return "กรุณาจองล่วงหน้าอย่างน้อย {$leadHours} ชั่วโมง";
    }
    $minDays = (int) setting('min_rental_days', '1');
    if (rental_days($pickup, $return) < $minDays) {
        return "ระยะเวลาเช่าขั้นต่ำ {$minDays} วัน";
    }
    if (rental_days($pickup, $return) > 365) {
        return 'ระยะเวลาเช่าเกิน 1 ปี กรุณาติดต่อเจ้าหน้าที่';
    }
    return null;
}

/**
 * รายได้รับจริงในช่วงวันที่ — นับจากเงินที่อนุมัติแล้ว
 * เงินประกันความเสียหายไม่ใช่รายได้ (ต้องคืนลูกค้า) จึงไม่นับ และรายการคืนเงินหักออก
 * ทุกหน้าที่แสดง "รายได้รับจริง" ต้องเรียกฟังก์ชันนี้ ตัวเลขจะได้ตรงกันทั้งระบบ
 */
function revenue_collected(string $from, string $to): float
{
    return (float) db_val(
        'SELECT COALESCE(SUM(' . PAYMENT_REVENUE_SQL . "), 0)
         FROM payments WHERE status = 'approved' AND DATE(approved_at) BETWEEN :a AND :b",
        [':a' => $from, ':b' => $to]
    );
}

/**
 * จำนวนวันเช่าที่ขายได้ในช่วงเวลา แยกรายคัน — นับเฉพาะส่วนที่ทับกับช่วงที่ถาม
 *
 * นับจากเวลาจริง (ชั่วโมง ÷ 24) ไม่ใช่นับจำนวนวันในปฏิทิน:
 * เช่า 10:00 วันที่ 5 ถึง 10:00 วันที่ 8 = 3 วัน ไม่ใช่ 4 วัน
 * ถ้านับตามปฏิทิน อัตราการใช้รถจะสูงเกินจริงทุกการจอง
 *
 * @return array<int,float> car_id => จำนวนวัน
 */
function car_days_sold(string $from, string $to): array
{
    $s = strtotime($from . ' 00:00:00');
    $e = strtotime($to . ' 23:59:59') + 1;
    $out = [];
    foreach (db_all(
        "SELECT car_id, pickup_at, return_at FROM bookings
         WHERE status IN ('confirmed','picked_up','returned','completed')
           AND pickup_at < :e AND return_at > :s",
        [':s' => date('Y-m-d H:i:s', $s), ':e' => date('Y-m-d H:i:s', $e)]
    ) as $b) {
        $a   = max($s, strtotime($b['pickup_at']));
        $z   = min($e, strtotime($b['return_at']));
        $day = max(0, $z - $a) / 86400;
        $out[(int) $b['car_id']] = ($out[(int) $b['car_id']] ?? 0) + $day;
    }
    return $out;
}


// ============================================================
// คืนรถ: ค่าใช้จ่ายเพิ่ม / เช่าต่อ / เงินประกัน
// ============================================================

/** เงื่อนไขคืนรถช้า — ค่าเดียวกับที่แสดงในหน้าเงื่อนไข คำถามที่พบบ่อย และสัญญาเช่า */
function late_policy(): array
{
    return [
        'grace'    => max(0, (int) setting('late_grace_hours', (string) GRACE_HOURS)),
        'full_day' => max(1, (int) setting('late_full_day_hours', '6')),
        'per_hour' => max(0, (float) setting('late_fee_per_hour', '200')),
    ];
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
function calc_return_charges(array $b, array $car, string $returnedAt, ?int $kmIn, ?int $fuelIn): array
{
    $lines = [];
    $pol   = late_policy();

    // ---------- คืนช้า ----------
    // ใช้กติกาเดียวกันซ้ำกับเศษของวัน: ช้า 26 ชม. = 1 วัน + เศษ 2 ชม. (อยู่ในผ่อนผัน) → คิด 1 วัน ไม่ใช่ 2 วัน
    $lateMin = (int) floor((strtotime($returnedAt) - strtotime((string) $b['return_at'])) / 60);
    if ($lateMin > $pol['grace'] * 60) {
        $h = intdiv($lateMin, 60); $m = $lateMin % 60;
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
    $perLv   = fuel_fee_per_level();
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

/** ค่าน้ำมันที่ขาด ต่อ 1 ขีด (1/8 ถัง) */
function fuel_fee_per_level(): float
{
    return max(0, (float) setting('fuel_fee_per_level', '300'));
}

/**
 * ข้อความเงื่อนไขคืนรถช้า — ใช้ที่หน้าเงื่อนไข วิธีเช่า คำถามที่พบบ่อย และสัญญา
 * ข้อความมาจากค่าเดียวกับที่ระบบใช้คิดเงินจริง จึงไม่มีทางที่หน้าเว็บบอกอย่างหนึ่งแต่ระบบคิดอีกอย่าง
 * @return array{grace:string, fee:string}
 */
function late_policy_text(): array
{
    $p = late_policy();
    return [
        'grace' => 'ผ่อนผันคืนช้าได้ไม่เกิน ' . $p['grace'] . ' ชั่วโมง',
        'fee'   => 'เกิน ' . $p['grace'] . ' ชั่วโมง คิดค่าปรับชั่วโมงละ ' . money0($p['per_hour'])
                 . ' บาท เกิน ' . $p['full_day'] . ' ชั่วโมงคิดเป็นค่าเช่าเพิ่ม 1 วันเต็ม',
    ];
}

/** จุดคืนรถของการจองนี้เป็นสนามบินหรือไม่ (ดูจากคำนำหน้าที่ระบบใส่ให้ตอนจอง) */
function booking_place_type(?string $place): string
{
    return $place !== null && str_starts_with(trim($place), '[สนามบิน]') ? 'airport' : 'shop';
}

/**
 * คิดเงินกรณีเช่าต่อ (เลื่อนวันคืนออกไป)
 *
 * คิดเฉพาะส่วนต่าง: (ค่าเช่า + บริการเสริมรายวัน ของจำนวนวันใหม่) − (ของจำนวนวันเดิม)
 * ค่าส่งรถถึงที่เป็นค่าต่อเที่ยว ไม่เปลี่ยนตามจำนวนวัน จึงไม่ถูกนำมาคิด
 * เช่ายาวขึ้นแล้วเข้าเรตสัปดาห์/เดือน ส่วนต่างจะถูกกว่ารายวันเอง
 *
 * @return array{ok:bool,error:?string,days_old:int,days_new:int,rental_delta:float,addon_delta:float,delta:float,vat:float,charge:float,new_total:float}
 */
function extension_quote(array $b, array $car, string $newReturn): array
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
    $warning = validate_service_time($newReturn, booking_place_type($b['return_place'] ?? ($b['pickup_place'] ?? null)), 'คืนรถ');
    // ช่วงที่ขอเพิ่มต้องไม่ชนการจองถัดไปของรถคันนี้ (นับเวลาพักรถด้วย)
    if (!is_car_available((int) $b['car_id'], (string) $b['return_at'], $newReturn, (int) $b['id'])) {
        $next = db_one(
            "SELECT pickup_at FROM bookings WHERE car_id = :c AND id <> :i
               AND status IN ('pending','confirmed','picked_up') AND pickup_at >= :r ORDER BY pickup_at LIMIT 1",
            [':c' => (int) $b['car_id'], ':i' => (int) $b['id'], ':r' => $b['return_at']]
        );
        $out['error'] = 'รถคันนี้ไม่ว่างถึงเวลาที่ขอ'
            . ($next ? ' — มีการจองถัดไปรับรถ ' . thai_date($next['pickup_at'], true) . ' และต้องเว้นเวลาพักรถ ' . booking_gap_hours() . ' ชม.' : '');
        return $out;
    }

    $addons  = json_decode((string) ($b['addons'] ?? ''), true) ?: [];
    $daysOld = rental_days((string) $b['pickup_at'], (string) $b['return_at']);
    $daysNew = rental_days((string) $b['pickup_at'], $newReturn);
    $rentalDelta = calc_rental($car, $daysNew)['total'] - calc_rental($car, $daysOld)['total'];
    $addonDelta  = calc_addons($car, $addons, $daysNew)['total'] - calc_addons($car, $addons, $daysOld)['total'];
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

/**
 * รายการค่าใช้จ่ายเพิ่มตอนคืนรถของการจอง แยกบรรทัด — ใช้แสดงในใบจอง สัญญา ใบเสร็จ และหน้าลูกค้า
 * การจองที่คืนรถก่อนเวอร์ชัน 5 ไม่มีรายการแยก จะได้บรรทัดเดียวจากยอดรวมกับหมายเหตุเดิม
 *
 * @param bool $chargedOnly true = เฉพาะรายการที่เก็บเงินจริง (ตัดรายการที่ยกเว้นออก)
 * @return array<int,array{key:string,label:string,detail:string,amount:float,suggested:float,waived:bool}>
 */
function booking_extra_lines(array $b, bool $chargedOnly = false): array
{
    $out = [];
    foreach (json_decode((string) ($b['extra_breakdown'] ?? ''), true) ?: [] as $l) {
        if (!is_array($l) || !isset($l['label'])) {
            continue;
        }
        $waived = !empty($l['waived']);
        if ($chargedOnly && ($waived || (float) ($l['amount'] ?? 0) <= 0)) {
            continue;
        }
        $out[] = ['key' => (string) ($l['key'] ?? 'other'), 'label' => (string) $l['label'], 'detail' => (string) ($l['detail'] ?? ''),
                  'amount' => (float) ($l['amount'] ?? 0), 'suggested' => (float) ($l['suggested'] ?? 0), 'waived' => $waived];
    }
    if (!$out && (float) ($b['extra_charge'] ?? 0) > 0) {
        $out[] = ['key' => 'other', 'label' => 'ค่าใช้จ่ายเพิ่มเติม', 'detail' => (string) ($b['extra_note'] ?? ''),
                  'amount' => (float) $b['extra_charge'], 'suggested' => (float) $b['extra_charge'], 'waived' => false];
    }
    return $out;
}

/**
 * เงินประกันที่ร้านยังถืออยู่ของการจองนี้
 * = รับเข้า − คืนลูกค้าแล้ว − ที่หักไปชำระยอดค้าง
 */
function security_held(int $bookingId): float
{
    return round((float) db_val(
        "SELECT COALESCE(SUM(CASE WHEN kind = 'security' THEN amount
                                  WHEN kind = 'security_refund' THEN -amount
                                  WHEN method = 'deduct' THEN -amount
                                  ELSE 0 END), 0)
         FROM payments WHERE booking_id = :b AND status = 'approved'",
        [':b' => $bookingId]
    ), 2);
}
