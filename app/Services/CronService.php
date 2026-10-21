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
        $today     = date('Y-m-d');
        $lines     = [];
        $adminLine = setting('line_admin_user_id');
        $adminMail = setting('notify_admin_email');

        // ------------------------------------------------------------
        // 1) งานประจำรถใกล้ครบกำหนด
        // ------------------------------------------------------------
        $warnDays = (int) setting('reminder_days_before', '30');
        $tasks = Maintenance::needingReminder(date('Y-m-d', strtotime("+$warnDays days")), $today);
        if ($tasks) {
            $msg = "🔧 งานประจำรถที่ต้องจัดการ\n";
            foreach ($tasks as $t) {
                $left = (int) floor((strtotime($t['due_date']) - strtotime($today)) / 86400);
                $msg .= sprintf("• %s (%s) — %s %s\n", $t['title'], $t['plate'],
                    $left < 0 ? 'เลยกำหนด ' . abs($left) . ' วัน' : 'อีก ' . $left . ' วัน',
                    thai_date($t['due_date']));
                Maintenance::markNotified((int) $t['id']);
            }
            NotificationService::queue('line', $adminLine, $msg, null, 'maintenances', null);
            NotificationService::queue('email', $adminMail, $msg, 'งานประจำรถที่ต้องจัดการ');
            $lines[] = 'maintenance reminders: ' . count($tasks);
        }

        // ------------------------------------------------------------
        // 2) งานวันนี้
        // ------------------------------------------------------------
        $pickups = Booking::pickupsOn($today);
        $returns = Booking::returnsOn($today);
        if ($pickups || $returns) {
            $msg = '📅 งานวันนี้ ' . thai_date($today, false, true) . "\n";
            if ($pickups) {
                $msg .= "\n🚗 ส่งมอบรถ (" . count($pickups) . ")\n";
                foreach ($pickups as $p) {
                    $msg .= sprintf("• %s น. %s (%s) — %s %s\n", date('H:i', strtotime($p['pickup_at'])),
                        $p['brand'] . ' ' . $p['model'], $p['plate'], $p['contact_name'], $p['contact_phone']);
                }
            }
            if ($returns) {
                $msg .= "\n🔙 รับคืนรถ (" . count($returns) . ")\n";
                foreach ($returns as $r) {
                    $msg .= sprintf("• %s น. %s (%s) — %s %s\n", date('H:i', strtotime($r['return_at'])),
                        $r['brand'] . ' ' . $r['model'], $r['plate'], $r['contact_name'], $r['contact_phone']);
                }
            }
            NotificationService::queue('line', $adminLine, $msg, null, 'bookings', null);
            NotificationService::queue('email', $adminMail, $msg, 'งานวันนี้ ' . thai_date($today));
            $lines[] = 'today jobs: ' . (count($pickups) + count($returns));
        }

        // ------------------------------------------------------------
        // 2.5) ยกเลิกการจองที่ไม่จ่ายมัดจำตามเวลาที่ตั้งไว้ (ปิดไว้เป็นค่าตั้งต้น — เปิดที่ หลังบ้าน → ตั้งค่า)
        //      การจองที่ยังไม่จ่ายก็กันรถไว้ไม่ให้คนอื่นจอง ถ้าไม่มีใครมายกเลิก รถคันนั้นจะขายไม่ได้ในช่วงนั้น
        //      ยกเลิกเฉพาะรายการที่: ยังรอยืนยัน · ยังไม่มีเงินเข้าเลย · ไม่มีสลิปรอตรวจ · จองผ่านหน้าเว็บ
        // ------------------------------------------------------------
        $expH = (int) setting('pending_expire_hours', '0');
        if ($expH > 0) {
            $gone = Booking::unpaidExpired(date('Y-m-d H:i:s', time() - $expH * 3600));
            foreach ($gone as $g) {
                Booking::cancelPending((int) $g['id'], 'ระบบยกเลิกอัตโนมัติ: ไม่ชำระมัดจำภายใน ' . $expH . ' ชั่วโมง');
                ActivityLog::log('booking_auto_cancelled', 'bookings', (int) $g['id'], $g['booking_no']);
            }
            if ($gone) {
                NotificationService::queue('line', $adminLine, "🗑️ ระบบยกเลิกการจองที่ไม่ชำระมัดจำภายใน {$expH} ชม. (" . count($gone) . " รายการ)\n"
                    . implode("\n", array_map(fn($g) => '• ' . $g['booking_no'], array_slice($gone, 0, 15))));
                $lines[] = 'auto-cancelled unpaid: ' . count($gone);
            }
        }

        // ------------------------------------------------------------
        // 3) จองค้างยืนยันเกิน 24 ชม.
        // ------------------------------------------------------------
        $stale = Booking::stalePending();
        if ($stale) {
            $msg = '⏳ การจองค้างยืนยันเกิน 24 ชั่วโมง (' . count($stale) . " รายการ)\n";
            foreach ($stale as $s) {
                $msg .= sprintf("• %s %s (%s) รับรถ %s\n", $s['booking_no'], $s['contact_name'],
                    $s['contact_phone'], thai_date($s['pickup_at'], true));
            }
            NotificationService::queue('line', $adminLine, $msg);
            $lines[] = 'stale pending: ' . count($stale);
        }

        // ------------------------------------------------------------
        // 4) รถเลยกำหนดคืน
        // ------------------------------------------------------------
        $overdue = Booking::overdue();
        if ($overdue) {
            $msg = '⚠️ รถเลยกำหนดคืน (' . count($overdue) . " คัน)\n";
            foreach ($overdue as $o) {
                $hours = (int) floor((time() - strtotime($o['return_at'])) / 3600);
                $msg .= sprintf("• %s (%s) %s %s — เลยมา %d ชม. (ค่าปรับประมาณ %s บาท)\n",
                    $o['booking_no'], $o['plate'], $o['contact_name'], $o['contact_phone'], $hours,
                    money0(PricingService::calcReturnCharges($o, ['price_day' => $o['price_day']], date('Y-m-d H:i:s'), null, null)['total']));
            }
            NotificationService::queue('line', $adminLine, $msg);
            NotificationService::queue('email', $adminMail, $msg, 'รถเลยกำหนดคืน');
            $lines[] = 'overdue: ' . count($overdue);
        }

        // ------------------------------------------------------------
        // 4.5) ลองส่งข้อความที่ค้างส่งไม่สำเร็จอีกรอบ — ต้องทำก่อนขั้นเตือนลูกค้า
        //      (ถ้าทำทีหลัง ข้อความเตือนที่เคยส่งไม่ผ่านจะถูกสร้างใหม่ในขั้นที่ 5 แล้วถูกปลุกซ้ำอีกรอบ = ลูกค้าได้สองข้อความ)
        // ------------------------------------------------------------
        $retry = Notification::retryRecentFailures(date('Y-m-d H:i:s', time() - 20 * 3600));
        if ($retry) {
            $lines[] = 'retry failed notifications: ' . $retry;
        }

        // ------------------------------------------------------------
        // 5) เตือนลูกค้าทาง LINE — เฉพาะคนที่ผูกบัญชีไว้ และส่งครั้งเดียวต่อการจอง
        //    ช่วงที่จับ = วันนี้ถึงพรุ่งนี้ เพื่อให้การเช่า 1 วันที่จองกระชั้นชิดยังได้ข้อความ
        // ------------------------------------------------------------
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $remind   = 0;
        foreach (Booking::confirmedPickupBetween($today, $tomorrow) as $bk) {
            if (LineService::notifyCustomer('pickup_reminder', $bk, null, [], true)) {
                $remind++;
            }
        }
        foreach (Booking::pickedUpReturnBetween($today, $tomorrow) as $bk) {
            if (LineService::notifyCustomer('return_reminder', $bk, null, [], true)) {
                $remind++;
            }
        }
        if ($remind) {
            $lines[] = 'customer reminders: ' . $remind;
        }

        // ------------------------------------------------------------
        // 6) สำรองฐานข้อมูล — วันละครั้ง (ถ้า cron ถูกเรียกถี่กว่านั้นจะข้าม ไม่สำรองซ้ำ)
        // ------------------------------------------------------------
        try {
            $lastBk = (string) setting('backup_last_at');
            if ($lastBk === '' || time() - strtotime($lastBk) > 20 * 3600) {
                $bk      = BackupService::create();
                $pruned  = BackupService::prune();
                $lines[] = 'backup: ' . human_bytes($bk['size']) . ', ' . $bk['rows'] . ' rows' . ($pruned ? ", pruned $pruned" : '');

                // เดือนละครั้ง: บอกเจ้าของร้านให้ดาวน์โหลดสำเนาไปเก็บนอกเซิร์ฟเวอร์
                // (ไม่แนบไฟล์ไปกับอีเมล เพราะในฐานข้อมูลมีข้อมูลส่วนบุคคลของลูกค้า)
                $ym = date('Y-m');
                if ((string) setting('backup_notice_month') !== $ym) {
                    $msg = '🗄️ สำเนาฐานข้อมูลประจำเดือน ' . thai_date(date('Y-m-d')) . " พร้อมแล้ว\n"
                         . 'ขนาด ' . human_bytes($bk['size']) . ' · ' . number_format($bk['rows']) . " แถว\n"
                         . "เข้าสู่ระบบแล้วดาวน์โหลดไปเก็บไว้นอกเซิร์ฟเวอร์ได้ที่\n" . site_url('admin/backups');
                    NotificationService::queue('line', $adminLine, $msg);
                    NotificationService::queue('email', $adminMail, $msg, 'สำเนาฐานข้อมูลประจำเดือน');
                    set_setting('backup_notice_month', $ym);
                }
            }
        } catch (\Throwable $e) {
            $lines[] = 'backup FAILED: ' . $e->getMessage();
            NotificationService::queue('line', $adminLine, "⚠️ สำรองฐานข้อมูลอัตโนมัติไม่สำเร็จ\n" . $e->getMessage());
            NotificationService::queue('email', $adminMail, "สำรองฐานข้อมูลอัตโนมัติไม่สำเร็จ\n" . $e->getMessage(), 'สำรองข้อมูลไม่สำเร็จ');
        }

        // ------------------------------------------------------------
        // 7) ส่งคิวที่ค้าง + เก็บกวาด
        // ------------------------------------------------------------
        $sent = NotificationService::flush(50);
        $lines[] = 'notifications sent: ' . $sent;

        try {
            LineEvent::purgeOld();
        } catch (\Throwable $e) {
            // ยังไม่ได้อัปเดตฐานข้อมูลเป็นเวอร์ชัน 4 — ข้ามไป ไม่ให้งานรายวันล้ม
        }
        set_setting('cron_last_at', date('Y-m-d H:i:s'));

        ActivityLog::log('cron_daily', null, null, implode(' | ', $lines));
        return $lines;
    }
}
