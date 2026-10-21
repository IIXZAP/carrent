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

        // ---------- รายคัน ----------
        $carRows = Report::monthlyPerCar($from, $to);
        $cost    = Maintenance::costByCar($from, $to);
        foreach ($carRows as &$r) {
            $r['days'] = $cur['days_map'][(int) $r['id']] ?? 0;
            $r['util'] = $r['days'] / $dim * 100;
            $r['cost'] = $cost[(int) $r['id']] ?? 0;
        }
        unset($r);
        usort($carRows, fn($a, $b) => $b['util'] <=> $a['util']);
        $best  = $carRows[0] ?? null;
        $worst = $carRows ? $carRows[count($carRows) - 1] : null;

        // ---------- ที่มาของการจอง / ลูกค้าใหม่–เก่า ----------
        $bySource = Report::bySourceCreated($from, $to);
        $srcTotal = array_sum(array_column($bySource, 'n'));
        $webShare = 0;
        foreach ($bySource as $r) {
            if ($r['source'] === 'web') {
                $webShare = $srcTotal ? $r['n'] / $srcTotal * 100 : 0;
            }
        }
        $custNew  = Report::newCustomers($from, $to);
        $custAll  = Report::allCustomers($from, $to);
        $custBack = max(0, $custAll - $custNew);

        // ---------- การดำเนินงาน ----------
        [$returned, $late] = Report::returnStats($from, $to);
        $slipHrs   = Payment::averageApprovalHours($from, $to);
        $maintCost = array_sum($cost);
        $busyDow   = Report::busiestPickupWeekday($from, $to);
        $dowName   = [1 => 'อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];

        $lineLinked = 0;
        $lineSent = 0;
        $inspLocked = 0;
        $inspTotal = 0;
        try {
            $lineLinked = Booking::linkedLineAccounts();
            $lineSent   = Notification::lineSentBetween($from, $to);
            [$inspTotal, $inspLocked] = Inspection::stats($from, $to);
        } catch (\Throwable $e) {
            // ฐานข้อมูลยังไม่ถึงเวอร์ชัน 4 — ข้ามตัวเลขส่วนนี้
        }

        // ---------- ข้อสังเกตอัตโนมัติ: สรุปจากตัวเลขเท่านั้น ไม่เดา ----------
        $notes = [];
        if ($cur['jobs'] === 0 && $cur['new'] === 0) {
            $notes[] = ['muted', 'เดือนนี้ยังไม่มีการจองในระบบ จึงยังสรุปข้อสังเกตไม่ได้'];
        } else {
            foreach ($carRows as $r) {
                if ($r['util'] >= 75) {
                    $notes[] = ['ok', sprintf('%s %s (%s) ถูกใช้ %d%% ของเดือน — ความต้องการสูงกว่ารถที่มี ลองปรับราคาขึ้นช่วงวันที่เต็มเร็ว หรือพิจารณาเพิ่มรถประเภทเดียวกัน',
                        $r['brand'], $r['model'], $r['plate'], round($r['util']))];
                }
            }
            // รถที่ใช้น้อย: 1–2 คันบอกรายคัน ถ้ามากกว่านั้นรวมเป็นข้อเดียว ไม่งั้นรายงานจะยาวเป็นข้อความซ้ำๆ
            $low = array_values(array_filter($carRows, fn($r) => $r['util'] < 20));
            if (count($low) > 0 && count($low) <= 2) {
                foreach ($low as $r) {
                    $notes[] = ['warn', sprintf('%s %s (%s) ถูกใช้เพียง %d%% ของเดือน (%s วัน) — ลองทำราคาเช่ายาวหรือโปรโมชันเฉพาะคันนี้',
                        $r['brand'], $r['model'], $r['plate'], round($r['util']), rtrim(rtrim(number_format($r['days'], 1), '0'), '.'))];
                }
            } elseif (count($low) > 2) {
                $names = array_map(fn($r) => $r['brand'] . ' ' . $r['model'] . ' ' . round($r['util']) . '%', $low);
                $notes[] = ['warn', sprintf('รถ %d จาก %d คันถูกใช้น้อยกว่า 20%% ของเดือน: %s — ภาพรวมยังมีรถว่างมาก ควรเน้นหาลูกค้าเพิ่มก่อนปรับราคารายคัน',
                    count($low), count($carRows), implode(' · ', $names))];
            }
            if ($cur['new'] >= 5 && $cur['cancel_rt'] >= 20) {
                $notes[] = ['warn', sprintf('ยกเลิก %d จาก %d การจอง (%d%%) — ตรวจเหตุผลการยกเลิกในหน้าการจอง ส่วนใหญ่เกิดจากไม่โอนมัดจำภายในเวลา',
                    $cur['cancelled'], $cur['new'], round($cur['cancel_rt']))];
            }
            if ($srcTotal >= 5) {
                $notes[] = [$webShare >= 50 ? 'ok' : 'muted', sprintf('จองผ่านเว็บ %d%% ของการจองทั้งหมด%s',
                    round($webShare), $webShare < 30 ? ' — ลูกค้าส่วนใหญ่ยังโทรหรือทักมา ลองแปะลิงก์จองไว้ในโปรไฟล์ LINE และ Facebook' : '')];
            }
            if ($returned > 0 && $late > 0) {
                $notes[] = ['warn', sprintf('คืนรถช้าเกิน 1 ชั่วโมง %d จาก %d ครั้ง — เก็บค่าใช้จ่ายเพิ่มรวม %s บาท', $late, $returned, money0($cur['extra']))];
            }
            if ($slipHrs !== null && $slipHrs > 6) {
                $notes[] = ['warn', sprintf('ใช้เวลาตรวจสลิปเฉลี่ย %.1f ชั่วโมง — ลูกค้ารอนานเกิน 6 ชั่วโมงมีโอกาสเปลี่ยนใจ', $slipHrs)];
            }
            if ($busyDow && (int) $busyDow['n'] >= 3) {
                $notes[] = ['muted', 'วันที่ลูกค้ารับรถมากที่สุดคือวัน' . $dowName[(int) $busyDow['d']] . ' (' . (int) $busyDow['n'] . ' ครั้ง)'];
            }
            if ($inspTotal > 0 && $inspLocked < $inspTotal) {
                $notes[] = ['warn', sprintf('ผลตรวจสภาพรถ %d จาก %d รอบยังไม่ถูกล็อก — รอบที่ไม่ล็อกใช้เป็นหลักฐานได้อ่อนกว่า', $inspTotal - $inspLocked, $inspTotal)];
            }
            if (!$notes) {
                $notes[] = ['ok', 'ไม่พบจุดที่ต้องจับตาเป็นพิเศษในเดือนนี้'];
            }
        }

        return compact(
            'm', 'from', 'to', 'dim', 'pm', 'nm', 'title', 'cur', 'prev', 'carRows', 'best', 'worst',
            'bySource', 'srcTotal', 'custNew', 'custBack', 'late', 'returned', 'slipHrs', 'maintCost',
            'lineLinked', 'lineSent', 'inspLocked', 'inspTotal', 'notes'
        );
    }
}
