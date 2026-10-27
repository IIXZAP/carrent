<?php
namespace App\Controllers\Admin;

use App\Services\Auth;
use App\Services\DashboardService;

final class DashboardController
{
    /** ภาพรวม — รายได้ทั้งหมดเห็นเฉพาะเจ้าของร้าน พนักงานเห็นเฉพาะงาน */
    public function index(): void
    {
        view('admin/dashboard', ['title' => 'ภาพรวม'] + DashboardService::data(Auth::isAdmin()), 'layouts/admin');
    }
}
