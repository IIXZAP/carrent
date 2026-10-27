<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;

/** เมนูด้านข้างของหลังบ้าน พร้อมตัวเลขแจ้งเตือน — เพิ่มรายการเมื่อทำหน้านั้นเสร็จ */
final class AdminNav
{
    /** @return array<string,array<int,array{path:string,key:string,icon:string,label:string,count:int}>> */
    public static function menu(): array
    {
        $pending = (int) (Booking::countByStatus()['pending'] ?? 0);
        $slips = Payment::pendingCount();

        // [path, key, icon, label, count, เฉพาะเจ้าของร้าน]
        $menu = [
            'งานประจำวัน' => [
                ['/admin', 'dashboard', '📊', 'ภาพรวม', 0, false],
                ['/admin/bookings', 'bookings', '📅', 'การจอง', $pending, false],
                ['/admin/calendar', 'calendar', '🗓️', 'ปฏิทินรถ', 0, false],
                ['/admin/payments', 'payments', '💰', 'ชำระเงิน', $slips, false],
            ],
            'ข้อมูลหลัก' => [
                ['/admin/cars', 'cars', '🚗', 'รถ', 0, false],
                ['/admin/customers', 'customers', '👤', 'ลูกค้า', 0, false],
            ],
            'เอกสาร' => [
                ['/admin/documents', 'documents', '📄', 'ใบเสนอราคา/ใบเสร็จ', 0, false],
            ],
            'ระบบ' => [
                ['/admin/settings', 'settings', '⚙️', 'ตั้งค่า', 0, true],
            ],
        ];

        $isAdmin = Auth::isAdmin();
        $out = [];
        foreach ($menu as $group => $items) {
            $rows = [];
            foreach ($items as [$path, $key, $icon, $label, $count, $ownerOnly]) {
                if ($ownerOnly && !$isAdmin) {
                    continue;
                }
                $rows[] = ['path' => $path, 'key' => $key, 'icon' => $icon, 'label' => $label, 'count' => $count];
            }
            if ($rows) {
                $out[$group] = $rows;
            }
        }
        return $out;
    }
}
