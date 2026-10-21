<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Maintenance;
use App\Models\Payment;

/** เมนูด้านข้างของหลังบ้าน พร้อมตัวเลขแจ้งเตือนบนเมนู */
final class AdminNavService
{
    /**
     * @return array<string,array<int,array{path:string,key:string,icon:string,label:string,count:int}>>
     *         กลุ่มเมนู => รายการ  (พนักงานไม่เห็นเมนูของเจ้าของร้าน กลุ่มที่ไม่เหลือเมนูเลยจะไม่แสดง)
     */
    public static function menu(): array
    {
        $cntPending = Booking::countByStatus('pending');
        $cntSlip    = Payment::pendingCount();
        $cntMaint   = Maintenance::dueCount(date('Y-m-d', strtotime('+' . (int) setting('reminder_days_before', '30') . ' days')));

        // owner = true → เห็นเฉพาะเจ้าของร้าน (role admin)
        $menu = [
            'งานประจำวัน' => [
                ['admin/dashboard', 'dashboard',  '📊', 'ภาพรวม', 0, false],
                ['admin/bookings',  'bookings',   '📅', 'การจอง', $cntPending, false],
                ['admin/calendar',  'calendar',   '🗓️', 'ปฏิทินรถ', 0, false],
                ['admin/payments',  'payments',   '💰', 'ชำระเงิน', $cntSlip, false],
            ],
            'ข้อมูลหลัก' => [
                ['admin/cars',        'cars',        '🚗', 'รถ', 0, false],
                ['admin/car-types',   'car-types',   '🏷️', 'ประเภทรถ', 0, true],
                ['admin/customers',   'customers',   '👤', 'ลูกค้า', 0, false],
                ['admin/maintenance', 'maintenance', '🔧', 'งานประจำรถ', $cntMaint, false],
            ],
            'เอกสาร & รายงาน' => [
                ['admin/documents',      'documents',      '📄', 'ใบเสนอราคา/ใบเสร็จ', 0, false],
                ['admin/reports',        'reports',        '📈', 'รายงาน', 0, true],
                ['admin/monthly-report', 'monthly-report', '🗒️', 'รายงานรายเดือน', 0, true],
                ['admin/ab-report',      'ab-report',      '🆎', 'เทียบผล UX', 0, true],
            ],
            'ระบบ' => [
                ['admin/line',     'line',     '💬', 'เชื่อมต่อ LINE', 0, true],
                ['admin/settings', 'settings', '⚙️', 'ตั้งค่า', 0, true],
                ['admin/users',    'users',    '🔑', 'ผู้ใช้งาน', 0, true],
                ['admin/backups',  'backups',  '🗄️', 'สำรองข้อมูล', 0, true],
                ['admin/health',   'health',   '🩺', 'สุขภาพระบบ', 0, true],
            ],
        ];

        $isAdmin = AuthService::isAdmin();
        $out = [];
        foreach ($menu as $group => $items) {
            $rows = [];
            foreach ($items as [$path, $key, $icon, $label, $count, $ownerOnly]) {
                if ($ownerOnly && !$isAdmin) {
                    continue;
                }
                $rows[] = ['path' => $path, 'key' => $key, 'icon' => $icon, 'label' => $label, 'count' => (int) $count];
            }
            if ($rows) {
                $out[$group] = $rows;
            }
        }
        return $out;
    }
}
