<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\ActivityLog;
use App\Services\MonthlyReportService;

/**
 * รายงานผลประกอบการรายเดือน — ฉบับเดียวจบ พิมพ์เป็น PDF ส่งเจ้าของร้านได้เลย
 *
 * ต่างจากหน้า "รายงาน" ตรงที่: เทียบกับเดือนก่อนให้ทุกตัวเลข ชี้รถที่ทำเงินดี–ด้อยที่สุด
 * สรุปข้อสังเกตจากข้อมูลให้อัตโนมัติ และมีช่องข้อเสนอแนะประจำเดือนที่บันทึกแยกรายเดือน
 */
class MonthlyReportController extends AdminController
{
    public function index(): Response
    {
        $m = $this->month();
        $data = MonthlyReportService::build($m);
        $data['monthTitle'] = $data['title'];   // ชื่อเดือนแบบไทย (key 'title' ใช้เป็นชื่อหน้า)
        unset($data['title']);

        return $this->view('admin/monthly_report', $data + [
            'title'  => 'รายงานรายเดือน',
            'note'   => (string) setting('report_note_' . $m),
            'srcLabel' => ['web' => 'จองผ่านเว็บ', 'admin' => 'หน้าร้าน', 'line' => 'LINE', 'phone' => 'โทรศัพท์'],
        ]);
    }

    public function saveNote(): Response
    {
        $m = $this->month();
        set_setting('report_note_' . $m, $this->request->str('note', 3000));
        ActivityLog::log('monthly_report_note', null, null, $m);
        return $this->done('admin/monthly-report?m=' . $m, 'ok', 'บันทึกข้อเสนอแนะประจำเดือนแล้ว');
    }

    /** เดือนที่ดู (ค่าเริ่มต้น = เดือนที่แล้ว เพราะรายงานทำหลังจบเดือน) */
    private function month(): string
    {
        $default = date('Y-m', strtotime('first day of last month'));
        $m = $this->request->str('m', 0, $default);
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : $default;
    }
}
