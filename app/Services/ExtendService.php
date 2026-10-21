<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Notification;

/**
 * เช่าต่อ / เลื่อนวันคืนรถ
 */
final class ExtendService
{
    /** @return array{0:string,1:string} [โทน, ข้อความ] */
    public static function run(array $booking, array $car, Request $request): array
    {
        $id    = (int) $booking['id'];
        $quote = self::quote($booking, $car, $request->str('new_return'));
        if (!$quote['ok']) {
            throw new \RuntimeException($quote['error']);
        }

        $charge = self::chargeToCollect($quote, $request);
        self::saveExtension($booking, $car, $quote, $charge);

        PaymentService::recalc($id);
        // เตือนวันคืนรถที่เคยส่งไปเป็นของกำหนดเดิม ปลดออกเพื่อให้ระบบเตือนกำหนดใหม่ได้อีกครั้ง
        Notification::releaseReminder('cust_return_reminder', $id);
        ActivityLog::log('booking_extended', 'bookings', $id, [
            'from' => $booking['return_at'], 'to' => $quote['new_return'], 'charge' => $charge, 'computed' => $quote['charge'],
        ]);
        self::notifyCustomer($id, $car, $charge);

        return ['ok', 'เลื่อนกำหนดคืนเป็น ' . thai_date($quote['new_return'], true) . ' แล้ว — ค่าเช่าเพิ่ม ' . money0($charge) . ' บาท'];
    }

    /**
     * คิดเงินกรณีเช่าต่อ (เลื่อนวันคืนออกไป)
     *
     * คิดเฉพาะส่วนต่าง: (ค่าเช่า + บริการเสริมรายวัน ของจำนวนวันใหม่) − (ของจำนวนวันเดิม)
     * ค่าส่งรถถึงที่เป็นค่าต่อเที่ยว ไม่เปลี่ยนตามจำนวนวัน จึงไม่ถูกนำมาคิด
     * เช่ายาวขึ้นแล้วเข้าเรตสัปดาห์/เดือน ส่วนต่างจะถูกกว่ารายวันเอง
     *
     * @return array ถ้า ok = false ให้ดู 'error'
     */
    public static function quote(array $booking, array $car, string $newReturnText): array
    {
        $time = strtotime($newReturnText);
        if ($time === false) {
            return self::quoteError($booking, 'รูปแบบวัน–เวลาไม่ถูกต้อง');
        }

        $newReturn = date('Y-m-d H:i:s', $time);
        if ($time <= strtotime((string) $booking['return_at'])) {
            return self::quoteError($booking, 'วันคืนใหม่ต้องหลังกำหนดคืนเดิม (' . thai_date($booking['return_at'], true) . ')');
        }

        // ช่วงที่ขอเพิ่มต้องไม่ชนการจองถัดไปของรถคันนี้ (นับเวลาพักรถด้วย)
        $isFree = AvailabilityService::isCarAvailable((int) $booking['car_id'], (string) $booking['return_at'], $newReturn, (int) $booking['id']);
        if (!$isFree) {
            return self::quoteError($booking, self::busyMessage($booking));
        }

        // นอกเวลาทำการ: ไม่ห้าม (พนักงานอาจตกลงกับลูกค้าเป็นกรณีพิเศษ) แต่เตือนให้เห็นก่อนบันทึก
        $returnPlace = Booking::placeType($booking['return_place'] ?? ($booking['pickup_place'] ?? null));
        $warning     = AvailabilityService::validateServiceTime($newReturn, $returnPlace, 'คืนรถ');

        $addons  = json_decode((string) ($booking['addons'] ?? ''), true) ?: [];
        $daysOld = PricingService::rentalDays((string) $booking['pickup_at'], (string) $booking['return_at']);
        $daysNew = PricingService::rentalDays((string) $booking['pickup_at'], $newReturn);

        $rentalDelta = PricingService::calcRental($car, $daysNew)['total'] - PricingService::calcRental($car, $daysOld)['total'];
        $addonDelta  = PricingService::calcAddons($car, $addons, $daysNew)['total'] - PricingService::calcAddons($car, $addons, $daysOld)['total'];
        $rentalDelta = max(0, $rentalDelta);
        $addonDelta  = max(0, $addonDelta);

        $delta = round($rentalDelta + $addonDelta, 2);
        $vat   = round($delta * (float) $booking['vat_rate'] / 100, 2);

        return [
            'ok'           => true,
            'error'        => null,
            'days_old'     => $daysOld,
            'days_new'     => $daysNew,
            'rental_delta' => round($rentalDelta, 2),
            'addon_delta'  => round($addonDelta, 2),
            'delta'        => $delta,
            'vat'          => $vat,
            'charge'       => round($delta + $vat, 2),
            'new_total'    => round((float) $booking['grand_total'] + $delta + $vat, 2),
            'new_return'   => $newReturn,
            'warning'      => $warning ?: null,
        ];
    }

    private static function quoteError(array $booking, string $message): array
    {
        return [
            'ok'           => false,
            'error'        => $message,
            'days_old'     => (int) $booking['rental_days'],
            'days_new'     => 0,
            'rental_delta' => 0.0,
            'addon_delta'  => 0.0,
            'delta'        => 0.0,
            'vat'          => 0.0,
            'charge'       => 0.0,
            'new_total'    => (float) $booking['grand_total'],
        ];
    }

    private static function busyMessage(array $booking): string
    {
        $message = 'รถคันนี้ไม่ว่างถึงเวลาที่ขอ';

        $next = Booking::nextPickupAfter((int) $booking['car_id'], (int) $booking['id'], (string) $booking['return_at']);
        if ($next) {
            $message .= ' — มีการจองถัดไปรับรถ ' . thai_date($next['pickup_at'], true)
                      . ' และต้องเว้นเวลาพักรถ ' . AvailabilityService::gapHours() . ' ชม.';
        }
        return $message;
    }

    /**
     * ยอดที่เก็บจริง: พนักงานลดราคาได้ แต่ไม่เกินยอดที่ระบบคิด
     * (ถ้าจะคิดเพิ่มให้ใช้ค่าใช้จ่ายเพิ่มตอนคืนรถ)
     */
    private static function chargeToCollect(array $quote, Request $request): float
    {
        $text = $request->str('charge');
        if ($text === '') {
            return $quote['charge'];
        }
        return max(0, min($quote['charge'], round((float) $text, 2)));
    }

    private static function saveExtension(array $booking, array $car, array $quote, float $charge): void
    {
        $id = (int) $booking['id'];

        // ยอดที่เก็บรวม VAT แล้ว ต้องแยกเป็นยอดก่อนภาษี + VAT
        $vatRate = (float) $booking['vat_rate'];
        $net     = $vatRate > 0 ? round($charge / (1 + $vatRate / 100), 2) : $charge;
        $vat     = round($charge - $net, 2);

        Database::begin();
        try {
            // ล็อกแถวของรถแล้วตรวจซ้ำ กันลูกค้ารายอื่นจองช่วงเดียวกันเข้ามาพร้อมกันพอดี
            Car::lock((int) $booking['car_id']);
            $again = self::quote(Booking::find($id), $car, $quote['new_return']);
            if (!$again['ok']) {
                throw new \RuntimeException($again['error']);
            }

            Booking::updateById($id, [
                'return_at'      => $quote['new_return'],
                'rental_days'    => $quote['days_new'],
                'rental_amount'  => round((float) $booking['rental_amount'] + $quote['rental_delta'], 2),
                'addon_amount'   => round((float) $booking['addon_amount'] + $quote['addon_delta'], 2),
                'discount'       => round((float) $booking['discount'] + max(0, $quote['delta'] - $net), 2),
                'subtotal'       => round((float) $booking['subtotal'] + $net, 2),
                'vat_amount'     => round((float) $booking['vat_amount'] + $vat, 2),
                'grand_total'    => round((float) $booking['grand_total'] + $charge, 2),
                'rate_breakdown' => json_encode(self::breakdownWithExtension($booking, $quote), JSON_UNESCAPED_UNICODE),
            ]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** เพิ่มบรรทัด "เช่าต่อถึง ..." ลงในรายละเอียดราคาเดิม */
    private static function breakdownWithExtension(array $booking, array $quote): array
    {
        $breakdown = json_decode((string) $booking['rate_breakdown'], true) ?: ['rental' => [], 'addons' => []];
        $addedDays = $quote['days_new'] - $quote['days_old'];

        $breakdown['rental'][] = [
            'label'  => 'เช่าต่อถึง ' . thai_date($quote['new_return'], true),
            'qty'    => max(1, $addedDays),
            'unit'   => $addedDays > 0 ? round($quote['rental_delta'] / $addedDays, 2) : $quote['rental_delta'],
            'amount' => $quote['rental_delta'],
        ];

        if ($quote['addon_delta'] > 0) {
            $breakdown['addons'][] = [
                'key'    => 'ext',
                'label'  => 'บริการเสริมช่วงเช่าต่อ (' . $addedDays . ' วัน)',
                'qty'    => $addedDays,
                'unit'   => round($quote['addon_delta'] / max(1, $addedDays), 2),
                'amount' => $quote['addon_delta'],
            ];
        }
        return $breakdown;
    }

    private static function notifyCustomer(int $bookingId, array $car, float $charge): void
    {
        try {
            $queued = LineService::notifyCustomer('extended', Booking::find($bookingId), $car, ['จำนวน' => money0($charge)]);
            if ($queued) {
                NotificationService::flush();
            }
        } catch (\Throwable $e) {
            // แจ้งเตือนล้มเหลวไม่ควรทำให้การเช่าต่อพัง
        }
    }
}
