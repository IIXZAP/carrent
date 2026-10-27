<?php
namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Setting;

/**
 * งานอัตโนมัติ — เรียกจาก bin/cron.php (ตั้งใน cPanel → Cron Jobs แนะนำทุกชั่วโมง)
 * งานที่ทำ: ยกเลิกจองที่ไม่จ่ายมัดจำ, ส่งแจ้งเตือน LINE ที่ค้าง/ลองใหม่
 * งานที่ยังไม่ได้ทำ (เตือนงานซ่อมบำรุง, เตือนรับ–คืนรถ, สำรองฐานข้อมูล) จะเพิ่มที่นี่ในขั้นถัดไป
 */
final class CronService
{
    /** @return string[] บรรทัดสรุปผล (ว่าง = ไม่มีอะไรต้องทำ) */
    public static function run(): array
    {
        $lines = [];

        $cancelled = BookingService::expireUnpaid((int) Setting::get('pending_expire_hours', '0'));
        if ($cancelled !== []) {
            $lines[] = 'auto-cancelled unpaid bookings: ' . count($cancelled) . ' (' . implode(', ', array_slice($cancelled, 0, 15)) . ')';
        }

        $lines = array_merge($lines, NotificationService::cron());

        if ($lines !== []) {
            ActivityLog::log('cron_run', null, null, implode(' | ', $lines));
        }
        return $lines;
    }
}
