<?php
namespace App\Models;

use App\Core\Database;

/** ใบตรวจสภาพรถ (รอบส่งมอบ 'out' / รอบรับคืน 'in') พร้อมรูปถ่าย — แหล่งจริงของเลขไมล์และขีดน้ำมัน */
final class Inspection
{
    /** ดึงใบตรวจของการจอง (รอบเดียว) พร้อมรูป: photos = ปัจจุบัน, history = ที่ถูกถ่ายแทนแล้ว (ไม่ลบ เพราะหลักฐานที่ลบได้ไม่ใช่หลักฐาน) */
    public static function get(int $bookingId, string $kind): ?array
    {
        $row = Database::one(
            'SELECT i.*, u.name AS inspector, l.name AS locker
               FROM inspections i
               LEFT JOIN users u ON u.id = i.inspected_by
               LEFT JOIN users l ON l.id = i.locked_by
              WHERE i.booking_id = :b AND i.kind = :k',
            ['b' => $bookingId, 'k' => $kind]
        );
        if ($row === null) {
            return null;
        }
        $row['photos'] = [];
        $row['history'] = [];
        foreach (Database::all(
            'SELECT p.*, u.name AS uploader FROM inspection_photos p LEFT JOIN users u ON u.id = p.uploaded_by
              WHERE p.inspection_id = :i ORDER BY p.id',
            ['i' => $row['id']]
        ) as $photo) {
            $row[$photo['superseded_at'] === null ? 'photos' : 'history'][] = $photo;
        }
        return $row;
    }

    /** สร้างใบตรวจถ้ายังไม่มี คืน id — ปลอดภัยเมื่อเรียกพร้อมกัน (UNIQUE booking_id+kind) */
    public static function ensure(int $bookingId, string $kind, ?int $userId): int
    {
        Database::run(
            'INSERT INTO inspections (booking_id, kind, inspected_by) VALUES (:b, :k, :u)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            ['b' => $bookingId, 'k' => $kind, 'u' => $userId]
        );
        return (int) Database::value('SELECT LAST_INSERT_ID()');
    }

    /** ตั้งเลขไมล์/น้ำมัน (เฉพาะที่ระบุ ไม่ทับค่าเดิมด้วย null) — แก้ไม่ได้เมื่อล็อกแล้ว */
    public static function setReadings(int $bookingId, string $kind, ?int $mileage, ?int $fuel): void
    {
        Database::run(
            'UPDATE inspections SET mileage = COALESCE(:m, mileage), fuel_level = COALESCE(:f, fuel_level)
              WHERE booking_id = :b AND kind = :k AND locked_at IS NULL',
            ['m' => $mileage, 'f' => $fuel, 'b' => $bookingId, 'k' => $kind]
        );
    }

    /** ล็อกผลตรวจ — ล็อกซ้ำไม่เปลี่ยนเวลาเดิม (เวลาล็อกครั้งแรกคือหลักฐาน) คืน true ถ้าเพิ่งล็อกจริง */
    public static function lock(int $bookingId, string $kind, ?int $userId): bool
    {
        $stmt = Database::run(
            'UPDATE inspections SET locked_at = NOW(), locked_by = :u WHERE booking_id = :b AND kind = :k AND locked_at IS NULL',
            ['u' => $userId, 'b' => $bookingId, 'k' => $kind]
        );
        return $stmt->rowCount() === 1;
    }

    /**
     * เพิ่มรูป 1 ใบ — $replace = true: รูปเดิมของมุมนั้นถูกประทับเวลาว่าถูกถ่ายแทน (เก็บเป็นประวัติ ไม่ลบ)
     */
    public static function addPhoto(int $inspectionId, string $position, string $path, ?string $caption, ?int $userId, bool $replace): int
    {
        // ด่านสุดท้าย: ผลตรวจที่ล็อกแล้วไม่รับรูปใหม่ (อ่านแบบล็อกแถว ไม่พึ่งการตรวจสถานะของ service)
        if (Database::value('SELECT id FROM inspections WHERE id = :i AND locked_at IS NULL FOR UPDATE', ['i' => $inspectionId]) === null) {
            throw new \LogicException('ผลตรวจรอบนี้ถูกล็อกแล้ว เพิ่มหรือเปลี่ยนรูปไม่ได้');
        }
        if ($replace) {
            Database::run(
                'UPDATE inspection_photos SET superseded_at = NOW() WHERE inspection_id = :i AND position = :p AND superseded_at IS NULL',
                ['i' => $inspectionId, 'p' => $position]
            );
        }
        return Database::insert(
            'INSERT INTO inspection_photos (inspection_id, position, file_path, caption, uploaded_by) VALUES (:i, :p, :f, :c, :u)',
            ['i' => $inspectionId, 'p' => $position, 'f' => $path, 'c' => $caption, 'u' => $userId]
        );
    }

    public static function photoCount(int $inspectionId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM inspection_photos WHERE inspection_id = :i', ['i' => $inspectionId]);
    }

    public static function photo(int $photoId): ?array
    {
        return Database::one('SELECT * FROM inspection_photos WHERE id = :id', ['id' => $photoId]);
    }
}
