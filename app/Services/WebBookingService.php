<?php

namespace App\Services;

use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;

/**
 * ลูกค้าจองรถจากหน้าเว็บ
 *
 * ขั้นตอน: ตรวจข้อมูล → ตรวจรถว่าง → กันจองถี่เกินไป → คิดราคา → บันทึก → แจ้งเตือนร้าน
 */
final class WebBookingService
{
    /** จองซ้ำได้สูงสุดกี่ครั้งที่ยังไม่จ่ายมัดจำ (ต่อเบอร์โทร) */
    private const MAX_UNPAID_PER_PHONE = 3;

    /** จองได้สูงสุดกี่ครั้งต่อชั่วโมง (ต่อ IP) */
    private const MAX_PER_IP_PER_HOUR = 8;

    /**
     * @param array $in ค่าจากฟอร์ม: pickup, ret, name, phone, email, line, note, place_pick, place_ret,
     *                  addons, km, pick_type, ret_type, accept, liff_uid
     * @return array{errors:string[],booking:?array,blacklisted:bool}
     */
    public static function create(array $car, array $in, string $ip): array
    {
        $carId  = (int) $car['id'];
        $pickup = BookingService::toDb($in['pickup']);
        $return = BookingService::toDb($in['ret']);

        $errors = array_merge(
            self::checkContact($in),
            self::checkPeriod($carId, $pickup, $return, $in),
            self::checkDelivery($in)
        );
        if (!$errors) {
            $errors = self::checkBookingLimits($in['phone'], $ip);
        }
        if ($errors) {
            return self::failure($errors);
        }

        $customer = Customer::byPhone($in['phone']);
        if ($customer && (int) $customer['is_blacklist'] === 1) {
            return self::failure(['ไม่สามารถจองออนไลน์ได้ กรุณาติดต่อเจ้าหน้าที่ ' . setting('phone')], true);
        }

        $quote = PricingService::quote($car, $pickup, $return, $in['addons'], 0.0, null, $in['km']);

        try {
            $bookingId = self::saveWithRetry($carId, $in, $customer, $pickup, $return, $quote);
        } catch (\Throwable $e) {
            $message = BookingService::publicError($e, 'บันทึกการจองไม่สำเร็จ กรุณาลองใหม่อีกครั้ง หรือติดต่อเจ้าหน้าที่ ' . setting('phone'));
            return self::failure([$message]);
        }

        $booking = Booking::find($bookingId);
        ActivityLog::log('booking_created', 'bookings', $bookingId, $booking['booking_no']);
        self::notifyShop($booking, $car);

        return ['errors' => [], 'booking' => $booking, 'blacklisted' => false];
    }

    // ------------------------------------------------------------
    // ตรวจข้อมูลจากฟอร์ม (แต่ละเมธอดคืนรายการข้อความผิดพลาด)
    // ------------------------------------------------------------

    private static function checkContact(array $in): array
    {
        $errors = [];

        if (mb_strlen($in['name']) < 2) {
            $errors[] = 'กรุณากรอกชื่อ–นามสกุล';
        }

        $phoneLooksValid = preg_match('/^[0-9\-\+\s]{9,20}$/', $in['phone']) && strlen(phone_digits($in['phone'])) >= 9;
        if (!$phoneLooksValid) {
            $errors[] = 'เบอร์โทรศัพท์ไม่ถูกต้อง';
        }

        if ($in['email'] !== '' && !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'อีเมลไม่ถูกต้อง';
        }

        if (empty($in['accept'])) {
            $errors[] = 'กรุณายอมรับเงื่อนไขการเช่า';
        }

        return $errors;
    }

    private static function checkPeriod(int $carId, string $pickup, string $return, array $in): array
    {
        if ($pickup === '' || $return === '') {
            return ['กรุณาเลือกวัน–เวลารับและคืนรถ'];
        }

        $problem = AvailabilityService::validatePeriod($pickup, $return, $in['pick_type'], $in['ret_type']);
        if ($problem) {
            return [$problem];
        }

        if (!AvailabilityService::isCarAvailable($carId, $pickup, $return)) {
            return ['ขออภัย รถคันนี้เพิ่งถูกจองในช่วงเวลาดังกล่าว กรุณาเลือกวันใหม่'];
        }

        return [];
    }

    private static function checkDelivery(array $in): array
    {
        if (!PricingService::deliveryOutOfRange($in['addons'], $in['km'])) {
            return [];
        }

        return ['ระยะทาง ' . number_format($in['km']) . ' กม. อยู่นอกพื้นที่ที่คิดค่าส่งรถอัตโนมัติ กรุณาติดต่อเจ้าหน้าที่ ' . setting('phone') . ' เพื่อขอราคา'];
    }

    /**
     * กันการจองถี่ผิดปกติ
     * การจองที่ยังไม่จ่ายมัดจำก็กันรถไว้ไม่ให้คนอื่นจอง ถ้าไม่จำกัด คนเดียวจองรัวๆ จะทำให้รถทั้งร้าน "ไม่ว่าง"
     */
    private static function checkBookingLimits(string $phone, string $ip): array
    {
        $unpaid = Booking::openUnpaidCountByPhone($phone);
        if ($unpaid >= self::MAX_UNPAID_PER_PHONE) {
            return ['เบอร์นี้มีการจองที่ยังไม่ได้ชำระมัดจำอยู่ ' . $unpaid . ' รายการ กรุณาชำระมัดจำรายการเดิมก่อน หรือติดต่อเจ้าหน้าที่ ' . setting('phone')];
        }

        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        if (ActivityLog::bookingsFromIpSince($ip, $oneHourAgo) >= self::MAX_PER_IP_PER_HOUR) {
            return ['มีการจองจากอุปกรณ์นี้หลายรายการในเวลาใกล้กัน กรุณาลองใหม่ภายหลัง หรือติดต่อเจ้าหน้าที่ ' . setting('phone')];
        }

        return [];
    }

    // ------------------------------------------------------------
    // บันทึกลงฐานข้อมูล
    // ------------------------------------------------------------

    /**
     * เลขที่จองออกจาก "เลขล่าสุด + 1" ถ้ามีสองคนจองพร้อมกันพอดีจะได้เลขซ้ำ
     * จึงลองบันทึกใหม่ให้เองสูงสุด 3 รอบ
     */
    private static function saveWithRetry(int $carId, array $in, ?array $customer, string $pickup, string $return, array $quote): int
    {
        $maxTries = 3;

        for ($try = 1; $try <= $maxTries; $try++) {
            try {
                return self::saveOnce($carId, $in, $customer, $pickup, $return, $quote);
            } catch (\Throwable $e) {
                $isDuplicateNumber = $e instanceof \PDOException && (string) $e->getCode() === '23000';
                if (!$isDuplicateNumber || $try === $maxTries) {
                    throw $e;
                }
                usleep(random_int(20000, 120000));
            }
        }

        throw new \RuntimeException('บันทึกการจองไม่สำเร็จ');
    }

    /** บันทึก 1 รอบใน transaction เดียว (ล้มเหลวจะย้อนกลับทั้งหมด) */
    private static function saveOnce(int $carId, array $in, ?array $customer, string $pickup, string $return, array $quote): int
    {
        Database::begin();
        try {
            // ล็อกแถวของรถไว้จนบันทึกเสร็จ คนที่จองรถคันเดียวกันพร้อมกันต้องรอคิว แล้วจะเห็นว่ารถถูกจองไปแล้ว
            Car::lock($carId);
            if (!AvailabilityService::isCarAvailable($carId, $pickup, $return)) {
                throw new \RuntimeException('รถคันนี้เพิ่งถูกจองไปแล้ว กรุณาเลือกวันใหม่');
            }

            $customerId = $customer ? (int) $customer['id'] : self::createCustomer($in);
            $row = self::bookingRow($carId, $customerId, $in, $pickup, $return, $quote);
            $bookingId = Booking::insert($row);

            Database::commit();
            return $bookingId;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    private static function createCustomer(array $in): int
    {
        return Customer::create([
            'type'      => 'individual',
            'full_name' => $in['name'],
            'phone'     => $in['phone'],
            'email'     => $in['email'] ?: null,
            'line_id'   => $in['line'] ?: null,
        ]);
    }

    private static function bookingRow(int $carId, int $customerId, array $in, string $pickup, string $return, array $quote): array
    {
        $row = [
            'booking_no'     => Booking::nextNumber(),
            'customer_id'    => $customerId,
            'car_id'         => $carId,
            'contact_name'   => $in['name'],
            'contact_phone'  => $in['phone'],
            'contact_email'  => $in['email'] ?: null,
            'contact_line'   => $in['line'] ?: null,
            'pickup_at'      => $pickup,
            'return_at'      => $return,
            'pickup_place'   => self::placeText($in['pick_type'], $in['place_pick']),
            'return_place'   => self::placeText($in['ret_type'], $in['place_ret']),
            'delivery_km'    => $in['km'] > 0 ? $in['km'] : null,
            'line_user_id'   => self::lineUserFromSession($in['liff_uid']),
            'addons'         => json_encode($in['addons'], JSON_UNESCAPED_UNICODE),
            'status'         => 'pending',
            'payment_status' => 'unpaid',
            'source'         => 'web',
            'note'           => $in['note'] ?: null,
        ];

        return $row + BookingService::priceColumns($quote);
    }

    /** เก็บประเภทจุดไว้หน้าข้อความ เพื่อให้แอดมินเห็นชัดว่าเป็นสนามบินหรือไม่ */
    private static function placeText(string $type, string $place): ?string
    {
        $prefix = $type === 'airport' ? '[สนามบิน] ' : '';
        return trim($prefix . $place) ?: null;
    }

    /**
     * จองจากในแอป LINE (ผ่านการยืนยันตัวตนกับ LINE มาแล้ว) ให้ผูกการจองกับ LINE นั้นทันที
     * ลูกค้าจะได้ข้อความยืนยันและเห็นการจองโดยไม่ต้องกรอกอะไรอีก
     */
    private static function lineUserFromSession(string $uid): ?string
    {
        return preg_match('/^U[0-9a-f]{32}$/', $uid) ? $uid : null;
    }

    private static function notifyShop(array $booking, array $car): void
    {
        try {
            NotificationService::newBooking($booking, $car);
        } catch (\Throwable $e) {
            // แจ้งเตือนล้มเหลวไม่ควรทำให้การจองพัง
        }
    }

    private static function failure(array $errors, bool $blacklisted = false): array
    {
        return ['errors' => $errors, 'booking' => null, 'blacklisted' => $blacklisted];
    }
}
