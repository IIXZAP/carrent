<?php

namespace App\Core;

/**
 * คลาสแม่ของ model — ครอบคลุมงานพื้นฐานของตาราง (หาตาม id, เพิ่ม, แก้, ลบ, นับ)
 * model ลูกเพิ่มคำสั่งค้นหาเฉพาะของตัวเอง โดยเรียก Database::one/all/value ด้วย prepared statement
 */
abstract class Model
{
    /** ชื่อตาราง — model ลูกต้องกำหนด */
    protected static string $table = '';

    protected static string $primaryKey = 'id';

    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT * FROM `' . static::$table . '` WHERE `' . static::$primaryKey . '` = :id',
            [':id' => $id]
        );
    }

    public static function insert(array $data): int
    {
        return Database::insert(static::$table, $data);
    }

    public static function updateById(int $id, array $data): int
    {
        return Database::update(static::$table, $data, static::$primaryKey, $id);
    }

    public static function deleteById(int $id): int
    {
        return Database::exec(
            'DELETE FROM `' . static::$table . '` WHERE `' . static::$primaryKey . '` = :id',
            [':id' => $id]
        );
    }

    public static function count(): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM `' . static::$table . '`');
    }
}
