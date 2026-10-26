<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\LineEvent;
use App\Models\Maintenance;
use App\Models\Notification;

/**
 * งานอัตโนมัติรายวัน — ตั้งใน cPanel → Cron Jobs (แนะนำวันละครั้ง 08:00)
 *
 * หน้าที่:
 *   1. เตือนงานประจำรถที่ใกล้ครบกำหนด (ประกัน / พ.ร.บ. / ภาษี / เช็กระยะ)
 *   2. เตือนงานส่งมอบ–รับคืนรถของวันนี้
 *   3. เตือนการจองที่ค้างยืนยันเกิน 24 ชั่วโมง
 *   4. เตือนรถที่เลยกำหนดคืน
 *   5. เตือนลูกค้าทาง LINE ล่วงหน้า 1 วันก่อนรับรถและก่อนคืนรถ
 *   6. สำรองฐานข้อมูล (วันละครั้ง) + ลบไฟล์สำรองที่เก่าเกินกำหนด + แจ้งเจ้าของร้านเดือนละครั้งให้ดาวน์โหลดสำเนา
 *   7. ส่งคิวแจ้งเตือนที่ค้างอยู่ + ล้างบันทึกเหตุการณ์ LINE ที่เก่าเกิน 30 วัน
 */
final class CronService
{
    /** @return string[] บรรทัดสรุปผลการทำงาน */
    public static function run(): array
    {
        $today = date('Y-m-d');
        $lines = [];

        self::remindMaintenance($today, $lines);
        self::sendTodayJobs($today, $lines);
        self::cancelUnpaidBookings($lines);
        self::warnStalePending($lines);
        self::warnOverdue($lines);

        // ลองส่งข้อความที่ค้างส่งไม่สำเร็จอีกรอบ — ต้องทำก่อนขั้นเตือนลูกค้า
        // (ถ้าทำทีหลัง ข้อความเตือนที่เคยส่งไม่ผ่านจะถูกสร้างใหม่แล้วถูกปลุกซ้ำ = ลูกค้าได้สองข้อความ)
        $retry = Notification::retryRecentFailures(date('Y-m-d H:i:s', time() - 20 * 3600));
        if ($retry) {
            $lines[] = 'retry failed notifications: ' . $retry;
        }

        self::remindCustomers($today, $lines);
        self::backupDatabase($lines);
        self::flushAndClean($lines);

        ActivityLog::log('cron_daily', null, null, implode(' | ', $lines));
        return $lines;
    }

    /** 1) งานประจำรถใกล้ครบกำหนด (ประกัน / พ.ร.บ. / ภาษี / เช็กระยะ) */
    private static function remindMaintenance(string $today, array &$lines): void
    {
        $warnDays = (int) setting('reminder_days_before', '30');
        $tasks = Maintenance::needingReminder(date('Y-m-d', strtotime("+$warnDays days")), $today);
        if (!$tasks) {
            return;
        }
        $msg = "🔧 งานประจำรถที่ต้องจัดการ\n";
        foreach ($tasks as $task) {
            $left = (int) floor((strtotime($task['due_date']) - strtotime($today)) / 86400);
            if ($left < 0) {
                $when = 'เลยกำหนด ' . abs($left) . ' วัน';
            } else {
                $when = 'อีก ' . $left . ' วัน';
            }
            $msg .= sprintf("• %s (%s) — %s %s\n", $task['title'], $task['plate'], $when, thai_date($task['due_date']));
        }
        NotificationService::queue('line', setting('line_admin_user_id'), $msg, null, 'maintenances', null);
        NotificationService::queue('email', setting('notify_admin_email'), $msg, 'งานประจำรถที่ต้องจัดการ');
        // ทำเครื่องหมาย "เตือนแล้ว" หลังเข้าคิวสำเร็จ (ถ้าเข้าคิวพลาดก่อนหน้านี้ รอบถัดไปจะเตือนใหม่)
        foreach ($tasks as $task) {
            Maintenance::markNotified((int) $task['id']);
        }
        $lines[] = 'maintenance reminders: ' . count($tasks);
    }

    /** 2) สรุปงานส่งมอบ–รับคืนรถของวันนี้ ส่งให้เจ้าของร้าน */
    private static function sendTodayJobs(string $today, array &$lines): void
    {
        $pickups = Booking::pickupsOn($today);
        $returns = Booking::returnsOn($today);
        if (!$pickups && !$returns) {
            return;
        }
        $msg = '📅 งานวันนี้ ' . thai_date($today, false, true) . "\n";
        if ($pickups) {
            $msg .= "\n🚗 ส่งมอบรถ (" . count($pickups) . ")\n" . self::jobLines($pickups, 'pickup_at');
        }
        if ($returns) {
            $msg .= "\n🔙 รับคืนรถ (" . count($returns) . ")\n" . self::jobLines($returns, 'return_at');
        }
        NotificationService::queue('line', setting('line_admin_user_id'), $msg, null, 'bookings', null);
        NotificationService::queue('email', setting('notify_admin_email'), $msg, 'งานวันนี้ ' . thai_date($today));
        $lines[] = 'today jobs: ' . (count($pickups) + count($returns));
    }

    /** บรรทัดรายการงาน เช่น "• 10:00 น. Toyota Yaris (1กก1234) — สมชาย 0812345678" */
    private static function jobLines(array $bookings, string $timeColumn): string
    {
        $text = '';
        foreach ($bookings as $b) {
            $text .= sprintf("• %s น. %s (%s) — %s %s\n", date('H:i', strtotime($b[$timeColumn])),
                $b['brand'] . ' ' . $b['model'], $b['plate'], $b['contact_name'], $b['contact_phone']);
        }
        return $text;
    }

    /**
     * 2.5) ยกเลิกการจองที่ไม่จ่ายมัดจำตามเวลาที่ตั้งไว้ (ปิดไว้เป็นค่าตั้งต้น — เปิดที่ หลังบ้าน → ตั้งค่า)
     * การจองที่ยังไม่จ่ายก็กันรถไว้ไม่ให้คนอื่นจอง ถ้าไม่มีใครมายกเลิก รถคันนั้นจะขายไม่ได้ในช่วงนั้น
     * ยกเลิกเฉพาะรายการที่: ยังรอยืนยัน · ยังไม่มีเงินเข้าเลย · ไม่มีสลิปรอตรวจ · จองผ่านหน้าเว็บ
     */
    private static function cancelUnpaidBookings(array &$lines): void
    {
        $hours = (int) setting('pending_expire_hours', '0');
        if ($hours <= 0) {
            return;
        }
        $expired = Booking::unpaidExpired(date('Y-m-d H:i:s', time() - $hours * 3600));
        if (!$expired) {
            return;
        }
        $numbers = [];
        foreach ($expired as $b) {
            Booking::cancelPending((int) $b['id'], 'ระบบยกเลิกอัตโนมัติ: ไม่ชำระมัดจำภายใน ' . $hours . ' ชั่วโมง');
            ActivityLog::log('booking_auto_cancelled', 'bookings', (int) $b['id'], $b['booking_no']);
            $numbers[] = '• ' . $b['booking_no'];
        }
        $msg = "🗑️ ระบบยกเลิกการจองที่ไม่ชำระมัดจำภายใน {$hours} ชม. (" . count($expired) . " รายการ)\n"
             . implode("\n", array_slice($numbers, 0, 15));
        NotificationService::queue('line', setting('line_admin_user_id'), $msg);
        $lines[] = 'auto-cancelled unpaid: ' . count($expired);
    }

    /** 3) จองค้างยืนยันเกิน 24 ชม. */
    private static function warnStalePending(array &$lines): void
    {
        $stale = Booking::stalePending();
        if (!$stale) {
            return;
        }
        $msg = '⏳ การจองค้างยืนยันเกิน 24 ชั่วโมง (' . count($stale) . " รายการ)\n";
        foreach ($stale as $b) {
            $msg .= sprintf("• %s %s (%s) รับรถ %s\n", $b['booking_no'], $b['contact_name'],
                $b['contact_phone'], thai_date($b['pickup_at'], true));
        }
        NotificationService::queue('line', setting('line_admin_user_id'), $msg);
        $lines[] = 'stale pending: ' . count($stale);
    }

    /** 4) รถเลยกำหนดคืน พร้อมค่าปรับโดยประมาณ */
    private static function warnOverdue(array &$lines): void
    {
        $overdue = Booking::overdue();
        if (!$overdue) {
            return;
        }
        $msg = '⚠️ รถเลยกำหนดคืน (' . count($overdue) . " คัน)\n";
        foreach ($overdue as $b) {
            $hours = (int) floor((time() - strtotime($b['return_at'])) / 3600);
            $late  = LateReturnService::calculate($b, ['price_day' => $b['price_day']], date('Y-m-d H:i:s'), null, null);
            $msg .= sprintf("• %s (%s) %s %s — เลยมา %d ชม. (ค่าปรับประมาณ %s บาท)\n",
                $b['booking_no'], $b['plate'], $b['contact_name'], $b['contact_phone'], $hours, money0($late['total']));
        }
        NotificationService::queue('line', setting('line_admin_user_id'), $msg);
        NotificationService::queue('email', setting('notify_admin_email'), $msg, 'รถเลยกำหนดคืน');
        $lines[] = 'overdue: ' . count($overdue);
    }

    /**
     * 5) เตือนลูกค้าทาง LINE — เฉพาะคนที่ผูกบัญชีไว้ และส่งครั้งเดียวต่อการจอง
     * ช่วงที่จับ = วันนี้ถึงพรุ่งนี้ เพื่อให้การเช่า 1 วันที่จองกระชั้นชิดยังได้ข้อความ
     */
    private static function remindCustomers(string $today, array &$lines): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $count    = 0;
        foreach (Booking::confirmedPickupBetween($today, $tomorrow) as $b) {
            if (LineService::notifyCustomer('pickup_reminder', $b, null, [], true)) {
                $count++;
            }
        }
        foreach (Booking::pickedUpReturnBetween($today, $tomorrow) as $b) {
            if (LineService::notifyCustomer('return_reminder', $b, null, [], true)) {
                $count++;
            }
        }
        if ($count) {
            $lines[] = 'customer reminders: ' . $count;
        }
    }

    /** 6) สำรองฐานข้อมูล — วันละครั้ง (ถ้า cron ถูกเรียกถี่กว่านั้นจะข้าม ไม่สำรองซ้ำ) */
    private static function backupDatabase(array &$lines): void
    {
        try {
            $last = (string) setting('backup_last_at');
            if ($last !== '' && time() - strtotime($last) <= 20 * 3600) {
                return;
            }
            $backup = BackupService::create();
            $pruned = BackupService::prune();
            $lines[] = 'backup: ' . human_bytes($backup['size']) . ', ' . $backup['rows'] . ' rows' . ($pruned ? ", pruned $pruned" : '');
            self::noticeMonthlyBackup($backup);
        } catch (\Throwable $e) {
            $lines[] = 'backup FAILED: ' . $e->getMessage();
            NotificationService::queue('line', setting('line_admin_user_id'), "⚠️ สำรองฐานข้อมูลอัตโนมัติไม่สำเร็จ\n" . $e->getMessage());
            NotificationService::queue('email', setting('notify_admin_email'), "สำรองฐานข้อมูลอัตโนมัติไม่สำเร็จ\n" . $e->getMessage(), 'สำรองข้อมูลไม่สำเร็จ');
        }
    }

    /**
     * เดือนละครั้ง: บอกเจ้าของร้านให้ดาวน์โหลดสำเนาไปเก็บนอกเซิร์ฟเวอร์
     * (ไม่แนบไฟล์ไปกับอีเมล เพราะในฐานข้อมูลมีข้อมูลส่วนบุคคลของลูกค้า)
     */
    private static function noticeMonthlyBackup(array $backup): void
    {
        $month = date('Y-m');
        if ((string) setting('backup_notice_month') === $month) {
            return;
        }
        $msg = '🗄️ สำเนาฐานข้อมูลประจำเดือน ' . thai_date(date('Y-m-d')) . " พร้อมแล้ว\n"
             . 'ขนาด ' . human_bytes($backup['size']) . ' · ' . number_format($backup['rows']) . " แถว\n"
             . "เข้าสู่ระบบแล้วดาวน์โหลดไปเก็บไว้นอกเซิร์ฟเวอร์ได้ที่\n" . site_url('admin/backups');
        NotificationService::queue('line', setting('line_admin_user_id'), $msg);
        NotificationService::queue('email', setting('notify_admin_email'), $msg, 'สำเนาฐานข้อมูลประจำเดือน');
        set_setting('backup_notice_month', $month);
    }

    /** 7) ส่งคิวที่ค้าง + เก็บกวาด */
    private static function flushAndClean(array &$lines): void
    {
        $lines[] = 'notifications sent: ' . NotificationService::flush(50);

        try {
            LineEvent::purgeOld();
        } catch (\Throwable $e) {
            // ยังไม่ได้อัปเดตฐานข้อมูลเป็นเวอร์ชัน 4 — ข้ามไป ไม่ให้งานรายวันล้ม
        }
        set_setting('cron_last_at', date('Y-m-d H:i:s'));
    }
}
