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

    /**
     * ใบจองที่เพิ่งสร้างโดยคนเดิม (เบอร์+ชื่อ) รถเดิม ช่วงเวลาเดิม — ใช้แยก "กดซ้ำ/รีเฟรช" ออกจากการจองซ้อนจริง
     * นับเฉพาะใบที่ยังใช้งาน (ใบที่ยกเลิกแล้วไม่ใช่ใบเดิมที่ควรส่งกลับให้)
     */
    public static function findRecentDuplicate(int $carId, string $phone, string $name, string $pickup, string $return, string $since): ?array
    {
        return Database::one(
            self::SELECT_WITH_CAR . " WHERE b.car_id = :car AND b.contact_phone = :phone AND b.contact_name = :name
                AND b.pickup_at = :p AND b.return_at = :r AND b.status IN ('pending','confirmed') AND b.created_at >= :since
              ORDER BY b.id DESC LIMIT 1",
            ['car' => $carId, 'phone' => $phone, 'name' => $name, 'p' => $pickup, 'r' => $return, 'since' => $since]
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

    /**
     * รหัสลับของลิงก์เซ็นสัญญา (128 บิต) — สร้างครั้งแรกที่ต้องใช้ แล้วใช้รหัสเดิมตลอด
     * UPDATE เดียวแบบ COALESCE: เรียกพร้อมกันกี่ครั้งก็ได้รหัสเดียวกัน
     */
    public static function signToken(int $bookingId): string
    {
        Database::run(
            'UPDATE bookings SET sign_token = COALESCE(sign_token, :t) WHERE id = :id',
            ['t' => bin2hex(random_bytes(16)), 'id' => $bookingId]
        );
        return (string) Database::value('SELECT sign_token FROM bookings WHERE id = :id', ['id' => $bookingId]);
    }

    /** หาการจองจากเลขที่จอง + รหัสลิงก์เซ็น — ต้องตรงทั้งคู่ (เทียบแบบ constant-time) */
    public static function findBySignToken(string $bookingNo, string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }
        $row = self::findByNo($bookingNo);
        return $row !== null && $row['sign_token'] !== null && hash_equals((string) $row['sign_token'], $token) ? $row : null;
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
            'SELECT b.*, c.plate, c.brand, c.model, v.paid_total, v.balance_due
               FROM bookings b JOIN cars c ON c.id = b.car_id LEFT JOIN v_booking_payment v ON v.booking_id = b.id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY b.id DESC LIMIT ' . (int) $limit,
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

    /** แถวบริการส่ง/รับรถถึงที่ของการจอง (พร้อมชื่อตั้งต้นจากตาราง addons) */
    public static function deliveryLines(int $bookingId): array
    {
        return Database::all(
            "SELECT ba.*, a.code, a.name AS base_name FROM booking_addons ba
               JOIN addons a ON a.id = ba.addon_id
              WHERE ba.booking_id = :b AND a.code IN ('delivery','return_delivery') ORDER BY ba.id",
            ['b' => $bookingId]
        );
    }

    public static function updateAddonLine(int $lineId, string $name, float $unitPrice, float $amount): void
    {
        Database::run('UPDATE booking_addons SET name = :n, unit_price = :u, amount = :m WHERE id = :id', ['n' => $name, 'u' => $unitPrice, 'm' => $amount, 'id' => $lineId]);
    }

    public static function addonTotal(int $bookingId): float
    {
        return (float) Database::value('SELECT COALESCE(SUM(amount), 0) FROM booking_addons WHERE booking_id = :b', ['b' => $bookingId]);
    }

    /** รายการบริการเสริมในรูปแบบเดียวกับ quote (เก็บใน rate_breakdown) */
    public static function addonLinesForBreakdown(int $bookingId): array
    {
        $out = [];
        foreach (Database::all(
            'SELECT ba.name, ba.qty, ba.unit_price, ba.amount, a.code FROM booking_addons ba LEFT JOIN addons a ON a.id = ba.addon_id WHERE ba.booking_id = :b ORDER BY ba.id',
            ['b' => $bookingId]
        ) as $row) {
            $out[] = ['key' => $row['code'], 'label' => $row['name'], 'qty' => (float) $row['qty'], 'unit' => (float) $row['unit_price'], 'amount' => (float) $row['amount']];
        }
        return $out;
    }

    /** แก้ยอดเงินของการจอง (เรียกใน transaction ที่ล็อกการจองแล้วเท่านั้น) */
    public static function updateTotals(int $id, array $values): void
    {
        $allowed = ['addon_amount', 'discount', 'subtotal', 'vat_amount', 'grand_total', 'deposit_amount', 'rate_breakdown', 'delivery_km'];
        $sets = [];
        $params = ['id' => $id];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $values)) {
                $sets[] = "$col = :$col";
                $params[$col] = $values[$col];
            }
        }
        Database::run('UPDATE bookings SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
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
     * เปลี่ยนสถานะ — UPDATE เดียวที่ตรวจสถานะเดิมด้วย คืน true เฉพาะที่เปลี่ยนจริง (กดซ้ำ/เปิดสองแท็บไม่ผ่านสองครั้ง)
     * $set = คอลัมน์อื่นที่แก้พร้อมกัน (ระบุเฉพาะชื่อที่อนุญาต)
     */
    public static function transition(int $id, array $from, string $to, array $set = []): bool
    {
        $allowed = ['picked_up_at', 'returned_at', 'extra_charge', 'extra_note', 'extra_breakdown', 'damage_note'];
        $sets = ['status = :to'];
        $params = ['to' => $to, 'id' => $id];
        foreach ($set as $col => $value) {
            if (!in_array($col, $allowed, true)) {
                throw new \InvalidArgumentException("คอลัมน์ไม่ได้รับอนุญาต: $col");
            }
            $sets[] = "$col = :$col";
            $params[$col] = $value;
        }
        $in = [];
        foreach (array_values($from) as $i => $status) {
            $in[] = ":f$i";
            $params["f$i"] = $status;
        }
        $stmt = Database::run('UPDATE bookings SET ' . implode(', ', $sets) . ' WHERE id = :id AND status IN (' . implode(', ', $in) . ')', $params);
        return $stmt->rowCount() === 1;
    }

    /** การจองอื่นของรถคันนี้ที่รับรถไปแล้วแต่ยังไม่คืน (รถไม่อยู่ที่ร้าน) — คืนเลขที่จอง หรือ null */
    public static function otherCheckedOutForCar(int $carId, int $exceptId): ?string
    {
        $no = Database::value("SELECT booking_no FROM bookings WHERE car_id = :c AND status = 'picked_up' AND id <> :x LIMIT 1", ['c' => $carId, 'x' => $exceptId]);
        return $no === null ? null : (string) $no;
    }

    /** เพิ่มค่าใช้จ่ายหลังรับคืน (บวกเข้ายอดเดิม + ต่อท้ายรายการ) — เรียกใน transaction ที่ล็อกการจองแล้ว */
    public static function addExtraCharge(int $id, float $amount, array $line): void
    {
        $booking = Database::one('SELECT extra_charge, extra_note, extra_breakdown FROM bookings WHERE id = :id', ['id' => $id]);
        $lines = json_decode((string) ($booking['extra_breakdown'] ?? ''), true) ?: [];
        $lines[] = $line;
        $note = trim((string) $booking['extra_note'] . ' · ' . $line['label'] . ' ' . number_format($amount, 0) . ' บ.', ' ·');
        Database::run(
            'UPDATE bookings SET extra_charge = :c, extra_breakdown = :b, extra_note = :n WHERE id = :id',
            ['c' => round((float) $booking['extra_charge'] + $amount, 2), 'b' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'n' => mb_substr($note, 0, 250), 'id' => $id]
        );
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
