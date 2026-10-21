<?php

namespace App\Middleware;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

/** ต้องล็อกอินหลังบ้านก่อน (admin หรือ staff) */
final class AuthMiddleware
{
    public function handle(Request $request): ?Response
    {
        if (AuthService::check()) {
            return null;
        }
        Flash::add('warn', 'กรุณาเข้าสู่ระบบก่อนใช้งาน');
        return Response::redirect('admin/login');
    }
}
