<?php
namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Booking;

/**
 * ลูกค้าตรวจสอบการจองด้วย "เลขที่จอง + เบอร์โทร"
 * เลขที่จองเดาได้ (ต่อเนื่องรายวัน) เบอร์โทรจึงเป็นตัวยืนยัน และต้องจำกัดการเดาสุ่ม
 */
final class TrackService
{
    /** ค้นไม่พบเกินกี่ครั้งต่อ IP ใน 15 นาที แล้วระงับการค้นชั่วคราว (กันเดาเลขที่จอง + เบอร์โทร) */
    public const MAX_FAILURES = 20;
    public const WINDOW_SECONDS = 900;

    public static function isBlocked(string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);
        return ActivityLog::countByIpSince('track_failed', $ip, $since) >= self::MAX_FAILURES;
    }

    /**
     * หาการจอง — ไม่พบ/เบอร์ไม่ตรงให้ผลเหมือนกัน (ไม่บอกว่าเลขที่จองมีอยู่จริงหรือไม่) และนับเป็นความล้มเหลว
     */
    public static function find(string $bookingNo, string $phone, string $ip): ?array
    {
        $normalized = Phone::normalize($phone);
        $booking = Booking::findByNo(strtoupper(trim($bookingNo)));
        if ($booking !== null && $normalized !== '' && hash_equals((string) $booking['contact_phone'], $normalized)) {
            return $booking;
        }
        ActivityLog::log('track_failed', null, null, null, null, $ip);
        return null;
    }
}
