<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** ใบตรวจสภาพรถ (รอบส่งมอบ 'out' / รอบรับคืน 'in') พร้อมรูปถ่าย */
final class Inspection extends Model
{
    protected static string $table = 'inspections';

    /** ดึงใบตรวจของการจอง (รอบเดียว) พร้อมรูปทั้งหมด */
    public static function get(int $bookingId, string $kind): ?array
    {
        $row = Database::one(
            'SELECT i.*, u.name AS inspector, l.name AS locker
             FROM inspections i
             LEFT JOIN users u ON u.id = i.inspected_by
             LEFT JOIN users l ON l.id = i.locked_by
             WHERE i.booking_id = :b AND i.kind = :k',
            [':b' => $bookingId, ':k' => $kind]
        );
        if (!$row) {
            return null;
        }
        $all = Database::all(
            'SELECT p.*, u.name AS uploader
             FROM inspection_photos p LEFT JOIN users u ON u.id = p.uploaded_by
             WHERE p.inspection_id = :i ORDER BY p.id',
            [':i' => (int) $row['id']]
        );
        // photos  = รูปที่ใช้เป็นผลตรวจปัจจุบัน (มุมละ 1 รูป)
        // history = รูปที่ถูกถ่ายแทน — ไม่ลบทิ้ง เก็บไว้ให้ตรวจย้อนหลังได้ว่าเคยมีรูปอะไร
        $row['photos']  = [];
        $row['history'] = [];
        foreach ($all as $photo) {
            if (empty($photo['superseded_at'])) {
                $row['photos'][] = $photo;
            } else {
                $row['history'][] = $photo;
            }
        }
        return $row;
    }

    /** สร้างใบตรวจถ้ายังไม่มี แล้วคืน id (ใบเดิมจะไม่ถูกสร้างซ้ำ) */
    public static function ensure(int $bookingId, string $kind, ?int $userId): int
    {
        $found = Database::one(
            'SELECT id FROM inspections WHERE booking_id = :b AND kind = :k',
            [':b' => $bookingId, ':k' => $kind]
        );
        if ($found) {
            return (int) $found['id'];
        }
        return self::insert([
            'booking_id'   => $bookingId,
            'kind'         => $kind,
            'inspected_by' => $userId,
            'inspected_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** เติมเลขไมล์/น้ำมันเฉพาะช่องที่ยังว่างและยังไม่ล็อก (ตอนบันทึกส่งมอบ/รับคืนรถ) */
    public static function fillMissing(int $bookingId, string $kind, ?int $mileage, int $fuel): void
    {
        Database::exec(
            'UPDATE inspections SET mileage = COALESCE(mileage, :km), fuel_level = COALESCE(fuel_level, :f)
             WHERE booking_id = :b AND kind = :k AND locked_at IS NULL',
            [':km' => $mileage, ':f' => $fuel, ':b' => $bookingId, ':k' => $kind]
        );
    }

    /**
     * ล็อกผลตรวจ — ล็อกซ้ำไม่เปลี่ยนเวลาเดิม (เวลาล็อกครั้งแรกคือหลักฐาน)
     * @return bool true ถ้าเพิ่งล็อกในครั้งนี้
     */
    public static function lock(int $bookingId, string $kind, ?int $userId): bool
    {
        $ins = Database::one(
            'SELECT id, locked_at FROM inspections WHERE booking_id = :b AND kind = :k',
            [':b' => $bookingId, ':k' => $kind]
        );
        if (!$ins || !empty($ins['locked_at'])) {
            return false;
        }
        self::updateById((int) $ins['id'], ['locked_at' => date('Y-m-d H:i:s'), 'locked_by' => $userId]);
        return true;
    }

    public static function isLockedRow(int $inspectionId): bool
    {
        return (bool) Database::value('SELECT locked_at FROM inspections WHERE id = :i', [':i' => $inspectionId]);
    }

    /**
     * เพิ่มรูป 1 ใบ — ถ้ามุมนั้นมีรูปอยู่แล้ว รูปเดิมถูกประทับเวลาว่า "ถูกถ่ายแทน" ไม่ถูกลบ
     * เพราะหลักฐานที่ลบได้ไม่ใช่หลักฐาน
     */
    public static function addPhoto(int $inspectionId, string $position, string $path, ?string $caption, ?int $userId): void
    {
        Database::exec(
            'UPDATE inspection_photos SET superseded_at = NOW()
             WHERE inspection_id = :i AND position = :p AND superseded_at IS NULL',
            [':i' => $inspectionId, ':p' => $position]
        );
        Database::insert('inspection_photos', [
            'inspection_id' => $inspectionId,
            'position'      => $position,
            'file_path'     => $path,
            'caption'       => $caption ?: null,
            'uploaded_by'   => $userId,
        ]);
    }

    /** สถิติใบตรวจในช่วงวันที่ [รวม, ล็อกแล้ว] — ใช้ในรายงานรายเดือน */
    public static function stats(string $from, string $to): array
    {
        $total  = (int) Database::value('SELECT COUNT(*) FROM inspections WHERE DATE(inspected_at) BETWEEN :a AND :b', [':a' => $from, ':b' => $to]);
        $locked = (int) Database::value('SELECT COUNT(*) FROM inspections WHERE locked_at IS NOT NULL AND DATE(inspected_at) BETWEEN :a AND :b', [':a' => $from, ':b' => $to]);
        return [$total, $locked];
    }
}
