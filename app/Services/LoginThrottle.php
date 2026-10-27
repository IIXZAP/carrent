<?php
namespace App\Services;

use App\Models\ActivityLog;

/**
 * จำกัดการเดารหัสผ่านหลังบ้าน — นับความล้มเหลวทั้งตาม IP และตามอีเมลที่ถูกลอง
 *   ตาม IP   : กันคนเดียวยิงรัว (ขีดจำกัดต่ำ)
 *   ตามอีเมล : กันการกระจายหลาย IP มาเดาบัญชีเดียว (ขีดจำกัดสูงกว่า — ไม่งั้นคนอื่นล็อกบัญชีเจ้าของร้านได้ง่ายเกินไป)
 * เมื่อถูกระงับ รหัสผ่านที่ถูกต้องก็เข้าไม่ได้ในช่วงนั้น (ไม่งั้นการเดายังได้ผลทางอ้อม)
 * บันทึกทุกครั้งที่ล้มเหลว แม้อีเมลไม่มีอยู่จริง เพื่อไม่ให้พฤติกรรมต่างกันจนเดาได้ว่าอีเมลไหนมีบัญชี
 */
final class LoginThrottle
{
    public const MAX_PER_IP = 10;
    public const MAX_PER_EMAIL = 30;
    public const WINDOW_SECONDS = 900;

    private static function email(string $email): string
    {
        return mb_substr(strtolower(trim($email)), 0, 150);
    }

    private static function since(): string
    {
        return date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);
    }

    public static function isBlocked(string $ip, string $email): bool
    {
        $since = self::since();
        return ActivityLog::countByIpSince('login_failed', $ip, $since) >= self::MAX_PER_IP
            || ActivityLog::countByDetailSince('login_failed', self::email($email), $since) >= self::MAX_PER_EMAIL;
    }

    public static function recordFailure(string $ip, string $email): void
    {
        ActivityLog::log('login_failed', null, null, self::email($email), null, $ip);
    }
}
