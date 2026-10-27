<?php
namespace App\Models;

use App\Core\Database;

/** ลายเซ็นลูกค้า — ไม่มีคำสั่งลบ (เซ็นใหม่ = ยกเลิกฉบับเดิมด้วย voided_at แล้วเก็บประวัติไว้) */
final class Signature
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM signatures WHERE id = :id', ['id' => $id]);
    }

    public static function lockForUpdate(int $id): ?array
    {
        return Database::one('SELECT * FROM signatures WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /** ฉบับที่ใช้งานอยู่ (ยังไม่ถูกยกเลิก) */
    public static function active(int $bookingId, string $kind): ?array
    {
        return Database::one(
            'SELECT * FROM signatures WHERE booking_id = :b AND kind = :k AND voided_at IS NULL ORDER BY id DESC LIMIT 1',
            ['b' => $bookingId, 'k' => $kind]
        );
    }

    /** ทุกฉบับของการจอง (รวมที่ยกเลิก) ใหม่สุดก่อน */
    public static function allFor(int $bookingId): array
    {
        return Database::all(
            'SELECT s.*, u.name AS voider FROM signatures s LEFT JOIN users u ON u.id = s.voided_by WHERE s.booking_id = :b ORDER BY s.id DESC',
            ['b' => $bookingId]
        );
    }

    public static function insert(array $row): int
    {
        $cols = array_keys($row);
        return Database::insert(
            'INSERT INTO signatures (' . implode(',', $cols) . ') VALUES (' . implode(',', array_map(static fn($c) => ':' . $c, $cols)) . ')',
            $row
        );
    }

    /** ยกเลิกเฉพาะฉบับที่ยังใช้งานอยู่ คืน true ถ้ายกเลิกได้จริง */
    public static function void(int $id, int $userId, string $reason): bool
    {
        return Database::run(
            'UPDATE signatures SET voided_at = NOW(), voided_by = :u, void_reason = :r WHERE id = :id AND voided_at IS NULL',
            ['u' => $userId, 'r' => $reason, 'id' => $id]
        )->rowCount() === 1;
    }
}
