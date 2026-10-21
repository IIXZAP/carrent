<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Car;
use App\Models\Maintenance;

/** งานประจำรถ — ประกัน / พ.ร.บ. / ภาษี / เช็กระยะ / ซ่อม */
class MaintenanceController extends AdminController
{
    private const TYPES = ['service', 'insurance', 'act', 'tax', 'tyre', 'repair', 'other'];

    public function index(): Response
    {
        $filter = $this->request->str('status', 0, 'pending');
        $filter = in_array($filter, ['pending', 'done', 'skipped', 'all'], true) ? $filter : 'pending';
        $today  = date('Y-m-d');
        $warn   = (int) setting('reminder_days_before', '30');

        return $this->view('admin/maintenance/index', [
            'title'     => 'งานประจำรถ',
            'filter'    => $filter,
            'rows'      => Maintenance::listByStatus($filter, 200),
            'cars'      => Car::active(),
            'today'     => $today,
            'warn'      => $warn,
            'overdue'   => Maintenance::overdueCount($today),
            'soon'      => Maintenance::dueBetweenCount($today, date('Y-m-d', strtotime("+$warn days"))),
            'costYear'  => Maintenance::costForYear(date('Y')),
            'typeLabel' => [
                'service' => 'เช็กระยะ/น้ำมันเครื่อง', 'insurance' => 'ประกันภัย', 'act' => 'พ.ร.บ.',
                'tax' => 'ภาษีประจำปี', 'tyre' => 'ยาง', 'repair' => 'ซ่อม', 'other' => 'อื่นๆ',
            ],
        ]);
    }

    public function add(): Response
    {
        $r   = $this->request;
        $mid = Maintenance::insert([
            'car_id'      => $r->int('car_id'),
            'task_type'   => in_array($r->str('task_type'), self::TYPES, true) ? $r->str('task_type') : 'other',
            'title'       => $r->str('title', 250) ?: 'งานประจำรถ',
            'due_date'    => $r->str('due_date') ?: null,
            'due_mileage' => $r->int('due_mileage') ?: null,
            'vendor'      => $r->str('vendor', 150) ?: null,
            'note'        => $r->str('note', 1000) ?: null,
            'status'      => 'pending',
        ]);
        ActivityLog::log('maintenance_added', 'maintenances', $mid);
        return $this->done('admin/maintenance', 'ok', 'เพิ่มงานแล้ว');
    }

    public function complete(): Response
    {
        $r    = $this->request;
        $mid  = $r->int('id');
        $cost = $r->float('cost');
        $m    = Maintenance::find($mid);
        if (!$m) {
            return $this->redirect('admin/maintenance');
        }
        Maintenance::updateById($mid, [
            'status'    => 'done',
            'done_date' => $r->str('done_date') ?: date('Y-m-d'),
            'cost'      => $cost,
            'vendor'    => $r->str('vendor', 150) ?: ($m['vendor'] ?? null),
        ]);

        // สร้างงานรอบถัดไปอัตโนมัติ (วันหมดอายุของรถดูจากงานที่ค้างอยู่ จึงเปลี่ยนตามเอง)
        $nextDue   = $r->str('next_due');
        $repeating = ['insurance', 'act', 'tax', 'service'];
        if ($nextDue !== '' && in_array($m['task_type'], $repeating, true)) {
            Maintenance::insert([
                'car_id'    => (int) $m['car_id'],
                'task_type' => $m['task_type'],
                'title'     => $m['title'],
                'due_date'  => $nextDue,
                'status'    => 'pending',
            ]);
        }
        ActivityLog::log('maintenance_done', 'maintenances', $mid, $cost);
        return $this->done('admin/maintenance', 'ok', 'บันทึกว่าทำงานเสร็จแล้ว');
    }

    public function skip(): Response
    {
        Maintenance::skip($this->request->int('id'));
        return $this->done('admin/maintenance', 'warn', 'ข้ามงานนี้แล้ว');
    }
}
