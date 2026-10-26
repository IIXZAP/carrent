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

    /**
     * อ่านแบบล็อก — เห็นข้อมูลล่าสุดที่ commit แล้ว แม้ transaction นี้เริ่มอ่านไปก่อนหน้า
     * (SELECT ธรรมดาใน REPEATABLE READ จะเห็นภาพข้อมูลเก่า จึงมองไม่เห็นลูกค้าที่เพิ่งถูกสร้างโดยอีกคำขอ)
     *
     * ต้องเป็น shared lock (LOCK IN SHARE MODE) ห้ามใช้ FOR UPDATE:
     * foreign key ตอน INSERT bookings ขอ shared lock ที่แถวลูกค้า ถ้าเราถือ exclusive ไว้
     * จะวนรอกับคำขออื่นที่ถือตัวนับเลขที่จอง = deadlock (เจอจริงในเทสต์จองพร้อมกันตอนเครื่องช้า)
     */
    private static function findByPhoneLocked(string $phone): ?array
    {
        return Database::one(
            'SELECT * FROM customers WHERE phone = :p AND deleted_at IS NULL ORDER BY is_blacklist DESC, id LIMIT 1 LOCK IN SHARE MODE',
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

    /**
     * สร้างลูกค้าใหม่ ถ้ามีเบอร์นี้อยู่แล้ว (รวมถึงที่อีกคำขอเพิ่งสร้างพร้อมกัน) คืนแถวเดิมแทน
     * DB เป็นผู้รับประกัน: UNIQUE phone_active ทำให้ INSERT ที่สองชน duplicate key (1062)
     * แล้วเราอ่านแถวของคนที่มาก่อนด้วยการอ่านแบบล็อก
     * @return array{customer:array,created:bool}
     */
    public static function createOrFind(string $name, string $phone, ?string $email, ?string $line): array
    {
        try {
            $id = self::create($name, $phone, $email, $line);
            return ['customer' => self::find($id), 'created' => true];
        } catch (\PDOException $e) {
            if (!self::isDuplicateKey($e)) {
                throw $e;
            }
        }
        $existing = self::findByPhoneLocked($phone);
        if ($existing === null) {
            throw new \RuntimeException('สร้างลูกค้าไม่สำเร็จ (เบอร์ซ้ำแต่ไม่พบแถวเดิม)');
        }
        return ['customer' => $existing, 'created' => false];
    }

    public static function create(string $name, string $phone, ?string $email, ?string $line): int
    {
        return Database::insert(
            "INSERT INTO customers (code, type, full_name, phone, email, line_id)
             VALUES (:code, 'individual', :name, :phone, :email, :line)",
            ['code' => self::nextCode(), 'name' => $name, 'phone' => $phone, 'email' => $email, 'line' => $line]
        );
    }

    /** MySQL/MariaDB error 1062 = duplicate entry */
    private static function isDuplicateKey(\PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
