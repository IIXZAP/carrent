<?php
namespace App\Services;

/**
 * ค่าส่ง–รับรถถึงที่ คิดตามระยะทางจากกรุงเทพ (เที่ยวเดียว) — ไม่เรียก DB
 *
 * ตารางราคาเก็บเป็นข้อความ "ถึงกม.:ราคา" คั่นด้วย , เช่น 30:0,50:500,80:1000
 * ระยะที่เกินช่วงสุดท้าย = ไม่มีราคา (null) ให้ติดต่อเจ้าหน้าที่
 */
final class DeliveryFeeService
{
    /** @return array<int,array{max_km:float,fee:float}> เรียงจากใกล้ไปไกล */
    public static function parseTiers(string $text): array
    {
        $tiers = [];
        foreach (explode(',', $text) as $part) {
            $pair = explode(':', trim($part));
            if (count($pair) !== 2) {
                continue;
            }
            $maxKm = (float) trim($pair[0]);
            if ($maxKm <= 0) {
                continue;
            }
            $tiers[] = ['max_km' => $maxKm, 'fee' => (float) trim($pair[1])];
        }
        usort($tiers, static fn(array $a, array $b): int => $a['max_km'] <=> $b['max_km']);
        return $tiers;
    }

    /** ค่าเดินทางสำหรับระยะทางนี้ — null = เกินระยะที่ให้บริการ */
    public static function feeForKm(float $km, float $freeKm, array $tiers): ?float
    {
        if ($km <= $freeKm) {
            return 0.0;
        }
        foreach ($tiers as $tier) {
            if ($km <= $tier['max_km']) {
                return $tier['fee'];
            }
        }
        return null;
    }

    /** ข้อความอธิบายตารางค่าส่ง ใช้แสดงในหน้าเว็บและเงื่อนไข (รับค่าจาก settings ผ่านผู้เรียก) */
    public static function tiersText(float $freeKm, array $tiers): string
    {
        $free = (int) $freeKm;
        $parts = ["ไม่เกิน {$free} กม. ไม่มีค่าใช้จ่าย"];
        $previousKm = $free;
        foreach ($tiers as $tier) {
            if ($tier['max_km'] <= $free) {
                continue;
            }
            $parts[] = sprintf('%d–%d กม. %s บาท', $previousKm + 1, (int) $tier['max_km'], number_format($tier['fee']));
            $previousKm = (int) $tier['max_km'];
        }
        $parts[] = "เกิน {$previousKm} กม. กรุณาติดต่อเจ้าหน้าที่";
        return implode(' · ', $parts);
    }
}
