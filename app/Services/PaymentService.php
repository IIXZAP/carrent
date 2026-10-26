<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;

/**
 * คิดยอดชำระของการจองจากรายการเงินที่อนุมัติแล้ว + รับแจ้งโอนจากลูกค้า
 */
final class PaymentService
{
    /**
     * คำนวณยอดที่ชำระแล้วและสถานะการชำระเงินของการจองใหม่จากรายการเงินที่อนุมัติแล้ว
     *
     * เงินประกัน (security / security_refund) ไม่นับเป็นค่าเช่าที่ชำระ
     * และไม่แตะ bookings.security_deposit — ช่องนั้นคือ "เงินประกันตามเงื่อนไข" ที่ตกลงกับลูกค้า
     * ยอดที่ร้านถืออยู่จริงให้เรียก Payment::securityHeld()
     */
    public static function recalc(int $bookingId): void
    {
        $paid = Payment::netPaid($bookingId);
        $bk   = Booking::find($bookingId);
        if (!$bk) {
            return;
        }
        $due = (float) $bk['grand_total'] + (float) $bk['extra_charge'];

        // คืนเงินค่าเช่าครบแล้ว (เช่น ยกเลิกแล้วคืนมัดจำ) ยอดสุทธิเป็น 0 — ต้องเป็น "คืนเงินแล้ว" ไม่ใช่ "ยังไม่ชำระ"
        $refunds = Payment::approvedRefundCount($bookingId);

        if ($paid < 0 || ($refunds > 0 && $paid <= 0.001)) {
            $status = 'refunded';
        } elseif ($paid <= 0.001) {
            $status = 'unpaid';
        } elseif ($paid >= $due - 0.01) {
            $status = 'paid';
        } else {
            $status = 'deposit_paid';
        }

        Booking::updateById($bookingId, ['paid_amount' => round($paid, 2), 'payment_status' => $status]);
    }

    /**
     * รับแจ้งโอนเงินจากลูกค้า — สร้างรายการชำระเงินสถานะ "รอตรวจสอบ" แล้วแจ้งร้าน
     * ใช้ร่วมกันระหว่างหน้าตรวจสอบการจองและหน้า LIFF
     * @throws \RuntimeException เมื่อข้อมูลไม่ครบหรือการจองปิดไปแล้ว
     */
    public static function submitSlip(array $b, array $file, float $amount, ?string $paidAt = null, ?string $note = null): int
    {
        if (in_array($b['status'], ['cancelled', 'completed'], true)) {
            throw new \RuntimeException('การจองนี้ปิดแล้ว ไม่สามารถแจ้งโอนได้');
        }
        if ($amount <= 0) {
            throw new \RuntimeException('กรุณาระบุจำนวนเงินที่โอน');
        }
        if ($amount > 2000000) {
            throw new \RuntimeException('จำนวนเงินไม่ถูกต้อง');
        }
        $path = UploadService::save($file, 'slips', 8);
        if (!$path) {
            throw new \RuntimeException('กรุณาแนบไฟล์สลิป');
        }
        $pid = Payment::insert([
            'booking_id' => (int) $b['id'],
            'kind'       => (float) $b['paid_amount'] > 0 ? 'balance' : 'deposit',
            'method'     => 'transfer',
            'amount'     => $amount,
            'paid_at'    => $paidAt ? date('Y-m-d H:i:s', strtotime($paidAt)) : date('Y-m-d H:i:s'),
            'slip_file'  => $path,
            'status'     => 'pending',
            'note'       => $note ?: null,
        ]);
        ActivityLog::log('slip_uploaded', 'bookings', (int) $b['id'], $amount);
        try {
            NotificationService::slipUploaded($b, $amount);
        } catch (\Throwable $e) {
            // แจ้งเตือนล้มเหลวไม่ควรทำให้การแจ้งโอนหาย
        }
        return $pid;
    }

    /**
     * อนุมัติสลิปแล้วทำต่อ: คิดยอดใหม่ → ถ้าจองยังรอยืนยันและมัดจำครบให้ยืนยันอัตโนมัติ → แจ้งลูกค้า
     * @return bool true ถ้าอนุมัติรายการนี้ (false = ไม่พบ/ตรวจไปแล้ว)
     */
    public static function approve(int $paymentId, int $userId): bool
    {
        $p = Payment::find($paymentId);
        if (!$p || $p['status'] !== 'pending') {
            return false;
        }
        if (!Payment::approve($paymentId, $userId)) {
            return false;   // มีคนอนุมัติ/ปฏิเสธไปก่อนหน้านี้เสี้ยววินาที
        }
        self::recalc((int) $p['booking_id']);

        // จองที่ยังรอยืนยัน + จ่ายมัดจำแล้ว → ยืนยันอัตโนมัติ
        $bk = Booking::find((int) $p['booking_id']);
        if ($bk && $bk['status'] === 'pending' && (float) $bk['paid_amount'] >= (float) $bk['deposit_amount']) {
            Booking::updateById((int) $bk['id'], ['status' => 'confirmed']);
            $car = \App\Models\Car::find((int) $bk['car_id']);
            $bk  = Booking::find((int) $bk['id']);
            try {
                NotificationService::bookingConfirmed($bk, $car);
            } catch (\Throwable $e) {
            }
        } elseif ($bk) {
            // ไม่ได้เปลี่ยนสถานะการจอง → แจ้งลูกค้าทาง LINE ว่าได้รับเงินแล้ว
            // (ถ้าเพิ่งยืนยันการจองไปข้างบน ลูกค้าได้ข้อความยืนยันแล้ว ไม่ส่งซ้ำสองข้อความ)
            try {
                NotificationService::slipResult($bk, (float) $p['amount'], true);
            } catch (\Throwable $e) {
            }
        }
        ActivityLog::log('payment_approved', 'payments', $paymentId, $p['amount']);
        return true;
    }

    public static function reject(int $paymentId, int $userId, string $reason): bool
    {
        $p = Payment::find($paymentId);
        if (!$p || $p['status'] !== 'pending') {
            return false;
        }
        $reason = $reason !== '' ? $reason : 'ข้อมูลไม่ถูกต้อง';
        if (!Payment::reject($paymentId, $userId, $reason)) {
            return false;
        }
        self::recalc((int) $p['booking_id']);
        $bk = Booking::find((int) $p['booking_id']);
        if ($bk) {
            try {
                NotificationService::slipResult($bk, (float) $p['amount'], false, $reason);
            } catch (\Throwable $e) {
            }
        }
        ActivityLog::log('payment_rejected', 'payments', $paymentId);
        return true;
    }
}
