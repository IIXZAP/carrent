<?php

namespace App\Services;


/**
 * ค่าส่ง–รับรถถึงที่ คิดตามระยะทางจากกรุงเทพ (เที่ยวเดียว)
 *
 * - ระยะแรกไม่คิดเงิน (ค่าเริ่มต้น 30 กม.) ส่วนที่เกินคิดเหมาเป็นช่วง
 * - ตารางราคาเก็บเป็นข้อความในตั้งค่า รูปแบบ "ถึงกม.:ราคา" คั่นด้วย , เพื่อให้แก้เองได้โดยไม่ต้องแตะโค้ด
 *     ตัวอย่าง: 30:0,50:500,80:1000,120:1500,200:2500
 * - ระยะที่เกินช่วงสุดท้าย ให้ติดต่อเจ้าหน้าที่ (ไม่มีราคา)
 */
final class DeliveryFeeService
{
    private const DEFAULT_TIERS = '30:0,50:500,80:1000,120:1500,200:2500';

    /** @return array<int,array{max_km:float,fee:float}> เรียงจากใกล้ไปไกล */
    public static function tiers(): array
    {
        $setting = (string) setting('delivery_tiers', self::DEFAULT_TIERS);

        $tiers = [];
        foreach (explode(',', $setting) as $part) {
            $pair = explode(':', trim($part));
            if (count($pair) !== 2) {
                continue;
            }

            $maxKm = (float) trim($pair[0]);
            $fee   = (float) trim($pair[1]);
            if ($maxKm > 0) {
                $tiers[] = ['max_km' => $maxKm, 'fee' => $fee];
            }
        }

        usort($tiers, [self::class, 'byDistance']);
        return $tiers;
    }

    private static function byDistance(array $a, array $b): int
    {
        return $a['max_km'] <=> $b['max_km'];
    }

    /** ระยะทางที่ส่งฟรี (กม.) */
    public static function freeKm(): float
    {
        return (float) setting('free_delivery_km', '30');
    }

    /** ค่าเดินทางสำหรับระยะทางนี้ (เที่ยวเดียว) — null = เกินระยะที่ให้บริการ ต้องคุยกับเจ้าหน้าที่ */
    public static function feeForKm(float $km): ?float
    {
        if ($km <= self::freeKm()) {
            return 0.0;
        }

        foreach (self::tiers() as $tier) {
            if ($km <= $tier['max_km']) {
                return $tier['fee'];
            }
        }
        return null;
    }

    /** ลูกค้าเลือกส่ง/รับรถถึงที่ แต่ระยะทางเกินพื้นที่ที่ตั้งราคาไว้ — ต้องให้เจ้าหน้าที่เสนอราคาเอง */
    public static function isOutOfRange(array $addons, float $km): bool
    {
        $wantsDelivery = !empty($addons['delivery']) || !empty($addons['return_delivery']);
        return $wantsDelivery && self::feeForKm($km) === null;
    }

    /**
     * ระยะทางส่งรถของการจอง
     * การจองเก่าไม่ได้เก็บระยะไว้ จึงย้อนหาจากค่าส่งที่คิดไปแล้ว
     */
    public static function bookingKm(array $booking): float
    {
        if (isset($booking['delivery_km']) && $booking['delivery_km'] !== '') {
            return (float) $booking['delivery_km'];
        }

        $breakdown = json_decode((string) ($booking['rate_breakdown'] ?? ''), true) ?: [];
        foreach (($breakdown['addons'] ?? []) as $line) {
            $isDelivery = in_array($line['key'] ?? '', ['delivery', 'return_delivery'], true);
            $unitPrice  = (float) ($line['unit'] ?? 0);
            if (!$isDelivery || $unitPrice <= 0) {
                continue;
            }

            foreach (self::tiers() as $tier) {
                if (abs($tier['fee'] - $unitPrice) < 0.01) {
                    return (float) $tier['max_km'];
                }
            }
        }
        return 0.0;
    }

    /** ข้อความอธิบายตารางค่าส่ง ใช้แสดงในหน้าเว็บและเงื่อนไข */
    public static function tiersText(): string
    {
        $freeKm = (int) self::freeKm();
        $parts  = ["ไม่เกิน {$freeKm} กม. ไม่มีค่าใช้จ่าย"];

        $previousKm = $freeKm;
        foreach (self::tiers() as $tier) {
            if ($tier['max_km'] <= $freeKm) {
                continue;
            }
            $parts[] = sprintf('%d–%d กม. %s บาท', $previousKm + 1, (int) $tier['max_km'], number_format($tier['fee']));
            $previousKm = (int) $tier['max_km'];
        }

        $parts[] = "เกิน {$previousKm} กม. กรุณาติดต่อเจ้าหน้าที่";
        return implode(' · ', $parts);
    }
}
