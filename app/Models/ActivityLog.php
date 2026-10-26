<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * บันทึกกิจกรรม (PDPA / ตรวจย้อนหลัง) และใช้นับความพยายามที่ล้มเหลว (ล็อกอินผิด / ผูก LINE ผิด)
 */
final class ActivityLog extends Model
{
    protected static string $table = 'activity_logs';

    /** บันทึกกิจกรรม — ล้มเหลวเงียบๆ ไม่ให้กระทบระบบหลัก */
    public static function log(string $action, ?string $table = null, ?int $refId = null, $detail = null): void
    {
        try {
            self::insert([
                'user_id'   => $_SESSION['user']['id'] ?? null,
                'action'    => $action,
                'ref_table' => $table,
                'ref_id'    => $refId,
                'detail'    => is_string($detail) || $detail === null ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE),
                'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // log ล้มเหลวไม่ควรทำให้ระบบหลักพัง
        }
    }

    /**
     * นับล็อกอินผิดภายในช่วงเวลา — นับทั้ง "อีเมลเดิม" และ "IP เดิม" เพื่อกันไล่เดาหลายบัญชีจากเครื่องเดียว
     * เก็บในฐานข้อมูล (ไม่ใช่ session) เพราะผู้โจมตีทิ้งคุกกี้แล้วเริ่มเซสชันใหม่ได้ทุกครั้ง
     */
    public static function loginFailures(string $email, string $ip, string $since): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM activity_logs
             WHERE action = 'login_failed' AND created_at >= :s
               AND (detail = :e OR (ip = :ip AND ip <> ''))",
            [':s' => $since, ':e' => $email, ':ip' => $ip]
        );
    }

    public static function clearLoginFailures(string $email): void
    {
        Database::exec("DELETE FROM activity_logs WHERE action = 'login_failed' AND detail = :e", [':e' => $email]);
    }

    /** จำนวนครั้งที่ค้นหาการจองที่หน้า /track ไม่พบใน 15 นาที แยกตาม IP (กันไล่เดาเลขที่จอง + เบอร์โทร) */
    public static function trackFailsByIp(string $ip): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM activity_logs
             WHERE action = 'track_fail' AND ip = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
            [':ip' => $ip]
        );
    }

    /** จำนวนครั้งที่ผูกบัญชี LINE ผิดใน 15 นาที แยกตามผู้ใช้ LINE */
    public static function liffFailsByUser(string $uid): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM activity_logs
             WHERE action = 'liff_link_fail' AND detail = :u AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
            [':u' => $uid]
        );
    }

    /** แยกตามหมายเลข IP — กันคนสลับบัญชี LINE หลายบัญชีมาเดาจากเครื่องเดียว */
    public static function liffFailsByIp(string $ip): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM activity_logs
             WHERE action = 'liff_link_fail' AND ip = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
            [':ip' => $ip]
        );
    }

    /** จำนวนการจองที่สร้างจาก IP นี้ตั้งแต่เวลาที่กำหนด */
    public static function bookingsFromIpSince(string $ip, string $since): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM activity_logs WHERE action = 'booking_created' AND ip = :ip AND ip <> '' AND created_at >= :t",
            [':ip' => $ip, ':t' => $since]
        );
    }

    /** ประวัติล่าสุดพร้อมชื่อผู้ใช้ (หน้าจัดการผู้ใช้) */
    public static function recent(int $limit = 40): array
    {
        $limit = max(1, min(500, $limit));
        return Database::all(
            "SELECT l.*, u.name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
             ORDER BY l.id DESC LIMIT $limit"
        );
    }
}
