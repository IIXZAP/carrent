<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** การจอง */
final class Booking extends Model
{
    protected static string $table = 'bookings';

    public const STATUSES = ['pending', 'confirmed', 'picked_up', 'returned', 'completed', 'cancelled'];

    // ------------------------------------------------------------
    // ดึงรายการเดียว
    // ------------------------------------------------------------
    public static function findByNo(string $no): ?array
    {
        return Database::one('SELECT * FROM bookings WHERE booking_no = :n', [':n' => $no]);
    }

    /** การจอง + ข้อมูลรถ (หน้างานหลังบ้าน) */
    public static function findWithCar(int $id): ?array
    {
        return Database::one(
            'SELECT b.*, c.brand, c.model, c.plate, c.color, c.mileage AS car_mileage
             FROM bookings b JOIN cars c ON c.id = b.car_id WHERE b.id = :id',
            [':id' => $id]
        );
    }

    /** การจอง + ข้อมูลรถแบบย่อ (หน้าลูกค้า ตรวจสอบการจอง / จองสำเร็จ) */
    public static function findByNoWithCar(string $no): ?array
    {
        return Database::one(
            'SELECT b.*, c.brand, c.model, c.plate, c.color
             FROM bookings b JOIN cars c ON c.id = b.car_id
             WHERE b.booking_no = :n',
            [':n' => $no]
        );
    }

    /** การจอง + ข้อมูลรถครบพอสำหรับเขียนสัญญา (หน้าเซ็นสัญญา / พิมพ์สัญญา) */
    public static function findByNoForContract(string $no): ?array
    {
        return Database::one(
            'SELECT b.*, c.brand, c.model, c.plate, c.province, c.color, c.year, c.insurance_type
             FROM bookings b JOIN cars c ON c.id = b.car_id WHERE b.booking_no = :n',
            [':n' => $no]
        );
    }

    public static function findByIdForContract(int $id): ?array
    {
        return Database::one(
            'SELECT b.*, c.brand, c.model, c.plate, c.province, c.color, c.year, c.insurance_type
             FROM bookings b JOIN cars c ON c.id = b.car_id WHERE b.id = :i',
            [':i' => $id]
        );
    }

    /** การจอง + รถ สำหรับเอกสารและฟอร์มสร้างเอกสาร */
    public static function findForDocument(int $id): ?array
    {
        return Database::one(
            'SELECT b.*, c.brand, c.model, c.plate FROM bookings b
             JOIN cars c ON c.id = b.car_id WHERE b.id = :i',
            [':i' => $id]
        );
    }

    public static function lock(int $id): void
    {
        Database::one('SELECT id FROM bookings WHERE id = :i FOR UPDATE', [':i' => $id]);
    }

    // ------------------------------------------------------------
    // เลขที่การจอง
    // ------------------------------------------------------------
    /** BK260919-001 — เลขที่รันในวันนี้ จากตัวนับ sequences (จองพร้อมกันก็ไม่ซ้ำ) */
    public static function nextNumber(): string
    {
        $day = date('ymd');
        return 'BK' . $day . '-' . str_pad((string) Sequence::next('booking', $day), 3, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------
    // กันจองซ้ำ / จองถี่ผิดปกติ
    // ------------------------------------------------------------
    /** การจองที่เพิ่งสร้างของรถคันเดิม ช่วงเวลาเดิม (กดยืนยันซ้ำ / กดย้อนแล้วส่งใหม่) */
    public static function recentDuplicate(string $no, int $carId, string $pickDb, string $retDb, string $sinceDb): ?array
    {
        return Database::one(
            'SELECT booking_no FROM bookings WHERE booking_no = :n AND car_id = :c AND pickup_at = :p AND return_at = :r
             AND created_at >= :t',
            [':n' => $no, ':c' => $carId, ':p' => $pickDb, ':r' => $retDb, ':t' => $sinceDb]
        );
    }

    /** จำนวนการจองที่ยังไม่จ่ายมัดจำของเบอร์โทรนี้ */
    public static function openUnpaidCountByPhone(string $phone): int
    {
        $n = 0;
        foreach (Database::all(
            "SELECT contact_phone FROM bookings WHERE status = 'pending' AND paid_amount <= 0 AND " . phone_sql('contact_phone') . ' LIKE :t',
            [':t' => '%' . substr(phone_digits($phone), -7) . '%']
        ) as $r) {
            $n += phone_match($r['contact_phone'], $phone) ? 1 : 0;
        }
        return $n;
    }

    // ------------------------------------------------------------
    // ตรวจรถว่าง (ใช้โดย AvailabilityService)
    // ------------------------------------------------------------
    /** จำนวนการจองที่เวลาชนกับช่วงที่ตรวจ */
    public static function conflictCount(int $carId, string $from, string $to, ?int $ignoreId = null): int
    {
        $sql = "SELECT COUNT(*) FROM bookings
                WHERE car_id = :car
                  AND status IN ('pending','confirmed','picked_up')
                  AND pickup_at < :ret AND return_at > :pick";
        $p = [':car' => $carId, ':pick' => $from, ':ret' => $to];
        if ($ignoreId) {
            $sql .= ' AND id <> :ign';
            $p[':ign'] = $ignoreId;
        }
        return (int) Database::value($sql, $p);
    }

    /** รถที่ยังไม่ได้กดคืน และเลยกำหนดคืนมาแล้ว = ยังอยู่กับลูกค้ารายเดิม */
    public static function overdueOutCount(int $carId, ?int $ignoreId = null): int
    {
        $sql = "SELECT COUNT(*) FROM bookings WHERE car_id = :car AND status = 'picked_up' AND return_at < NOW()";
        $p   = [':car' => $carId];
        if ($ignoreId) {
            $sql .= ' AND id <> :ign';
            $p[':ign'] = $ignoreId;
        }
        return (int) Database::value($sql, $p);
    }

    /** ช่วงที่รถไม่ว่าง (การจอง + ช่วงปิดรถ) ใช้วาดปฏิทิน */
    public static function busyRanges(int $carId, string $from, string $to): array
    {
        return Database::all(
            "SELECT pickup_at AS start_at, return_at AS end_at, 'booking' AS kind, booking_no AS ref
             FROM bookings
             WHERE car_id = :c AND status IN ('pending','confirmed','picked_up')
               AND return_at >= :f AND pickup_at <= :t
             UNION ALL
             SELECT start_at, end_at, 'block' AS kind, reason AS ref
             FROM car_blocks
             WHERE car_id = :c2 AND end_at >= :f2 AND start_at <= :t2
             ORDER BY start_at",
            [':c' => $carId, ':f' => $from, ':t' => $to, ':c2' => $carId, ':f2' => $from, ':t2' => $to]
        );
    }

    /** การจองถัดไปของรถคันนี้หลังเวลาที่กำหนด (ใช้บอกเหตุผลตอนเช่าต่อไม่ได้) */
    public static function nextPickupAfter(int $carId, int $exceptId, string $afterAt): ?array
    {
        return Database::one(
            "SELECT pickup_at FROM bookings WHERE car_id = :c AND id <> :i
               AND status IN ('pending','confirmed','picked_up') AND pickup_at >= :r ORDER BY pickup_at LIMIT 1",
            [':c' => $carId, ':i' => $exceptId, ':r' => $afterAt]
        );
    }

    // ------------------------------------------------------------
    // รายการ / ค้นหา (หลังบ้าน)
    // ------------------------------------------------------------
    /**
     * ค้นหา + แบ่งหน้า
     * @param array{q?:string,status?:string,car_id?:int,from?:string,to?:string} $f
     * @return array{rows:array,total:int,pages:int,page:int,sum_total:float,sum_paid:float}
     */
    public static function search(array $f, int $page, int $per = 25, bool $withSums = false): array
    {
        $w = ['1=1'];
        $p = [];
        $q = (string) ($f['q'] ?? '');
        if ($q !== '') {
            $w[] = '(b.booking_no LIKE :q OR b.contact_name LIKE :q2 OR b.contact_phone LIKE :q3 OR c.plate LIKE :q4)';
            $p[':q'] = $p[':q2'] = $p[':q3'] = $p[':q4'] = '%' . $q . '%';
        }
        $status = (string) ($f['status'] ?? '');
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $w[] = 'b.status = :st';
            $p[':st'] = $status;
        }
        if (!empty($f['car_id'])) {
            $w[] = 'b.car_id = :car';
            $p[':car'] = (int) $f['car_id'];
        }
        if (!empty($f['from'])) {
            $w[] = 'DATE(b.pickup_at) >= :f';
            $p[':f'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $w[] = 'DATE(b.pickup_at) <= :t';
            $p[':t'] = $f['to'];
        }
        $where = implode(' AND ', $w);

        $total = (int) Database::value("SELECT COUNT(*) FROM bookings b JOIN cars c ON c.id = b.car_id WHERE $where", $p);
        $pages = max(1, (int) ceil($total / $per));
        $page  = min(max(1, $page), $pages);
        $off   = ($page - 1) * $per;

        $rows = Database::all(
            "SELECT b.*, c.brand, c.model, c.plate
             FROM bookings b JOIN cars c ON c.id = b.car_id
             WHERE $where ORDER BY b.pickup_at DESC, b.id DESC LIMIT $per OFFSET $off",
            $p
        );

        // ยอดเงินรวมเป็นข้อมูลของเจ้าของร้าน — การจองที่ยกเลิกไม่นับเป็นมูลค่าและยอดค้างรับ
        $sumTotal = $sumPaid = 0.0;
        if ($withSums) {
            $sumTotal = (float) Database::value("SELECT COALESCE(SUM(b.grand_total + b.extra_charge),0) FROM bookings b JOIN cars c ON c.id = b.car_id WHERE ($where) AND b.status <> 'cancelled'", $p);
            $sumPaid  = (float) Database::value("SELECT COALESCE(SUM(b.paid_amount),0) FROM bookings b JOIN cars c ON c.id = b.car_id WHERE ($where) AND b.status <> 'cancelled'", $p);
        }
        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page, 'sum_total' => $sumTotal, 'sum_paid' => $sumPaid];
    }

    // ------------------------------------------------------------
    // งานวันนี้ / ค้าง
    // ------------------------------------------------------------
    public static function pickupsOn(string $date): array
    {
        return Database::all(
            "SELECT b.*, c.brand, c.model, c.plate FROM bookings b JOIN cars c ON c.id = b.car_id
             WHERE DATE(b.pickup_at) = :d AND b.status IN ('pending','confirmed') ORDER BY b.pickup_at",
            [':d' => $date]
        );
    }

    public static function returnsOn(string $date): array
    {
        return Database::all(
            "SELECT b.*, c.brand, c.model, c.plate FROM bookings b JOIN cars c ON c.id = b.car_id
             WHERE DATE(b.return_at) = :d AND b.status = 'picked_up' ORDER BY b.return_at",
            [':d' => $date]
        );
    }

    /** รถที่เลยกำหนดคืนและยังไม่กดรับคืน */
    public static function overdue(): array
    {
        return Database::all(
            "SELECT b.*, c.brand, c.model, c.plate, c.price_day FROM bookings b JOIN cars c ON c.id = b.car_id
             WHERE b.status = 'picked_up' AND b.return_at < NOW() ORDER BY b.return_at"
        );
    }

    /** จองค้างยืนยันเกิน 24 ชั่วโมง (รับรถยังไม่ถึง) */
    public static function stalePending(): array
    {
        return Database::all(
            "SELECT b.*, c.plate FROM bookings b JOIN cars c ON c.id = b.car_id
             WHERE b.status = 'pending' AND b.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND b.pickup_at > NOW()"
        );
    }

    /** จองผ่านเว็บที่ไม่จ่ายมัดจำเกินเวลา และไม่มีสลิปรอตรวจ */
    public static function unpaidExpired(string $createdBefore): array
    {
        return Database::all(
            "SELECT b.id, b.booking_no FROM bookings b
             WHERE b.status = 'pending' AND b.source = 'web' AND b.paid_amount <= 0 AND b.created_at < :t
               AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.status IN ('pending','approved'))",
            [':t' => $createdBefore]
        );
    }

    public static function cancelPending(int $id, string $reason): void
    {
        Database::exec(
            "UPDATE bookings SET status = 'cancelled', cancel_reason = :r WHERE id = :i AND status = 'pending'",
            [':r' => $reason, ':i' => $id]
        );
    }

    /** ยืนยันแล้ว รับรถระหว่างวันที่ — ใช้เตือนลูกค้าก่อนรับรถ */
    public static function confirmedPickupBetween(string $a, string $b): array
    {
        return Database::all(
            "SELECT * FROM bookings WHERE status = 'confirmed'
               AND DATE(pickup_at) BETWEEN :a AND :b AND pickup_at > NOW()",
            [':a' => $a, ':b' => $b]
        );
    }

    /** รถออกงานอยู่ ครบกำหนดคืนระหว่างวันที่ — ใช้เตือนลูกค้าก่อนคืนรถ */
    public static function pickedUpReturnBetween(string $a, string $b): array
    {
        return Database::all(
            "SELECT * FROM bookings WHERE status = 'picked_up'
               AND DATE(return_at) BETWEEN :a AND :b AND return_at > NOW()",
            [':a' => $a, ':b' => $b]
        );
    }

    // ------------------------------------------------------------
    // ตัวนับ / ยอดรวม
    // ------------------------------------------------------------
    public static function countByStatus(string $status): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM bookings WHERE status = :s', [':s' => $status]);
    }

    /** มูลค่างานที่รับรถในช่วงวันที่ (ไม่รวมที่ยกเลิก) */
    public static function bookedValue(string $from, string $to): float
    {
        return (float) Database::value(
            "SELECT COALESCE(SUM(grand_total + extra_charge),0) FROM bookings
             WHERE status NOT IN ('cancelled') AND DATE(pickup_at) BETWEEN :a AND :b",
            [':a' => $from, ':b' => $to]
        );
    }

    // ------------------------------------------------------------
    // ปฏิทินรถ
    // ------------------------------------------------------------
    public static function forCalendar(string $first, string $last): array
    {
        return Database::all(
            "SELECT b.*, c.plate FROM bookings b JOIN cars c ON c.id = b.car_id
             WHERE b.status IN ('pending','confirmed','picked_up','returned')
               AND DATE(b.pickup_at) <= :e AND DATE(b.return_at) >= :s
             ORDER BY FIELD(b.status,'returned','pending','confirmed','picked_up')",
            [':s' => $first, ':e' => $last]
        );
    }

    // ------------------------------------------------------------
    // ผูกบัญชี LINE
    // ------------------------------------------------------------
    public static function setLineUser(int $id, ?string $uid): void
    {
        Database::exec('UPDATE bookings SET line_user_id = :u WHERE id = :i', [':u' => $uid, ':i' => $id]);
    }

    public static function lineUserOf(int $id): ?string
    {
        $uid = Database::value('SELECT line_user_id FROM bookings WHERE id = :i', [':i' => $id]);
        return $uid ? (string) $uid : null;
    }

    public static function unlinkLine(string $uid): void
    {
        Database::exec('UPDATE bookings SET line_user_id = NULL WHERE line_user_id = :u', [':u' => $uid]);
    }

    public static function isLineLinked(string $uid): bool
    {
        return $uid !== '' && (int) Database::value('SELECT COUNT(*) FROM bookings WHERE line_user_id = :u', [':u' => $uid]) > 0;
    }

    public static function linkedLineAccounts(): int
    {
        return (int) Database::value("SELECT COUNT(DISTINCT line_user_id) FROM bookings WHERE line_user_id IS NOT NULL AND line_user_id <> ''");
    }

    /** การจองที่ LINE นี้ยืนยันไว้เอง (เฉพาะของตัวเอง) */
    public static function forLineUser(string $uid, int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        return Database::all(
            "SELECT b.*, c.brand, c.model, c.plate, c.color, c.cover_image
             FROM bookings b JOIN cars c ON c.id = b.car_id
             WHERE b.line_user_id = :u
             ORDER BY (b.status IN ('pending','confirmed','picked_up')) DESC, b.pickup_at DESC
             LIMIT $limit",
            [':u' => $uid]
        );
    }

    public static function findOfLineUser(int $id, string $uid): ?array
    {
        return Database::one('SELECT * FROM bookings WHERE id = :i AND line_user_id = :u', [':i' => $id, ':u' => $uid]);
    }

    // ------------------------------------------------------------
    // ลิงก์เซ็นสัญญา
    // ------------------------------------------------------------
    public static function signToken(int $id): string
    {
        $row = Database::one('SELECT sign_token FROM bookings WHERE id = :i', [':i' => $id]);
        if (!empty($row['sign_token'])) {
            return (string) $row['sign_token'];
        }
        $t = bin2hex(random_bytes(16));
        Database::exec('UPDATE bookings SET sign_token = :t WHERE id = :i AND (sign_token IS NULL OR sign_token = "")', [':t' => $t, ':i' => $id]);
        return (string) Database::value('SELECT sign_token FROM bookings WHERE id = :i', [':i' => $id]);
    }

    // ------------------------------------------------------------
    // ข้อมูลเสริมที่คำนวณจากแถวการจอง
    // ------------------------------------------------------------
    /**
     * รายการค่าใช้จ่ายเพิ่มตอนคืนรถของการจอง แยกบรรทัด — ใช้แสดงในใบจอง สัญญา ใบเสร็จ และหน้าลูกค้า
     * การจองที่คืนรถก่อนเวอร์ชัน 5 ไม่มีรายการแยก จะได้บรรทัดเดียวจากยอดรวมกับหมายเหตุเดิม
     *
     * @param bool $chargedOnly true = เฉพาะรายการที่เก็บเงินจริง (ตัดรายการที่ยกเว้นออก)
     * @return array<int,array{key:string,label:string,detail:string,amount:float,suggested:float,waived:bool}>
     */
    public static function extraLines(array $b, bool $chargedOnly = false): array
    {
        $out = [];
        foreach (json_decode((string) ($b['extra_breakdown'] ?? ''), true) ?: [] as $l) {
            if (!is_array($l) || !isset($l['label'])) {
                continue;
            }
            $waived = !empty($l['waived']);
            if ($chargedOnly && ($waived || (float) ($l['amount'] ?? 0) <= 0)) {
                continue;
            }
            $out[] = ['key' => (string) ($l['key'] ?? 'other'), 'label' => (string) $l['label'], 'detail' => (string) ($l['detail'] ?? ''),
                      'amount' => (float) ($l['amount'] ?? 0), 'suggested' => (float) ($l['suggested'] ?? 0), 'waived' => $waived];
        }
        if (!$out && (float) ($b['extra_charge'] ?? 0) > 0) {
            $out[] = ['key' => 'other', 'label' => 'ค่าใช้จ่ายเพิ่มเติม', 'detail' => (string) ($b['extra_note'] ?? ''),
                      'amount' => (float) $b['extra_charge'], 'suggested' => (float) $b['extra_charge'], 'waived' => false];
        }
        return $out;
    }

    /** จุดรับ/คืนรถเป็นสนามบินหรือไม่ (ดูจากคำนำหน้าที่ระบบใส่ให้ตอนจอง) */
    public static function placeType(?string $place): string
    {
        return $place !== null && str_starts_with(trim($place), '[สนามบิน]') ? 'airport' : 'shop';
    }

    /** ยอดที่ต้องเก็บทั้งหมด (ค่าเช่า + ค่าใช้จ่ายเพิ่มตอนคืนรถ) */
    public static function amountDue(array $b): float
    {
        return (float) $b['grand_total'] + (float) $b['extra_charge'];
    }

    /** ยอดคงเหลือที่ยังไม่ได้ชำระ (ติดลบได้ถ้าจ่ายเกิน) */
    public static function balance(array $b): float
    {
        return self::amountDue($b) - (float) $b['paid_amount'];
    }
}
