<?php
namespace App\Models;

use App\Core\Database;

/** เอกสารซื้อขาย (ใบเสนอราคา/ใบแจ้งหนี้/ใบเสร็จ/สัญญา) + รายการสินค้าในเอกสาร */
final class Document
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM documents WHERE id = :id', ['id' => $id]);
    }

    /** อ่านแบบล็อกแถว — ใช้ก่อนแก้/เปลี่ยนสถานะ ให้สองคนแก้เอกสารเดียวกันไม่ทับกัน */
    public static function lockForUpdate(int $id): ?array
    {
        return Database::one('SELECT * FROM documents WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    public static function items(int $id): array
    {
        return Database::all('SELECT * FROM document_items WHERE document_id = :id ORDER BY line_no', ['id' => $id]);
    }

    public static function insert(array $row): int
    {
        $cols = array_keys($row);
        return Database::insert(
            'INSERT INTO documents (' . implode(',', $cols) . ') VALUES (' . implode(',', array_map(static fn($c) => ':' . $c, $cols)) . ')',
            $row
        );
    }

    public static function updateFields(int $id, array $row): void
    {
        $sets = [];
        foreach (array_keys($row) as $c) {
            $sets[] = "$c = :$c";
        }
        Database::run('UPDATE documents SET ' . implode(', ', $sets) . ' WHERE id = :__id', $row + ['__id' => $id]);
    }

    public static function replaceItems(int $id, array $items): void
    {
        Database::run('DELETE FROM document_items WHERE document_id = :id', ['id' => $id]);
        foreach (array_values($items) as $i => $it) {
            Database::run(
                'INSERT INTO document_items (document_id, line_no, description, qty, unit, price, amount) VALUES (:d, :n, :ds, :q, :u, :p, :a)',
                ['d' => $id, 'n' => $i + 1, 'ds' => $it['description'], 'q' => $it['qty'], 'u' => $it['unit'] !== '' ? $it['unit'] : null, 'p' => $it['price'], 'a' => $it['amount']]
            );
        }
    }

    public static function list(string $type = '', int $limit = 300): array
    {
        $where = $type !== '' ? 'WHERE d.doc_type = :t' : '';
        $params = $type !== '' ? ['t' => $type] : [];
        return Database::all(
            "SELECT d.*, b.booking_no FROM documents d LEFT JOIN bookings b ON b.id = d.booking_id $where
              ORDER BY d.id DESC LIMIT " . (int) $limit,
            $params
        );
    }

    public static function forBooking(int $bookingId): array
    {
        return Database::all('SELECT * FROM documents WHERE booking_id = :b ORDER BY id DESC', ['b' => $bookingId]);
    }
}
