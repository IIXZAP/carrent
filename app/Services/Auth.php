<?php
namespace App\Services;

use App\Core\Session;
use App\Models\User;

final class Auth
{
    private static ?array $user = null;

    /** คืน true ถ้าอีเมล/รหัสผ่านถูกต้อง แล้วบันทึกการล็อกอิน */
    public static function attempt(string $email, string $password): bool
    {
        $user = User::findByEmail($email);
        // เทียบ hash เสมอ แม้ไม่พบผู้ใช้ เพื่อให้เวลาตอบใกล้เคียงกัน
        $hash = $user['password_hash'] ?? password_hash('dummy', PASSWORD_DEFAULT);
        $ok = password_verify($password, $hash);
        if (!$ok || $user === null) {
            return false;
        }
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        User::touchLogin((int) $user['id']);
        self::$user = $user;
        return true;
    }

    public static function logout(): void
    {
        self::$user = null;
        Session::destroy();
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = Session::get('user_id');
        if (!is_int($id)) {
            return null;
        }
        self::$user = User::find($id);
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && $user['role'] === 'admin';
    }
}
