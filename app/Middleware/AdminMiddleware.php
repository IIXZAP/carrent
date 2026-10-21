<?php

namespace App\Middleware;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

/** เฉพาะเจ้าของร้าน (role = admin) — ใช้ต่อจาก AuthMiddleware */
final class AdminMiddleware
{
    public function handle(Request $request): ?Response
    {
        if (AuthService::isAdmin()) {
            return null;
        }
        // พนักงานเปิดหน้าของเจ้าของร้าน → พากลับหน้าภาพรวมพร้อมบอกเหตุผล แทนหน้าขาวที่มีข้อความบรรทัดเดียว
        Flash::add('warn', 'หน้านี้สำหรับเจ้าของร้านเท่านั้น');
        return Response::redirect('admin/dashboard');
    }
}
