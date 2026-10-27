<?php
namespace App\Services;

use App\Core\Database;
use App\Models\Car;
use App\Models\Payment;

/** ตัวเลขหน้าภาพรวมหลังบ้าน — อ่านอย่างเดียว */
final class DashboardService
{
    private const TH_MONTHS = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    /** รายได้รับจริงในช่วงวัน: เงินที่อนุมัติแล้ว ไม่รวมเงินประกัน และหักที่คืนลูกค้า */
    public static function revenueCollected(string $from, string $to): float
    {
        return round((float) Database::value(
            "SELECT COALESCE(SUM(" . Payment::REVENUE_SQL . "), 0) FROM payments
              WHERE status = 'approved' AND DATE(COALESCE(paid_at, created_at)) BETWEEN :f AND :t",
            ['f' => $from, 't' => $to]
        ), 2);
    }

    /** มูลค่างานที่รับไว้ (ค่าเช่ารวม ไม่นับที่ยกเลิก) ของการจองที่รับรถในช่วงวันนี้ */
    public static function bookedValue(string $from, string $to): float
    {
        return round((float) Database::value(
            "SELECT COALESCE(SUM(grand_total), 0) FROM bookings WHERE status <> 'cancelled' AND DATE(pickup_at) BETWEEN :f AND :t",
            ['f' => $from, 't' => $to]
        ), 2);
    }

    /** วันเช่าต่อคันในช่วง (นับตามเวลาจริงที่ทับช่วง ไม่ใช่วันในปฏิทิน) car_id => วัน */
    public static function daysByCar(string $from, string $to): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT car_id, SUM(GREATEST(0, TIMESTAMPDIFF(MINUTE, GREATEST(pickup_at, :f), LEAST(return_at, :t)))) / 1440 AS d
               FROM bookings WHERE status <> 'cancelled' AND pickup_at < :t2 AND return_at > :f2 GROUP BY car_id",
            ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59', 't2' => $to . ' 23:59:59', 'f2' => $from . ' 00:00:00']
        ) as $row) {
            $out[(int) $row['car_id']] = (float) $row['d'];
        }
        return $out;
    }

    private static function bookingsOn(string $column, array $statuses, string $day): array
    {
        $in = implode(',', array_map(static fn($s) => "'" . $s . "'", $statuses));
        return Database::all(
            "SELECT b.*, c.brand, c.model, c.plate FROM bookings b JOIN cars c ON c.id = b.car_id
              WHERE b.status IN ($in) AND DATE(b.$column) = :d ORDER BY b.$column",
            ['d' => $day]
        );
    }

    public static function overdue(): array
    {
        return Database::all(
            "SELECT b.*, c.brand, c.model, c.plate FROM bookings b JOIN cars c ON c.id = b.car_id
              WHERE b.status = 'picked_up' AND b.return_at < NOW() ORDER BY b.return_at"
        );
    }

    public static function data(bool $isOwner): array
    {
        $today = date('Y-m-d');
        $mStart = date('Y-m-01');
        $mEnd = date('Y-m-t');
        $daysInMonth = (int) date('t');

        $cars = Database::all("SELECT id, brand, model, plate FROM cars WHERE deleted_at IS NULL AND status <> 'inactive'");
        $totalCars = count($cars);
        $byCar = self::daysByCar($mStart, $mEnd);
        $bookedDays = round(array_sum($byCar), 1);

        $trend = [];
        $perCar = [];
        $revenueMonth = $bookedMonth = 0.0;
        if ($isOwner) {
            $revenueMonth = self::revenueCollected($mStart, $mEnd);
            $bookedMonth = self::bookedValue($mStart, $mEnd);
            for ($i = 5; $i >= 0; $i--) {
                $s = date('Y-m-01', strtotime("-$i months"));
                $trend[] = ['label' => self::TH_MONTHS[(int) date('n', strtotime($s))], 'value' => max(0, self::revenueCollected($s, date('Y-m-t', strtotime($s))))];
            }
            $jobs = [];
            foreach (Database::all(
                "SELECT car_id, COUNT(*) AS jobs, COALESCE(SUM(grand_total), 0) AS revenue FROM bookings
                  WHERE status <> 'cancelled' AND DATE(pickup_at) BETWEEN :f AND :t GROUP BY car_id",
                ['f' => $mStart, 't' => $mEnd]
            ) as $r) {
                $jobs[(int) $r['car_id']] = $r;
            }
            foreach ($cars as $c) {
                $perCar[] = $c + [
                    'jobs'    => (int) ($jobs[$c['id']]['jobs'] ?? 0),
                    'days'    => (int) round($byCar[$c['id']] ?? 0),
                    'revenue' => (float) ($jobs[$c['id']]['revenue'] ?? 0),
                ];
            }
            usort($perCar, static fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        }

        $pending = (int) Database::value("SELECT COUNT(*) FROM bookings WHERE status = 'pending'");
        return [
            'today' => $today, 'isOwner' => $isOwner,
            'revenueMonth' => $revenueMonth, 'bookedMonth' => $bookedMonth,
            'activeNow' => (int) Database::value("SELECT COUNT(*) FROM bookings WHERE status = 'picked_up'"),
            'totalCars' => $totalCars, 'pending' => $pending, 'slipWait' => Payment::pendingCount(),
            'bookedDays' => $bookedDays, 'daysInM' => $daysInMonth,
            'utilization' => $totalCars > 0 ? (int) min(100, round($bookedDays / ($totalCars * $daysInMonth) * 100)) : 0,
            'pickupToday' => self::bookingsOn('pickup_at', ['pending', 'confirmed'], $today),
            'returnToday' => self::bookingsOn('return_at', ['picked_up'], $today),
            'overdue' => self::overdue(),
            'trend' => $trend, 'maxTrend' => max(1, $trend ? max(array_column($trend, 'value')) : 1),
            'perCar' => $perCar, 'maxCarRev' => max(1, $perCar ? (float) max(array_column($perCar, 'revenue')) : 1),
            'carStatus' => array_values(array_filter(array_map(
                static fn($k, $n) => ['status' => $k, 'n' => $n], array_keys(Car::countByStatus()), Car::countByStatus()
            ), static fn($r) => $r['n'] > 0)),
        ];
    }
}
