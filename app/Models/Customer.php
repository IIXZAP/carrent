<?php
namespace App\Models;

use App\Core\Database;

final class Customer
{
    /** หาจากเบอร์โทร (เบอร์ต้อง normalize แล้ว — ดู Phone::normalize) ; ถ้ามีหลายคน blacklist มาก่อน */
    public static function findByPhone(string $phone): ?array
    {
        return Database::one(
            'SELECT * FROM customers WHERE phone = :p AND deleted_at IS NULL ORDER BY is_blacklist DESC, id LIMIT 1',
            ['p' => $phone]
        );
    }

    /**
     * อ่านแบบล็อก — เห็นข้อมูลล่าสุดที่ commit แล้ว แม้ transaction นี้เริ่มอ่านไปก่อนหน้า
     * (SELECT ธรรมดาใน REPEATABLE READ จะเห็นภาพข้อมูลเก่า จึงมองไม่เห็นลูกค้าที่เพิ่งถูกสร้างโดยอีกคำขอ)
     *
     * ต้องเป็น shared lock (LOCK IN SHARE MODE) ห้ามใช้ FOR UPDATE:
     * foreign key ตอน INSERT bookings ขอ shared lock ที่แถวลูกค้า ถ้าเราถือ exclusive ไว้
     * จะวนรอกับคำขออื่นที่ถือตัวนับเลขที่จอง = deadlock (เจอจริงในเทสต์จองพร้อมกันตอนเครื่องช้า)
     */
    private static function findByPhoneLocked(string $phone): ?array
    {
        return Database::one(
            'SELECT * FROM customers WHERE phone = :p AND deleted_at IS NULL ORDER BY is_blacklist DESC, id LIMIT 1 LOCK IN SHARE MODE',
            ['p' => $phone]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM customers WHERE id = :id', ['id' => $id]);
    }

    /** รหัสลูกค้าถัดไป CUS-00001 (ข้ามเลขที่ถูกใช้ไปแล้ว เช่น นำเข้าข้อมูลเก่า) */
    public static function nextCode(): string
    {
        do {
            $code = 'CUS-' . str_pad((string) Sequence::next('customer'), 5, '0', STR_PAD_LEFT);
            $taken = Database::value('SELECT 1 FROM customers WHERE code = :c', ['c' => $code]);
        } while ($taken !== null);
        return $code;
    }

    /**
     * สร้างลูกค้าใหม่ ถ้ามีเบอร์นี้อยู่แล้ว (รวมถึงที่อีกคำขอเพิ่งสร้างพร้อมกัน) คืนแถวเดิมแทน
     * DB เป็นผู้รับประกัน: UNIQUE phone_active ทำให้ INSERT ที่สองชน duplicate key (1062)
     * แล้วเราอ่านแถวของคนที่มาก่อนด้วยการอ่านแบบล็อก
     * @return array{customer:array,created:bool}
     */
    public static function createOrFind(string $name, string $phone, ?string $email, ?string $line): array
    {
        try {
            $id = self::create($name, $phone, $email, $line);
            return ['customer' => self::find($id), 'created' => true];
        } catch (\PDOException $e) {
            if (!self::isDuplicateKey($e)) {
                throw $e;
            }
        }
        $existing = self::findByPhoneLocked($phone);
        if ($existing === null) {
            throw new \RuntimeException('สร้างลูกค้าไม่สำเร็จ (เบอร์ซ้ำแต่ไม่พบแถวเดิม)');
        }
        return ['customer' => $existing, 'created' => false];
    }

    public static function create(string $name, string $phone, ?string $email, ?string $line): int
    {
        return Database::insert(
            "INSERT INTO customers (code, type, full_name, phone, email, line_id)
             VALUES (:code, 'individual', :name, :phone, :email, :line)",
            ['code' => self::nextCode(), 'name' => $name, 'phone' => $phone, 'email' => $email, 'line' => $line]
        );
    }

    /** MySQL/MariaDB error 1062 = duplicate entry */
    private static function isDuplicateKey(\PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    // ------------------------------------------------------------
    // หน้าจัดการลูกค้า (หลังบ้าน)
    // ------------------------------------------------------------

    private const ROW_SQL = "SELECT c.*, COALESCE(j.jobs, 0) AS jobs, j.last_rent, COALESCE(p.spend, 0) AS spend, COALESCE(d.docs, 0) AS docs
          FROM customers c
          LEFT JOIN (SELECT customer_id, COUNT(*) AS jobs, MAX(pickup_at) AS last_rent FROM bookings WHERE status <> 'cancelled' GROUP BY customer_id) j ON j.customer_id = c.id
          LEFT JOIN (SELECT b.customer_id, SUM(v.paid_total) AS spend FROM bookings b JOIN v_booking_payment v ON v.booking_id = b.id
                      WHERE b.status <> 'cancelled' GROUP BY b.customer_id) p ON p.customer_id = c.id
          LEFT JOIN (SELECT customer_id, COUNT(*) AS docs FROM customer_documents GROUP BY customer_id) d ON d.customer_id = c.id";

    /** ค้นหาลูกค้า (ชื่อ/เบอร์/รหัส/เลขผู้เสียภาษี/อีเมล) พร้อมจำนวนงาน ยอดเงินที่เก็บได้จริง วันเช่าล่าสุด และจำนวนเอกสาร */
    public static function search(string $q, bool $onlyBlacklist, int $limit = 200): array
    {
        $where = ['c.deleted_at IS NULL'];
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $conds = ['c.full_name LIKE :q1', 'c.code LIKE :q2', 'c.tax_id LIKE :q3', 'c.email LIKE :q4', 'c.phone LIKE :q5'];
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
            $digits = preg_replace('/\D+/', '', $q);
            if (strlen($digits) >= 3) {
                $conds[] = 'c.phone LIKE :q6';
                $params['q6'] = '%' . $digits . '%';
            }
            $where[] = '(' . implode(' OR ', $conds) . ')';
        }
        if ($onlyBlacklist) {
            $where[] = 'c.is_blacklist = 1';
        }
        return Database::all(self::ROW_SQL . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY c.id DESC LIMIT ' . (int) $limit, $params);
    }

    /** @return array{total:int,repeat:int,blacklist:int} */
    public static function totals(): array
    {
        $row = Database::one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(c.is_blacklist = 1), 0) AS blacklist,
                    COALESCE(SUM((SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id AND b.status <> 'cancelled') >= 2), 0) AS repeat_n
               FROM customers c WHERE c.deleted_at IS NULL"
        );
        return ['total' => (int) $row['total'], 'repeat' => (int) $row['repeat_n'], 'blacklist' => (int) $row['blacklist']];
    }

    /** ประวัติการเช่าของลูกค้า (ทุกสถานะ) ใหม่สุดก่อน */
    public static function history(int $customerId, int $limit = 50): array
    {
        return Database::all(
            'SELECT b.id, b.booking_no, b.status, b.pickup_at, b.return_at, b.rental_days, b.grand_total, c.brand, c.model, c.plate
               FROM bookings b JOIN cars c ON c.id = b.car_id WHERE b.customer_id = :c ORDER BY b.id DESC LIMIT ' . (int) $limit,
            ['c' => $customerId]
        );
    }

    /** ลูกค้าที่ยังมีการจองไม่จบ (กันการลบ) */
    public static function openBookingCount(int $customerId): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM bookings WHERE customer_id = :c AND status IN ('pending','confirmed','picked_up')", ['c' => $customerId]);
    }

    private const FIELDS = ['type', 'full_name', 'tax_id', 'phone', 'email', 'line_id', 'address', 'license_no', 'license_expire', 'is_blacklist', 'blacklist_note', 'note'];

    public static function insertFull(array $data, string $code): int
    {
        $cols = self::FIELDS;
        return Database::insert(
            'INSERT INTO customers (code, ' . implode(', ', $cols) . ') VALUES (:code, :' . implode(', :', $cols) . ')',
            ['code' => $code] + self::pick($data)
        );
    }

    public static function updateById(int $id, array $data): void
    {
        $sets = array_map(static fn($c) => "$c = :$c", self::FIELDS);
        Database::run('UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = :__id AND deleted_at IS NULL', self::pick($data) + ['__id' => $id]);
    }

    private static function pick(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $c) {
            $out[$c] = $data[$c] ?? null;
        }
        return $out;
    }

    public static function softDelete(int $id): bool
    {
        return Database::run('UPDATE customers SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL', ['id' => $id])->rowCount() === 1;
    }

    public static function isDuplicatePhone(\PDOException $e): bool
    {
        return self::isDuplicateKey($e);
    }

    // ---- เอกสารแนบ ----

    public static function documents(int $customerId): array
    {
        return Database::all(
            'SELECT d.*, u.name AS uploader FROM customer_documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.customer_id = :c ORDER BY d.id DESC',
            ['c' => $customerId]
        );
    }

    public static function findDocument(int $id): ?array
    {
        return Database::one('SELECT * FROM customer_documents WHERE id = :id', ['id' => $id]);
    }

    public static function addDocument(array $row): int
    {
        return Database::insert(
            'INSERT INTO customer_documents (customer_id, doc_kind, file_path, mime, file_size, note, uploaded_by) VALUES (:customer_id, :doc_kind, :file_path, :mime, :file_size, :note, :uploaded_by)',
            $row
        );
    }

    public static function deleteDocument(int $id): bool
    {
        return Database::run('DELETE FROM customer_documents WHERE id = :id', ['id' => $id])->rowCount() === 1;
    }
}
