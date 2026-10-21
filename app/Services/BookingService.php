<?php

namespace App\Services;

/**
 * ฟังก์ชันเล็กๆ ที่การจองทุกแบบใช้ร่วมกัน
 *
 * งานใหญ่แยกไว้ในคลาสของตัวเอง (อ่านทีละไฟล์ได้ง่ายกว่า):
 *   WebBookingService     ลูกค้าจองจากหน้าเว็บ
 *   AdminBookingService   พนักงานสร้าง/แก้การจองจากหลังบ้าน
 *   BookingActionService  ปุ่มบนหน้างาน (ยืนยัน ส่งมอบ ยกเลิก รับเงิน ...)
 *   ReturnService         รับคืนรถ + คิดค่าใช้จ่ายเพิ่ม
 *   ExtendService         เช่าต่อ / เลื่อนวันคืน
 *   SecurityDepositService ปิดเงินประกัน
 */
final class BookingService
{
    /** สถานะที่ถือว่า "ส่งมอบรถแล้วหรือปิดแล้ว" — ห้ามแก้รถ วันเวลา และราคา */
    private const LOCKED_STATUSES = ['picked_up', 'returned', 'completed', 'cancelled'];

    /**
     * ข้อความแจ้งผู้ใช้เมื่อเกิดข้อผิดพลาด
     * - RuntimeException ที่เราโยนเอง = ข้อความที่ตั้งใจให้ผู้ใช้อ่าน แสดงตามนั้น
     * - ข้อผิดพลาดอื่น (เช่น ฐานข้อมูล) = บันทึกลง log แล้วแสดงข้อความกลางแทน
     */
    public static function publicError(\Throwable $e, string $fallback = 'ทำรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'): string
    {
        $isOurMessage = $e instanceof \RuntimeException && !($e instanceof \PDOException);
        if ($isOurMessage) {
            return $e->getMessage();
        }

        error_log('[thonglorcarrent] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return $fallback;
    }

    /** จำนวนบริการเสริมจากฟอร์ม: จำกัด 0–5 ต่อรายการ */
    public static function cleanAddons(array $raw): array
    {
        $clean = [];
        foreach ($raw as $key => $value) {
            $number = is_scalar($value) ? (int) $value : 0;
            $clean[$key] = max(0, min(5, $number));
        }
        return $clean;
    }

    /** การจองนี้ถูกล็อกหรือยัง (ส่งมอบรถแล้ว / ปิดงานแล้ว / ยกเลิกแล้ว) */
    public static function isLocked(?array $booking): bool
    {
        return $booking !== null && in_array($booking['status'], self::LOCKED_STATUSES, true);
    }

    /** แปลงวันเวลาจากฟอร์มเป็นรูปแบบฐานข้อมูล — ไม่ถูกต้องคืน '' */
    public static function toDb(string $text): string
    {
        $time = $text !== '' ? strtotime($text) : false;
        return $time === false ? '' : date('Y-m-d H:i:s', $time);
    }

    /** คอลัมน์ราคาที่ได้จาก PricingService::quote() เพื่อบันทึกลงตาราง bookings */
    public static function priceColumns(array $quote): array
    {
        $breakdown = ['rental' => $quote['rental_lines'], 'addons' => $quote['addon_lines']];

        return [
            'rental_days'      => $quote['days'],
            'rate_breakdown'   => json_encode($breakdown, JSON_UNESCAPED_UNICODE),
            'rental_amount'    => $quote['rental_amount'],
            'addon_amount'     => $quote['addon_amount'],
            'discount'         => $quote['discount'],
            'subtotal'         => $quote['subtotal'],
            'vat_rate'         => $quote['vat_rate'],
            'vat_amount'       => $quote['vat_amount'],
            'grand_total'      => $quote['grand_total'],
            'deposit_amount'   => $quote['deposit_amount'],
            'security_deposit' => $quote['security_deposit'],
        ];
    }
}
