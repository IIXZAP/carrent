<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** เหตุการณ์จาก LINE webhook (เพิ่มเพื่อน / เลิกติดตาม / ข้อความ) */
final class LineEvent extends Model
{
    protected static string $table = 'line_events';

    /** คนที่ทักเข้ามาล่าสุด (1 แถวต่อ 1 คน) — ใช้เลือกผู้รับแจ้งเตือนของร้าน */
    public static function latestPeople(int $limit = 12): array
    {
        $limit = max(1, min(100, $limit));
        return Database::all(
            "SELECT e.line_user_id, e.display_name, e.text, e.event_type, e.created_at
             FROM line_events e
             JOIN (SELECT line_user_id, MAX(id) AS mid FROM line_events GROUP BY line_user_id) t ON t.mid = e.id
             ORDER BY e.id DESC LIMIT $limit"
        );
    }

    /** ล้างบันทึกที่เก่ากว่า 30 วัน */
    public static function purgeOld(): void
    {
        Database::exec('DELETE FROM line_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    }
}
