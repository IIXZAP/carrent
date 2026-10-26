<?php
namespace App\Models;

use App\Core\Database;

final class ActivityLog
{
    public static function log(string $action, ?string $refTable = null, ?int $refId = null, ?string $detail = null, ?int $userId = null, ?string $ip = null): void
    {
        Database::run(
            'INSERT INTO activity_logs (user_id, action, ref_table, ref_id, detail, ip)
             VALUES (:u, :a, :t, :i, :d, :ip)',
            ['u' => $userId, 'a' => $action, 't' => $refTable, 'i' => $refId, 'd' => $detail, 'ip' => $ip]
        );
    }

    /** จำนวนครั้งที่ IP นี้ทำ action นี้ตั้งแต่เวลาที่กำหนด */
    public static function countByIpSince(string $action, string $ip, string $since): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM activity_logs WHERE action = :a AND ip = :ip AND created_at >= :s',
            ['a' => $action, 'ip' => $ip, 's' => $since]
        );
    }
}
