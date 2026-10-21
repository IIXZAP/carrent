<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\CarBlock;

/** ปฏิทินรถแบบ timeline — เห็นทุกคันพร้อมกันว่าคันไหนว่างวันไหน + ปิดรถชั่วคราว (ซ่อม / เจ้าของใช้เอง) */
class CalendarController extends AdminController
{
    public function index(): Response
    {
        $month = $this->request->str('m', 0, date('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = date('Y-m');
        }
        $first  = $month . '-01';
        $daysIn = (int) date('t', strtotime($first));
        $last   = date('Y-m-t', strtotime($first));

        $cars = Car::active();

        // map: [car_id][day] = ช่อง
        $grid = [];
        foreach (Booking::forCalendar($first, $last) as $bk) {
            $s = date('Y-m', strtotime($bk['pickup_at'])) === $month ? (int) date('j', strtotime($bk['pickup_at'])) : 1;
            $e = date('Y-m', strtotime($bk['return_at'])) === $month ? (int) date('j', strtotime($bk['return_at'])) : $daysIn;
            for ($d = $s; $d <= $e; $d++) {
                $grid[(int) $bk['car_id']][$d] = [
                    'kind'  => $bk['status'],
                    'label' => $d === $s ? $bk['booking_no'] . ' ' . mb_substr($bk['contact_name'], 0, 10) : '',
                    'link'  => url('admin/bookings/' . (int) $bk['id']),
                    'title' => $bk['booking_no'] . ' · ' . $bk['contact_name'] . ' · ' . thai_date($bk['pickup_at']) . '–' . thai_date($bk['return_at']),
                ];
            }
        }
        foreach (CarBlock::inRange($first, $last) as $bl) {
            $s = date('Y-m', strtotime($bl['start_at'])) === $month ? (int) date('j', strtotime($bl['start_at'])) : 1;
            $e = date('Y-m', strtotime($bl['end_at'])) === $month ? (int) date('j', strtotime($bl['end_at'])) : $daysIn;
            for ($d = $s; $d <= $e; $d++) {
                if (isset($grid[(int) $bl['car_id']][$d])) {
                    continue;
                }
                $grid[(int) $bl['car_id']][$d] = [
                    'kind'  => 'block',
                    'label' => $d === $s ? ($bl['reason'] ?: 'ปิดรถ') : '',
                    'link'  => '#',
                    'title' => 'ปิดรถ: ' . $bl['reason'],
                ];
            }
        }

        // อัตราการใช้รถในเดือนนี้
        $usedCells = 0;
        foreach ($grid as $days) {
            $usedCells += count($days);
        }

        return $this->view('admin/calendar', [
            'title'     => 'ปฏิทินรถ',
            'month'     => $month,
            'first'     => $first,
            'daysIn'    => $daysIn,
            'prev'      => date('Y-m', strtotime($first . ' -1 month')),
            'next'      => date('Y-m', strtotime($first . ' +1 month')),
            'today'     => date('Y-m-d'),
            'cars'      => $cars,
            'grid'      => $grid,
            'util'      => $cars ? (int) round($usedCells / (count($cars) * $daysIn) * 100) : 0,
            'allBlocks' => CarBlock::upcoming(20),
        ]);
    }

    public function block(): Response
    {
        $r     = $this->request;
        $month = $r->str('m', 0, date('Y-m'));
        try {
            $carId = $r->int('car_id');
            $s = strtotime($r->str('start_at'));
            $e = strtotime($r->str('end_at'));
            if (!$carId || $s === false || $e === false) {
                throw new \RuntimeException('กรุณาเลือกรถและช่วงเวลา');
            }
            if ($e <= $s) {
                throw new \RuntimeException('วันสิ้นสุดต้องหลังวันเริ่ม');
            }
            CarBlock::insert([
                'car_id'   => $carId,
                'start_at' => date('Y-m-d H:i:s', $s),
                'end_at'   => date('Y-m-d H:i:s', $e),
                'reason'   => $r->str('reason', 250) ?: 'ปิดการใช้งาน',
            ]);
            ActivityLog::log('car_blocked', 'cars', $carId);
            $this->flash('ok', 'บันทึกช่วงปิดรถแล้ว');
        } catch (\Throwable $ex) {
            $this->flash('danger', $ex instanceof \RuntimeException ? $ex->getMessage() : 'บันทึกไม่สำเร็จ');
        }
        return $this->redirect('admin/calendar?m=' . $month);
    }

    public function unblock(): Response
    {
        CarBlock::deleteById($this->request->int('block_id'));
        $this->flash('ok', 'ยกเลิกช่วงปิดรถแล้ว');
        return $this->redirect('admin/calendar?m=' . $this->request->str('m', 0, date('Y-m')));
    }
}
