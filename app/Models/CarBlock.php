<?php
namespace App\Models;

use App\Core\Database;

final class CarBlock
{
    public static function overlapCount(int $carId, string $from, string $to): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM car_blocks WHERE car_id = :car AND start_at < :to_at AND end_at > :from_at',
            ['car' => $carId, 'from_at' => $from, 'to_at' => $to]
        );
    }

    public static function insert(int $carId, string $start, string $end, string $reason, ?int $userId): int
    {
        return Database::insert(
            'INSERT INTO car_blocks (car_id, start_at, end_at, reason, created_by) VALUES (:c, :s, :e, :r, :u)',
            ['c' => $carId, 's' => $start, 'e' => $end, 'r' => $reason, 'u' => $userId]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM car_blocks WHERE id = :id', ['id' => $id]);
    }

    /** ลบช่วงปิดรถ คืน true ถ้ามีแถวถูกลบจริง (ลบซ้ำ/พร้อมกันได้ผลครั้งเดียว) */
    public static function deleteById(int $id): bool
    {
        return Database::run('DELETE FROM car_blocks WHERE id = :id', ['id' => $id])->rowCount() === 1;
    }

    /** ช่วงปิดรถที่ทับช่วงวันนี้ (ใช้วาดปฏิทิน) */
    public static function inRange(string $fromDate, string $toDate): array
    {
        return Database::all(
            'SELECT * FROM car_blocks WHERE start_at <= :t AND end_at >= :f ORDER BY start_at',
            ['f' => $fromDate . ' 00:00:00', 't' => $toDate . ' 23:59:59']
        );
    }

    /** ช่วงปิดรถที่ยังไม่จบ พร้อมทะเบียน เรียงตามวันเริ่ม */
    public static function upcoming(int $limit = 20): array
    {
        return Database::all(
            'SELECT k.*, c.plate, c.brand, c.model FROM car_blocks k JOIN cars c ON c.id = k.car_id
              WHERE k.end_at >= NOW() ORDER BY k.start_at LIMIT ' . (int) $limit
        );
    }
}
