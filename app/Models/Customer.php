<?php
namespace App\Models;

use App\Core\Database;

final class Customer
{
    /** หาจากเบอร์โทร (เบอร์ต้อง normalize แล้ว — ดู Phone::normalize) ; ถ้ามีหลายคน blacklist มาก่อน */
    public static function findByPhone(string $phone): ?array
    {
        return Database::one(
            'SELECT * FROM customers WHERE phone = :p AND deleted_at IS NULL ORDER BY is_blacklist DESC, id LIMIT 1',
            ['p' => $phone]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM customers WHERE id = :id', ['id' => $id]);
    }

    /** รหัสลูกค้าถัดไป CUS-00001 (ข้ามเลขที่ถูกใช้ไปแล้ว เช่น นำเข้าข้อมูลเก่า) */
    public static function nextCode(): string
    {
        do {
            $code = 'CUS-' . str_pad((string) Sequence::next('customer'), 5, '0', STR_PAD_LEFT);
            $taken = Database::value('SELECT 1 FROM customers WHERE code = :c', ['c' => $code]);
        } while ($taken !== null);
        return $code;
    }

    public static function create(string $name, string $phone, ?string $email, ?string $line): int
    {
        return Database::insert(
            "INSERT INTO customers (code, type, full_name, phone, email, line_id)
             VALUES (:code, 'individual', :name, :phone, :email, :line)",
            ['code' => self::nextCode(), 'name' => $name, 'phone' => $phone, 'email' => $email, 'line' => $line]
        );
    }
}
