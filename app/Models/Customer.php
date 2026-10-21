<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** ลูกค้า + เอกสารแนบของลูกค้า */
final class Customer extends Model
{
    protected static string $table = 'customers';

    /**
     * หาลูกค้าเดิมจากเบอร์โทร — เทียบแบบตัวเลขล้วนทั้งสองฝั่ง
     * คัดในฐานข้อมูลก่อนด้วย 7 ตัวท้าย (ตัดขีด ช่องว่าง วงเล็บออก) แล้วเทียบเต็มเบอร์ใน PHP
     * เบอร์ในฐานข้อมูลมีได้หลายรูปแบบ เช่น "08-1234-56-78" หรือ "0812345678 (แม่)"
     * เรียงลูกค้าที่ติด Blacklist ขึ้นก่อน — ถ้ามีระเบียนซ้ำ ต้องเจอระเบียนที่ถูกห้ามจองเสมอ
     */
    public static function byPhone(string $phone): ?array
    {
        $d = phone_digits($phone);
        if (strlen($d) < 9) {
            return null;
        }
        foreach (Database::all(
            'SELECT * FROM customers WHERE ' . phone_sql('phone') . ' LIKE :t ORDER BY is_blacklist DESC, id',
            [':t' => '%' . substr($d, -7) . '%']
        ) as $c) {
            if (phone_match($c['phone'], $d)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * รหัสลูกค้าถัดไป CUS-00001 จากตัวนับ sequences
     * ถ้าเลขนั้นถูกใช้แล้ว (เช่น พิมพ์รหัสเองหรือนำเข้ามา) ข้ามไปเลขถัดไป
     */
    public static function nextCode(): string
    {
        do {
            $code = 'CUS-' . str_pad((string) Sequence::next('customer'), 5, '0', STR_PAD_LEFT);
            $taken = Database::value('SELECT 1 FROM customers WHERE code = :c', [':c' => $code]);
        } while ($taken);
        return $code;
    }

    /** สร้างลูกค้าใหม่พร้อมรหัสอัตโนมัติ */
    public static function create(array $data): int
    {
        $data['code'] = $data['code'] ?? self::nextCode();
        return self::insert($data);
    }

    /** ค้นหาลูกค้าพร้อมสถิติการเช่า */
    public static function search(string $q, bool $onlyBlacklist, int $limit = 200): array
    {
        $w = ['1=1'];
        $p = [];
        if ($q !== '') {
            $w[] = '(c.full_name LIKE :q OR c.phone LIKE :q2 OR c.code LIKE :q3 OR c.email LIKE :q4 OR c.tax_id LIKE :q5)';
            $p[':q'] = $p[':q2'] = $p[':q3'] = $p[':q4'] = $p[':q5'] = '%' . $q . '%';
        }
        if ($onlyBlacklist) {
            $w[] = 'c.is_blacklist = 1';
        }
        $where = implode(' AND ', $w);
        $limit = max(1, min(1000, $limit));

        return Database::all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id AND b.status <> 'cancelled') AS jobs,
                    (SELECT COALESCE(SUM(b.grand_total + b.extra_charge),0) FROM bookings b WHERE b.customer_id = c.id AND b.status <> 'cancelled') AS spend,
                    (SELECT MAX(b.pickup_at) FROM bookings b WHERE b.customer_id = c.id) AS last_rent,
                    (SELECT COUNT(*) FROM customer_documents d WHERE d.customer_id = c.id) AS docs
             FROM customers c WHERE $where
             ORDER BY c.is_blacklist DESC, last_rent DESC, c.id DESC LIMIT $limit",
            $p
        );
    }

    /** ตัวเลขสรุปบนหัวหน้าลูกค้า */
    public static function totals(): array
    {
        return [
            'total'     => self::count(),
            'blacklist' => (int) Database::value('SELECT COUNT(*) FROM customers WHERE is_blacklist = 1'),
            'repeat'    => (int) Database::value("SELECT COUNT(*) FROM (SELECT customer_id FROM bookings
                                                  WHERE status <> 'cancelled' AND customer_id IS NOT NULL
                                                  GROUP BY customer_id HAVING COUNT(*) > 1) t"),
        ];
    }

    public static function history(int $customerId, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        return Database::all(
            "SELECT b.*, ca.brand, ca.model, ca.plate FROM bookings b JOIN cars ca ON ca.id = b.car_id
             WHERE b.customer_id = :c ORDER BY b.pickup_at DESC LIMIT $limit",
            [':c' => $customerId]
        );
    }

    public static function spend(int $customerId): float
    {
        return (float) Database::value(
            "SELECT COALESCE(SUM(grand_total + extra_charge),0) FROM bookings WHERE customer_id = :c AND status <> 'cancelled'",
            [':c' => $customerId]
        );
    }

    /** เอกสารแนบในที่เก็บแบบปิด พร้อมชื่อผู้อัปโหลด */
    public static function documents(int $customerId): array
    {
        return Database::all(
            'SELECT d.*, u.name AS uploader FROM customer_documents d LEFT JOIN users u ON u.id = d.uploaded_by
             WHERE d.customer_id = :c ORDER BY d.id DESC',
            [':c' => $customerId]
        );
    }

    public static function addDocument(array $data): int
    {
        return Database::insert('customer_documents', $data);
    }

    public static function findDocument(int $id): ?array
    {
        return Database::one('SELECT * FROM customer_documents WHERE id = :i', [':i' => $id]);
    }

    /** ชื่อไฟล์เดิม (ก่อนเวอร์ชัน 5) ของลูกค้า — $column = id_card_file | license_file */
    public static function legacyFile(int $customerId, string $column): ?string
    {
        if (!in_array($column, ['id_card_file', 'license_file'], true)) {
            return null;
        }
        $v = Database::value("SELECT `$column` FROM customers WHERE id = :i", [':i' => $customerId]);
        return $v ? (string) $v : null;
    }

    /** จดไว้ว่าลูกค้ารายนี้ใช้ LINE — ไม่เขียนทับค่าที่มีอยู่แล้ว */
    public static function rememberLine(int $customerId, string $uid): void
    {
        Database::exec(
            "UPDATE customers SET line_user_id = :u WHERE id = :c AND (line_user_id IS NULL OR line_user_id = '')",
            [':u' => $uid, ':c' => $customerId]
        );
    }

    public static function unlinkLine(string $uid): void
    {
        Database::exec('UPDATE customers SET line_user_id = NULL WHERE line_user_id = :u', [':u' => $uid]);
    }

    // ---------- ย้ายเอกสารเดิมเข้าที่เก็บแบบปิด ----------
    public static function legacyDocCount(): int
    {
        return (int) Database::value("SELECT COALESCE(SUM((id_card_file IS NOT NULL AND id_card_file <> '') + (license_file IS NOT NULL AND license_file <> '')), 0) FROM customers");
    }

    public static function withLegacyFiles(): array
    {
        return Database::all(
            "SELECT id, id_card_file, license_file FROM customers
             WHERE (id_card_file IS NOT NULL AND id_card_file <> '') OR (license_file IS NOT NULL AND license_file <> '')"
        );
    }

    public static function clearLegacyFile(int $customerId, string $column): void
    {
        if (in_array($column, ['id_card_file', 'license_file'], true)) {
            Database::exec("UPDATE customers SET `$column` = NULL WHERE id = :i", [':i' => $customerId]);
        }
    }
}
