<?php

namespace App\Services;

use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Inspection;

/**
 * รับคืนรถ: บันทึกเลขไมล์ น้ำมัน เวลาคืนจริง และค่าใช้จ่ายเพิ่ม (คืนช้า / น้ำมันขาด / อื่นๆ)
 */
final class ReturnService
{
    private const MAX_EXTRA_AMOUNT = 1000000;

    /** @return array{0:string,1:string} [โทน, ข้อความ] */
    public static function run(array $booking, array $car, Request $request): array
    {
        $id        = (int) $booking['id'];
        $mileage   = max(0, $request->int('mileage_in'));
        $fuel      = max(0, min(8, $request->int('fuel_in', 8)));

        self::checkMileage($booking, $mileage);
        $returnedAt = self::returnedAt($booking, $request);

        $lines = self::chargeLines($booking, $car, $returnedAt, $mileage, $fuel, $request);
        $extra = round(array_sum(array_column($lines, 'amount')), 2);

        Booking::updateById($id, [
            'status'          => 'returned',
            'mileage_in'      => $mileage ?: null,
            'fuel_in'         => $fuel,
            'returned_at'     => $returnedAt,
            'extra_charge'    => $extra,
            'extra_note'      => self::summaryText($lines),
            'extra_breakdown' => $lines ? json_encode($lines, JSON_UNESCAPED_UNICODE) : null,
            'damage_note'     => $request->str('damage_note') ?: null,
        ]);
        if ($mileage > 0) {
            Car::setMileage((int) $booking['car_id'], $mileage);
        }

        // ค่าใช้จ่ายเพิ่มทำให้ยอดที่ต้องชำระเปลี่ยน จึงต้องคิดสถานะการชำระเงินใหม่
        PaymentService::recalc($id);
        self::lockInspection($id, $mileage, $fuel);
        ActivityLog::log('booking_return', 'bookings', $id, ['km' => $mileage, 'extra' => $extra, 'lines' => $lines]);
        self::notifyCustomer($id, $car, $lines);

        $message = 'บันทึกการรับคืนรถแล้ว' . ($extra > 0 ? ' — ค่าใช้จ่ายเพิ่ม ' . money0($extra) . ' บาท' : '');
        return ['ok', $message];
    }

    private static function checkMileage(array $booking, int $mileageIn): void
    {
        $mileageOut = (int) $booking['mileage_out'];

        if ($mileageIn > 0 && $mileageOut > 0 && $mileageIn < $mileageOut) {
            throw new \RuntimeException('เลขไมล์ตอนรับคืน (' . number_format($mileageIn) . ') น้อยกว่าตอนส่งมอบ ('
                . number_format($mileageOut) . ') กรุณาตรวจตัวเลขอีกครั้ง');
        }
    }

    /**
     * เวลารับคืนจริง
     * - ค่าเริ่มต้นคือ "ตอนนี้" (พนักงานไม่ได้แก้ช่องเวลา)
     * - พนักงานแก้ย้อนหลังได้ เช่น รถคืน 18:00 แต่มาบันทึก 21:00 ไม่งั้นลูกค้าจะโดนค่าปรับคืนช้าทั้งที่คืนตรงเวลา
     */
    private static function returnedAt(array $booking, Request $request): string
    {
        $now = date('Y-m-d H:i:s');

        $staffEditedTime = $request->bool('rt_manual');
        $text = $staffEditedTime ? $request->str('returned_at') : '';
        if ($text === '') {
            return $now;
        }

        $time = strtotime($text);
        if ($time === false) {
            throw new \RuntimeException('รูปแบบเวลารับคืนรถไม่ถูกต้อง');
        }
        if ($time > time() + 300) {
            throw new \RuntimeException('เวลารับคืนรถอยู่ในอนาคต กรุณาตรวจอีกครั้ง');
        }
        if (!empty($booking['picked_up_at']) && $time < strtotime($booking['picked_up_at'])) {
            throw new \RuntimeException('เวลารับคืนรถอยู่ก่อนเวลาส่งมอบรถ (' . thai_date($booking['picked_up_at'], true) . ')');
        }

        return date('Y-m-d H:i:s', $time);
    }

    /**
     * รายการค่าใช้จ่ายเพิ่ม: ระบบคิดให้ตามเงื่อนไข พนักงานแก้จำนวนหรือยกเว้นได้ในฟอร์ม
     * เก็บทั้ง "ยอดแนะนำ" และ "ยอดที่คิดจริง" เพื่อตรวจย้อนหลังได้ว่ารายการไหนถูกยกเว้น
     */
    private static function chargeLines(array $booking, array $car, string $returnedAt, int $mileage, int $fuel, Request $request): array
    {
        $suggested = LateReturnService::calculate($booking, $car, $returnedAt, $mileage ?: null, $fuel);
        $posted    = $request->arr('chg');

        $lines = [];
        foreach ($suggested['lines'] as $line) {
            $lines[] = self::lineFromForm($line, $posted[$line['key']] ?? null);
        }

        $other = self::otherLine($request);
        if ($other) {
            $lines[] = $other;
        }
        return $lines;
    }

    /** @param mixed $formValue ค่าที่พนักงานส่งมาของรายการนี้ (null = ฟอร์มไม่มีรายการนี้) */
    private static function lineFromForm(array $line, $formValue): array
    {
        if (is_array($formValue)) {
            $checked = !empty($formValue['on']);
            $amount  = $checked ? self::amountFromForm($formValue['amount'] ?? '', $line['amount']) : 0.0;
        } else {
            // รายการที่เพิ่งเกิดระหว่างที่เปิดหน้าค้างไว้ ให้คิดตามเงื่อนไข
            $amount = $line['amount'];
        }

        return [
            'key'       => $line['key'],
            'label'     => $line['label'],
            'detail'    => $line['detail'],
            'suggested' => $line['amount'],
            'amount'    => $amount,
            'waived'    => $amount <= 0,
        ];
    }

    private static function amountFromForm($text, float $default): float
    {
        $amount = $text !== '' ? (float) $text : $default;
        return max(0, min(self::MAX_EXTRA_AMOUNT, round($amount, 2)));
    }

    /** ค่าใช้จ่ายอื่นที่พนักงานพิมพ์เอง (เช่น ค่าทำความสะอาด) */
    private static function otherLine(Request $request): ?array
    {
        $amount = max(0, min(self::MAX_EXTRA_AMOUNT, round($request->float('other_amount'), 2)));
        if ($amount <= 0) {
            return null;
        }

        $label = $request->str('other_label');
        return [
            'key'       => 'other',
            'label'     => $label !== '' ? mb_substr($label, 0, 120) : 'ค่าใช้จ่ายอื่น',
            'detail'    => '',
            'suggested' => $amount,
            'amount'    => $amount,
            'waived'    => false,
        ];
    }

    /** ข้อความสรุปสั้นๆ เก็บไว้ในคอลัมน์ extra_note เช่น "น้ำมันขาด 2 ขีด 600 บ." */
    private static function summaryText(array $lines): ?string
    {
        $parts = [];
        foreach ($lines as $line) {
            $price   = $line['waived'] ? '(ยกเว้น)' : money0($line['amount']) . ' บ.';
            $parts[] = $line['label'] . ' ' . $price;
        }

        $text = implode(' · ', $parts);
        return $text !== '' ? mb_substr($text, 0, 250) : null;
    }

    /**
     * ใบตรวจสภาพรถตอนรับคืนต้องมีเสมอและถูกล็อก (แม้ไม่ได้ถ่ายรูป)
     * ถ้าพนักงานไม่ได้กรอกเลขไมล์ในหน้าถ่ายรูป ให้ใช้ค่าจากฟอร์มรับคืนเติมให้ก่อนล็อก
     */
    private static function lockInspection(int $bookingId, int $mileage, int $fuel): void
    {
        Inspection::ensure($bookingId, 'in', AuthService::id());
        Inspection::fillMissing($bookingId, 'in', $mileage ?: null, $fuel);
        InspectionService::lock($bookingId, 'in');
    }

    private static function notifyCustomer(int $bookingId, array $car, array $lines): void
    {
        try {
            NotificationService::bookingReturned(Booking::find($bookingId), $car, $lines);
        } catch (\Throwable $e) {
            // แจ้งเตือนล้มเหลวไม่ควรทำให้การรับคืนพัง
        }
    }
}
