<?php
namespace App\Models;

use App\Core\Database;

/** คิวแจ้งเตือน — สถานะ: queued → sending → sent | failed (ส่งซ้ำได้จนครบจำนวนครั้ง แล้วค่อยเป็น failed) */
final class Notification
{
    /**
     * เข้าคิว — dedupe_key ซ้ำ = มีแจ้งเหตุการณ์นี้ไปแล้ว ไม่เพิ่มแถวใหม่
     * @return int id ของแถวใหม่ หรือ 0 ถ้าซ้ำ
     */
    public static function queue(string $channel, string $recipient, string $message, ?string $refTable, ?int $refId, ?string $dedupeKey): int
    {
        try {
            return Database::insert(
                'INSERT INTO notifications (channel, recipient, message, ref_table, ref_id, dedupe_key, retry_key, status)
                 VALUES (:c, :r, :m, :t, :i, :d, :k, \'queued\')',
                ['c' => $channel, 'r' => $recipient, 'm' => $message, 't' => $refTable, 'i' => $refId, 'd' => $dedupeKey, 'k' => self::uuid()]
            );
        } catch (\PDOException $e) {
            if ($dedupeKey !== null && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                return 0;   // แจ้งเหตุการณ์นี้ไปแล้ว
            }
            throw $e;
        }
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM notifications WHERE id = :id', ['id' => $id]);
    }

    /** @return int[] id ของแถวที่ถึงเวลาส่ง */
    public static function dueIds(int $limit): array
    {
        return array_map('intval', array_column(Database::all(
            'SELECT id FROM notifications WHERE status = \'queued\' AND (next_try_at IS NULL OR next_try_at <= NOW())
             ORDER BY id LIMIT ' . max(1, min(200, $limit))
        ), 'id'));
    }

    /** จองแถวไปส่ง (atomic) — false = process อื่นจองไปก่อน หรือยังไม่ถึงเวลา */
    public static function claim(int $id): bool
    {
        return Database::run(
            'UPDATE notifications SET status = \'sending\', claimed_at = NOW(), attempts = attempts + 1
             WHERE id = :id AND status = \'queued\' AND (next_try_at IS NULL OR next_try_at <= NOW())',
            ['id' => $id]
        )->rowCount() === 1;
    }

    public static function markSent(int $id): void
    {
        Database::run(
            'UPDATE notifications SET status = \'sent\', sent_at = NOW(), error = NULL, next_try_at = NULL, claimed_at = NULL
             WHERE id = :id AND status = \'sending\'',
            ['id' => $id]
        );
    }

    /** ส่งไม่สำเร็จ — $retryAfterSeconds null = เลิกส่ง (failed) */
    public static function markFailed(int $id, string $error, ?int $retryAfterSeconds): void
    {
        Database::run(
            'UPDATE notifications SET status = :s, error = :e, claimed_at = NULL,
                    next_try_at = ' . ($retryAfterSeconds === null ? 'NULL' : 'DATE_ADD(NOW(), INTERVAL ' . (int) $retryAfterSeconds . ' SECOND)') . '
             WHERE id = :id AND status = \'sending\'',
            ['s' => $retryAfterSeconds === null ? 'failed' : 'queued', 'e' => mb_substr($error, 0, 250), 'id' => $id]
        );
    }

    /** แถวที่ค้างสถานะ sending นานเกิน $minutes (process ตายกลางทาง) → กลับเข้าคิว */
    public static function recoverStale(int $minutes): int
    {
        return Database::run(
            'UPDATE notifications SET status = \'queued\', claimed_at = NULL, error = \'ค้างระหว่างส่ง — ส่งใหม่\'
             WHERE status = \'sending\' AND claimed_at < DATE_SUB(NOW(), INTERVAL ' . max(1, $minutes) . ' MINUTE)'
        )->rowCount();
    }

    /** ลองส่งใหม่ด้วยมือ (จากหลังบ้าน) — เฉพาะแถวที่ failed */
    public static function requeueFailed(int $id): bool
    {
        return Database::run(
            'UPDATE notifications SET status = \'queued\', attempts = 0, next_try_at = NULL, error = NULL
             WHERE id = :id AND status = \'failed\'',
            ['id' => $id]
        )->rowCount() === 1;
    }

    public static function purge(int $sentDays, int $failedDays): int
    {
        return Database::run(
            'DELETE FROM notifications WHERE (status = \'sent\' AND created_at < DATE_SUB(NOW(), INTERVAL :a DAY))
                                          OR (status = \'failed\' AND created_at < DATE_SUB(NOW(), INTERVAL :b DAY))',
            ['a' => max(1, $sentDays), 'b' => max(1, $failedDays)]
        )->rowCount();
    }

    public static function recent(int $limit = 40): array
    {
        return Database::all('SELECT * FROM notifications ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
    }

    public static function countByStatus(): array
    {
        $out = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0];
        foreach (Database::all('SELECT status, COUNT(*) c FROM notifications GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
