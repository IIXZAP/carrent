<?php
namespace App\Models;

use App\Core\Database;

final class Car
{
    private const COLUMNS = [
        'plate', 'province', 'brand', 'model', 'year', 'color', 'car_type_code', 'seats',
        'gear', 'fuel', 'price_day', 'price_week', 'price_month',
        'security_deposit', 'booking_deposit', 'status', 'description',
    ];

    /** รถทั้งหมดสำหรับหลังบ้าน (ยกเว้นที่ลบแล้ว) */
    public static function allForAdmin(): array
    {
        return Database::all(
            'SELECT c.*, t.label_th AS type_label
               FROM cars c JOIN car_types t ON t.code = c.car_type_code
              WHERE c.deleted_at IS NULL
              ORDER BY c.sort_order, c.id'
        );
    }

    /** รถที่โชว์หน้าเว็บลูกค้า */
    public static function allPublic(): array
    {
        return Database::all(
            "SELECT c.*, t.label_th AS type_label
               FROM cars c JOIN car_types t ON t.code = c.car_type_code
              WHERE c.deleted_at IS NULL AND c.status = 'available'
              ORDER BY c.sort_order, c.price_day, c.id"
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT c.*, t.label_th AS type_label
               FROM cars c JOIN car_types t ON t.code = c.car_type_code
              WHERE c.id = :id AND c.deleted_at IS NULL',
            ['id' => $id]
        );
    }

    public static function plateExists(string $plate, int $exceptId = 0): bool
    {
        return Database::value(
            'SELECT 1 FROM cars WHERE plate = :plate AND id <> :id',
            ['plate' => $plate, 'id' => $exceptId]
        ) !== null;
    }

    public static function countByStatus(): array
    {
        $rows = Database::all(
            'SELECT status, COUNT(*) AS n FROM cars WHERE deleted_at IS NULL GROUP BY status'
        );
        $out = ['available' => 0, 'maintenance' => 0, 'inactive' => 0];
        foreach ($rows as $row) {
            $out[$row['status']] = (int) $row['n'];
        }
        return $out;
    }

    public static function hasOpenBookings(int $id): bool
    {
        return Database::value(
            "SELECT 1 FROM bookings
              WHERE car_id = :id AND status IN ('pending','confirmed','picked_up') LIMIT 1",
            ['id' => $id]
        ) !== null;
    }

    public static function create(array $data): int
    {
        $cols = implode(', ', self::COLUMNS);
        $marks = ':' . implode(', :', self::COLUMNS);
        return Database::insert("INSERT INTO cars ($cols) VALUES ($marks)", self::pick($data));
    }

    public static function update(int $id, array $data): void
    {
        $sets = [];
        foreach (self::COLUMNS as $col) {
            $sets[] = "$col = :$col";
        }
        $params = self::pick($data) + ['id' => $id];
        Database::run('UPDATE cars SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    public static function softDelete(int $id): void
    {
        Database::run('UPDATE cars SET deleted_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    private static function pick(array $data): array
    {
        $out = [];
        foreach (self::COLUMNS as $col) {
            $out[$col] = $data[$col] ?? null;
        }
        return $out;
    }
}
