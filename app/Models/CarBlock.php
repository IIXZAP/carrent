<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** ช่วงที่ปิดรถ (ซ่อม / เจ้าของใช้เอง) */
final class CarBlock extends Model
{
    protected static string $table = 'car_blocks';

    /** ช่วงปิดรถที่ทับกับเดือนที่ดู */
    public static function inRange(string $firstDay, string $lastDay): array
    {
        return Database::all(
            'SELECT * FROM car_blocks WHERE DATE(start_at) <= :e AND DATE(end_at) >= :s',
            [':s' => $firstDay, ':e' => $lastDay]
        );
    }

    /** ช่วงปิดรถที่ยังไม่สิ้นสุด */
    public static function upcoming(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        return Database::all(
            "SELECT k.*, c.plate, c.brand, c.model FROM car_blocks k JOIN cars c ON c.id = k.car_id
             WHERE k.end_at >= NOW() ORDER BY k.start_at LIMIT $limit"
        );
    }

    /** จำนวนช่วงปิดรถที่ทับกับช่วงเวลา (ใช้ตรวจรถว่าง) */
    public static function overlapCount(int $carId, string $from, string $to): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM car_blocks WHERE car_id = :car AND start_at < :ret AND end_at > :pick',
            [':car' => $carId, ':pick' => $from, ':ret' => $to]
        );
    }
}
