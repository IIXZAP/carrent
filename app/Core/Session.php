<?php

namespace App\Core;

/**
 * เซสชัน — ตั้งค่าคุกกี้ให้ปลอดภัยและจำกัดไว้เฉพาะโฟลเดอร์ที่ติดตั้ง
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            // งาน cron / ทดสอบบนบรรทัดคำสั่งไม่ต้องใช้คุกกี้เซสชัน
            $_SESSION = $_SESSION ?? [];
            return;
        }
        session_name(SESSION_NAME);
        // จำกัดคุกกี้ไว้เฉพาะโฟลเดอร์ที่ติดตั้ง — ถ้าลงระบบหลายชุดบนโดเมนเดียวกัน
        // (เช่น /thonglorcarrent กับ /thonglorcarrent2) เซสชันจะไม่ตีกัน
        // ล็อกอินหลังบ้านคนละชุดพร้อมกันได้ และ CSRF token ไม่ข้ามชุด
        $cookiePath = BASE_URL === '' ? '/' : rtrim(BASE_URL, '/') . '/';
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => $cookiePath,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_start();
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** ล้างเซสชันทั้งหมดพร้อมคุกกี้ (ใช้ตอนออกจากระบบ) */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
        }
    }
}
