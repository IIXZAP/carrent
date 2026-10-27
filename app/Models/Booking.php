<?php
namespace App\Models;

use App\Core\Database;

final class Booking
{
    private const INSERT_COLUMNS = [
        'booking_no', 'customer_id', 'car_id', 'contact_name', 'contact_phone', 'contact_email', 'contact_line',
        'pickup_at', 'return_at', 'pickup_place', 'return_place', 'delivery_km', 'rental_days', 'rate_breakdown',
        'rental_amount', 'addon_amount', 'discount', 'subtotal', 'vat_rate', 'vat_amount', 'grand_total',
        'deposit_amount', 'security_deposit', 'status', 'payment_status', 'source', 'note', 'created_by',
    ];

    // ------------------------------------------------------------
    // สร้าง / อ่าน
    // ------------------------------------------------------------

    /** เลขที่จอง BK261026-001 (ตัวนับรายวัน) — เรียกใน transaction เดียวกับการ INSERT */
    public static function nextNumber(): string
    {
        $day = date('ymd');
        return 'BK' . $day . '-' . str_pad((string) Sequence::next('booking', $day), 3, '0', STR_PAD_LEFT);
    }

    public static function insert(array $row): int
    {
        $cols  = self::INSERT_COLUMNS;
        $params = [];
        foreach ($cols as $col) {
            $params[$col] = $row[$col] ?? null;
        }
        return Database::insert(
            'INSERT INTO bookings (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')',
            $params
        );
    }

    /** บริการเสริมของการจอง (snapshot ชื่อ/ราคา ณ วันจอง) */
    public static function insertAddon(int $bookingId, ?int $addonId, string $name, float $qty, float $unitPrice, float $amount): void
    {
        Database::run(
            'INSERT INTO booking_addons (booking_id, addon_id, name, qty, unit_price, amount)
             VALUES (:b, :a, :n, :q, :u, :m)',
            ['b' => $bookingId, 'a' => $addonId, 'n' => $name, 'q' => $qty, 'u' => $unitPrice, 'm' => $amount]
        );
    }

    private const SELECT_WITH_CAR = 'SELECT b.*, c.plate, c.brand, c.model
                                       FROM bookings b JOIN cars c ON c.id = b.car_id';

    public static function find(int $id): ?array
    {
        return Database::one(self::SELECT_WITH_CAR . ' WHERE b.id = :id', ['id' => $id]);
    }

    public static function findByNo(string $no): ?array
    {
        return Database::one(self::SELECT_WITH_CAR . ' WHERE b.booking_no = :no', ['no' => $no]);
    }

    public static function addons(int $bookingId): array
    {
        return Database::all('SELECT * FROM booking_addons WHERE booking_id = :b ORDER BY id', ['b' => $bookingId]);
    }

    /** รายการสำหรับหลังบ้าน: กรองสถานะ + ค้นเลขที่จอง/ชื่อ/เบอร์/ทะเบียน */
    public static function search(string $status, string $q, int $limit = 200): array
    {
        $where  = ['1 = 1'];
        $params = [];
        if ($status !== '') {
            $where[] = 'b.status = :status';
            $params['status'] = $status;
        }
        if ($q !== '') {
            $where[] = '(b.booking_no LIKE :q1 OR b.contact_name LIKE :q2 OR b.contact_phone LIKE :q3 OR c.plate LIKE :q4)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
        return Database::all(
            self::SELECT_WITH_CAR . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY b.id DESC LIMIT ' . (int) $limit,
            $params
        );
    }

    public static function countByStatus(): array
    {
        $out = [];
        foreach (Database::all('SELECT status, COUNT(*) AS n FROM bookings GROUP BY status') as $row) {
            $out[$row['status']] = (int) $row['n'];
        }
        return $out;
    }

    /** การจองที่ยังไม่จ่ายมัดจำของเบอร์นี้ (ยังกันรถไว้) — เบอร์ต้อง normalize แล้ว */
    public static function openUnpaidCountByPhone(string $phone): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM bookings
              WHERE contact_phone = :p AND status = 'pending' AND payment_status = 'unpaid'",
            ['p' => $phone]
        );
    }

    /** เงื่อนไขการจองที่หมดอายุยกเลิกอัตโนมัติได้ (ใช้ซ้ำทั้งตอนหาและตอน UPDATE เพื่อให้ตรวจ ณ ขณะเปลี่ยนจริง) */
    private const EXPIRABLE = "b.status = 'pending' AND b.source = 'web' AND b.payment_status = 'unpaid' AND b.created_at < :t
                               AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.status IN ('pending','approved'))";

    /**
     * ยกเลิกการจองที่ค้างไม่จ่ายมัดจำ — คืนเลขที่จองของใบที่ยกเลิกได้จริง
     * ยกเลิกเฉพาะ: จองผ่านเว็บ · ยังรอยืนยัน · ยังไม่มีเงินเข้า · ไม่มีสลิปรอตรวจหรืออนุมัติแล้ว (สลิปที่ถูกปฏิเสธไม่นับ)
     * การจองที่พนักงานสร้างเองไม่ถูกยกเลิกอัตโนมัติ (พนักงานตั้งใจกันรถไว้ให้ลูกค้า)
     * แต่ละใบเป็น UPDATE เดียวที่ตรวจเงื่อนไขซ้ำ จึงไม่ชนกับคนที่เพิ่งอนุมัติสลิป/รับเงินในเสี้ยววินาทีเดียวกัน
     */
    public static function cancelExpiredUnpaid(string $createdBefore, string $reason): array
    {
        $candidates = Database::all('SELECT b.id, b.booking_no FROM bookings b WHERE ' . self::EXPIRABLE, ['t' => $createdBefore]);
        $cancelled = [];
        foreach ($candidates as $row) {
            $stmt = Database::run(
                "UPDATE bookings b SET b.status = 'cancelled', b.cancel_reason = :r WHERE b.id = :id AND " . self::EXPIRABLE,
                ['r' => $reason, 'id' => $row['id'], 't' => $createdBefore]
            );
            if ($stmt->rowCount() === 1) {
                $cancelled[] = $row;
            }
        }
        return $cancelled;
    }

    /**
     * ล็อกแถวการจองไว้จนจบ transaction — ใช้ก่อนแก้ยอดชำระ/สถานะ ให้คนที่แตะการจองใบเดียวกันต้องรอคิว
     * (ต้องเป็นคำสั่งแรกใน transaction ก่อนอ่านอย่างอื่น เหมือน Car::lockForUpdate)
     */
    public static function lockForUpdate(int $id): ?array
    {
        return Database::one('SELECT * FROM bookings WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    public static function setPaymentStatus(int $id, string $status): void
    {
        Database::run('UPDATE bookings SET payment_status = :s WHERE id = :id', ['s' => $status, 'id' => $id]);
    }

    /** ยืนยันการจองที่ยังรอยืนยัน — คืน true เฉพาะที่เปลี่ยนจริง (atomic) */
    public static function confirmIfPending(int $id): bool
    {
        $stmt = Database::run("UPDATE bookings SET status = 'confirmed' WHERE id = :id AND status = 'pending'", ['id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * ยกเลิกการจอง — UPDATE เงื่อนไขเดียว (atomic) คืน false ถ้าสถานะเปลี่ยนไปแล้ว
     * ยกเลิกได้เฉพาะก่อนรับรถ
     */
    public static function cancel(int $id, string $reason): bool
    {
        $stmt = Database::run(
            "UPDATE bookings SET status = 'cancelled', cancel_reason = :r
              WHERE id = :id AND status IN ('pending','confirmed')",
            ['id' => $id, 'r' => $reason]
        );
        return $stmt->rowCount() === 1;
    }

    /** จำนวนการจองที่ทับช่วง [from, to) — นับเฉพาะที่ยังกินคิวรถ */
    public static function conflictCount(int $carId, string $from, string $to, ?int $ignoreId = null): int
    {
        $sql = "SELECT COUNT(*) FROM bookings
                 WHERE car_id = :car
                   AND status IN ('pending','confirmed','picked_up')
                   AND pickup_at < :to_at AND return_at > :from_at";
        $params = ['car' => $carId, 'from_at' => $from, 'to_at' => $to];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ign';
            $params['ign'] = $ignoreId;
        }
        return (int) Database::value($sql, $params);
    }

    /** รถที่รับไปแล้วแต่เลยกำหนดคืนและยังไม่กดรับคืน = ยังอยู่กับลูกค้ารายเดิม */
    public static function overdueOutCount(int $carId, ?int $ignoreId = null): int
    {
        $sql = "SELECT COUNT(*) FROM bookings
                 WHERE car_id = :car AND status = 'picked_up' AND return_at < NOW()";
        $params = ['car' => $carId];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ign';
            $params['ign'] = $ignoreId;
        }
        return (int) Database::value($sql, $params);
    }

    /** ช่วงที่รถไม่ว่าง (การจอง + ช่วงปิดรถ) ใช้แสดงลูกค้า — ไม่ส่งเลขที่จอง/เหตุผลออกไป */
    public static function busyRanges(int $carId, string $from, string $to): array
    {
        return Database::all(
            "SELECT pickup_at AS start_at, return_at AS end_at
               FROM bookings
              WHERE car_id = :c AND status IN ('pending','confirmed','picked_up')
                AND return_at >= :f AND pickup_at <= :t
             UNION ALL
             SELECT start_at, end_at
               FROM car_blocks
              WHERE car_id = :c2 AND end_at >= :f2 AND start_at <= :t2
             ORDER BY start_at",
            ['c' => $carId, 'f' => $from, 't' => $to, 'c2' => $carId, 'f2' => $from, 't2' => $to]
        );
    }
}
