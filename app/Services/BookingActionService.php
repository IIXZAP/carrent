<?php

namespace App\Services;

use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Inspection;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Signature;

/**
 * ปุ่มบนหน้างานการจอง (หลังบ้าน)
 *
 * ขั้นตอนปกติ:  รอยืนยัน → ยืนยัน → ส่งมอบรถ → รับคืนรถ → ปิดงาน
 * ทางแยก:       ยกเลิก (ก่อนส่งมอบ) · เช่าต่อ · ปิดเงินประกัน · รับเงิน · เซ็นเอกสาร
 *
 * ทุกเมธอดคืนค่าเป็น [โทน, ข้อความ] เพื่อแสดงเป็น flash message
 * ถ้าทำไม่ได้จะโยน \RuntimeException พร้อมข้อความที่ผู้ใช้อ่านได้
 */
final class BookingActionService
{
    /**
     * สถานะที่อนุญาตให้ทำแต่ละการกระทำ
     * กันกดย้อนขั้นหรือข้ามขั้นจากหน้าที่เปิดค้างไว้ (เช่น เปิดสองแท็บ แท็บหนึ่งยกเลิกไปแล้ว)
     */
    private const ALLOWED_STATUSES = [
        'confirm'         => ['pending'],
        'pickup'          => ['pending', 'confirmed'],
        'return'          => ['picked_up'],
        'complete'        => ['returned'],
        // ยกเลิกได้เฉพาะก่อนส่งมอบรถ ถ้ายกเลิกตอนรถอยู่กับลูกค้า รถจะกลับมา "ว่าง" ในระบบทั้งที่ยังไม่ได้คืน
        'cancel'          => ['pending', 'confirmed'],
        'extend'          => ['pending', 'confirmed', 'picked_up'],
        'security_settle' => ['returned', 'completed', 'cancelled'],
    ];

    /** @return array{0:string,1:string} [โทน, ข้อความ] */
    public static function perform(string $action, array $booking, array $car, Request $request): array
    {
        self::checkStatusAllowsAction($action, $booking);

        switch ($action) {
            case 'confirm':         return self::confirm($booking, $car);
            case 'pickup':          return self::pickup($booking, $request);
            case 'return':          return ReturnService::run($booking, $car, $request);
            case 'extend':          return ExtendService::run($booking, $car, $request);
            case 'security_settle': return SecurityDepositService::run($booking, $car, $request);
            case 'complete':        return self::complete($booking);
            case 'cancel':          return self::cancel($booking, $request);
            case 'payment':         return self::addPayment($booking, $request);
            case 'note':            return self::saveNote($booking, $request);
            case 'sign_send':       return self::sendSignLink($booking, $car, $request);
            case 'sign_void':       return self::voidSignature($booking, $request);
        }

        return ['warn', 'ไม่รู้จักคำสั่งนี้'];
    }

    private static function checkStatusAllowsAction(string $action, array $booking): void
    {
        $allowed = self::ALLOWED_STATUSES[$action] ?? null;
        if ($allowed === null || in_array($booking['status'], $allowed, true)) {
            return;
        }

        [$statusName] = booking_status_label($booking['status']);
        throw new \RuntimeException('ทำรายการนี้ไม่ได้ เพราะการจองอยู่ในสถานะ "' . $statusName . '" แล้ว — หน้านี้อาจเปิดค้างไว้ กรุณาตรวจสถานะล่าสุด');
    }

    // ------------------------------------------------------------
    // ขั้นตอนหลัก
    // ------------------------------------------------------------

    private static function confirm(array $booking, array $car): array
    {
        $id = (int) $booking['id'];

        Booking::updateById($id, ['status' => 'confirmed']);
        ActivityLog::log('booking_confirmed', 'bookings', $id);

        try {
            NotificationService::bookingConfirmed(Booking::find($id), $car);
        } catch (\Throwable $e) {
            // แจ้งเตือนล้มเหลวไม่ควรทำให้การยืนยันพัง
        }
        return ['ok', 'ยืนยันการจองแล้ว'];
    }

    private static function pickup(array $booking, Request $request): array
    {
        $id      = (int) $booking['id'];
        $mileage = max(0, $request->int('mileage_out'));
        $fuel    = max(0, min(8, $request->int('fuel_out', 8)));

        Booking::updateById($id, [
            'status'       => 'picked_up',
            'mileage_out'  => $mileage ?: null,
            'fuel_out'     => $fuel,
            'picked_up_at' => date('Y-m-d H:i:s'),
        ]);
        if ($mileage > 0) {
            Car::setMileage((int) $booking['car_id'], $mileage);
        }

        // ส่งมอบรถแล้ว = สภาพรถ ณ วันส่งมอบต้องนิ่ง แก้ย้อนหลังไม่ได้
        // ไม่ได้ถ่ายรูปก็ต้องมีบันทึกรอบส่งมอบ (เลขไมล์ + น้ำมัน) และถูกล็อกเหมือนกัน
        Inspection::ensure($id, 'out', AuthService::id());
        Inspection::fillMissing($id, 'out', $mileage ?: null, $fuel);
        InspectionService::lock($id, 'out');

        ActivityLog::log('booking_pickup', 'bookings', $id, $mileage);
        return ['ok', 'บันทึกการส่งมอบรถแล้ว'];
    }

    private static function complete(array $booking): array
    {
        $id = (int) $booking['id'];

        Booking::updateById($id, ['status' => 'completed']);
        ActivityLog::log('booking_completed', 'bookings', $id);
        return ['ok', 'ปิดงานเรียบร้อย'];
    }

    private static function cancel(array $booking, Request $request): array
    {
        $id     = (int) $booking['id'];
        $reason = $request->str('cancel_reason');

        Booking::updateById($id, ['status' => 'cancelled', 'cancel_reason' => $reason ?: null]);
        ActivityLog::log('booking_cancelled', 'bookings', $id, $reason);
        return ['warn', 'ยกเลิกการจองแล้ว'];
    }

    // ------------------------------------------------------------
    // การเงิน / หมายเหตุ
    // ------------------------------------------------------------

    private static function addPayment(array $booking, Request $request): array
    {
        $id     = (int) $booking['id'];
        $amount = round($request->float('amount'), 2);

        if ($amount <= 0) {
            throw new \RuntimeException('กรุณาระบุจำนวนเงิน');
        }
        if ($amount > 5000000) {
            throw new \RuntimeException('จำนวนเงินสูงผิดปกติ กรุณาตรวจตัวเลขอีกครั้ง');
        }

        $now = date('Y-m-d H:i:s');
        $paymentId = Payment::insert([
            'booking_id'  => $id,
            'kind'        => self::allowedOrDefault($request->str('kind'), ['deposit', 'balance', 'security', 'extra', 'refund'], 'balance'),
            'method'      => self::allowedOrDefault($request->str('method'), ['transfer', 'cash', 'card', 'promptpay'], 'cash'),
            'amount'      => $amount,
            'paid_at'     => $now,
            'status'      => 'approved',
            'approved_by' => AuthService::id(),
            'approved_at' => $now,
            'note'        => $request->str('pnote') ?: null,
        ]);

        PaymentService::recalc($id);
        ActivityLog::log('payment_added', 'payments', $paymentId, $amount);
        return ['ok', 'บันทึกการชำระเงินแล้ว'];
    }

    private static function saveNote(array $booking, Request $request): array
    {
        Booking::updateById((int) $booking['id'], ['note' => $request->str('note') ?: null]);
        return ['ok', 'บันทึกหมายเหตุแล้ว'];
    }

    // ------------------------------------------------------------
    // ลายเซ็นลูกค้า
    // ------------------------------------------------------------

    /** ส่งลิงก์หน้าเซ็นเอกสารให้ลูกค้าทาง LINE (เฉพาะลูกค้าที่ผูกบัญชีไว้) */
    private static function sendSignLink(array $booking, array $car, Request $request): array
    {
        $id   = (int) $booking['id'];
        $kind = self::signatureKind($request);

        $blocked = SignatureService::blocker($booking, $kind);
        if ($blocked) {
            throw new \RuntimeException($blocked);
        }
        if (Signature::active($id, $kind)) {
            throw new \RuntimeException('เอกสารนี้ลูกค้าลงนามแล้ว');
        }
        if (!LineService::userForBooking($booking)) {
            throw new \RuntimeException('ลูกค้ายังไม่ได้ผูกบัญชี LINE กับการจองนี้ — ใช้ปุ่ม "คัดลอกลิงก์" แล้วส่งให้ลูกค้าทางช่องทางอื่นแทน');
        }

        $docName   = SignatureService::kinds()[$kind][0];
        $variables = ['ลิงก์เซ็น' => SignatureService::url($booking, $kind), 'เอกสาร' => $docName];
        if (!LineService::notifyCustomer('sign_request', $booking, $car, $variables)) {
            throw new \RuntimeException('การแจ้งเตือนลูกค้าทาง LINE ถูกปิดอยู่ — เปิดได้ที่เมนู LINE หรือใช้ปุ่ม "คัดลอกลิงก์" แทน');
        }

        NotificationService::flush();
        $sent = Notification::latestFor('cust_sign_request', $id);
        if (($sent['status'] ?? '') !== 'sent') {
            $reason = !empty($sent['error']) ? ' (' . $sent['error'] . ')' : '';
            throw new \RuntimeException('ส่ง LINE ไม่สำเร็จ' . $reason . ' — ใช้ปุ่ม "คัดลอกลิงก์" แทนได้');
        }

        ActivityLog::log('signature_link_sent', 'bookings', $id, $kind);
        return ['ok', 'ส่งลิงก์เซ็น' . $docName . 'ให้ลูกค้าทาง LINE แล้ว'];
    }

    /** ขอให้ลูกค้าเซ็นใหม่ — ฉบับเดิมไม่ถูกลบ เก็บเป็นประวัติพร้อมเหตุผล */
    private static function voidSignature(array $booking, Request $request): array
    {
        if (!AuthService::isAdmin()) {
            throw new \RuntimeException('การยกเลิกลายเซ็นทำได้เฉพาะเจ้าของร้าน');
        }

        $id        = (int) $booking['id'];
        $kind      = self::signatureKind($request);
        $reason    = $request->str('void_reason');
        $signature = Signature::active($id, $kind);

        if (!$signature) {
            throw new \RuntimeException('ไม่พบลายเซ็นที่ใช้อยู่ของเอกสารนี้');
        }
        if (mb_strlen($reason) < 3) {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่ขอให้ลูกค้าเซ็นใหม่');
        }

        Signature::void((int) $signature['id'], (int) AuthService::id(), $reason);
        ActivityLog::log('signature_voided', 'bookings', $id, [
            'kind' => $kind, 'signature_id' => (int) $signature['id'], 'reason' => $reason,
        ]);
        return ['warn', 'ยกเลิกลายเซ็นฉบับเดิมแล้ว (เก็บไว้เป็นประวัติ) — ส่งลิงก์ให้ลูกค้าเซ็นใหม่ได้'];
    }

    // ------------------------------------------------------------

    /** ชนิดเอกสารที่ขอเซ็น (contract / return) ไม่รู้จักให้ใช้ contract */
    private static function signatureKind(Request $request): string
    {
        $kind = $request->str('kind');
        return isset(SignatureService::kinds()[$kind]) ? $kind : 'contract';
    }

    private static function allowedOrDefault(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
