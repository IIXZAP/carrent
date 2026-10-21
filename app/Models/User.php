<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** ผู้ใช้ระบบหลังบ้าน (admin / staff) */
final class User extends Model
{
    protected static string $table = 'users';

    public static function findActiveByEmail(string $email): ?array
    {
        return Database::one('SELECT * FROM users WHERE email = :e AND is_active = 1', [':e' => $email]);
    }

    /** สิทธิ์และสถานะล่าสุด (อ่านทุกครั้งที่ตรวจล็อกอิน เพื่อให้การปิดบัญชี/ลดสิทธิ์มีผลทันที) */
    public static function authState(int $id): ?array
    {
        return Database::one('SELECT name, role, is_active FROM users WHERE id = :i', [':i' => $id]);
    }

    public static function touchLogin(int $id): void
    {
        self::updateById($id, ['last_login_at' => date('Y-m-d H:i:s')]);
    }

    public static function all(): array
    {
        return Database::all('SELECT * FROM users ORDER BY role, name');
    }

    public static function emailTaken(string $email, int $exceptId = 0): bool
    {
        return (bool) Database::one('SELECT id FROM users WHERE email = :e AND id <> :i', [':e' => $email, ':i' => $exceptId]);
    }

    /** จำนวนเจ้าของร้านที่ใช้งานได้ (ไม่นับ $exceptId) */
    public static function activeAdminCount(int $exceptId = 0): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> :i",
            [':i' => $exceptId]
        );
    }

    public static function roleAndStatus(int $id): ?array
    {
        return Database::one('SELECT role, is_active FROM users WHERE id = :i', [':i' => $id]);
    }
}
