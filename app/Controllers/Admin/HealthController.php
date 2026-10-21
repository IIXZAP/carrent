<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Services\HealthService;
use App\Services\PrivateFileService;

/**
 * สุขภาพระบบ — รายการตรวจที่ใช้ทำรายงานตรวจสุขภาพระบบรายไตรมาส
 * พิมพ์หน้านี้เป็น PDF แนบรายงานให้ลูกค้าได้เลย (เฉพาะเจ้าของร้าน)
 */
class HealthController extends AdminController
{
    public function index(): Response
    {
        PrivateFileService::protectLegacyDocs();
        $legacyDocs = 0;
        try {
            $legacyDocs = Customer::legacyDocCount();
        } catch (\Throwable $e) {
            // ตารางยังไม่มี — ข้าม
        }

        // จัดกลุ่มตามระดับ แล้วเรียงให้ข้อที่ต้องแก้ (fail) ขึ้นก่อน
        $byLevel = ['fail' => [], 'warn' => [], 'ok' => []];
        foreach (HealthService::checks() as $check) {
            $byLevel[$check[1]][] = $check;
        }
        $count  = ['ok' => count($byLevel['ok']), 'warn' => count($byLevel['warn']), 'fail' => count($byLevel['fail'])];
        $checks = array_merge($byLevel['fail'], $byLevel['warn'], $byLevel['ok']);

        return $this->view('admin/health/index', [
            'title'      => 'สุขภาพระบบ',
            'checks'     => $checks,
            'count'      => $count,
            'overall'    => $count['fail'] > 0 ? 'fail' : ($count['warn'] > 0 ? 'warn' : 'ok'),
            'legacyDocs' => $legacyDocs,
            'pingUrl'    => site_url('health'),
            'key'        => (string) setting('health_key'),
        ]);
    }

    /** ย้ายเอกสารลูกค้าเดิมใน uploads/docs เข้าที่เก็บแบบปิด */
    public function migrateDocs(): Response
    {
        try {
            $r = PrivateFileService::migrateLegacyDocs($this->userId());
            ActivityLog::log('documents_migrated', 'customers', null, $r);
            $this->flash($r['failed'] > 0 ? 'warn' : 'ok', 'ย้ายเอกสารลูกค้าเข้าที่เก็บแบบปิดแล้ว ' . $r['moved'] . ' ไฟล์'
                . ($r['missing'] ? ' · ไม่พบไฟล์จริง ' . $r['missing'] . ' รายการ' : '')
                . ($r['failed'] ? ' · ย้ายไม่สำเร็จ ' . $r['failed'] . ' ไฟล์ (ไฟล์ยังอยู่ที่เดิม ตรวจสิทธิ์โฟลเดอร์ storage แล้วกดอีกครั้ง)' : ''));
        } catch (\Throwable $ex) {
            $this->flash('danger', 'ย้ายเอกสารไม่สำเร็จ: ' . $ex->getMessage());
        }
        return $this->redirect('admin/health');
    }
}
