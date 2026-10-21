<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** รถเช่า */
final class Car extends Model
{
    protected static string $table = 'cars';

    /** รถที่แสดงหน้าเว็บลูกค้า (ไม่รวมที่ปิดใช้งาน) */
    public static function findPublic(int $id): ?array
    {
        return Database::one("SELECT * FROM cars WHERE id = :id AND status <> 'inactive'", [':id' => $id]);
    }

    public static function allPublic(?string $type = null): array
    {
        $sql = "SELECT * FROM cars WHERE status <> 'inactive'";
        $p   = [];
        if ($type) {
            $sql .= ' AND car_type = :t';
            $p[':t'] = $type;
        }
        return Database::all($sql . ' ORDER BY sort_order, price_day', $p);
    }

    /** รถทุกคัน (หลังบ้าน) */
    public static function all(): array
    {
        return Database::all('SELECT * FROM cars ORDER BY sort_order, id');
    }

    /** รถที่ใช้งานอยู่ (ใช้เป็นตัวเลือกในฟอร์ม) */
    public static function active(): array
    {
        return Database::all("SELECT * FROM cars WHERE status <> 'inactive' ORDER BY sort_order");
    }

    /** รายการสั้นๆ ไว้ทำตัวเลือกกรอง */
    public static function options(): array
    {
        return Database::all('SELECT id, brand, model, plate FROM cars ORDER BY sort_order');
    }

    /**
     * วันหมดอายุประกัน/พ.ร.บ./ภาษี และกำหนดเช็กระยะของรถทุกคัน (car_id => แถว)
     * ข้อมูลมาจากงานประจำรถที่ยังค้างอยู่ (VIEW v_car_compliance)
     */
    public static function complianceMap(): array
    {
        $map = [];
        foreach (Database::all('SELECT * FROM v_car_compliance') as $row) {
            $map[(int) $row['car_id']] = $row;
        }
        return $map;
    }

    /** วันหมดอายุ/กำหนดเช็กระยะของรถคันเดียว (insurance_expire, act_expire, tax_expire, next_service_at, next_service_km) */
    public static function compliance(int $id): array
    {
        $row = Database::one(
            'SELECT insurance_expire, act_expire, tax_expire, next_service_at, next_service_km
             FROM v_car_compliance WHERE car_id = :c',
            [':c' => $id]
        );
        return $row ?: [];
    }

    public static function activeCount(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM cars WHERE status <> 'inactive'");
    }

    public static function minAvailablePrice(): float
    {
        return (float) Database::value("SELECT MIN(price_day) FROM cars WHERE status = 'available'");
    }

    /** รถที่ใช้เป็นภาพเด่นหน้าแรก (คันที่ 3 ที่มีรูป ถ้าไม่มีใช้คันแรก) */
    public static function hero(): ?array
    {
        return Database::one(
            "SELECT * FROM cars WHERE cover_image IS NOT NULL AND status = 'available'
             ORDER BY sort_order LIMIT 1 OFFSET 2"
        );
    }

    public static function status(int $id): ?string
    {
        $s = Database::value('SELECT status FROM cars WHERE id = :id', [':id' => $id]);
        return $s === false ? null : (string) $s;
    }

    public static function mileage(int $id): int
    {
        return (int) Database::value('SELECT mileage FROM cars WHERE id = :c', [':c' => $id]);
    }

    public static function setMileage(int $id, int $km): void
    {
        self::updateById($id, ['mileage' => $km]);
    }

    public static function setStatus(int $id, string $status): void
    {
        self::updateById($id, ['status' => $status]);
    }

    /** ล็อกแถวรถไว้จนจบทรานแซกชัน — คนที่จองรถคันเดียวกันพร้อมกันต้องรอคิว */
    public static function lock(int $id): void
    {
        Database::one('SELECT id FROM cars WHERE id = :c FOR UPDATE', [':c' => $id]);
    }

    /** การจองที่ยังไม่จบของรถคันนี้ (ใช้กันไม่ให้ปิดใช้งานรถที่ยังมีงานค้าง) */
    public static function openBookingCount(int $id): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM bookings WHERE car_id = :c AND status IN ('pending','confirmed','picked_up')",
            [':c' => $id]
        );
    }

    /** การจองที่รถกำลังออกงานอยู่ (แสดงในหน้ารายการรถ) */
    public static function activeRental(int $id): ?array
    {
        return Database::one(
            "SELECT booking_no, return_at FROM bookings
             WHERE car_id = :c AND status = 'picked_up' ORDER BY return_at LIMIT 1",
            [':c' => $id]
        );
    }

    public static function statusCounts(): array
    {
        return Database::all('SELECT status, COUNT(*) n FROM cars GROUP BY status');
    }

    public static function sitemapRows(): array
    {
        return Database::all("SELECT id, updated_at FROM cars WHERE status <> 'inactive' ORDER BY sort_order");
    }
}
