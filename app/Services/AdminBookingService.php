<?php
namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;

/**
 * พนักงานสร้างการจองจากหลังบ้าน (รับงานทางโทรศัพท์ / LINE / หน้าร้าน)
 * เข้มงวดน้อยกว่าเว็บ: ไม่ตรวจเวลาทำการ/จองล่วงหน้า/จำนวนครั้ง แต่ตรวจรถว่างเหมือนกัน
 */
final class AdminBookingService
{
    /**
     * @param array $in car_id pickup_at return_at name phone email line addons km discount vat
     *                  deposit_amount security_deposit pickup_place return_place note source
     * @return array{errors:string[],id:?int}
     */
    public static function create(array $in, int $userId, string $ip): array
    {
        $errors = [];
        $name  = trim((string) ($in['name'] ?? ''));
        $phone = Phone::normalize((string) ($in['phone'] ?? ''));
        if ($name === '') {
            $errors[] = 'กรุณากรอกชื่อผู้เช่า';
        }
        if ($phone === '') {
            $errors[] = 'เบอร์โทรต้องมีตัวเลข 9–15 หลัก (ใช้ยืนยันตัวตนตอนลูกค้าตรวจสอบการจอง)';
        }

        $carId = (int) ($in['car_id'] ?? 0);
        if (Car::statusOf($carId) === null) {
            $errors[] = 'กรุณาเลือกรถ';
        }

        $pickup = normalize_datetime($in['pickup_at'] ?? '');
        $return = normalize_datetime($in['return_at'] ?? '');
        if ($pickup === null || $return === null) {
            $errors[] = 'กรุณาเลือกวัน–เวลารับและคืนรถ';
        } elseif (strtotime($return) <= strtotime($pickup)) {
            $errors[] = 'วันคืนรถต้องหลังวันรับรถ';
        } elseif (PricingService::rentalDays($pickup, $return) > 365) {
            $errors[] = 'ระยะเวลาเช่าเกิน 1 ปี กรุณาตรวจวันที่อีกครั้ง';
        }

        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'อีเมลไม่ถูกต้อง';
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'id' => null];
        }

        $source = array_key_exists($in['source'] ?? '', BookingStatus::SOURCE) ? $in['source'] : 'admin';
        $contact = ['name' => $name, 'phone' => $phone, 'email' => $email, 'line' => trim((string) ($in['line'] ?? ''))];
        $opt = [
            'addons'            => is_array($in['addons'] ?? null) ? $in['addons'] : [],
            'km'                => (float) ($in['km'] ?? 0),
            'discount'          => max(0.0, (float) ($in['discount'] ?? 0)),
            'vat'               => !empty($in['vat']),
            'deposit_override'  => self::optionalMoney($in['deposit_amount'] ?? ''),
            'security_override' => self::optionalMoney($in['security_deposit'] ?? ''),
            'pickup_place'      => mb_substr(trim((string) ($in['pickup_place'] ?? '')), 0, 250),
            'return_place'      => mb_substr(trim((string) ($in['return_place'] ?? '')), 0, 250),
            'note'              => mb_substr(trim((string) ($in['note'] ?? '')), 0, 1000),
            'source'            => $source,
            'created_by'        => $userId,
        ];

        try {
            // พนักงานจองให้ลูกค้า blacklist ได้ (ตัดสินใจเอง) แต่ระบบเตือนที่หน้าฟอร์มแล้ว
            $id = BookingService::createBooking($carId, $pickup, $return, $contact, $opt, false);
        } catch (\Throwable $e) {
            return ['errors' => [BookingService::publicError($e, 'บันทึกการจองไม่สำเร็จ กรุณาลองใหม่อีกครั้ง')], 'id' => null];
        }

        $booking = Booking::find($id);
        ActivityLog::log('booking_created', 'bookings', $id, $booking['booking_no'] . ' (' . $source . ')', $userId, $ip);
        return ['errors' => [], 'id' => $id];
    }

    /** ช่องเงินที่เว้นว่างได้ — '' = null (ใช้ตามที่ระบบคิด) */
    private static function optionalMoney($value): ?float
    {
        $text = str_replace(',', '', trim((string) $value));
        return $text !== '' && is_numeric($text) ? max(0.0, (float) $text) : null;
    }

    /** ลูกค้านี้ถูก blacklist หรือไม่ (ใช้เตือนในหน้าฟอร์ม) */
    public static function isBlacklisted(string $phone): bool
    {
        $normalized = Phone::normalize($phone);
        $customer = $normalized === '' ? null : Customer::findByPhone($normalized);
        return $customer !== null && (int) $customer['is_blacklist'] === 1;
    }

    public static function cancel(int $bookingId, string $reason, int $userId, string $ip): bool
    {
        $reason = mb_substr(trim($reason), 0, 250);
        if (!Booking::cancel($bookingId, $reason !== '' ? $reason : 'ยกเลิกโดยพนักงาน')) {
            return false;
        }
        ActivityLog::log('booking_cancelled', 'bookings', $bookingId, $reason, $userId, $ip);
        return true;
    }
}
