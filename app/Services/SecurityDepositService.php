<?php
namespace App\Services;

use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;

/**
 * ปิดเงินประกัน: หักชำระยอดค้าง (ถ้ามี) แล้วคืนส่วนที่เหลือให้ลูกค้า
 *
 * บันทึกเป็นรายการการเงิน 2 แบบ
 *   deduct (kind balance/extra)  หักจากเงินประกันไปชำระยอดค้าง — นับเป็นรายได้
 *   security_refund              คืนเงินประกันให้ลูกค้า — ไม่นับเป็นรายได้
 * ทำใน transaction เดียวที่ล็อกการจอง: กดปิดสองครั้ง/สองคนพร้อมกัน จะคืนเงินซ้ำไม่ได้ (รอบที่สองเห็นว่าไม่เหลือเงินประกัน)
 */
final class SecurityDepositService
{
    private const REFUND_METHODS = ['cash', 'transfer', 'promptpay'];

    /**
     * @param array $in deduct(หักจากยอดค้างเดิม) add_amount+add_label(ค่าใช้จ่ายที่พบหลังรับคืน) refund_method refund_note
     * @return array{held:float,deduct:float,refund:float,method:string,added:float}
     * @throws UserError
     */
    public static function settle(int $bookingId, array $in, int $userId, string $ip = ''): array
    {
        $result = Database::retryOnDeadlock(static function () use ($bookingId, $in, $userId): array {
            Database::begin();
            try {
                $result = self::settleLocked($bookingId, $in, $userId);
                Database::commit();
                return $result;
            } catch (\Throwable $e) {
                Database::rollBack();
                throw $e;
            }
        });
        ActivityLog::log('security_settled', 'bookings', $bookingId, json_encode($result, JSON_UNESCAPED_UNICODE), $userId, $ip);
        return $result;
    }

    private static function settleLocked(int $bookingId, array $in, int $userId): array
    {
        $booking = Booking::lockForUpdate($bookingId);
        if ($booking === null) {
            throw new UserError('ไม่พบการจอง');
        }
        if (!in_array($booking['status'], ['returned', 'completed', 'cancelled'], true)) {
            throw new UserError('ปิดเงินประกันได้หลังรับคืนรถ (หรือเมื่อยกเลิกการจอง) เท่านั้น');
        }
        $held = Payment::securityHeld($bookingId);
        if ($held <= 0) {
            throw new UserError('ไม่มีเงินประกันที่ร้านถืออยู่สำหรับการจองนี้ (อาจคืนไปแล้ว)');
        }

        // ยอดค้างชำระ "ก่อน" บวกรายการใหม่ — ตัวเลขเดียวกับที่หน้าจอแสดงให้พนักงานเลือกหัก
        $owed = self::amountOwed($booking, Payment::netPaid($bookingId));

        $added = max(0.0, min($held, (float) (PaymentService::parseAmount((string) ($in['add_amount'] ?? ''), true) ?? 0)));
        $addedLabel = mb_substr(trim((string) ($in['add_label'] ?? '')), 0, 120);
        if ($added > 0) {
            if ($addedLabel === '') {
                throw new UserError('กรุณาระบุว่าค่าใช้จ่ายที่หักเพิ่มคือค่าอะไร');
            }
            Booking::addExtraCharge($bookingId, $added, [
                'key' => 'after', 'label' => $addedLabel, 'detail' => 'บันทึกตอนปิดเงินประกัน ' . date('d/m/Y H:i'),
                'suggested' => $added, 'amount' => $added, 'waived' => false,
            ]);
        }

        // ที่หัก = ส่วนที่พนักงานเลือกหักจากยอดค้างเดิม (ไม่เกินยอดค้าง) + ค่าใช้จ่ายที่เพิ่งเพิ่ม ; ไม่เกินเงินประกันที่ถือ
        $chosen = max(0.0, min($owed, (float) (PaymentService::parseAmount((string) ($in['deduct'] ?? ''), true) ?? 0)));
        $deduct = round(max(0.0, min($held, $added + $chosen)), 2);
        $refund = round($held - $deduct, 2);
        $method = in_array($in['refund_method'] ?? '', self::REFUND_METHODS, true) ? $in['refund_method'] : 'cash';

        $now = date('Y-m-d H:i:s');
        $base = ['booking_id' => $bookingId, 'paid_at' => $now, 'status' => 'approved', 'approved_by' => $userId, 'approved_at' => $now];
        if ($deduct > 0) {
            $hasExtra = (float) Database::value('SELECT extra_charge FROM bookings WHERE id = :id', ['id' => $bookingId]) > 0;
            Payment::insert($base + [
                'kind' => $hasExtra ? 'extra' : 'balance', 'method' => 'deduct', 'amount' => $deduct,
                'note' => 'หักจากเงินประกัน' . ($added > 0 ? ' — ' . $addedLabel : ''),
            ]);
        }
        if ($refund > 0) {
            $note = trim((string) ($in['refund_note'] ?? ''));
            Payment::insert($base + ['kind' => 'security_refund', 'method' => $method, 'amount' => $refund, 'note' => $note !== '' ? mb_substr($note, 0, 200) : 'คืนเงินประกัน']);
        }
        PaymentService::recalc($bookingId);

        return ['held' => $held, 'deduct' => $deduct, 'refund' => $refund, 'method' => $method, 'added' => $added];
    }

    /**
     * ยอดที่ลูกค้ายังค้างชำระ
     * การจองที่ยกเลิก: ค่าเช่าที่ยังไม่จ่ายไม่ใช่หนี้ (ไม่ได้เช่าจริง) นับเฉพาะค่าใช้จ่ายเพิ่มที่เกิดขึ้น
     */
    public static function amountOwed(array $booking, float $netPaid): float
    {
        $extra = (float) $booking['extra_charge'];
        if ($booking['status'] === 'cancelled') {
            return max(0.0, round($extra - max(0.0, $netPaid), 2));
        }
        return max(0.0, round((float) $booking['grand_total'] + $extra - $netPaid, 2));
    }
}
