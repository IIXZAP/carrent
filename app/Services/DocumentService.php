<?php
namespace App\Services;

use App\Core\Database;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Sequence;
use App\Models\Setting;

/**
 * เอกสาร: คำนวณยอด · ออกเลขที่ · สร้าง/แก้/เปลี่ยนสถานะ · สร้างร่างจากการจอง
 *
 * ยอดรวม (ปัดเศษ 2 ตำแหน่งทุกขั้น เพื่อให้ตัวเลขบนกระดาษบวกกันได้พอดี):
 *   amount = qty × price (ต่อบรรทัด) ; subtotal = Σ amount ; net = subtotal − discount
 *   vat = net × vat_rate% ; wht = net × wht_rate% ; grand = net + vat − wht
 */
final class DocumentService
{
    public const TYPES = ['quotation' => 'ใบเสนอราคา', 'invoice' => 'ใบแจ้งหนี้', 'receipt' => 'ใบเสร็จรับเงิน', 'contract' => 'สัญญาเช่า'];
    public const PREFIX = ['quotation' => 'QT', 'invoice' => 'IV', 'receipt' => 'RC', 'contract' => 'CT'];
    public const STATUSES = ['draft' => 'ร่าง', 'sent' => 'ส่งแล้ว', 'accepted' => 'ลูกค้ายอมรับ', 'paid' => 'ชำระแล้ว', 'void' => 'ยกเลิก'];

    /** ไปต่อสถานะไหนได้บ้าง — paid/void เป็นปลายทาง (เอกสารทางบัญชีที่ออกแล้วไม่ย้อนกลับ) */
    private const NEXT = [
        'draft'    => ['sent', 'accepted', 'paid', 'void'],
        'sent'     => ['accepted', 'paid', 'void'],
        'accepted' => ['paid', 'void'],
        'paid'     => [],
        'void'     => [],
    ];

    private static function num($value, string $what): float
    {
        $s = trim((string) $value);
        if ($s === '') {
            return 0.0;
        }
        $s = str_replace(',', '', $s);
        if (!is_numeric($s)) {
            throw new UserError("$what ต้องเป็นตัวเลข");
        }
        return (float) $s;
    }

    /**
     * @param array $items [description, qty, unit?, price]
     * @return array{items:array,subtotal:float,net:float,vat:float,wht:float,grand:float}
     * @throws UserError
     */
    public static function totals(array $items, $discount, $vatRate, $whtRate): array
    {
        $clean = [];
        foreach ($items as $it) {
            $desc = trim((string) ($it['description'] ?? ''));
            if ($desc === '' && trim((string) ($it['price'] ?? '')) === '') {
                continue;   // แถวว่างจากฟอร์ม
            }
            if ($desc === '') {
                throw new UserError('ทุกรายการต้องมีคำอธิบาย');
            }
            $qty = self::num($it['qty'] ?? '1', 'จำนวน');
            $price = self::num($it['price'] ?? '0', 'ราคา');
            if ($qty <= 0) {
                throw new UserError('จำนวนต้องมากกว่า 0');
            }
            if ($price < 0) {
                throw new UserError('ราคาต่อหน่วยติดลบไม่ได้');
            }
            $clean[] = [
                'description' => mb_substr($desc, 0, 255),
                'qty'         => round($qty, 2),
                'unit'        => mb_substr(trim((string) ($it['unit'] ?? '')), 0, 20),
                'price'       => round($price, 2),
                'amount'      => round(round($qty, 2) * round($price, 2), 2),
            ];
        }
        if ($clean === []) {
            throw new UserError('ต้องมีอย่างน้อย 1 รายการ');
        }
        $discount = round(self::num($discount, 'ส่วนลด'), 2);
        $vatRate = round(self::num($vatRate, 'อัตรา VAT'), 2);
        $whtRate = round(self::num($whtRate, 'อัตราหัก ณ ที่จ่าย'), 2);
        if ($vatRate < 0 || $vatRate > 100 || $whtRate < 0 || $whtRate > 100) {
            throw new UserError('อัตราภาษีต้องอยู่ระหว่าง 0–100');
        }
        $subtotal = round(array_sum(array_column($clean, 'amount')), 2);
        if ($discount < 0) {
            throw new UserError('ส่วนลดติดลบไม่ได้');
        }
        if ($discount > $subtotal) {
            throw new UserError('ส่วนลดมากกว่ายอดรวมรายการ');
        }
        $net = round($subtotal - $discount, 2);
        $vat = round($net * $vatRate / 100, 2);
        $wht = round($net * $whtRate / 100, 2);
        return [
            'items' => $clean, 'subtotal' => $subtotal, 'discount' => $discount, 'net' => $net,
            'vat_rate' => $vatRate, 'wht_rate' => $whtRate, 'vat' => $vat, 'wht' => $wht,
            'grand' => round($net + $vat - $wht, 2),
        ];
    }

    private static function validDate(string $value, string $what): string
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            throw new UserError("$what ไม่ถูกต้อง");
        }
        return $value;
    }

    /** แปลงค่าจากฟอร์มเป็นแถวของตาราง documents (ไม่รวมเลขที่) */
    private static function row(array $in): array
    {
        $type = (string) ($in['doc_type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw new UserError('ชนิดเอกสารไม่ถูกต้อง');
        }
        $billName = mb_substr(trim((string) ($in['bill_name'] ?? '')), 0, 180);
        if ($billName === '') {
            throw new UserError('กรุณากรอกชื่อผู้รับเอกสาร');
        }
        $t = self::totals((array) ($in['items'] ?? []), $in['discount'] ?? 0, $in['vat_rate'] ?? 0, $in['wht_rate'] ?? 0);
        $due = trim((string) ($in['due_date'] ?? ''));
        $issue = self::validDate((string) ($in['issue_date'] ?? ''), 'วันที่ออกเอกสาร');
        $dueDate = $due !== '' ? self::validDate($due, 'วันครบกำหนด') : null;
        return [[
            'doc_type'     => $type,
            'issue_date'   => $issue,
            'due_date'     => $dueDate,
            'bill_name'    => $billName,
            'bill_tax_id'  => mb_substr(trim((string) ($in['bill_tax_id'] ?? '')), 0, 20) ?: null,
            'bill_address' => mb_substr(trim((string) ($in['bill_address'] ?? '')), 0, 1000) ?: null,
            'subtotal'     => $t['subtotal'],
            'discount'     => $t['discount'],
            'vat_rate'     => $t['vat_rate'],
            'vat_amount'   => $t['vat'],
            'wht_rate'     => $t['wht_rate'],
            'wht_amount'   => $t['wht'],
            'grand_total'  => $t['grand'],
            'note'         => mb_substr(trim((string) ($in['note'] ?? '')), 0, 2000) ?: null,
        ], $t['items']];
    }

    /** @throws UserError */
    public static function create(array $in, ?int $userId): int
    {
        [$row, $items] = self::row($in);
        $bookingId = (int) ($in['booking_id'] ?? 0);
        $customerId = null;
        if ($bookingId > 0) {
            $b = Booking::find($bookingId);
            if ($b === null) {
                throw new UserError('ไม่พบการจองที่อ้างถึง');
            }
            $customerId = (int) $b['customer_id'];
        }
        return (int) Database::transaction(static function () use ($row, $items, $userId, $bookingId, $customerId): int {
            // ขอเลขที่เป็นขั้นตอนสุดท้ายก่อนบันทึก (แถวตัวนับถูกล็อกจนจบ transaction)
            $period = date('ym', strtotime($row['issue_date']));
            $no = self::PREFIX[$row['doc_type']] . $period . '-' . str_pad((string) Sequence::next('doc_' . $row['doc_type'], $period), 4, '0', STR_PAD_LEFT);
            $id = Document::insert($row + [
                'doc_no' => $no, 'booking_id' => $bookingId > 0 ? $bookingId : null, 'customer_id' => $customerId, 'created_by' => $userId,
            ]);
            Document::replaceItems($id, $items);
            return $id;
        });
    }

    /** @throws UserError */
    public static function update(int $id, array $in): void
    {
        [$row, $items] = self::row($in);
        unset($row['doc_type']);   // เลขที่ออกตามประเภทแล้ว เปลี่ยนประเภทภายหลังไม่ได้
        Database::transaction(static function () use ($id, $row, $items): void {
            $doc = Document::lockForUpdate($id);
            if ($doc === null) {
                throw new UserError('ไม่พบเอกสาร');
            }
            if (in_array($doc['status'], ['paid', 'void'], true)) {
                throw new UserError('เอกสารที่ชำระแล้วหรือยกเลิกแล้วแก้ไขไม่ได้');
            }
            Document::updateFields($id, $row);
            Document::replaceItems($id, $items);
        });
    }

    /** @throws UserError */
    public static function setStatus(int $id, string $status): void
    {
        if (!isset(self::STATUSES[$status])) {
            throw new UserError('สถานะไม่ถูกต้อง');
        }
        Database::transaction(static function () use ($id, $status): void {
            $doc = Document::lockForUpdate($id);
            if ($doc === null) {
                throw new UserError('ไม่พบเอกสาร');
            }
            if (!in_array($status, self::NEXT[$doc['status']], true)) {
                throw new UserError('เปลี่ยนสถานะจาก "' . self::STATUSES[$doc['status']] . '" เป็น "' . self::STATUSES[$status] . '" ไม่ได้');
            }
            Document::updateFields($id, ['status' => $status]);
        });
    }

    public static function get(int $id): ?array
    {
        $doc = Document::find($id);
        if ($doc !== null) {
            $doc['items'] = Document::items($id);
        }
        return $doc;
    }

    /**
     * ร่างเอกสารจากการจอง — ยอดตรงกับการจอง (ค่าเช่า + บริการเสริม − ส่วนลด + VAT)
     * ใบแจ้งหนี้/ใบเสร็จเพิ่มค่าใช้จ่ายหลังรับคืน (คืนช้า/น้ำมัน/ฯลฯ) ซึ่งการจองไม่รวมใน grand_total
     * @throws UserError
     */
    public static function inputFromBooking(int $bookingId, string $type): array
    {
        $b = Booking::find($bookingId);
        if ($b === null) {
            throw new UserError('ไม่พบการจอง');
        }
        if (!isset(self::TYPES[$type])) {
            throw new UserError('ชนิดเอกสารไม่ถูกต้อง');
        }
        $customer = Customer::find((int) $b['customer_id']) ?: [];
        $breakdown = json_decode((string) $b['rate_breakdown'], true) ?: [];

        $items = [];
        foreach ((array) ($breakdown['rental'] ?? []) as $line) {
            $items[] = ['description' => 'ค่าเช่ารถ ' . $line['label'], 'qty' => $line['qty'], 'unit' => '', 'price' => $line['unit']];
        }
        // อ่านบริการเสริมจากตารางจริง (พนักงานอาจปรับค่าส่งหลังจอง ซึ่ง rate_breakdown เดิมอาจไม่ทันสมัย)
        foreach (Booking::addonLinesForBreakdown($bookingId) as $line) {
            $items[] = ['description' => $line['label'], 'qty' => $line['qty'], 'unit' => '', 'price' => $line['unit']];
        }
        if (in_array($type, ['invoice', 'receipt'], true)) {
            foreach ((array) json_decode((string) ($b['extra_breakdown'] ?? ''), true) as $line) {
                if ((float) ($line['amount'] ?? 0) > 0) {
                    $items[] = ['description' => (string) $line['label'], 'qty' => 1, 'unit' => '', 'price' => $line['amount']];
                }
            }
        }
        return [
            'doc_type'     => $type,
            'booking_id'   => $bookingId,
            'issue_date'   => date('Y-m-d'),
            'bill_name'    => $customer['full_name'] ?? $b['contact_name'],
            'bill_tax_id'  => $customer['tax_id'] ?? '',
            'bill_address' => $customer['address'] ?? '',
            'items'        => $items,
            'discount'     => $b['discount'],
            'vat_rate'     => $b['vat_rate'],
            'wht_rate'     => 0,
            'note'         => 'การจอง ' . $b['booking_no'],
        ];
    }

    public static function defaultVatRate(): float
    {
        return Setting::get('vat_enabled', '0') === '1' ? (float) Setting::get('vat_rate', '7') : 0.0;
    }
}
