<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\Maintenance;
use App\Models\Payment;
use App\Models\Report;

/** รายงาน — รายได้ อัตราการใช้รถ ต้นทุนบำรุงรักษา กำไรต่อคัน + export CSV (เฉพาะเจ้าของร้าน) */
class ReportController extends AdminController
{
    public function index(): Response
    {
        $from = $this->request->str('from', 0, date('Y-m-01'));
        $to   = $this->request->str('to', 0, date('Y-m-t'));
        if (strtotime($from) === false || strtotime($to) === false) {
            [$from, $to] = [date('Y-m-01'), date('Y-m-t')];
        }
        if (strtotime($to) < strtotime($from)) {
            [$from, $to] = [$to, $from];
        }
        $days = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);

        $perCar = Report::performancePerCar($from, $to);
        // วันที่รถออกจริงในช่วงที่ดู — ใช้ฟังก์ชันเดียวกับหน้าภาพรวมและรายงานรายเดือน ตัวเลขอัตราการใช้รถจึงตรงกันทุกหน้า
        // (เดิมรวม rental_days ของการจองที่ "รับรถ" ในช่วงนี้ การเช่าข้ามเดือนจึงถูกนับทั้งก้อนในเดือนที่รับรถ)
        $sold = Report::daysSoldByCar($from, $to);
        foreach ($perCar as &$r) {
            $r['rent_days'] = (int) round($sold[(int) $r['id']] ?? 0);
        }
        unset($r);
        $costByCar = Maintenance::costByCar($from, $to);

        if ($this->request->str('export') === 'csv') {
            return $this->exportCsv($perCar, $costByCar, $from, $to, $days);
        }

        $revenue  = array_sum(array_map(fn($r) => (float) $r['revenue'], $perCar));
        $cost     = array_sum($costByCar);
        $rentDays = array_sum(array_map(fn($r) => (int) $r['rent_days'], $perCar));
        $carCount = count($perCar);
        $jobs     = array_sum(array_map(fn($r) => (int) $r['jobs'], $perCar));
        $daily    = Payment::dailyRevenue($from, $to);

        return $this->view('admin/reports/index', [
            'title'     => 'รายงาน',
            'from'      => $from,
            'to'        => $to,
            'days'      => $days,
            'perCar'    => $perCar,
            'costByCar' => $costByCar,
            'revenue'   => $revenue,
            'cost'      => $cost,
            'rentDays'  => $rentDays,
            'carCount'  => $carCount,
            'util'      => $carCount ? round($rentDays / ($carCount * $days) * 100, 1) : 0,
            'collected' => Payment::revenueCollected($from, $to),
            'jobs'      => $jobs,
            'avgTicket' => $jobs ? $revenue / $jobs : 0,
            'maxRev'    => max(1, $perCar ? max(array_map(fn($r) => (float) $r['revenue'], $perCar)) : 1),
            'daily'     => $daily,
            'maxDaily'  => max(1, $daily ? max(array_map(fn($r) => (float) $r['amt'], $daily)) : 1),
            'byType'    => Report::byCarType($from, $to),
            'bySource'  => Report::bySourcePickup($from, $to),
            'srcLabel'  => ['web' => 'จองผ่านเว็บ', 'admin' => 'หน้าร้าน', 'line' => 'LINE', 'phone' => 'โทรศัพท์'],
        ]);
    }

    private function exportCsv(array $perCar, array $costByCar, string $from, string $to, int $days): Response
    {
        $rows = [];
        foreach ($perCar as $r) {
            $c = $costByCar[(int) $r['id']] ?? 0;
            $rows[] = [
                $r['plate'], $r['brand'] . ' ' . $r['model'], (int) $r['jobs'], (int) $r['rent_days'],
                round((int) $r['rent_days'] / $days * 100, 1),
                round((float) $r['revenue'], 2), round($c, 2), round((float) $r['revenue'] - $c, 2),
                (int) $r['rent_days'] > 0 ? round((float) $r['revenue'] / (int) $r['rent_days'], 2) : 0,
            ];
        }
        return $this->csv(
            'report_' . $from . '_' . $to . '.csv',
            ['ทะเบียน', 'รถ', 'จำนวนงาน', 'วันเช่ารวม', 'อัตราการใช้รถ (%)', 'รายได้ (บาท)', 'ค่าบำรุงรักษา (บาท)', 'กำไรขั้นต้น (บาท)', 'รายได้เฉลี่ย/วันที่ปล่อยเช่า'],
            $rows
        );
    }
}
