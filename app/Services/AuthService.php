<?php

namespace App\Services;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\User;

/**
 * ระบบล็อกอินหลังบ้าน (admin / staff) + ป้องกัน brute force
 */
final class AuthService
{
    /** ล็อกอินสำเร็จหรือไม่ — ล้มเหลวจะมี flash บอกเหตุผล (กรณีถูกล็อกชั่วคราว) */
    public static function attempt(string $email, string $password): bool
    {
        $req = Request::current();

        // ล็อกชั่วคราวหลังพยายามล็อกอินผิดหลายครั้ง (นับจากฐานข้อมูล)
        if (self::recentFailures($email, $req->ip()) >= MAX_LOGIN_ATTEMPTS) {
            Flash::add('danger', 'พยายามเข้าสู่ระบบผิดหลายครั้ง กรุณารออีก ' . LOGIN_LOCK_MINUTES . ' นาที');
            return false;
        }

        $user = User::findActiveByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            ActivityLog::log('login_failed', 'users', null, $email);
            return false;
        }

        // ล็อกอินสำเร็จ — ล้างประวัติที่ผิดของอีเมลนี้ เพื่อไม่ให้ไปล็อกครั้งถัดไป
        try {
            ActivityLog::clearLoginFailures($email);
        } catch (\Throwable $e) {
        }

        Session::regenerate();
        $_SESSION['user'] = [
            'id'    => (int) $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ];
        $_SESSION['last_activity'] = time();
        User::touchLogin((int) $user['id']);
        ActivityLog::log('login', 'users', (int) $user['id']);
        return true;
    }

    private static function recentFailures(string $email, string $ip): int
    {
        $since = date('Y-m-d H:i:s', time() - LOGIN_LOCK_MINUTES * 60);
        try {
            return ActivityLog::loginFailures($email, $ip, $since);
        } catch (\Throwable $e) {
            return 0;   // ถ้าอ่านตารางไม่ได้ อย่าล็อกผู้ใช้จนเข้าระบบไม่ได้
        }
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
    }

    public static function check(): bool
    {
        if (empty($_SESSION['user'])) {
            return false;
        }
        // หมดอายุจากการไม่ใช้งาน
        if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > SESSION_LIFETIME) {
            self::logout();
            return false;
        }
        $_SESSION['last_activity'] = time();

        // อ่านสิทธิ์จากฐานข้อมูลทุกครั้ง (ครั้งเดียวต่อ 1 request)
        // เจ้าของร้านปิดบัญชี ลบ หรือลดสิทธิ์พนักงานแล้วต้องมีผลทันที ไม่ใช่รอให้คนนั้นออกจากระบบเอง
        static $checked = false;
        if (!$checked) {
            $checked = true;
            try {
                $u = User::authState((int) $_SESSION['user']['id']);
            } catch (\Throwable $e) {
                return true;   // อ่านไม่ได้ชั่วคราว — อย่าเตะผู้ใช้ออกกลางงาน
            }
            if (!$u || !(int) $u['is_active']) {
                self::logout();
                return false;
            }
            $_SESSION['user']['role'] = $u['role'];
            $_SESSION['user']['name'] = $u['name'];
        }
        return true;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function logout(): void
    {
        if (!empty($_SESSION['user'])) {
            ActivityLog::log('logout', 'users', (int) $_SESSION['user']['id']);
        }
        Session::destroy();
    }
}
