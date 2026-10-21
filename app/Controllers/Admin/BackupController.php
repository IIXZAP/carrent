<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\ActivityLog;
use App\Services\BackupService;
use App\Services\UploadService;

/** สำรองข้อมูล — ดูรายการไฟล์สำรอง สำรองทันที และดาวน์โหลดไปเก็บเอง (เฉพาะเจ้าของร้าน) */
class BackupController extends AdminController
{
    public function index(): Response
    {
        $list = BackupService::list();
        $last = (string) setting('backup_last_at');
        [$upBytes, $upFiles] = UploadService::dirUsage(rtrim(UPLOAD_DIR, '/'));

        return $this->view('admin/backups/index', [
            'title'   => 'สำรองข้อมูล',
            'list'    => $list,
            'total'   => array_sum(array_column($list, 'size')),
            'keep'    => max(7, (int) setting('backup_keep_days', '90')),
            'last'    => $last,
            'ageH'    => $last !== '' ? (time() - strtotime($last)) / 3600 : null,
            'upBytes' => $upBytes,
            'upFiles' => $upFiles,
        ]);
    }

    public function download(): Response
    {
        $f = BackupService::find($this->request->str('name'));
        if (!$f) {
            return $this->done('admin/backups', 'danger', 'ไม่พบไฟล์สำรองนี้');
        }
        ActivityLog::log('backup_downloaded', null, null, $f['name']);
        return Response::file($f['path'], [
            'Content-Type'        => str_ends_with($f['name'], '.gz') ? 'application/gzip' : 'application/sql',
            'Content-Disposition' => 'attachment; filename="' . $f['name'] . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /** act = create (สำรองทันที) | keep (ตั้งระยะเวลาเก็บไฟล์) */
    public function update(): Response
    {
        $act = $this->request->str('act');
        if ($act === 'create') {
            try {
                $r = BackupService::create();
                ActivityLog::log('backup_created', null, null, $r['name']);
                $this->flash('ok', 'สำรองข้อมูลเรียบร้อย — ' . $r['tables'] . ' ตาราง ' . number_format($r['rows']) . ' แถว ขนาด ' . human_bytes($r['size']));
            } catch (\Throwable $e) {
                $this->flash('danger', 'สำรองไม่สำเร็จ: ' . $e->getMessage());
            }
        } elseif ($act === 'keep') {
            $d = max(7, min(3650, $this->request->int('backup_keep_days', 90)));
            set_setting('backup_keep_days', (string) $d);
            $this->flash('ok', 'ตั้งให้เก็บไฟล์สำรองย้อนหลัง ' . $d . ' วัน');
        }
        return $this->redirect('admin/backups');
    }
}
