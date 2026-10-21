<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Response;
use App\Services\HealthService;

/**
 * จุดตรวจสำหรับบริการเฝ้าระวังระบบล่ม (uptime monitor)
 *
 *   /health            → ตอบ "OK" (200) เมื่อเว็บและฐานข้อมูลทำงานปกติ ไม่เช่นนั้นตอบ "FAIL" (503)
 *                        ไม่เปิดเผยข้อมูลใดๆ จึงเปิดสาธารณะได้
 *   /health?key=รหัส   → ตอบผลตรวจทุกข้อเป็น JSON (รหัสดูได้ที่ หลังบ้าน → สุขภาพระบบ)
 */
class HealthController extends Controller
{
    public function index(): Response
    {
        try {
            Database::value('SELECT 1');
            $real = (string) setting('health_key');
        } catch (\Throwable $e) {
            return $this->plain('FAIL', 503);
        }

        $key = $this->request->str('key');
        if ($key === '' || $real === '' || !hash_equals($real, $key)) {
            return $this->plain('OK', 200);
        }

        $out = ['status' => 'ok', 'checked_at' => date('c'), 'checks' => []];
        foreach (HealthService::checks() as [$title, $level, $found, $advice]) {
            $out['checks'][] = ['title' => $title, 'level' => $level, 'found' => $found, 'advice' => $advice];
            if ($level === 'fail') {
                $out['status'] = 'fail';
            } elseif ($level === 'warn' && $out['status'] === 'ok') {
                $out['status'] = 'warn';
            }
        }
        return Response::text((string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 200, 'application/json')
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    private function plain(string $text, int $status): Response
    {
        return Response::text($text, $status)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
