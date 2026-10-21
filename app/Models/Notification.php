<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** คิวแจ้งเตือน (LINE / อีเมล) */
final class Notification extends Model
{
    protected static string $table = 'notifications';

    /** ใส่คิวแจ้งเตือน */
    public static function queue(string $channel, ?string $recipient, string $message, ?string $subject = null, ?string $refTable = null, ?int $refId = null, ?string $tplKey = null): int
    {
        // ผู้รับของร้านหลายคนรวมกันยาวเกินคอลัมน์ → เก็บเป็นค่าว่าง ตอนส่งระบบจะใช้รายชื่อผู้รับของร้านจากตั้งค่าเอง
        if ($recipient !== null && strlen($recipient) > 190) {
            $recipient = null;
        }
        return self::insert([
            'channel'   => $channel,
            'tpl_key'   => $tplKey,
            'recipient' => $recipient,
            'subject'   => $subject,
            'message'   => $message,
            'ref_table' => $refTable,
            'ref_id'    => $refId,
            'status'    => 'queued',
        ]);
    }

    public static function queued(int $limit = 20): array
    {
        $limit = max(1, min(500, $limit));
        return Database::all("SELECT * FROM notifications WHERE status = 'queued' ORDER BY id LIMIT $limit");
    }

    /**
     * จองแถวนี้ก่อนส่ง: ถ้ามีอีกคำขอกำลังส่งคิวพร้อมกัน (เช่น cron กับหน้าเว็บ) แถวเดียวกันจะไม่ถูกส่งซ้ำสองครั้ง
     * ระหว่างส่งสถานะเป็น failed + ข้อความ "กำลังส่ง" — ถ้าระบบล่มกลางทางจะค้างเป็น "ส่งไม่สำเร็จ" ให้เห็น ไม่หายเงียบ
     */
    public static function claim(int $id): bool
    {
        return Database::exec("UPDATE notifications SET status = 'failed', error = 'กำลังส่ง' WHERE id = :i AND status = 'queued'", [':i' => $id]) >= 1;
    }

    public static function markResult(int $id, bool $ok, ?string $error): void
    {
        self::updateById($id, [
            'status'  => $ok ? 'sent' : 'failed',
            'error'   => $ok ? null : mb_substr((string) $error, 0, 250),
            'sent_at' => $ok ? date('Y-m-d H:i:s') : null,
        ]);
    }

    /** ข้อความแบบนี้ของการจองนี้เคยเข้าคิว/ส่งไปแล้วหรือไม่ (กันส่งเตือนซ้ำ) */
    public static function alreadySent(string $tplKey, int $bookingId): bool
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM notifications
             WHERE tpl_key = :k AND ref_table = 'bookings' AND ref_id = :r AND status IN ('queued','sent')",
            [':k' => $tplKey, ':r' => $bookingId]
        ) > 0;
    }

    /** ปลดเครื่องหมาย "เตือนแล้ว" เพื่อให้ระบบเตือนใหม่ตามวันที่เปลี่ยน */
    public static function releaseReminder(string $tplKey, int $bookingId): void
    {
        Database::exec(
            "UPDATE notifications SET tpl_key = :new WHERE tpl_key = :old AND ref_table = 'bookings' AND ref_id = :i",
            [':new' => $tplKey . '_old', ':old' => $tplKey, ':i' => $bookingId]
        );
    }

    /** ข้อความล่าสุดของการจองตามแบบที่ระบุ (ใช้ตรวจผลส่งทันที) */
    public static function latestFor(string $tplKey, int $bookingId): ?array
    {
        return Database::one(
            "SELECT status, error FROM notifications WHERE tpl_key = :k AND ref_table = 'bookings' AND ref_id = :i ORDER BY id DESC LIMIT 1",
            [':k' => $tplKey, ':i' => $bookingId]
        );
    }

    public static function lastFailedError(): ?string
    {
        $r = Database::one("SELECT error FROM notifications WHERE status='failed' ORDER BY id DESC LIMIT 1");
        return $r['error'] ?? null;
    }

    /** ลองส่งอีกรอบเฉพาะที่ล้มเหลวจากสาเหตุชั่วคราวในรอบ 20 ชั่วโมงที่ผ่านมา (ไม่ลองซ้ำช่องทางที่ปิด/ไม่มีผู้รับ/กำลังส่ง) */
    public static function retryRecentFailures(string $createdAfter): int
    {
        return Database::exec(
            "UPDATE notifications SET status = 'queued'
             WHERE status = 'failed' AND created_at >= :t
               AND error IS NOT NULL AND error <> 'กำลังส่ง' AND error NOT LIKE 'ปิดการใช้งาน%' AND error NOT LIKE 'ไม่มี%'",
            [':t' => $createdAfter]
        );
    }

    // ---------- สถิติ / ประวัติ ----------
    public static function recentLine(int $limit = 12): array
    {
        $limit = max(1, min(100, $limit));
        return Database::all("SELECT * FROM notifications WHERE channel = 'line' ORDER BY id DESC LIMIT $limit");
    }

    public static function lineSentSince(string $since): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM notifications WHERE channel = 'line' AND status = 'sent' AND sent_at >= :d", [':d' => $since]);
    }

    public static function lineSentBetween(string $from, string $to): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM notifications WHERE channel = 'line' AND status = 'sent' AND DATE(sent_at) BETWEEN :a AND :b",
            [':a' => $from, ':b' => $to]
        );
    }

    public static function lineFailedLast7Days(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM notifications WHERE channel = 'line' AND status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    }

    public static function failedLast7Days(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM notifications WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    }

    public static function stuckQueued(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM notifications WHERE status = 'queued' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }
}
