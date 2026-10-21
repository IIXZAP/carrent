<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Car;
use App\Models\Inspection;
use App\Models\Maintenance;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Report;

/**
 * รวบรวมตัวเลขของรายงานผลประกอบการรายเดือน เทียบกับเดือนก่อน และสรุปข้อสังเกตจากข้อมูลให้อัตโนมัติ
 */
final class MonthlyReportService
{
    /** ตัวเลขหลักของช่วงเวลาหนึ่ง — ใช้คำนวณทั้งเดือนนี้และเดือนก่อนด้วยสูตรเดียวกัน */
    public static function metrics(string $from, string $to, int $dim): array
    {
        $cars = Car::activeCount();
        $days = Report::daysSoldByCar($from, $to);
        $sold = array_sum($days);
        [$all, $canc] = Report::bookingCreatedCounts($from, $to);
        $jobs = Report::jobsSummary($from, $to);
        return [
            'cars'      => $cars,
            'days_map'  => $days,
            'sold'      => $sold,
            'util'      => $cars > 0 ? $sold / ($cars * $dim) * 100 : 0,
            'collected' => Payment::revenueCollected($from, $to),
            'new'       => $all,
            'cancelled' => $canc,
            'cancel_rt' => $all > 0 ? $canc / $all * 100 : 0,
            'jobs'      => (int) $jobs['n'],
            'booked'    => (float) $jobs['v'],
            'extra'     => (float) $jobs['x'],
            'ticket'    => (int) $jobs['n'] > 0 ? (float) $jobs['v'] / (int) $jobs['n'] : 0,
        ];
    }

    /**
     * ประกอบข้อมูลทั้งหมดของรายงานเดือน $m (รูปแบบ YYYY-MM)
     */
    public static function build(string $m): array
    {
        $from  = $m . '-01';
        $to    = date('Y-m-t', strtotime($from));
        $dim   = (int) date('t', strtotime($from));
        $pm    = date('Y-m', strtotime($from . ' -1 month'));
        $pFrom = $pm . '-01';
        $pTo   = date('Y-m-t', strtotime($pFrom));
        $pDim  = (int) date('t', strtotime($pFrom));
        $nm    = date('Y-m', strtotime($from . ' +1 month'));
        $title = TH_MONTHS_FULL[(int) date('n', strtotime($from))] . ' ' . ((int) date('Y', strtotime($from)) + 543);

        $cur  = self::metrics($from, $to, $dim);
        $prev = self::metrics($pFrom, $pTo, $pDim);

        // รายคัน
        $cost    = Maintenance::costByCar($from, $to);
        $carRows = self::carRows($from, $to, $dim, $cur['days_map'], $cost);
        $best    = $carRows[0] ?? null;
        $worst   = $carRows ? $carRows[count($carRows) - 1] : null;

        // ที่มาของการจอง / ลูกค้าใหม่–เก่า
        $bySource = Report::bySourceCreated($from, $to);
        $srcTotal = array_sum(array_column($bySource, 'n'));
        $custNew  = Report::newCustomers($from, $to);
        $custBack = max(0, Report::allCustomers($from, $to) - $custNew);

        // การดำเนินงาน
        [$returned, $late] = Report::returnStats($from, $to);
        $slipHrs   = Payment::averageApprovalHours($from, $to);
        $maintCost = array_sum($cost);

        $lineLinked = 0;
        $lineSent   = 0;
        $inspLocked = 0;
        $inspTotal  = 0;
        try {
            $lineLinked = Booking::linkedLineAccounts();
            $lineSent   = Notification::lineSentBetween($from, $to);
            [$inspTotal, $inspLocked] = Inspection::stats($from, $to);
        } catch (\Throwable $e) {
            // ฐานข้อมูลยังไม่ถึงเวอร์ชัน 4 — ข้ามตัวเลขส่วนนี้
        }

        $notes = self::buildNotes([
            'cur'        => $cur,
            'carRows'    => $carRows,
            'srcTotal'   => $srcTotal,
            'webShare'   => self::webShare($bySource, $srcTotal),
            'returned'   => $returned,
            'late'       => $late,
            'slipHrs'    => $slipHrs,
            'busyDay'    => Report::busiestPickupWeekday($from, $to),
            'inspTotal'  => $inspTotal,
            'inspLocked' => $inspLocked,
        ]);

        return compact(
            'm', 'from', 'to', 'dim', 'pm', 'nm', 'title', 'cur', 'prev', 'carRows', 'best', 'worst',
            'bySource', 'srcTotal', 'custNew', 'custBack', 'late', 'returned', 'slipHrs', 'maintCost',
            'lineLinked', 'lineSent', 'inspLocked', 'inspTotal', 'notes'
        );
    }

    /** ตารางรายคัน เรียงจากใช้งานมากไปน้อย (เพิ่มวันที่ขายได้ % ใช้งาน และค่าซ่อมของแต่ละคัน) */
    private static function carRows(string $from, string $to, int $dim, array $daysMap, array $costMap): array
    {
        $rows = [];
        foreach (Report::monthlyPerCar($from, $to) as $row) {
            $id = (int) $row['id'];
            $row['days'] = $daysMap[$id] ?? 0;
            $row['util'] = $row['days'] / $dim * 100;
            $row['cost'] = $costMap[$id] ?? 0;
            $rows[] = $row;
        }
        usort($rows, [self::class, 'mostUsedFirst']);
        return $rows;
    }

    private static function mostUsedFirst(array $a, array $b): int
    {
        return $b['util'] <=> $a['util'];
    }

    /** สัดส่วน (%) ของการจองที่มาจากหน้าเว็บ */
    private static function webShare(array $bySource, $total): float
    {
        foreach ($bySource as $row) {
            if ($row['source'] === 'web' && $total) {
                return $row['n'] / $total * 100;
            }
        }
        return 0;
    }

    /**
     * ข้อสังเกตอัตโนมัติ: สรุปจากตัวเลขเท่านั้น ไม่เดา
     * @return array<int,array{0:string,1:string}> [ระดับ ok|warn|muted, ข้อความ]
     */
    private static function buildNotes(array $d): array
    {
        $cur = $d['cur'];
        if ($cur['jobs'] === 0 && $cur['new'] === 0) {
            return [['muted', 'เดือนนี้ยังไม่มีการจองในระบบ จึงยังสรุปข้อสังเกตไม่ได้']];
        }

        $notes = array_merge(
            self::busyCarNotes($d['carRows']),
            self::idleCarNotes($d['carRows'])
        );

        if ($cur['new'] >= 5 && $cur['cancel_rt'] >= 20) {
            $notes[] = ['warn', sprintf('ยกเลิก %d จาก %d การจอง (%d%%) — ตรวจเหตุผลการยกเลิกในหน้าการจอง ส่วนใหญ่เกิดจากไม่โอนมัดจำภายในเวลา',
                $cur['cancelled'], $cur['new'], round($cur['cancel_rt']))];
        }
        if ($d['srcTotal'] >= 5) {
            $webShare = $d['webShare'];
            $hint = $webShare < 30 ? ' — ลูกค้าส่วนใหญ่ยังโทรหรือทักมา ลองแปะลิงก์จองไว้ในโปรไฟล์ LINE และ Facebook' : '';
            $notes[] = [$webShare >= 50 ? 'ok' : 'muted', sprintf('จองผ่านเว็บ %d%% ของการจองทั้งหมด%s', round($webShare), $hint)];
        }
        if ($d['returned'] > 0 && $d['late'] > 0) {
            $notes[] = ['warn', sprintf('คืนรถช้าเกิน 1 ชั่วโมง %d จาก %d ครั้ง — เก็บค่าใช้จ่ายเพิ่มรวม %s บาท',
                $d['late'], $d['returned'], money0($cur['extra']))];
        }
        if ($d['slipHrs'] !== null && $d['slipHrs'] > 6) {
            $notes[] = ['warn', sprintf('ใช้เวลาตรวจสลิปเฉลี่ย %.1f ชั่วโมง — ลูกค้ารอนานเกิน 6 ชั่วโมงมีโอกาสเปลี่ยนใจ', $d['slipHrs'])];
        }
        if ($d['busyDay'] && (int) $d['busyDay']['n'] >= 3) {
            $dayNames = [1 => 'อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
            $notes[] = ['muted', 'วันที่ลูกค้ารับรถมากที่สุดคือวัน' . $dayNames[(int) $d['busyDay']['d']] . ' (' . (int) $d['busyDay']['n'] . ' ครั้ง)'];
        }
        if ($d['inspTotal'] > 0 && $d['inspLocked'] < $d['inspTotal']) {
            $notes[] = ['warn', sprintf('ผลตรวจสภาพรถ %d จาก %d รอบยังไม่ถูกล็อก — รอบที่ไม่ล็อกใช้เป็นหลักฐานได้อ่อนกว่า',
                $d['inspTotal'] - $d['inspLocked'], $d['inspTotal'])];
        }
        if (!$notes) {
            $notes[] = ['ok', 'ไม่พบจุดที่ต้องจับตาเป็นพิเศษในเดือนนี้'];
        }
        return $notes;
    }

    /** รถที่ถูกใช้ตั้งแต่ 75% ของเดือน */
    private static function busyCarNotes(array $carRows): array
    {
        $notes = [];
        foreach ($carRows as $car) {
            if ($car['util'] >= 75) {
                $notes[] = ['ok', sprintf('%s %s (%s) ถูกใช้ %d%% ของเดือน — ความต้องการสูงกว่ารถที่มี ลองปรับราคาขึ้นช่วงวันที่เต็มเร็ว หรือพิจารณาเพิ่มรถประเภทเดียวกัน',
                    $car['brand'], $car['model'], $car['plate'], round($car['util']))];
            }
        }
        return $notes;
    }

    /** รถที่ใช้น้อยกว่า 20%: 1–2 คันบอกรายคัน ถ้ามากกว่านั้นรวมเป็นข้อเดียว ไม่งั้นรายงานจะยาวเป็นข้อความซ้ำๆ */
    private static function idleCarNotes(array $carRows): array
    {
        $idle = [];
        foreach ($carRows as $car) {
            if ($car['util'] < 20) {
                $idle[] = $car;
            }
        }
        if (!$idle) {
            return [];
        }
        if (count($idle) > 2) {
            $names = [];
            foreach ($idle as $car) {
                $names[] = $car['brand'] . ' ' . $car['model'] . ' ' . round($car['util']) . '%';
            }
            return [['warn', sprintf('รถ %d จาก %d คันถูกใช้น้อยกว่า 20%% ของเดือน: %s — ภาพรวมยังมีรถว่างมาก ควรเน้นหาลูกค้าเพิ่มก่อนปรับราคารายคัน',
                count($idle), count($carRows), implode(' · ', $names))]];
        }
        $notes = [];
        foreach ($idle as $car) {
            $days = rtrim(rtrim(number_format($car['days'], 1), '0'), '.');
            $notes[] = ['warn', sprintf('%s %s (%s) ถูกใช้เพียง %d%% ของเดือน (%s วัน) — ลองทำราคาเช่ายาวหรือโปรโมชันเฉพาะคันนี้',
                $car['brand'], $car['model'], $car['plate'], round($car['util']), $days)];
        }
        return $notes;
    }
}
