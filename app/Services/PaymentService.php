<?php
namespace App\Services;

use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;

/**
 * รับแจ้งโอนจากลูกค้า / พนักงานบันทึกรับ–คืนเงิน / อนุมัติ–ปฏิเสธสลิป
 *
 * กฎสำคัญ (ทุกเส้นทางที่เปลี่ยนยอดเงินของการจองต้องทำตามนี้):
 *   1) ทำใน transaction และ "ล็อกแถวการจองก่อนอ่านอย่างอื่น" (Booking::lockForUpdate)
 *      ไม่งั้นสองคนที่อนุมัติสลิปคนละใบของการจองเดียวกันพร้อมกัน จะคำนวณยอดจากภาพข้อมูลที่ไม่เห็นกัน
 *      แล้วสถานะการชำระเงินผิด
 *   2) เปลี่ยนสถานะสลิปด้วย UPDATE ... WHERE status = 'pending' คำสั่งเดียว (อนุมัติซ้ำไม่ได้)
 *   3) ยอดที่ชำระแล้วไม่เก็บซ้ำในตาราง bookings — คำนวณจาก payments เสมอ (Payment::netPaid)
 */
final class PaymentService
{
    public const MAX_AMOUNT = 2000000.0;
    public const MAX_PENDING_PER_BOOKING = 5;

    /** รายการที่นับเป็นค่าเช่า (ต่างจากเงินประกัน/คืนเงิน) — เป็นตัวกำหนดว่าจะยืนยันการจองอัตโนมัติหรือไม่ */
    private const RENTAL_KINDS = ['deposit', 'balance', 'extra'];

    /** แปลงข้อความจำนวนเงิน "1,234.50" → float ; null ถ้าไม่ถูกต้อง (ไม่ใช่ตัวเลข, <= 0, เกินกำหนด, ทศนิยมเกิน 2 ตำแหน่ง) */
    public static function parseAmount(string $text, bool $allowZero = false): ?float
    {
        $clean = str_replace([',', ' '], '', trim($text));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $clean)) {
            return null;
        }
        $value = (float) $clean;
        return ($value > 0 || ($allowZero && $value == 0.0)) && $value <= self::MAX_AMOUNT ? round($value, 2) : null;
    }

    /** สถานะชำระเงินจากยอดสุทธิ — ฟังก์ชันล้วน */
    public static function statusFor(float $netPaid, float $due, int $refundCount): string
    {
        // คืนค่าเช่าครบแล้ว (เช่น ยกเลิกแล้วคืนมัดจำ) ยอดสุทธิ 0 ต้องเป็น "คืนเงินแล้ว" ไม่ใช่ "ยังไม่ชำระ"
        if ($netPaid < -0.001 || ($refundCount > 0 && $netPaid <= 0.001)) {
            return 'refunded';
        }
        if ($netPaid <= 0.001) {
            return 'unpaid';
        }
        return $netPaid >= $due - 0.01 ? 'paid' : 'deposit_paid';
    }

    /** คิดสถานะชำระเงินของการจองใหม่ — เรียกใน transaction ที่ล็อกการจองแล้ว */
    public static function recalc(int $bookingId): string
    {
        $booking = Booking::find($bookingId);
        if ($booking === null) {
            throw new \RuntimeException('ไม่พบการจอง');
        }
        $due    = (float) $booking['grand_total'] + (float) $booking['extra_charge'];
        $status = self::statusFor(Payment::netPaid($bookingId), $due, Payment::approvedRefundCount($bookingId));
        Booking::setPaymentStatus($bookingId, $status);
        return $status;
    }

    /** หลังเงินเปลี่ยน: คิดสถานะใหม่ ถ้าจองยังรอยืนยันและมัดจำครบจากรายการค่าเช่า → ยืนยันอัตโนมัติ */
    private static function afterMoneyChange(int $bookingId, string $kind): void
    {
        self::recalc($bookingId);
        if (!in_array($kind, self::RENTAL_KINDS, true)) {
            return;
        }
        $booking = Booking::find($bookingId);
        $paid = Payment::netPaid($bookingId);
        if ($booking['status'] === 'pending' && $paid > 0 && $paid >= (float) $booking['deposit_amount'] - 0.01) {
            Booking::confirmIfPending($bookingId);
        }
    }

    // ------------------------------------------------------------
    // ลูกค้าแจ้งโอน
    // ------------------------------------------------------------

    /**
     * รับแจ้งโอนจากลูกค้า → รายการ "รอตรวจสอบ"
     * @throws UserError
     */
    public static function submitSlip(array $booking, array $file, float $amount, ?string $paidAt, ?string $note, bool $viaHttp = true): int
    {
        if (in_array($booking['status'], ['cancelled', 'completed'], true)) {
            throw new UserError('การจองนี้ปิดแล้ว ไม่สามารถแจ้งโอนได้');
        }
        if ($amount <= 0 || $amount > self::MAX_AMOUNT) {
            throw new UserError('กรุณาระบุจำนวนเงินที่โอนให้ถูกต้อง');
        }
        if (Payment::pendingCountForBooking((int) $booking['id']) >= self::MAX_PENDING_PER_BOOKING) {
            throw new UserError('มีรายการแจ้งโอนที่รอตรวจสอบหลายรายการแล้ว กรุณารอร้านตรวจสอบก่อน');
        }

        $path = UploadService::saveImage($file, 'slips', $viaHttp);
        try {
            $id = Payment::insert([
                'booking_id' => (int) $booking['id'],
                'kind'       => Payment::netPaid((int) $booking['id']) > 0 ? 'balance' : 'deposit',
                'method'     => 'transfer',
                'amount'     => $amount,
                'paid_at'    => normalize_datetime($paidAt) ?? date('Y-m-d H:i:s'),
                'slip_file'  => $path,
                'status'     => 'pending',
                'note'       => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            ]);
        } catch (\Throwable $e) {
            UploadService::delete($path);   // บันทึกไม่สำเร็จ ไม่ทิ้งไฟล์กำพร้า
            throw $e;
        }
        ActivityLog::log('slip_uploaded', 'bookings', (int) $booking['id'], (string) $amount);
        return $id;
    }

    // ------------------------------------------------------------
    // พนักงานอนุมัติ / ปฏิเสธ
    // ------------------------------------------------------------

    /** @return bool true = อนุมัติรายการนี้ ; false = ไม่พบ/ถูกตรวจไปแล้ว */
    public static function approve(int $paymentId, int $userId, string $ip = ''): bool
    {
        $payment = Payment::find($paymentId);
        if ($payment === null || $payment['status'] !== 'pending') {
            return false;
        }
        $done = Database::retryOnDeadlock(function () use ($payment, $paymentId, $userId): bool {
            Database::begin();
            try {
                Booking::lockForUpdate((int) $payment['booking_id']);
                if (!Payment::approve($paymentId, $userId)) {
                    Database::rollBack();
                    return false;   // มีคนอนุมัติ/ปฏิเสธไปก่อนเสี้ยววินาที
                }
                self::afterMoneyChange((int) $payment['booking_id'], $payment['kind']);
                Database::commit();
                return true;
            } catch (\Throwable $e) {
                Database::rollBack();
                throw $e;
            }
        });
        if ($done) {
            ActivityLog::log('payment_approved', 'payments', $paymentId, (string) $payment['amount'], $userId, $ip);
        }
        return $done;
    }

    public static function reject(int $paymentId, int $userId, string $reason, string $ip = ''): bool
    {
        $payment = Payment::find($paymentId);
        if ($payment === null || $payment['status'] !== 'pending') {
            return false;
        }
        $reason = trim($reason) !== '' ? mb_substr(trim($reason), 0, 250) : 'ข้อมูลไม่ถูกต้อง';
        $done = Database::retryOnDeadlock(function () use ($payment, $paymentId, $userId, $reason): bool {
            Database::begin();
            try {
                Booking::lockForUpdate((int) $payment['booking_id']);
                if (!Payment::reject($paymentId, $userId, $reason)) {
                    Database::rollBack();
                    return false;
                }
                self::recalc((int) $payment['booking_id']);
                Database::commit();
                return true;
            } catch (\Throwable $e) {
                Database::rollBack();
                throw $e;
            }
        });
        if ($done) {
            ActivityLog::log('payment_rejected', 'payments', $paymentId, $reason, $userId, $ip);
        }
        return $done;
    }

    // ------------------------------------------------------------
    // พนักงานบันทึกรับ / คืนเงินเอง (เงินสด หน้าร้าน ฯลฯ) — อนุมัติทันที
    // ------------------------------------------------------------

    /**
     * @throws UserError
     */
    public static function record(int $bookingId, int $userId, string $kind, string $method, float $amount, ?string $note, string $ip = ''): int
    {
        if (!array_key_exists($kind, Payment::KINDS) || !array_key_exists($method, Payment::METHODS)) {
            throw new UserError('ประเภทรายการไม่ถูกต้อง');
        }
        if ($amount <= 0 || $amount > self::MAX_AMOUNT) {
            throw new UserError('จำนวนเงินไม่ถูกต้อง');
        }
        if ($method === 'deduct' && !in_array($kind, ['balance', 'extra'], true)) {
            throw new UserError('"หักจากเงินประกัน" ใช้ได้กับค่าเช่าส่วนที่เหลือหรือค่าใช้จ่ายเพิ่มเท่านั้น');
        }

        $id = Database::retryOnDeadlock(function () use ($bookingId, $userId, $kind, $method, $amount, $note): int {
            Database::begin();
            try {
                $booking = Booking::lockForUpdate($bookingId);
                if ($booking === null) {
                    throw new UserError('ไม่พบการจอง');
                }
                self::checkRecordAllowed($booking, $kind, $method, $amount);

                $id = Payment::insert([
                    'booking_id'  => $bookingId,
                    'kind'        => $kind,
                    'method'      => $method,
                    'amount'      => $amount,
                    'paid_at'     => date('Y-m-d H:i:s'),
                    'status'      => 'approved',
                    'approved_by' => $userId,
                    'approved_at' => date('Y-m-d H:i:s'),
                    'note'        => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
                ]);
                self::afterMoneyChange($bookingId, $kind);
                Database::commit();
                return $id;
            } catch (\Throwable $e) {
                Database::rollBack();
                throw $e;
            }
        });
        ActivityLog::log('payment_recorded', 'payments', $id, $kind . ' ' . $amount, $userId, $ip);
        return $id;
    }

    /** เงื่อนไขที่ต้องตรวจหลังล็อกการจองแล้ว (ยอดถูกต้องล่าสุด) */
    private static function checkRecordAllowed(array $booking, string $kind, string $method, float $amount): void
    {
        $id = (int) $booking['id'];
        if ($booking['status'] === 'cancelled' && in_array($kind, ['deposit', 'balance', 'extra'], true)) {
            throw new UserError('การจองนี้ถูกยกเลิกแล้ว รับเงินค่าเช่าเพิ่มไม่ได้ (คืนเงินได้)');
        }
        if ($kind === 'refund' && $amount > Payment::netPaid($id) + 0.001) {
            throw new UserError('คืนค่าเช่าเกินยอดที่รับไว้ (รับไว้สุทธิ ฿' . number_format(Payment::netPaid($id), 2) . ')');
        }
        $needsSecurity = $kind === 'security_refund' || $method === 'deduct';
        if ($needsSecurity && $amount > Payment::securityHeld($id) + 0.001) {
            throw new UserError('เกินเงินประกันที่ร้านถืออยู่ (฿' . number_format(Payment::securityHeld($id), 2) . ')');
        }
    }
}
