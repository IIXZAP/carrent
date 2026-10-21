<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Maintenance;
use App\Models\Payment;
use App\Models\Report;

/** ภาพรวม — ตัวเลขที่ต้องดูทุกเช้า: งานวันนี้ รายได้ อัตราการใช้รถ งานค้าง */
class DashboardController extends AdminController
{
    public function index(): Response
    {
        $today   = date('Y-m-d');
        $mStart  = date('Y-m-01');
        $mEnd    = date('Y-m-t');
        $daysInM = (int) date('t');

        // เจ้าของร้านเท่านั้นที่เห็นตัวเลขรายได้ — พนักงานเห็นเฉพาะงานที่ต้องทำ
        $isOwner = is_admin();

        $totalCars = Car::activeCount();
        $pending   = Booking::countByStatus('pending');
        $slipWait  = Payment::pendingCount();

        // อัตราการใช้รถเดือนนี้ = วันที่ถูกจองรวม / (จำนวนรถ × วันในเดือน)
        // นับจากเวลาเช่าจริง — เดิมนับตามวันในปฏิทิน ทำให้เช่า 1 วันถูกนับเป็น 2 วัน
        $bookedDays  = round(array_sum(Report::daysSoldByCar($mStart, $mEnd)), 1);
        $utilization = $totalCars > 0 ? (int) min(100, round($bookedDays / ($totalCars * $daysInM) * 100)) : 0;

        $reminderDays = (int) setting('reminder_days_before', '30');

        $trend = [];
        $perCar = [];
        $revenueMonth = $bookedMonth = 0.0;
        if ($isOwner) {
            // เดิมนับเงินประกันความเสียหายเป็นรายได้ด้วย ทำให้ยอดสูงเกินจริงคันละ 3,000–10,000 บาท
            $revenueMonth = Payment::revenueCollected($mStart, $mEnd);
            $bookedMonth  = Booking::bookedValue($mStart, $mEnd);
            for ($i = 5; $i >= 0; $i--) {
                $s = date('Y-m-01', strtotime("-$i months"));
                $e = date('Y-m-t', strtotime("-$i months"));
                $trend[] = [
                    'label' => TH_MONTHS[(int) date('n', strtotime($s))],
                    'value' => max(0, Payment::revenueCollected($s, $e)),
                ];
            }
            $perCar = Report::revenuePerCar($mStart, $mEnd);
        }

        return $this->view('admin/dashboard', [
            'title'        => 'ภาพรวม',
            'today'        => $today,
            'isOwner'      => $isOwner,
            'revenueMonth' => $revenueMonth,
            'bookedMonth'  => $bookedMonth,
            'activeNow'    => Booking::countByStatus('picked_up'),
            'totalCars'    => $totalCars,
            'pending'      => $pending,
            'slipWait'     => $slipWait,
            'bookedDays'   => $bookedDays,
            'daysInM'      => $daysInM,
            'utilization'  => $utilization,
            'pickupToday'  => Booking::pickupsOn($today),
            'returnToday'  => Booking::returnsOn($today),
            'overdue'      => Booking::overdue(),
            'reminderDays' => $reminderDays,
            'dueTasks'     => Maintenance::dueUntil(date('Y-m-d', strtotime("+$reminderDays days")), 10),
            'trend'        => $trend,
            'maxTrend'     => max(1, $trend ? max(array_column($trend, 'value')) : 1),
            'perCar'       => $perCar,
            'maxCarRev'    => max(1, $perCar ? max(array_map(fn($r) => (float) $r['revenue'], $perCar)) : 1),
            'carStatus'    => Car::statusCounts(),
        ]);
    }
}
