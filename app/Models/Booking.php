<?php
namespace App\Models;

use App\Core\Database;

/** (ตอนนี้มีเฉพาะคำถามเรื่องรถว่าง — การสร้าง/แก้ไขการจองมาในขั้นถัดไป) */
final class Booking
{
    /** จำนวนการจองที่ทับช่วง [from, to) — นับเฉพาะที่ยังกินคิวรถ */
    public static function conflictCount(int $carId, string $from, string $to, ?int $ignoreId = null): int
    {
        $sql = "SELECT COUNT(*) FROM bookings
                 WHERE car_id = :car
                   AND status IN ('pending','confirmed','picked_up')
                   AND pickup_at < :to_at AND return_at > :from_at";
        $params = ['car' => $carId, 'from_at' => $from, 'to_at' => $to];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ign';
            $params['ign'] = $ignoreId;
        }
        return (int) Database::value($sql, $params);
    }

    /** รถที่รับไปแล้วแต่เลยกำหนดคืนและยังไม่กดรับคืน = ยังอยู่กับลูกค้ารายเดิม */
    public static function overdueOutCount(int $carId, ?int $ignoreId = null): int
    {
        $sql = "SELECT COUNT(*) FROM bookings
                 WHERE car_id = :car AND status = 'picked_up' AND return_at < NOW()";
        $params = ['car' => $carId];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ign';
            $params['ign'] = $ignoreId;
        }
        return (int) Database::value($sql, $params);
    }

    /** ช่วงที่รถไม่ว่าง (การจอง + ช่วงปิดรถ) ใช้แสดงลูกค้า — ไม่ส่งเลขที่จอง/เหตุผลออกไป */
    public static function busyRanges(int $carId, string $from, string $to): array
    {
        return Database::all(
            "SELECT pickup_at AS start_at, return_at AS end_at
               FROM bookings
              WHERE car_id = :c AND status IN ('pending','confirmed','picked_up')
                AND return_at >= :f AND pickup_at <= :t
             UNION ALL
             SELECT start_at, end_at
               FROM car_blocks
              WHERE car_id = :c2 AND end_at >= :f2 AND start_at <= :t2
             ORDER BY start_at",
            ['c' => $carId, 'f' => $from, 't' => $to, 'c2' => $carId, 'f2' => $from, 't2' => $to]
        );
    }
}
