<?php
namespace App\Services;

/** ป้ายแสดงสถานะการจอง (ใช้ในหลังบ้านและหน้าลูกค้า) */
final class BookingStatus
{
    public const STATUS = [
        'pending'   => 'รอยืนยัน',
        'confirmed' => 'ยืนยันแล้ว',
        'picked_up' => 'รับรถแล้ว',
        'returned'  => 'คืนรถแล้ว',
        'completed' => 'เสร็จสิ้น',
        'cancelled' => 'ยกเลิก',
    ];

    public const PAYMENT = [
        'unpaid'       => 'ยังไม่ชำระ',
        'deposit_paid' => 'จ่ายมัดจำแล้ว',
        'paid'         => 'ชำระครบ',
        'refunded'     => 'คืนเงินแล้ว',
    ];

    public const SOURCE = [
        'web'   => 'หน้าเว็บ',
        'admin' => 'หลังบ้าน',
        'line'  => 'LINE',
        'phone' => 'โทรศัพท์',
    ];

    /** ยกเลิกได้เฉพาะก่อนรับรถ */
    public static function canCancel(array $booking): bool
    {
        return in_array($booking['status'], ['pending', 'confirmed'], true);
    }
}
