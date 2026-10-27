<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Car;
use App\Models\CarBlock;

/** ข้อมูลปฏิทินรถรายเดือน: รถ × วัน → การจอง/ช่วงปิดรถ */
final class CalendarService
{
    /** รับเฉพาะ YYYY-MM ที่เป็นเดือนจริง ไม่งั้นใช้เดือนนี้ */
    public static function normalizeMonth(string $m): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])\z/', $m) === 1 ? $m : date('Y-m');
    }

    /** @return array{month:string,first:string,last:string,daysIn:int,prev:string,next:string,cars:array,grid:array,util:int} */
    public static function month(string $m): array
    {
        $month = self::normalizeMonth($m);
        $first = $month . '-01';
        $daysIn = (int) date('t', strtotime($first));
        $last = date('Y-m-t', strtotime($first));
        $cars = Car::forCalendar();

        $dayOf = static fn(string $dt, bool $isStart) => date('Y-m', strtotime($dt)) === $month
            ? (int) date('j', strtotime($dt))
            : ($isStart ? 1 : $daysIn);

        $grid = [];
        foreach (Booking::forCalendar($first, $last) as $bk) {
            $s = $dayOf($bk['pickup_at'], true);
            $e = $dayOf($bk['return_at'], false);
            for ($d = $s; $d <= $e; $d++) {
                if (isset($grid[(int) $bk['car_id']][$d])) {
                    continue;   // รถคืนเช้า รับต่อบ่ายวันเดียวกัน: แสดงใบแรกของวัน
                }
                $grid[(int) $bk['car_id']][$d] = [
                    'kind'  => $bk['status'],
                    'label' => $d === $s && date('Y-m', strtotime($bk['pickup_at'])) === $month ? $bk['booking_no'] . ' ' . mb_substr($bk['contact_name'], 0, 10) : '',
                    'link'  => url('/admin/bookings/' . (int) $bk['id']),
                    'title' => $bk['booking_no'] . ' · ' . $bk['contact_name'] . ' · ' . thai_date($bk['pickup_at']) . '–' . thai_date($bk['return_at']),
                ];
            }
        }
        foreach (CarBlock::inRange($first, $last) as $bl) {
            $s = $dayOf($bl['start_at'], true);
            $e = $dayOf($bl['end_at'], false);
            for ($d = $s; $d <= $e; $d++) {
                if (isset($grid[(int) $bl['car_id']][$d])) {
                    continue;
                }
                $grid[(int) $bl['car_id']][$d] = [
                    'kind'  => 'block',
                    'label' => $d === $s && date('Y-m', strtotime($bl['start_at'])) === $month ? ($bl['reason'] ?: 'ปิดรถ') : '',
                    'link'  => '#',
                    'title' => 'ปิดรถ: ' . $bl['reason'],
                ];
            }
        }
        ksort($grid);
        foreach ($grid as &$days) {
            ksort($days);
        }
        unset($days);

        $used = 0;
        foreach ($cars as $c) {
            $used += count($grid[(int) $c['id']] ?? []);
        }
        return [
            'month' => $month, 'first' => $first, 'last' => $last, 'daysIn' => $daysIn,
            'prev' => date('Y-m', strtotime($first . ' -1 month')), 'next' => date('Y-m', strtotime($first . ' +1 month')),
            'cars' => $cars, 'grid' => $grid,
            'util' => $cars ? (int) round($used / (count($cars) * $daysIn) * 100) : 0,
        ];
    }
}
