<?php

namespace App\Services;

use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Notification;

/**
 * พนักงานสร้าง / แก้ไขการจองจากหลังบ้าน (รับงานทางโทรศัพท์ / LINE / หน้าร้าน)
 *
 * การจองที่ส่งมอบรถแล้วจะถูกล็อก: แก้ได้เฉพาะข้อมูลผู้เช่า จุดรับ–คืน และหมายเหตุ
 * (รถ วัน–เวลา และราคาผูกกับผลตรวจสภาพรถและการเงินไปแล้ว)
 */
final class AdminBookingService
{
    /**
     * บันทึกการจอง (สร้างใหม่ถ้า $existing = null)
     * @return array{id:int,message:string}
     */
    public static function save(?array $existing, Request $request): array
    {
        self::checkNotEditedElsewhere($existing, $request);

        $contact = self::contactColumns($existing, $request);
        $data    = $contact;

        if (!BookingService::isLocked($existing)) {
            $data += self::rentalColumns($existing, $request);
        }

        if ($existing) {
            return self::update($existing, $data);
        }
        return self::create($data, $request);
    }

    // ------------------------------------------------------------
    // ตรวจและเตรียมข้อมูล
    // ------------------------------------------------------------

    /**
     * หน้านี้อาจเปิดค้างไว้แล้วมีคนแก้การจองจากที่อื่น (เช่น กดเช่าต่อ รับเงิน)
     * ถ้าบันทึกทับ ข้อมูลใหม่จะหาย จึงเทียบเวลาแก้ไขล่าสุดก่อน
     */
    private static function checkNotEditedElsewhere(?array $existing, Request $request): void
    {
        if ($existing && (string) $request->post('rev', '') !== (string) $existing['updated_at']) {
            throw new \RuntimeException('การจองนี้ถูกแก้ไขจากหน้าอื่นหลังจากที่เปิดหน้านี้ กรุณาตรวจข้อมูลล่าสุดด้านล่างแล้วแก้ไขใหม่อีกครั้ง');
        }
    }

    /** ข้อมูลผู้เช่า จุดรับ–คืน หมายเหตุ (แก้ได้เสมอ) */
    private static function contactColumns(?array $existing, Request $request): array
    {
        $name  = $request->str('contact_name', 150);
        $phone = $request->str('contact_phone', 30);

        if ($name === '' || $phone === '') {
            throw new \RuntimeException('กรุณากรอกชื่อและเบอร์โทร');
        }
        if (strlen(phone_digits($phone)) < 9) {
            throw new \RuntimeException('เบอร์โทรต้องมีตัวเลขอย่างน้อย 9 หลัก (ใช้ยืนยันตัวตนตอนลูกค้าตรวจสอบการจอง)');
        }

        $columns = [
            'customer_id'   => self::findOrCreateCustomerId($name, $phone, $request),
            'contact_name'  => $name,
            'contact_phone' => $phone,
            'contact_email' => $request->str('contact_email', 150) ?: null,
            'contact_line'  => $request->str('contact_line', 80) ?: null,
            'pickup_place'  => $request->str('pickup_place', 250) ?: null,
            'return_place'  => $request->str('return_place', 250) ?: null,
            'note'          => $request->str('note', 1000) ?: null,
        ];

        // เปลี่ยนเบอร์ = อาจเป็นคนละคน จึงเลิกส่งแจ้งเตือน LINE ไปหาคนเดิม
        // (ลูกค้าผูกใหม่ได้จากหน้า "การจองของฉัน")
        if ($existing && !phone_match($existing['contact_phone'], $phone)) {
            $columns['line_user_id'] = null;
        }

        return $columns;
    }

    private static function findOrCreateCustomerId(string $name, string $phone, Request $request): int
    {
        $customer = Customer::byPhone($phone);
        if ($customer) {
            return (int) $customer['id'];
        }

        return Customer::create([
            'type'      => 'individual',
            'full_name' => $name,
            'phone'     => $phone,
            'email'     => $request->str('contact_email') ?: null,
            'line_id'   => $request->str('contact_line') ?: null,
        ]);
    }

    /** รถ วัน–เวลา บริการเสริม และราคา (แก้ได้เฉพาะก่อนส่งมอบรถ) */
    private static function rentalColumns(?array $existing, Request $request): array
    {
        $existingId = $existing ? (int) $existing['id'] : 0;

        $car = Car::find($request->int('car_id'));
        if (!$car) {
            throw new \RuntimeException('กรุณาเลือกรถ');
        }

        [$pickup, $return] = self::readPeriod($request);
        if (!AvailabilityService::isCarAvailable((int) $car['id'], $pickup, $return, $existingId ?: null)) {
            throw new \RuntimeException('รถคันนี้ไม่ว่างในช่วงเวลาที่เลือก');
        }

        $addons = BookingService::cleanAddons($request->arr('addons'));
        $km     = max(0, min(2000, $request->float('delivery_km')));
        if (PricingService::deliveryOutOfRange($addons, $km)) {
            throw new \RuntimeException('ระยะทาง ' . number_format($km) . ' กม. เกินตารางค่าส่งรถที่ตั้งไว้ ระบบจึงคิดค่าส่งให้ไม่ได้ — เพิ่มช่วงระยะทางที่ หลังบ้าน → ตั้งค่า หรือใส่ระยะทางไม่เกินช่วงสุดท้ายของตาราง');
        }

        $discount = max(0, round($request->float('discount'), 2));
        $quote    = PricingService::quote($car, $pickup, $return, $addons, $discount, $request->has('vat'), $km);

        $columns = [
            'car_id'       => (int) $car['id'],
            'pickup_at'    => $pickup,
            'return_at'    => $return,
            'delivery_km'  => $km > 0 ? $km : null,
            'addons'       => json_encode($addons, JSON_UNESCAPED_UNICODE),
        ];
        $columns += BookingService::priceColumns($quote);

        // มัดจำและเงินประกัน: พนักงานกรอกเองได้ เว้นว่าง = ใช้ตามที่ระบบคิดให้
        $columns['deposit_amount']   = self::depositAmount($request, $quote);
        $columns['security_deposit'] = self::securityDeposit($request, $quote, $existing === null);

        return $columns;
    }

    /** @return array{0:string,1:string} [วันรับ, วันคืน] ในรูปแบบฐานข้อมูล */
    private static function readPeriod(Request $request): array
    {
        $pickup = BookingService::toDb($request->str('pickup_at'));
        $return = BookingService::toDb($request->str('return_at'));

        if ($pickup === '' || $return === '') {
            throw new \RuntimeException('กรุณาเลือกวัน–เวลารับและคืนรถ');
        }
        if (strtotime($return) <= strtotime($pickup)) {
            throw new \RuntimeException('วันคืนรถต้องหลังวันรับรถ');
        }
        if (PricingService::rentalDays($pickup, $return) > 365) {
            throw new \RuntimeException('ระยะเวลาเช่าเกิน 1 ปี กรุณาตรวจวันที่อีกครั้ง');
        }

        return [$pickup, $return];
    }

    private static function depositAmount(Request $request, array $quote): float
    {
        $text = trim((string) $request->post('deposit_amount', ''));
        if ($text === '') {
            return (float) $quote['deposit_amount'];
        }
        // มัดจำต้องไม่เกินยอดรวม
        return max(0, min((float) $quote['grand_total'], (float) $text));
    }

    private static function securityDeposit(Request $request, array $quote, bool $isNew): float
    {
        $text = trim((string) $request->post('security_deposit', ''));
        if ($text === '') {
            return (float) $quote['security_deposit'];
        }

        $amount = max(0, (float) $text);
        // การจองใหม่ที่ใส่ 0 = ใช้ตามเงื่อนไขของรถคันนั้น (แก้การจองเดิมให้เป็น 0 ได้)
        if ($isNew && $amount <= 0) {
            return (float) $quote['security_deposit'];
        }
        return $amount;
    }

    // ------------------------------------------------------------
    // บันทึกลงฐานข้อมูล
    // ------------------------------------------------------------

    private static function create(array $data, Request $request): array
    {
        $data['booking_no']     = Booking::nextNumber();
        $data['status']         = self::pick($request->str('status'), ['pending', 'confirmed'], 'confirmed');
        $data['payment_status'] = 'unpaid';
        $data['source']         = self::pick($request->str('source'), ['admin', 'line', 'phone', 'web'], 'admin');
        $data['created_by']     = AuthService::id();

        $id = Booking::insert($data);
        ActivityLog::log('booking_created_admin', 'bookings', $id, $data['booking_no']);

        return ['id' => $id, 'message' => 'สร้างการจอง ' . $data['booking_no'] . ' แล้ว'];
    }

    private static function update(array $existing, array $data): array
    {
        $id     = (int) $existing['id'];
        $locked = BookingService::isLocked($existing);

        Booking::updateById($id, $data);

        // ยอดรวมอาจเปลี่ยน จึงต้องคิดสถานะการชำระเงินใหม่ (ไม่งั้นขึ้น "ชำระครบ" ทั้งที่ยังค้าง)
        PaymentService::recalc($id);

        if (!$locked) {
            self::resetRemindersIfDatesChanged($existing, $data);
        }

        ActivityLog::log('booking_updated', 'bookings', $id, $locked ? 'contact only' : null);
        return ['id' => $id, 'message' => 'บันทึกการแก้ไขแล้ว'];
    }

    /** วันรับ/คืนเปลี่ยน → ปลดเครื่องหมาย "เตือนแล้ว" ให้ระบบเตือนลูกค้าตามวันใหม่ */
    private static function resetRemindersIfDatesChanged(array $old, array $new): void
    {
        $id = (int) $old['id'];

        if ($new['pickup_at'] !== $old['pickup_at']) {
            Notification::releaseReminder('cust_pickup_reminder', $id);
        }
        if ($new['return_at'] !== $old['return_at']) {
            Notification::releaseReminder('cust_return_reminder', $id);
        }
    }

    /** คืนค่าที่อยู่ในรายการที่อนุญาต ไม่งั้นคืนค่าเริ่มต้น */
    private static function pick(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
