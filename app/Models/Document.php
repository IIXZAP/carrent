<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** ใบเสนอราคา / ใบแจ้งหนี้ / ใบเสร็จ / สัญญา */
final class Document extends Model
{
    protected static string $table = 'documents';

    public const TYPES    = ['quotation', 'invoice', 'receipt', 'contract'];
    public const STATUSES = ['draft', 'sent', 'accepted', 'paid', 'void'];

    /** QT2609-0001 — ตัวย่อชนิดเอกสาร + ปีเดือน + เลขรันในเดือน (จากตัวนับ sequences) */
    public static function nextNumber(string $type): string
    {
        $map   = ['quotation' => 'QT', 'invoice' => 'IV', 'receipt' => 'RC', 'contract' => 'CT'];
        $month = date('ym');
        $seq   = Sequence::next(isset($map[$type]) ? $type : 'document', $month);
        return ($map[$type] ?? 'DC') . $month . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /** รายการเอกสาร กรองตามชนิด ('' = ทั้งหมด) */
    public static function listByType(string $type, int $limit = 200): array
    {
        $w   = in_array($type, self::TYPES, true) ? 'd.doc_type = :t' : '1=1';
        $prm = $w === '1=1' ? [] : [':t' => $type];
        $limit = max(1, min(1000, $limit));
        return Database::all(
            "SELECT d.*, b.booking_no, c.full_name
             FROM documents d
             LEFT JOIN bookings b ON b.id = d.booking_id
             LEFT JOIN customers c ON c.id = d.customer_id
             WHERE $w ORDER BY d.id DESC LIMIT $limit",
            $prm
        );
    }

    public static function forBooking(int $bookingId): array
    {
        return Database::all('SELECT * FROM documents WHERE booking_id = :b ORDER BY id DESC', [':b' => $bookingId]);
    }

    /** มูลค่าใบเสนอราคาที่รอลูกค้าตอบ */
    public static function pendingQuotationSum(): float
    {
        return (float) Database::value("SELECT COALESCE(SUM(grand_total),0) FROM documents WHERE doc_type='quotation' AND status IN ('sent','accepted')");
    }

    public static function setStatus(int $id, string $status): void
    {
        self::updateById($id, ['status' => $status]);
    }
}
