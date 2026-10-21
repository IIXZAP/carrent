<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** ประเภทรถ (eco, sedan, suv, ...) — เพิ่ม/แก้ได้ที่ หลังบ้าน → ประเภทรถ */
final class CarType extends Model
{
    protected static string $table      = 'car_types';
    protected static string $primaryKey = 'code';

    /** ป้ายชื่อที่โหลดแล้วใน request นี้ (code => label) */
    private static ?array $labels = null;

    /** ทุกประเภท พร้อมจำนวนรถที่ใช้ประเภทนั้น (หน้าจัดการ) */
    public static function allWithUsage(): array
    {
        return Database::all(
            'SELECT t.*, COUNT(c.id) AS car_count
             FROM car_types t LEFT JOIN cars c ON c.car_type = t.code
             GROUP BY t.code, t.label_th, t.sort_order, t.is_active
             ORDER BY t.sort_order, t.code'
        );
    }

    /**
     * ตัวเลือกสำหรับ dropdown (code => ป้ายชื่อ)
     * $includeCode = ประเภทที่ปิดใช้งานแล้วแต่รถคันนี้ยังใช้อยู่ ให้ยังแสดงในฟอร์มแก้ไขรถ
     */
    public static function options(?string $includeCode = null): array
    {
        $options = [];
        foreach (Database::all('SELECT code, label_th, is_active FROM car_types ORDER BY sort_order, code') as $row) {
            if ((int) $row['is_active'] === 1 || $row['code'] === $includeCode) {
                $options[$row['code']] = $row['label_th'];
            }
        }
        return $options;
    }

    /** ป้ายชื่อของประเภท ถ้าไม่พบ (ลบไปแล้ว) แสดงรหัสเดิม */
    public static function label(string $code): string
    {
        if (self::$labels === null) {
            self::$labels = [];
            foreach (Database::all('SELECT code, label_th FROM car_types') as $row) {
                self::$labels[$row['code']] = $row['label_th'];
            }
        }
        return self::$labels[$code] ?? $code;
    }

    /** เพิ่มประเภทใหม่ */
    public static function create(string $code, string $label, int $sortOrder): void
    {
        Database::exec(
            'INSERT INTO car_types (code, label_th, sort_order, is_active) VALUES (:c, :l, :s, 1)',
            [':c' => $code, ':l' => $label, ':s' => $sortOrder]
        );
    }

    /** แก้ชื่อ ลำดับ และสถานะของประเภท (รหัสแก้ไม่ได้ เพราะรถอ้างถึงอยู่) */
    public static function change(string $code, string $label, int $sortOrder, bool $active): void
    {
        Database::exec(
            'UPDATE car_types SET label_th = :l, sort_order = :s, is_active = :a WHERE code = :c',
            [':l' => $label, ':s' => $sortOrder, ':a' => $active ? 1 : 0, ':c' => $code]
        );
    }

    public static function remove(string $code): void
    {
        Database::exec('DELETE FROM car_types WHERE code = :c', [':c' => $code]);
    }

    /** รหัสประเภทนี้มีในระบบหรือไม่ */
    public static function exists(string $code): bool
    {
        return (bool) Database::value('SELECT 1 FROM car_types WHERE code = :c', [':c' => $code]);
    }

    /** จำนวนรถที่ใช้ประเภทนี้ */
    public static function usage(string $code): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM cars WHERE car_type = :c', [':c' => $code]);
    }
}
