<?php
namespace App\Controllers\Admin;

use App\Models\Car;

final class DashboardController
{
    public function index(): void
    {
        view('admin/dashboard', [
            'title'  => 'ภาพรวม',
            'counts' => Car::countByStatus(),
        ], 'layouts/admin');
    }
}
