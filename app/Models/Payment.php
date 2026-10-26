<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** การชำระเงิน / สลิป / เงินประกัน */
final class Payment extends Model
{
    protected static string $table = 'payments';

    /**
     * สูตรนับ "เงินที่เป็นรายได้" จากตาราง payments — ใช้สูตรนี้ทุกที่ ห้ามเขียนซ้ำ
     *   refund                      = คืนค่าเช่าให้ลูกค้า → หักออก
     *   security / security_refund  = เงินประกันรับเข้า–คืนออก → ไม่ใช่รายได้ทั้งคู่
     *   ที่เหลือ (deposit / balance / extra) = รายได้ รวมถึงรายการที่หักจากเงินประกัน (method = deduct)
     */
    public const REVENUE_SQL = "CASE WHEN kind = 'refund' THEN -amount WHEN kind IN ('security','security_refund') THEN 0 ELSE amount END";

    public static function forBooking(int $bookingId): array
    {
        return Database::all(
            'SELECT p.*, u.name AS approver FROM payments p LEFT JOIN users u ON u.id = p.approved_by
             WHERE p.booking_id = :b ORDER BY p.id DESC',
            [':b' => $bookingId]
        );
    }

    public static function pendingCountForBooking(int $bookingId): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM payments WHERE booking_id = :b AND status = 'pending'", [':b' => $bookingId]);
    }

    /** รายการล่าสุดของการจอง (หน้า LIFF) */
    public static function recentForBooking(int $bookingId, int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        return Database::all(
            "SELECT amount, status, paid_at, reject_reason, kind FROM payments
             WHERE booking_id = :b ORDER BY id DESC LIMIT $limit",
            [':b' => $bookingId]
        );
    }

    /** รายการสลิปพร้อมข้อมูลการจอง (หน้าตรวจสลิป) — $status = pending|approved|rejected|all */
    public static function listByStatus(string $status, int $limit = 200): array
    {
        $w   = $status === 'all' ? '1=1' : 'p.status = :st';
        $prm = $status === 'all' ? [] : [':st' => $status];
        $limit = max(1, min(1000, $limit));
        return Database::all(
            "SELECT p.*, b.booking_no, b.contact_name, b.contact_phone, b.grand_total, b.paid_amount, b.extra_charge,
                    c.plate, c.brand, c.model, u.name AS approver
             FROM payments p
             JOIN bookings b ON b.id = p.booking_id
             JOIN cars c ON c.id = b.car_id
             LEFT JOIN users u ON u.id = p.approved_by
             WHERE $w ORDER BY p.status = 'pending' DESC, p.id DESC LIMIT $limit",
            $prm
        );
    }

    public static function pendingCount(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM payments WHERE status = 'pending'");
    }

    public static function pendingSum(): float
    {
        return (float) Database::value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'pending'");
    }

    /**
     * อนุมัติสลิปที่ยังรอตรวจ — คืน true เฉพาะคำขอที่เปลี่ยนสถานะได้จริง
     * (เงื่อนไข status = 'pending' อยู่ในคำสั่ง UPDATE เดียว ถ้าแอดมินสองคนกดพร้อมกัน จะสำเร็จแค่คนเดียว)
     */
    public static function approve(int $id, int $userId): bool
    {
        $changed = Database::exec(
            "UPDATE payments SET status = 'approved', approved_by = :u, approved_at = :t
             WHERE id = :id AND status = 'pending'",
            [':u' => $userId, ':t' => date('Y-m-d H:i:s'), ':id' => $id]
        );
        return $changed === 1;
    }

    /** ปฏิเสธสลิปที่ยังรอตรวจ — คืน true เฉพาะคำขอที่เปลี่ยนสถานะได้จริง (เหมือน approve) */
    public static function reject(int $id, int $userId, string $reason): bool
    {
        $changed = Database::exec(
            "UPDATE payments SET status = 'rejected', approved_by = :u, approved_at = :t, reject_reason = :r
             WHERE id = :id AND status = 'pending'",
            [':u' => $userId, ':t' => date('Y-m-d H:i:s'), ':r' => $reason, ':id' => $id]
        );
        return $changed === 1;
    }

    // ------------------------------------------------------------
    // ยอดรวม
    // ------------------------------------------------------------
    /** เงินรับสุทธิที่ยืนยันแล้วของการจอง (ไม่รวมเงินประกัน หักรายการคืนเงิน) */
    public static function netPaid(int $bookingId): float
    {
        return (float) Database::value(
            'SELECT COALESCE(SUM(' . self::REVENUE_SQL . "), 0)
             FROM payments WHERE booking_id = :b AND status = 'approved'",
            [':b' => $bookingId]
        );
    }

    public static function approvedRefundCount(int $bookingId): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM payments WHERE booking_id = :b AND status = 'approved' AND kind = 'refund'",
            [':b' => $bookingId]
        );
    }

    /**
     * เงินประกันที่ร้านยังถืออยู่ของการจองนี้
     * = รับเข้า − คืนลูกค้าแล้ว − ที่หักไปชำระยอดค้าง
     */
    public static function securityHeld(int $bookingId): float
    {
        return round((float) Database::value(
            "SELECT COALESCE(SUM(CASE WHEN kind = 'security' THEN amount
                                      WHEN kind = 'security_refund' THEN -amount
                                      WHEN method = 'deduct' THEN -amount
                                      ELSE 0 END), 0)
             FROM payments WHERE booking_id = :b AND status = 'approved'",
            [':b' => $bookingId]
        ), 2);
    }

    /**
     * รายได้รับจริงในช่วงวันที่ — นับจากเงินที่อนุมัติแล้ว
     * เงินประกันความเสียหายไม่ใช่รายได้ (ต้องคืนลูกค้า) จึงไม่นับ และรายการคืนเงินหักออก
     * ทุกหน้าที่แสดง "รายได้รับจริง" ต้องเรียกฟังก์ชันนี้ ตัวเลขจะได้ตรงกันทั้งระบบ
     */
    public static function revenueCollected(string $from, string $to): float
    {
        return (float) Database::value(
            'SELECT COALESCE(SUM(' . self::REVENUE_SQL . "), 0)
             FROM payments WHERE status = 'approved' AND DATE(approved_at) BETWEEN :a AND :b",
            [':a' => $from, ':b' => $to]
        );
    }

    /** รายได้รับจริงรายวัน */
    public static function dailyRevenue(string $from, string $to): array
    {
        return Database::all(
            'SELECT DATE(approved_at) d, SUM(' . self::REVENUE_SQL . ") amt
             FROM payments WHERE status='approved' AND DATE(approved_at) BETWEEN :a AND :b
             GROUP BY DATE(approved_at) ORDER BY d",
            [':a' => $from, ':b' => $to]
        );
    }

    /** เวลาตรวจสลิปเฉลี่ย (ชั่วโมง) ในช่วงวันที่ — null ถ้าไม่มีข้อมูล */
    public static function averageApprovalHours(string $from, string $to): ?float
    {
        $v = Database::value(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, approved_at)) / 60 FROM payments
             WHERE status = 'approved' AND slip_file IS NOT NULL AND DATE(approved_at) BETWEEN :a AND :b",
            [':a' => $from, ':b' => $to]
        );
        return $v === null || $v === false ? null : (float) $v;
    }
}
