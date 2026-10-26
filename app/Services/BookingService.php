<?php
namespace App\Services;

use App\Core\Database;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;

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
        $customer = Customer::findByPhone($contact['phone']);
        if ($customer !== null) {
            if ($blockBlacklist && (int) $customer['is_blacklist'] === 1) {
                throw new UserError('ไม่สามารถจองได้ กรุณาติดต่อเจ้าหน้าที่');
            }
            return (int) $customer['id'];
        }
        return Customer::create($contact['name'], $contact['phone'], $contact['email'] ?: null, $contact['line'] ?: null);
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
