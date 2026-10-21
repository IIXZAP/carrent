<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** ลายเซ็นอิเล็กทรอนิกส์ของผู้เช่า (ไม่ลบ — ขอเซ็นใหม่ = ทำเครื่องหมายยกเลิกฉบับเดิม) */
final class Signature extends Model
{
    protected static string $table = 'signatures';

    /** ลายเซ็นที่ใช้อยู่ (ยังไม่ถูกยกเลิก) ของการจองและเอกสารชนิดนี้ */
    public static function active(int $bookingId, string $kind): ?array
    {
        return Database::one(
            'SELECT * FROM signatures WHERE booking_id = :b AND kind = :k AND voided_at IS NULL ORDER BY id DESC LIMIT 1',
            [':b' => $bookingId, ':k' => $kind]
        );
    }

    /** ลายเซ็นที่ถูกยกเลิกไปแล้ว (ประวัติ) */
    public static function voided(int $bookingId): array
    {
        return Database::all(
            'SELECT s.*, u.name AS voider FROM signatures s LEFT JOIN users u ON u.id = s.voided_by
             WHERE s.booking_id = :b AND s.voided_at IS NOT NULL ORDER BY s.id DESC',
            [':b' => $bookingId]
        );
    }

    public static function void(int $id, int $userId, string $reason): bool
    {
        return (bool) Database::exec(
            'UPDATE signatures SET voided_at = NOW(), voided_by = :u, void_reason = :r WHERE id = :i AND voided_at IS NULL',
            [':u' => $userId, ':r' => mb_substr($reason, 0, 250), ':i' => $id]
        );
    }
}
