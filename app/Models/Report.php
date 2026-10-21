<?php

namespace App\Models;

use App\Core\Database;

/**
 * คำสั่งค้นข้อมูลเพื่อทำรายงาน (อ่านอย่างเดียว) — ภาพรวม รายงานรายช่วง และรายงานรายเดือน
 */
final class Report
{
    /**
     * จำนวนวันเช่าที่ขายได้ในช่วงเวลา แยกรายคัน — นับเฉพาะส่วนที่ทับกับช่วงที่ถาม
     *
     * นับจากเวลาจริง (ชั่วโมง ÷ 24) ไม่ใช่นับจำนวนวันในปฏิทิน:
     * เช่า 10:00 วันที่ 5 ถึง 10:00 วันที่ 8 = 3 วัน ไม่ใช่ 4 วัน
     * ถ้านับตามปฏิทิน อัตราการใช้รถจะสูงเกินจริงทุกการจอง
     *
     * @return array<int,float> car_id => จำนวนวัน
     */
    public static function daysSoldByCar(string $from, string $to): array
    {
        $s = strtotime($from . ' 00:00:00');
        $e = strtotime($to . ' 23:59:59') + 1;
        $out = [];
        foreach (Database::all(
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

    /** รายได้ต่อคัน (หน้าภาพรวม) */
    public static function revenuePerCar(string $from, string $to): array
    {
        return Database::all(
            "SELECT c.id, c.plate, c.brand, c.model, c.price_day,
                    COALESCE(SUM(b.grand_total + b.extra_charge),0) AS revenue,
                    COUNT(b.id) AS jobs,
                    COALESCE(SUM(b.rental_days),0) AS days
             FROM cars c
             LEFT JOIN bookings b ON b.car_id = c.id
                  AND b.status NOT IN ('cancelled')
                  AND DATE(b.pickup_at) BETWEEN :a AND :b
             WHERE c.status <> 'inactive'
             GROUP BY c.id ORDER BY revenue DESC",
            [':a' => $from, ':b' => $to]
        );
    }

    /** ผลประกอบการรายคัน (หน้ารายงาน) */
    public static function performancePerCar(string $from, string $to): array
    {
        return Database::all(
            "SELECT c.id, c.plate, c.brand, c.model, c.price_day, c.mileage,
                    COALESCE(SUM(b.grand_total + b.extra_charge), 0) AS revenue,
                    COUNT(b.id)                                      AS jobs,
                    COALESCE(SUM(b.rental_days), 0)                  AS rent_days
             FROM cars c
             LEFT JOIN bookings b ON b.car_id = c.id AND b.status NOT IN ('cancelled')
                  AND DATE(b.pickup_at) BETWEEN :a AND :b
             WHERE c.status <> 'inactive'
             GROUP BY c.id ORDER BY revenue DESC",
            [':a' => $from, ':b' => $to]
        );
    }

    public static function byCarType(string $from, string $to): array
    {
        return Database::all(
            "SELECT c.car_type, COUNT(b.id) jobs, COALESCE(SUM(b.grand_total + b.extra_charge),0) revenue
             FROM bookings b JOIN cars c ON c.id = b.car_id
             WHERE b.status NOT IN ('cancelled') AND DATE(b.pickup_at) BETWEEN :a AND :b
             GROUP BY c.car_type ORDER BY revenue DESC",
            [':a' => $from, ':b' => $to]
        );
    }

    /** งานแยกตามช่องทาง นับจากวันที่รับรถ (หน้ารายงาน) */
    public static function bySourcePickup(string $from, string $to): array
    {
        return Database::all(
            "SELECT source, COUNT(*) n, COALESCE(SUM(grand_total + extra_charge),0) revenue FROM bookings
             WHERE status NOT IN ('cancelled') AND DATE(pickup_at) BETWEEN :a AND :b
             GROUP BY source ORDER BY n DESC",
            [':a' => $from, ':b' => $to]
        );
    }

    // ------------------------------------------------------------
    // รายงานรายเดือน
    // ------------------------------------------------------------
    /** จำนวนการจองใหม่ / ยกเลิก ที่สร้างในช่วงวันที่ */
    public static function bookingCreatedCounts(string $from, string $to): array
    {
        $all  = (int) Database::value('SELECT COUNT(*) FROM bookings WHERE DATE(created_at) BETWEEN :a AND :b', [':a' => $from, ':b' => $to]);
        $canc = (int) Database::value("SELECT COUNT(*) FROM bookings WHERE status = 'cancelled' AND DATE(created_at) BETWEEN :a AND :b", [':a' => $from, ':b' => $to]);
        return [$all, $canc];
    }

    /** งานที่รับรถในช่วงวันที่: จำนวน / มูลค่า / ค่าใช้จ่ายเพิ่ม */
    public static function jobsSummary(string $from, string $to): array
    {
        return Database::one(
            "SELECT COUNT(*) n, COALESCE(SUM(grand_total + extra_charge),0) v, COALESCE(SUM(extra_charge),0) x
             FROM bookings WHERE status <> 'cancelled' AND DATE(pickup_at) BETWEEN :a AND :b",
            [':a' => $from, ':b' => $to]
        ) ?? ['n' => 0, 'v' => 0, 'x' => 0];
    }

    public static function monthlyPerCar(string $from, string $to): array
    {
        return Database::all(
            "SELECT c.id, c.plate, c.brand, c.model, c.car_type, c.price_day,
                    COUNT(b.id) jobs, COALESCE(SUM(b.grand_total + b.extra_charge),0) revenue
             FROM cars c
             LEFT JOIN bookings b ON b.car_id = c.id AND b.status <> 'cancelled' AND DATE(b.pickup_at) BETWEEN :a AND :b
             WHERE c.status <> 'inactive'
             GROUP BY c.id",
            [':a' => $from, ':b' => $to]
        );
    }

    /** ที่มาของการจอง นับจากวันที่สร้าง */
    public static function bySourceCreated(string $from, string $to): array
    {
        return Database::all(
            "SELECT source, COUNT(*) n FROM bookings WHERE status <> 'cancelled' AND DATE(created_at) BETWEEN :a AND :b
             GROUP BY source ORDER BY n DESC",
            [':a' => $from, ':b' => $to]
        );
    }

    /** ลูกค้าที่จองครั้งแรกในช่วงนี้ */
    public static function newCustomers(string $from, string $to): int
    {
        return (int) Database::value(
            "SELECT COUNT(DISTINCT b.customer_id) FROM bookings b
             WHERE b.status <> 'cancelled' AND b.customer_id IS NOT NULL AND DATE(b.created_at) BETWEEN :a AND :b
               AND NOT EXISTS (SELECT 1 FROM bookings o WHERE o.customer_id = b.customer_id AND o.status <> 'cancelled' AND o.created_at < :s)",
            [':a' => $from, ':b' => $to, ':s' => $from . ' 00:00:00']
        );
    }

    public static function allCustomers(string $from, string $to): int
    {
        return (int) Database::value(
            "SELECT COUNT(DISTINCT customer_id) FROM bookings
             WHERE status <> 'cancelled' AND customer_id IS NOT NULL AND DATE(created_at) BETWEEN :a AND :b",
            [':a' => $from, ':b' => $to]
        );
    }

    /** [จำนวนครั้งที่รับคืน, จำนวนที่คืนช้าเกิน 1 ชั่วโมง] */
    public static function returnStats(string $from, string $to): array
    {
        $late = (int) Database::value(
            "SELECT COUNT(*) FROM bookings WHERE returned_at IS NOT NULL AND DATE(returned_at) BETWEEN :a AND :b
               AND returned_at > DATE_ADD(return_at, INTERVAL 1 HOUR)",
            [':a' => $from, ':b' => $to]
        );
        $returned = (int) Database::value(
            'SELECT COUNT(*) FROM bookings WHERE returned_at IS NOT NULL AND DATE(returned_at) BETWEEN :a AND :b',
            [':a' => $from, ':b' => $to]
        );
        return [$returned, $late];
    }

    /** วันในสัปดาห์ที่มีลูกค้ารับรถมากที่สุด (DAYOFWEEK: 1 = อาทิตย์) */
    public static function busiestPickupWeekday(string $from, string $to): ?array
    {
        return Database::one(
            "SELECT DAYOFWEEK(pickup_at) d, COUNT(*) n FROM bookings WHERE status <> 'cancelled' AND DATE(pickup_at) BETWEEN :a AND :b
             GROUP BY DAYOFWEEK(pickup_at) ORDER BY n DESC LIMIT 1",
            [':a' => $from, ':b' => $to]
        );
    }
}
