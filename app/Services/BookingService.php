<?php
namespace App\Services;

use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Setting;

/**
 * แกนกลางของการสร้างการจอง — ทั้งเว็บและหลังบ้านเรียกที่นี่ที่เดียว
 * (ห้ามมี INSERT bookings จากที่อื่น ไม่งั้นการกันจองซ้อนจะรั่ว)
 *
 * ลำดับใน transaction สำคัญมาก:
 *   1) ล็อกแถวรถเป็นคำสั่งแรก  — ใครจองรถคันเดียวกันพร้อมกันต้องรอคิว
 *      (ถ้าอ่านอะไรก่อนล็อก MySQL จะใช้ภาพข้อมูลเก่า แล้วตรวจรถว่างพลาด)
 *   2) ตรวจรถว่าง  3) คิดราคา  4) หาหรือสร้างลูกค้า
 *   5) ขอเลขที่จอง (ล็อกตัวนับ จึงทำท้ายสุดเพื่อให้ล็อกสั้นที่สุด)  6) INSERT
 */
final class BookingService
{
    /**
     * @param array $contact name, phone(normalize แล้ว), email, line
     * @param array $opt     addons(ค่าดิบ) km discount vat(?bool) pickup_place return_place note
     *                       source created_by deposit_override(?float) security_override(?float)
     * @return int id ของการจองที่สร้าง
     * @throws UserError รถไม่ว่าง / ข้อมูลใช้ไม่ได้ (ข้อความอ่านได้โดยผู้ใช้)
     */
    public static function createBooking(int $carId, string $pickup, string $return, array $contact, array $opt, bool $blockBlacklist = true): int
    {
        return Database::retryOnDeadlock(
            fn(): int => self::createOnce($carId, $pickup, $return, $contact, $opt, $blockBlacklist)
        );
    }

    private static function createOnce(int $carId, string $pickup, string $return, array $contact, array $opt, bool $blockBlacklist): int
    {
        Database::begin();
        try {
            $car = Car::lockForUpdate($carId);
            if ($car === null) {
                throw new UserError('ไม่พบรถคันนี้');
            }
            if (!AvailabilityService::isCarAvailable($carId, $pickup, $return)) {
                throw new UserError('รถคันนี้ไม่ว่างในช่วงเวลาที่เลือก (อาจเพิ่งถูกจองไป) กรุณาเลือกวันใหม่');
            }

            $km    = max(0.0, min(2000.0, (float) ($opt['km'] ?? 0)));
            $quote = QuoteService::forCar($car, $pickup, $return, $opt['addons'] ?? [], (float) ($opt['discount'] ?? 0), $km, $opt['vat'] ?? null);
            if ($quote['needs_km']) {
                throw new UserError('กรุณาระบุระยะทางส่ง–รับรถ (กม.) เมื่อเลือกบริการส่ง/รับรถถึงที่');
            }
            if ($quote['out_of_range']) {
                throw new UserError('ระยะทางส่ง–รับรถเกินตารางค่าส่งที่ตั้งไว้ ระบบคิดราคาให้ไม่ได้ กรุณาติดต่อเจ้าหน้าที่');
            }
            $quote = self::applyDepositOverrides($quote, $opt);

            $customerId = self::findOrCreateCustomer($contact, $blockBlacklist);

            $id = Booking::insert(self::row($carId, $customerId, $pickup, $return, $contact, $opt, $km, $quote));
            self::saveAddons($id, $quote);

            Database::commit();
            return $id;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** ลูกค้าตามเบอร์โทร ไม่มีก็สร้างใหม่ ; blacklist จองไม่ได้ */
    private static function findOrCreateCustomer(array $contact, bool $blockBlacklist): int
    {
        $customer = Customer::findByPhone($contact['phone'])
            ?? Customer::createOrFind($contact['name'], $contact['phone'], $contact['email'] ?: null, $contact['line'] ?: null)['customer'];
        if ($blockBlacklist && (int) $customer['is_blacklist'] === 1) {
            throw new UserError('ไม่สามารถจองได้ กรุณาติดต่อเจ้าหน้าที่');
        }
        return (int) $customer['id'];
    }

    /** พนักงานกรอกมัดจำ/เงินประกันเองได้ (มัดจำไม่เกินยอดรวม) — ไม่กรอก = ตามที่ระบบคิด */
    private static function applyDepositOverrides(array $quote, array $opt): array
    {
        if (($opt['deposit_override'] ?? null) !== null) {
            $quote['deposit_amount'] = round(max(0.0, min((float) $quote['grand_total'], (float) $opt['deposit_override'])), 2);
        }
        if (($opt['security_override'] ?? null) !== null) {
            $quote['security_deposit'] = round(max(0.0, (float) $opt['security_override']), 2);
        }
        $quote['balance_on_pickup'] = round(max(0.0, $quote['grand_total'] - $quote['deposit_amount']), 2);
        $quote['due_on_pickup']     = round($quote['balance_on_pickup'] + $quote['security_deposit'], 2);
        return $quote;
    }

    private static function row(int $carId, int $customerId, string $pickup, string $return, array $contact, array $opt, float $km, array $quote): array
    {
        $breakdown = [
            'rental'      => $quote['rental_lines'],
            'addons'      => $quote['addon_lines'],
            'delivery_km' => $km,
        ];
        return [
            'booking_no'       => Booking::nextNumber(),
            'customer_id'      => $customerId,
            'car_id'           => $carId,
            'contact_name'     => $contact['name'],
            'contact_phone'    => $contact['phone'],
            'contact_email'    => $contact['email'] ?: null,
            'contact_line'     => $contact['line'] ?: null,
            'pickup_at'        => $pickup,
            'return_at'        => $return,
            'pickup_place'     => ($opt['pickup_place'] ?? '') ?: null,
            'return_place'     => ($opt['return_place'] ?? '') ?: null,
            'delivery_km'      => $km > 0 ? $km : null,
            'rental_days'      => $quote['days'],
            'rate_breakdown'   => json_encode($breakdown, JSON_UNESCAPED_UNICODE),
            'rental_amount'    => $quote['rental_amount'],
            'addon_amount'     => $quote['addon_amount'],
            'discount'         => $quote['discount'],
            'subtotal'         => $quote['subtotal'],
            'vat_rate'         => $quote['vat_rate'],
            'vat_amount'       => $quote['vat_amount'],
            'grand_total'      => $quote['grand_total'],
            'deposit_amount'   => $quote['deposit_amount'],
            'security_deposit' => $quote['security_deposit'],
            'status'           => 'pending',
            'payment_status'   => 'unpaid',
            'source'           => $opt['source'] ?? 'web',
            'note'             => ($opt['note'] ?? '') ?: null,
            'created_by'       => $opt['created_by'] ?? null,
        ];
    }

    /** บันทึกบริการเสริมเป็นแถว (snapshot ชื่อ/ราคา) — ผลรวมต้องเท่ากับ bookings.addon_amount */
    private static function saveAddons(int $bookingId, array $quote): void
    {
        $catalog = Addon::activeCatalog();
        foreach ($quote['addon_lines'] as $line) {
            $addonId = $catalog[$line['key']]['id'] ?? null;
            Booking::insertAddon($bookingId, $addonId, $line['label'], (float) $line['qty'], (float) $line['unit'], (float) $line['amount']);
        }
    }

    /**
     * พนักงานปรับค่าส่งรถของการจองที่ยังไม่รับรถ (ระยะที่ลูกค้ากรอกอาจไม่ตรงจริง / ตกลงราคาพิเศษ)
     * ระบุ "ค่าส่งต่อเที่ยว" เอง หรือระบุแค่ระยะใหม่ให้ระบบคิดตามตารางค่าส่ง
     * คำนวณยอดรวม VAT มัดจำ และสถานะการชำระเงินใหม่ทั้งหมดใน transaction เดียวที่ล็อกการจอง
     * @throws UserError
     */
    public static function adjustDelivery(int $bookingId, ?float $km, ?float $feePerTrip, string $reason, int $userId, string $ip = ''): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new UserError('กรุณาระบุเหตุผลในการปรับค่าส่ง');
        }
        if ($km !== null && ($km < 0 || $km > 2000)) {
            throw new UserError('ระยะทางไม่ถูกต้อง');
        }
        if ($feePerTrip === null) {
            if ($km === null) {
                throw new UserError('กรุณาระบุค่าส่งหรือระยะทางอย่างใดอย่างหนึ่ง');
            }
            $rules = QuoteService::rules();
            $feePerTrip = DeliveryFeeService::feeForKm($km, (float) $rules['free_km'], $rules['delivery_tiers']);
            if ($feePerTrip === null) {
                throw new UserError('ระยะทางเกินตารางค่าส่งที่ตั้งไว้ กรุณาระบุค่าส่งเอง');
            }
        }
        if ($feePerTrip < 0 || $feePerTrip > 99999) {
            throw new UserError('ค่าส่งไม่ถูกต้อง');
        }
        $feePerTrip = round($feePerTrip, 2);

        $result = Database::retryOnDeadlock(function () use ($bookingId, $km, $feePerTrip, $reason): array {
            Database::begin();
            try {
                $result = self::applyDeliveryFee($bookingId, $km, $feePerTrip, $reason);
                Database::commit();
                return $result;
            } catch (\Throwable $e) {
                Database::rollBack();
                throw $e;
            }
        });
        ActivityLog::log('delivery_adjusted', 'bookings', $bookingId, json_encode($result + ['reason' => $reason], JSON_UNESCAPED_UNICODE), $userId, $ip);
    }

    /** ส่วนที่ทำใน transaction (ล็อกการจองแล้ว) */
    private static function applyDeliveryFee(int $bookingId, ?float $km, float $fee, string $reason): array
    {
        $booking = Booking::lockForUpdate($bookingId);
        if ($booking === null) {
            throw new UserError('ไม่พบการจอง');
        }
        if (!in_array($booking['status'], ['pending', 'confirmed'], true)) {
            throw new UserError('ปรับค่าส่งได้เฉพาะการจองที่ยังไม่รับรถ');
        }
        $lines = Booking::deliveryLines($bookingId);
        if ($lines === []) {
            throw new UserError('การจองนี้ไม่ได้เลือกบริการส่ง/รับรถถึงที่');
        }

        $labelKm = $km ?? (float) ($booking['delivery_km'] ?? 0);
        foreach ($lines as $line) {
            $amount = round((float) $line['qty'] * $fee, 2);
            $label = PricingService::addonLabel((string) $line['base_name'], false, 0, true, $labelKm, $amount);
            Booking::updateAddonLine((int) $line['id'], $label, $fee, $amount);
        }

        // ยอดรวมใหม่ (สูตรเดียวกับ PricingService::quote และ CHECK ของตาราง bookings)
        $addon    = round(Booking::addonTotal($bookingId), 2);
        $gross    = round((float) $booking['rental_amount'] + $addon, 2);
        $discount = min((float) $booking['discount'], $gross);
        $subtotal = round($gross - $discount, 2);
        $vat      = round($subtotal * (float) $booking['vat_rate'] / 100, 2);
        $grand    = round($subtotal + $vat, 2);
        $deposit  = round(min((float) $booking['deposit_amount'], $grand), 2);

        $breakdown = json_decode((string) $booking['rate_breakdown'], true) ?: [];
        $breakdown['addons'] = Booking::addonLinesForBreakdown($bookingId);
        $breakdown['delivery_km'] = $km ?? ($breakdown['delivery_km'] ?? 0);
        $breakdown['delivery_adjustments'][] = ['at' => date('Y-m-d H:i:s'), 'fee_per_trip' => $fee, 'reason' => $reason];

        Booking::updateTotals($bookingId, [
            'addon_amount' => $addon, 'discount' => $discount, 'subtotal' => $subtotal, 'vat_amount' => $vat,
            'grand_total' => $grand, 'deposit_amount' => $deposit,
            'rate_breakdown' => json_encode($breakdown, JSON_UNESCAPED_UNICODE),
            'delivery_km' => $km !== null ? ($km > 0 ? $km : null) : $booking['delivery_km'],
        ]);
        PaymentService::recalc($bookingId);

        return ['fee_per_trip' => $fee, 'km' => $km, 'grand_before' => (float) $booking['grand_total'], 'grand_after' => $grand];
    }

    /**
     * ยกเลิกการจองหน้าเว็บที่ไม่จ่ายมัดจำภายใน $hours ชั่วโมง (0 = ปิด) — เรียกจาก bin/cron.php
     * @return string[] เลขที่จองที่ถูกยกเลิก
     */
    public static function expireUnpaid(int $hours): array
    {
        if ($hours <= 0) {
            return [];
        }
        $rows = Booking::cancelExpiredUnpaid(
            date('Y-m-d H:i:s', time() - $hours * 3600),
            'ระบบยกเลิกอัตโนมัติ: ไม่ชำระมัดจำภายใน ' . $hours . ' ชั่วโมง'
        );
        $numbers = [];
        foreach ($rows as $row) {
            ActivityLog::log('booking_auto_cancelled', 'bookings', (int) $row['id'], $row['booking_no']);
            $numbers[] = $row['booking_no'];
        }
        return $numbers;
    }

    /**
     * เวลาที่การจองนี้จะถูกยกเลิกอัตโนมัติถ้ายังไม่จ่ายมัดจำ (ไว้แจ้งลูกค้า) — null = ไม่มีกำหนด
     */
    public static function expiryDeadline(array $booking): ?string
    {
        $hours = (int) Setting::get('pending_expire_hours', '0');
        $applies = $hours > 0 && $booking['status'] === 'pending' && $booking['source'] === 'web'
            && $booking['payment_status'] === 'unpaid'
            && Payment::pendingCountForBooking((int) $booking['id']) === 0;
        return $applies ? date('Y-m-d H:i:s', strtotime($booking['created_at']) + $hours * 3600) : null;
    }

    /** ข้อความแจ้งผู้ใช้: ของที่เราตั้งใจให้อ่านแสดงตามนั้น ที่เหลือลง log แล้วใช้ข้อความกลาง */
    public static function publicError(\Throwable $e, string $fallback = 'ทำรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'): string
    {
        if ($e instanceof UserError) {
            return $e->getMessage();
        }
        error_log('[carrent] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return $fallback;
    }
}
