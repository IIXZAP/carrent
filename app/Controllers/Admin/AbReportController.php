<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\AbEvent;

/**
 * เทียบผล UX สองเวอร์ชัน — ต่อฐานข้อมูลของ "อีกเวอร์ชัน" เพิ่มอีกหนึ่งตัวแล้วดึงสถิติมาวางเทียบกัน
 * ตั้งค่าชื่อฐานข้อมูลอีกฝั่งได้ที่ หลังบ้าน → ตั้งค่า → ab_other_db
 */
class AbReportController extends AdminController
{
    public function index(): Response
    {
        $r      = $this->request;
        $from   = $r->str('from', 0, date('Y-m-d', strtotime('-29 days')));
        $to     = $r->str('to', 0, date('Y-m-d'));
        $device = $r->str('device', 0, 'all');
        $device = in_array($device, ['all', 'mobile', 'desktop'], true) ? $device : 'all';

        $mine = AbEvent::funnel($from, $to, $device);

        // ---------- ต่อฐานข้อมูลอีกฝั่ง ----------
        $otherDb    = trim((string) setting('ab_other_db', DB_NAME === 'carrent' ? 'carrent2' : 'carrent'));
        $otherUser  = trim((string) setting('ab_other_user', '')) ?: DB_USER;
        $otherPass  = (string) setting('ab_other_pass', '') !== '' ? (string) setting('ab_other_pass') : DB_PASS;
        $other      = null;
        $otherError = null;

        if ($otherDb !== '' && $otherDb !== DB_NAME) {
            try {
                $pdo = new \PDO(
                    sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, $otherDb, DB_CHARSET),
                    $otherUser,
                    $otherPass,
                    [
                        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                        \PDO::ATTR_EMULATE_PREPARES   => false,
                    ]
                );
                $other = AbEvent::funnel($from, $to, $device, $pdo);
            } catch (\Throwable $e) {
                $otherError = $e->getMessage();
            }
        }

        // เวอร์ชันนี้คือ A หรือ B
        $meIsA = !defined('SITE_VARIANT') || SITE_VARIANT === 'A';

        return $this->view('admin/ab_report', [
            'title'      => 'เทียบผล UX สองเวอร์ชัน',
            'from'       => $from,
            'to'         => $to,
            'device'     => $device,
            'A'          => $meIsA ? $mine : $other,
            'B'          => $meIsA ? $other : $mine,
            'labelA'     => 'A · ดีไซน์เดิม',
            'labelB'     => 'B · ดีไซน์ใหม่',
            'steps'      => AbEvent::STEPS,
            'otherDb'    => $otherDb,
            'otherError' => $otherError,
        ]);
    }
}
