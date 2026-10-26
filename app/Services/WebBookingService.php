<?php
namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Setting;

/**
 * ลูกค้าจองรถจากหน้าเว็บ
 * ขั้นตอน: ตรวจข้อมูล → ตรวจรถว่างเบื้องต้น → กันจองรัว → blacklist → จองจริง (BookingService) → log
 */
final class WebBookingService
{
    /** ค้างมัดจำได้สูงสุดกี่ใบต่อเบอร์ (การจองที่ยังไม่จ่ายก็กันรถไว้ ถ้าไม่จำกัด คนเดียวจองรัวทำให้รถทั้งร้านไม่ว่าง) */
    public const MAX_UNPAID_PER_PHONE = 3;

    /** จองได้สูงสุดกี่ครั้งต่อชั่วโมงต่อ IP */
    public const MAX_PER_IP_PER_HOUR = 8;

    /**
     * @param array $in car_id pickup return addons(array) km name phone email line
     *                  pick_type ret_type place_pick place_ret note accept
     * @return array{errors:string[],booking:?array}
     */
    public static function create(array $in, string $ip): array
    {
        $car = Car::find((int) ($in['car_id'] ?? 0));
        if ($car === null || $car['status'] !== 'available') {
            return self::failure(['ไม่พบรถคันนี้']);
        }

        $pickup = normalize_datetime($in['pickup'] ?? '');
        $return = normalize_datetime($in['return'] ?? '');
        $phone  = Phone::normalize((string) ($in['phone'] ?? ''));

        $errors = array_merge(
            self::checkContact($in, $phone),
            self::checkPeriod($car, $pickup, $return, $in)
        );
        if ($errors === []) {
            $errors = self::checkLimits($phone, $ip);
        }
        if ($errors !== []) {
            return self::failure($errors);
        }

        $contact = [
            'name'  => trim($in['name']),
            'phone' => $phone,
            'email' => trim((string) ($in['email'] ?? '')),
            'line'  => trim((string) ($in['line'] ?? '')),
        ];
        $opt = [
            'addons'       => is_array($in['addons'] ?? null) ? $in['addons'] : [],
            'km'           => (float) ($in['km'] ?? 0),
            'pickup_place' => self::placeText($in['pick_type'] ?? 'shop', (string) ($in['place_pick'] ?? '')),
            'return_place' => self::placeText($in['ret_type'] ?? 'shop', (string) ($in['place_ret'] ?? '')),
            'note'         => mb_substr(trim((string) ($in['note'] ?? '')), 0, 1000),
            'source'       => 'web',
        ];

        try {
            $id = BookingService::createBooking((int) $car['id'], $pickup, $return, $contact, $opt);
        } catch (\Throwable $e) {
            return self::failure([BookingService::publicError($e, 'บันทึกการจองไม่สำเร็จ กรุณาลองใหม่อีกครั้ง หรือติดต่อเจ้าหน้าที่ ' . Setting::get('phone'))]);
        }

        $booking = Booking::find($id);
        ActivityLog::log('booking_created', 'bookings', $id, $booking['booking_no'], null, $ip);
        return ['errors' => [], 'booking' => $booking];
    }

    private static function checkContact(array $in, string $phone): array
    {
        $errors = [];
        if (mb_strlen(trim((string) ($in['name'] ?? ''))) < 2) {
            $errors[] = 'กรุณากรอกชื่อ–นามสกุล';
        }
        if ($phone === '') {
            $errors[] = 'เบอร์โทรศัพท์ไม่ถูกต้อง';
        }
        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'อีเมลไม่ถูกต้อง';
        }
        if (empty($in['accept'])) {
            $errors[] = 'กรุณายอมรับเงื่อนไขการเช่า';
        }
        return $errors;
    }

    private static function checkPeriod(array $car, ?string $pickup, ?string $return, array $in): array
    {
        if ($pickup === null || $return === null) {
            return ['กรุณาเลือกวัน–เวลารับและคืนรถ'];
        }
        $problem = AvailabilityService::validatePeriod($pickup, $return, self::placeType($in['pick_type'] ?? ''), self::placeType($in['ret_type'] ?? ''));
        if ($problem !== null) {
            return [$problem];
        }
        // ตรวจเบื้องต้นให้ข้อความเร็ว (ตัวจริงตรวจอีกครั้งใน transaction ที่ล็อกรถ)
        if (!AvailabilityService::isCarAvailable((int) $car['id'], $pickup, $return)) {
            return ['ขออภัย รถคันนี้ไม่ว่างในช่วงเวลาดังกล่าว กรุณาเลือกวันใหม่'];
        }
        return [];
    }

    private static function checkLimits(string $phone, string $ip): array
    {
        $contactPhone = Setting::get('phone');
        $unpaid = Booking::openUnpaidCountByPhone($phone);
        if ($unpaid >= self::MAX_UNPAID_PER_PHONE) {
            return ["เบอร์นี้มีการจองที่ยังไม่ได้ชำระมัดจำอยู่ {$unpaid} รายการ กรุณาชำระมัดจำรายการเดิมก่อน หรือติดต่อเจ้าหน้าที่ {$contactPhone}"];
        }
        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        if (ActivityLog::countByIpSince('booking_created', $ip, $oneHourAgo) >= self::MAX_PER_IP_PER_HOUR) {
            return ["มีการจองจากอุปกรณ์นี้หลายรายการในเวลาใกล้กัน กรุณาลองใหม่ภายหลัง หรือติดต่อเจ้าหน้าที่ {$contactPhone}"];
        }
        $customer = Customer::findByPhone($phone);
        if ($customer !== null && (int) $customer['is_blacklist'] === 1) {
            return ["ไม่สามารถจองออนไลน์ได้ กรุณาติดต่อเจ้าหน้าที่ {$contactPhone}"];
        }
        return [];
    }

    private static function placeType(string $type): string
    {
        return $type === 'airport' ? 'airport' : 'shop';
    }

    /** เก็บประเภทจุดไว้หน้าข้อความ เพื่อให้แอดมินเห็นชัดว่าเป็นสนามบินหรือไม่ */
    private static function placeText(string $type, string $place): ?string
    {
        $prefix = $type === 'airport' ? '[สนามบิน] ' : '';
        $text = trim($prefix . mb_substr(trim($place), 0, 250));
        return $text === '' ? null : $text;
    }

    private static function failure(array $errors): array
    {
        return ['errors' => $errors, 'booking' => null];
    }
}
