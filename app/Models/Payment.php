<?php
namespace App\Models;

use App\Core\Database;

/** การชำระเงิน / สลิป / เงินประกัน */
final class Payment
{
    /**
     * สูตรนับ "เงินที่เป็นรายได้" — ใช้สูตรนี้ทุกที่ ห้ามเขียนซ้ำ (ตรงกับ VIEW v_booking_payment)
     *   refund                      = คืนค่าเช่าให้ลูกค้า → หักออก
     *   security / security_refund  = เงินประกันรับเข้า–คืนออก → ไม่ใช่รายได้ทั้งคู่
     *   ที่เหลือ (deposit / balance / extra) = รายได้ รวมที่หักจากเงินประกัน (method = deduct)
     */
    public const REVENUE_SQL = "CASE WHEN kind = 'refund' THEN -amount WHEN kind IN ('security','security_refund') THEN 0 ELSE amount END";

    public const KINDS = [
        'deposit'         => 'มัดจำ / ค่าเช่า',
        'balance'         => 'ค่าเช่าส่วนที่เหลือ',
        'extra'           => 'ค่าใช้จ่ายเพิ่ม',
        'security'        => 'รับเงินประกัน',
        'refund'          => 'คืนค่าเช่า',
        'security_refund' => 'คืนเงินประกัน',
    ];

    public const METHODS = [
        'transfer'  => 'โอนเงิน',
        'promptpay' => 'พร้อมเพย์',
        'cash'      => 'เงินสด',
        'card'      => 'บัตร',
        'deduct'    => 'หักจากเงินประกัน',
    ];

    public const STATUS = ['pending' => 'รอตรวจสอบ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ปฏิเสธ'];

    public static function insert(array $row): int
    {
        return Database::insert(
            'INSERT INTO payments (booking_id, kind, method, amount, paid_at, slip_file, bank_ref, status, approved_by, approved_at, note)
             VALUES (:booking_id, :kind, :method, :amount, :paid_at, :slip_file, :bank_ref, :status, :approved_by, :approved_at, :note)',
            [
                'booking_id'  => $row['booking_id'],
                'kind'        => $row['kind'],
                'method'      => $row['method'] ?? 'transfer',
                'amount'      => $row['amount'],
                'paid_at'     => $row['paid_at'] ?? null,
                'slip_file'   => $row['slip_file'] ?? null,
                'bank_ref'    => $row['bank_ref'] ?? null,
                'status'      => $row['status'] ?? 'pending',
                'approved_by' => $row['approved_by'] ?? null,
                'approved_at' => $row['approved_at'] ?? null,
                'note'        => $row['note'] ?? null,
            ]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM payments WHERE id = :id', ['id' => $id]);
    }

    public static function forBooking(int $bookingId): array
    {
        return Database::all(
            'SELECT p.*, u.name AS approver FROM payments p LEFT JOIN users u ON u.id = p.approved_by
              WHERE p.booking_id = :b ORDER BY p.id DESC',
            ['b' => $bookingId]
        );
    }

    public static function pendingCountForBooking(int $bookingId): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM payments WHERE booking_id = :b AND status = 'pending'", ['b' => $bookingId]);
    }

    /** รายการพร้อมข้อมูลการจอง (หน้าตรวจสลิป) — $status = pending|approved|rejected|all */
    public static function listByStatus(string $status, int $limit = 200): array
    {
        $where  = $status === 'all' ? '1 = 1' : 'p.status = :st';
        $params = $status === 'all' ? [] : ['st' => $status];
        return Database::all(
            "SELECT p.*, b.booking_no, b.contact_name, b.contact_phone, b.grand_total, b.deposit_amount, b.status AS booking_status,
                    c.plate, c.brand, c.model, u.name AS approver
               FROM payments p
               JOIN bookings b ON b.id = p.booking_id
               JOIN cars c ON c.id = b.car_id
               LEFT JOIN users u ON u.id = p.approved_by
              WHERE $where ORDER BY p.id DESC LIMIT " . max(1, min(1000, $limit)),
            $params
        );
    }

    /** จำนวนสลิปรอตรวจ (ใช้ในเมนูแอดมินทุกหน้า — COUNT เดียวที่ใช้ index ของ status) */
    public static function pendingCount(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM payments WHERE status = 'pending'");
    }

    public static function countByStatus(): array
    {
        $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach (Database::all('SELECT status, COUNT(*) AS n FROM payments GROUP BY status') as $row) {
            $out[$row['status']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * อนุมัติรายการที่ยังรอตรวจ — คืน true เฉพาะคำขอที่เปลี่ยนสถานะได้จริง
     * เงื่อนไข status = 'pending' อยู่ในคำสั่ง UPDATE เดียว (atomic): แอดมินสองคนกดพร้อมกัน สำเร็จแค่คนเดียว
     */
    public static function approve(int $id, int $userId): bool
    {
        $stmt = Database::run(
            "UPDATE payments SET status = 'approved', approved_by = :u, approved_at = NOW()
              WHERE id = :id AND status = 'pending'",
            ['u' => $userId, 'id' => $id]
        );
        return $stmt->rowCount() === 1;
    }

    public static function reject(int $id, int $userId, string $reason): bool
    {
        $stmt = Database::run(
            "UPDATE payments SET status = 'rejected', approved_by = :u, approved_at = NOW(), reject_reason = :r
              WHERE id = :id AND status = 'pending'",
            ['u' => $userId, 'r' => $reason, 'id' => $id]
        );
        return $stmt->rowCount() === 1;
    }

    /** เงินรับสุทธิที่อนุมัติแล้ว (ไม่รวมเงินประกัน หักรายการคืนค่าเช่า) */
    public static function netPaid(int $bookingId): float
    {
        return round((float) Database::value(
            'SELECT COALESCE(SUM(' . self::REVENUE_SQL . "), 0) FROM payments WHERE booking_id = :b AND status = 'approved'",
            ['b' => $bookingId]
        ), 2);
    }

    public static function approvedRefundCount(int $bookingId): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM payments WHERE booking_id = :b AND status = 'approved' AND kind = 'refund'",
            ['b' => $bookingId]
        );
    }

    /** เงินประกันที่ร้านยังถืออยู่ = รับเข้า − คืนลูกค้า − ที่หักไปชำระยอดค้าง */
    public static function securityHeld(int $bookingId): float
    {
        return round((float) Database::value(
            "SELECT COALESCE(SUM(CASE WHEN kind = 'security' THEN amount
                                      WHEN kind = 'security_refund' THEN -amount
                                      WHEN method = 'deduct' THEN -amount
                                      ELSE 0 END), 0)
               FROM payments WHERE booking_id = :b AND status = 'approved'",
            ['b' => $bookingId]
        ), 2);
    }
}
