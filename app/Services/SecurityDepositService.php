<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;

/**
 * ปิดเงินประกัน: หักชำระยอดค้าง (ถ้ามี) แล้วคืนส่วนที่เหลือให้ลูกค้า
 *
 * บันทึกเป็นรายการการเงิน 2 แบบ
 *   deduct           หักจากเงินประกันไปชำระยอดค้าง (นับเป็นรายได้)
 *   security_refund  คืนเงินประกันให้ลูกค้า (ไม่นับเป็นรายได้)
 */
final class SecurityDepositService
{
    /** @return array{0:string,1:string} [โทน, ข้อความ] */
    public static function run(array $booking, array $car, Request $request): array
    {
        $id = (int) $booking['id'];

        Database::begin();
        try {
            Booking::lock($id);   // กันกดปิดเงินประกันสองครั้งพร้อมกัน
            $result = self::settle($id, $request);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        PaymentService::recalc($id);
        ActivityLog::log('security_settled', 'bookings', $id, $result);
        self::notifyCustomer($id, $car, $result);

        return ['ok', 'ปิดเงินประกันแล้ว — หักชำระ ' . money0($result['deduct']) . ' บาท · คืนลูกค้า ' . money0($result['refund']) . ' บาท'];
    }

    /** @return array{held:float,deduct:float,refund:float,method:string,added:float,added_label:string} */
    private static function settle(int $bookingId, Request $request): array
    {
        $held = Payment::securityHeld($bookingId);
        if ($held <= 0) {
            throw new \RuntimeException('ไม่มีเงินประกันที่ร้านถืออยู่สำหรับการจองนี้ (อาจคืนไปแล้ว)');
        }

        // ยอดค้างชำระ "ก่อน" บวกรายการใหม่ เป็นตัวเลขเดียวกับที่หน้าจอแสดงให้พนักงานเห็น
        $owed = self::amountOwed(Booking::find($bookingId));

        $addedAmount = max(0, min($held, round($request->float('add_amount'), 2)));
        $addedLabel  = $request->str('add_label', 120);
        if ($addedAmount > 0) {
            self::addExtraCharge($bookingId, $addedLabel, $addedAmount);
        }

        // ยอดที่หัก = ส่วนที่พนักงานเลือกหักจากยอดค้างเดิม (ไม่เกินยอดค้าง) + ค่าใช้จ่ายที่เพิ่งบันทึก (หักเต็มเสมอ)
        $chosen = max(0, min($owed, round($request->float('deduct'), 2)));
        $deduct = max(0, min($held, $addedAmount + $chosen));
        $refund = round($held - $deduct, 2);
        $method = self::refundMethod($request);

        self::recordPayments($bookingId, $deduct, $refund, $method, $addedAmount, $addedLabel, $request);

        return ['held' => $held, 'deduct' => $deduct, 'refund' => $refund, 'method' => $method,
                'added' => $addedAmount, 'added_label' => $addedLabel];
    }

    /**
     * ยอดที่ลูกค้ายังค้างชำระ
     * การจองที่ยกเลิก: ค่าเช่าที่ยังไม่จ่ายไม่ใช่หนี้ (ไม่ได้เช่าจริง) นับเฉพาะค่าใช้จ่ายที่บันทึกไว้
     */
    private static function amountOwed(array $booking): float
    {
        $paid  = max(0, (float) $booking['paid_amount']);
        $extra = (float) $booking['extra_charge'];

        if ($booking['status'] === 'cancelled') {
            return max(0, round($extra - $paid, 2));
        }
        return max(0, round((float) $booking['grand_total'] + $extra - (float) $booking['paid_amount'], 2));
    }

    /**
     * ค่าใช้จ่ายที่พบหลังรับคืนรถ (ใบสั่งจราจรที่ตามมา ค่าซ่อม ฯลฯ)
     * บันทึกเป็นค่าใช้จ่ายเพิ่มของการจองก่อน แล้วจึงหักจากเงินประกัน ที่มาของเงินที่หักจึงตรวจย้อนหลังได้
     */
    private static function addExtraCharge(int $bookingId, string $label, float $amount): void
    {
        if ($label === '') {
            throw new \RuntimeException('กรุณาระบุว่าค่าใช้จ่ายที่หักเพิ่มคือค่าอะไร');
        }

        $booking = Booking::find($bookingId);
        $lines   = Booking::extraLines($booking);
        $lines[] = [
            'key'       => 'after',
            'label'     => $label,
            'detail'    => 'บันทึกหลังรับคืนรถ ' . thai_date(date('Y-m-d H:i:s'), true),
            'suggested' => $amount,
            'amount'    => $amount,
            'waived'    => false,
        ];

        $note = trim((string) $booking['extra_note'] . ' · ' . $label . ' ' . money0($amount) . ' บ.', ' ·');
        Booking::updateById($bookingId, [
            'extra_charge'    => round((float) $booking['extra_charge'] + $amount, 2),
            'extra_breakdown' => json_encode($lines, JSON_UNESCAPED_UNICODE),
            'extra_note'      => mb_substr($note, 0, 250),
        ]);
    }

    private static function refundMethod(Request $request): string
    {
        $method = $request->str('refund_method');
        return in_array($method, ['cash', 'transfer', 'promptpay'], true) ? $method : 'cash';
    }

    private static function recordPayments(int $bookingId, float $deduct, float $refund, string $method, float $addedAmount, string $addedLabel, Request $request): void
    {
        $now  = date('Y-m-d H:i:s');
        $base = [
            'booking_id'  => $bookingId,
            'paid_at'     => $now,
            'status'      => 'approved',
            'approved_by' => AuthService::id(),
            'approved_at' => $now,
        ];

        if ($deduct > 0) {
            $hasExtra = (float) Booking::find($bookingId)['extra_charge'] > 0;
            Payment::insert($base + [
                'kind'   => $hasExtra ? 'extra' : 'balance',
                'method' => 'deduct',
                'amount' => $deduct,
                'note'   => 'หักจากเงินประกัน' . ($addedAmount > 0 ? ' — ' . $addedLabel : ''),
            ]);
        }

        if ($refund > 0) {
            Payment::insert($base + [
                'kind'   => 'security_refund',
                'method' => $method,
                'amount' => $refund,
                'note'   => $request->str('refund_note', 200) ?: 'คืนเงินประกัน',
            ]);
        }
    }

    private static function notifyCustomer(int $bookingId, array $car, array $result): void
    {
        try {
            $variables = ['คืนเงิน' => money0($result['refund']), 'หัก' => money0($result['deduct'])];
            if (LineService::notifyCustomer('deposit_refunded', Booking::find($bookingId), $car, $variables)) {
                NotificationService::flush();
            }
        } catch (\Throwable $e) {
            // แจ้งเตือนล้มเหลวไม่ควรทำให้การปิดเงินประกันพัง
        }
    }
}
