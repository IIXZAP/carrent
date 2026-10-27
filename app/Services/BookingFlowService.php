<?php
namespace App\Services;

use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Inspection;

/**
 * ขั้นตอนหลังบ้านของการจอง:  รอยืนยัน → ยืนยัน → ส่งมอบรถ → รับคืนรถ → ปิดงาน   (ยกเลิกได้ก่อนส่งมอบ — Booking::cancel)
 *
 * ทุกขั้นเปลี่ยนสถานะด้วย UPDATE ... WHERE status IN (...) คำสั่งเดียวภายใน transaction ที่ล็อกการจองไว้
 * จึงกดซ้ำ/เปิดสองแท็บ/สองคนพร้อมกันผ่านได้ครั้งเดียว และกดย้อนขั้นหรือข้ามขั้นไม่ได้
 * ลำดับล็อกเสมอ: รถ → การจอง (ตรงกับตอนสร้างการจอง จึงไม่วนรอกัน)
 * เวลาปัจจุบัน ($now) ฉีดเข้ามาได้เพื่อทดสอบ
 */
final class BookingFlowService
{
    private const MAX_EXTRA_AMOUNT = 1000000.0;
    private const MAX_MILEAGE = 9999999;

    // ------------------------------------------------------------
    // ยืนยัน / ปิดงาน
    // ------------------------------------------------------------

    public static function confirm(int $bookingId, int $userId, string $ip = ''): void
    {
        if (!Booking::transition($bookingId, ['pending'], 'confirmed')) {
            throw self::refusal($bookingId);
        }
        ActivityLog::log('booking_confirmed', 'bookings', $bookingId, null, $userId, $ip);
    }

    public static function complete(int $bookingId, int $userId, string $ip = ''): void
    {
        if (!Booking::transition($bookingId, ['returned'], 'completed')) {
            throw self::refusal($bookingId);
        }
        ActivityLog::log('booking_completed', 'bookings', $bookingId, null, $userId, $ip);
    }

    // ------------------------------------------------------------
    // ส่งมอบรถ
    // ------------------------------------------------------------

    /**
     * @param int|null $mileage เลขไมล์ตอนส่งมอบ (null = ใช้ที่บันทึกไว้ตอนถ่ายรูป ถ้ามี)
     * @throws UserError
     */
    public static function pickup(int $bookingId, ?int $mileage, int $fuel, int $userId, string $ip = '', ?int $now = null): void
    {
        $now ??= time();
        if ($fuel < 0 || $fuel > 8) {
            throw new UserError('ขีดน้ำมันต้องอยู่ระหว่าง 0–8');
        }
        if ($mileage !== null && ($mileage < 0 || $mileage > self::MAX_MILEAGE)) {
            throw new UserError('เลขไมล์ไม่ถูกต้อง');
        }
        $booking = Booking::find($bookingId);
        if ($booking === null) {
            throw new UserError('ไม่พบการจอง');
        }
        $carId = (int) $booking['car_id'];

        Database::retryOnDeadlock(static function () use ($bookingId, $carId, $mileage, $fuel, $userId, $now): void {
            Database::begin();
            try {
                Car::lockForUpdate($carId);   // กันส่งมอบรถคันเดียวกันให้สองการจองพร้อมกัน
                $locked = Booking::lockForUpdate($bookingId);
                if ($locked === null || !in_array($locked['status'], ['pending', 'confirmed'], true)) {
                    throw self::refusal($bookingId, $locked);
                }
                $other = Booking::otherCheckedOutForCar($carId, $bookingId);
                if ($other !== null) {
                    throw new UserError("รถคันนี้ยังอยู่กับลูกค้ารายอื่น (การจอง $other) ยังไม่ได้รับคืน จึงส่งมอบไม่ได้");
                }

                // ส่งมอบแล้ว = สภาพรถ ณ วันส่งมอบต้องนิ่ง: ไม่ได้ถ่ายรูปก็ต้องมีบันทึกรอบส่งมอบ (ไมล์ + น้ำมัน) แล้วล็อก
                Inspection::ensure($bookingId, 'out', $userId);
                Inspection::setReadings($bookingId, 'out', $mileage, $fuel);
                Booking::transition($bookingId, ['pending', 'confirmed'], 'picked_up', ['picked_up_at' => date('Y-m-d H:i:s', $now)]);
                Inspection::lock($bookingId, 'out', $userId);

                $recorded = Inspection::get($bookingId, 'out')['mileage'];
                if ($recorded !== null) {
                    Car::bumpMileage($carId, (int) $recorded);
                }
                Database::commit();
            } catch (\Throwable $e) {
                Database::rollBack();
                throw $e;
            }
        });
        ActivityLog::log('booking_pickup', 'bookings', $bookingId, (string) $mileage, $userId, $ip);
    }

    // ------------------------------------------------------------
    // รับคืนรถ
    // ------------------------------------------------------------

    /**
     * ดูค่าใช้จ่ายที่ระบบแนะนำก่อนยืนยันรับคืน (ไม่เปลี่ยนอะไรในฐานข้อมูล)
     * @param array $in mileage_in fuel_in returned_at(ว่าง = ตอนนี้)
     * @return array{returned_at:string,mileage_in:?int,fuel_in:int,lines:array}
     * @throws UserError
     */
    public static function previewReturn(array $booking, array $in, ?int $now = null): array
    {
        $parsed = self::parseReturn($booking, $in, $now ?? time());
        return $parsed + ['lines' => self::suggestedLines($booking, $parsed)];
    }

    /**
     * รับคืนรถ: บันทึกเลขไมล์ น้ำมัน เวลาคืนจริง ค่าใช้จ่ายเพิ่ม (คืนช้า/น้ำมัน/ไมล์ + อื่นๆ) ล็อกผลตรวจรอบรับคืน และคิดสถานะชำระเงินใหม่
     * @param array $in mileage_in fuel_in returned_at chg[key][on|amount] other_label other_amount damage_note
     * @return array{extra:float,lines:array,returned_at:string}
     * @throws UserError
     */
    public static function returnCar(int $bookingId, array $in, int $userId, string $ip = '', ?int $now = null): array
    {
        $now ??= time();
        $booking = Booking::find($bookingId);
        if ($booking === null) {
            throw new UserError('ไม่พบการจอง');
        }
        $carId = (int) $booking['car_id'];

        $result = Database::retryOnDeadlock(static function () use ($bookingId, $carId, $in, $userId, $now): array {
            Database::begin();
            try {
                $locked = Booking::lockForUpdate($bookingId);
                if ($locked === null || $locked['status'] !== 'picked_up') {
                    throw self::refusal($bookingId, $locked);
                }
                $parsed = self::parseReturn($locked, $in, $now);
                $lines = self::chargeLines($locked, $parsed, $in);
                $extra = round(array_sum(array_column($lines, 'amount')), 2);

                Booking::transition($bookingId, ['picked_up'], 'returned', [
                    'returned_at'     => $parsed['returned_at'],
                    'extra_charge'    => $extra,
                    'extra_note'      => self::summaryText($lines),
                    'extra_breakdown' => $lines === [] ? null : json_encode($lines, JSON_UNESCAPED_UNICODE),
                    'damage_note'     => ($note = mb_substr(trim((string) ($in['damage_note'] ?? '')), 0, 1000)) !== '' ? $note : null,
                ]);

                Inspection::ensure($bookingId, 'in', $userId);
                Inspection::setReadings($bookingId, 'in', $parsed['mileage_in'], $parsed['fuel_in']);
                Inspection::lock($bookingId, 'in', $userId);
                if ($parsed['mileage_in'] !== null) {
                    Car::bumpMileage($carId, $parsed['mileage_in']);
                }
                PaymentService::recalc($bookingId);   // ค่าใช้จ่ายเพิ่มทำให้ยอดที่ต้องชำระเปลี่ยน
                Database::commit();
                return ['extra' => $extra, 'lines' => $lines, 'returned_at' => $parsed['returned_at']];
            } catch (\Throwable $e) {
                Database::rollBack();
                throw $e;
            }
        });
        ActivityLog::log('booking_return', 'bookings', $bookingId, json_encode(['extra' => $result['extra'], 'lines' => $result['lines']], JSON_UNESCAPED_UNICODE), $userId, $ip);
        return $result;
    }

    // ------------------------------------------------------------
    // ภายใน
    // ------------------------------------------------------------

    /** แปลงและตรวจค่าจากฟอร์มรับคืนรถ */
    private static function parseReturn(array $booking, array $in, int $now): array
    {
        $mileageText = trim((string) ($in['mileage_in'] ?? ''));
        if ($mileageText !== '' && (!ctype_digit($mileageText) || (int) $mileageText > self::MAX_MILEAGE)) {
            throw new UserError('เลขไมล์ตอนรับคืนไม่ถูกต้อง');
        }
        $mileage = $mileageText === '' ? null : (int) $mileageText;

        $fuelText = trim((string) ($in['fuel_in'] ?? ''));
        if (!ctype_digit($fuelText) || (int) $fuelText > 8) {
            throw new UserError('กรุณาระบุขีดน้ำมันตอนรับคืน (0–8)');
        }
        $fuel = (int) $fuelText;

        $out = Inspection::get((int) $booking['id'], 'out');
        $mileageOut = (int) ($out['mileage'] ?? 0);
        if ($mileage !== null && $mileageOut > 0 && $mileage < $mileageOut) {
            throw new UserError('เลขไมล์ตอนรับคืน (' . number_format($mileage) . ') น้อยกว่าตอนส่งมอบ (' . number_format($mileageOut) . ') กรุณาตรวจตัวเลขอีกครั้ง');
        }

        $returnedAt = date('Y-m-d H:i:s', $now);   // ไม่ระบุ = ตอนนี้
        $text = trim((string) ($in['returned_at'] ?? ''));
        if ($text !== '') {
            $normalized = normalize_datetime($text);
            if ($normalized === null) {
                throw new UserError('รูปแบบเวลารับคืนรถไม่ถูกต้อง');
            }
            $ts = strtotime($normalized);
            if ($ts > $now + 300) {
                throw new UserError('เวลารับคืนรถอยู่ในอนาคต กรุณาตรวจอีกครั้ง');
            }
            if (!empty($booking['picked_up_at']) && $ts < strtotime($booking['picked_up_at'])) {
                throw new UserError('เวลารับคืนรถอยู่ก่อนเวลาส่งมอบรถ (' . date('d/m/Y H:i', strtotime($booking['picked_up_at'])) . ')');
            }
            $returnedAt = $normalized;
        }
        return ['mileage_in' => $mileage, 'fuel_in' => $fuel, 'returned_at' => $returnedAt];
    }

    /** ค่าใช้จ่ายที่ระบบแนะนำ (คืนช้า/น้ำมัน/ไมล์) จากค่าที่ตรวจแล้ว */
    private static function suggestedLines(array $booking, array $parsed): array
    {
        $out = Inspection::get((int) $booking['id'], 'out');
        $forCalc = $booking + ['fuel_out' => $out['fuel_level'] ?? null, 'mileage_out' => $out['mileage'] ?? null];
        $car = ['price_day' => Car::priceDay((int) $booking['car_id'])];
        return LateReturnService::calculate($forCalc, $car, $parsed['returned_at'], $parsed['mileage_in'], $parsed['fuel_in'], LateReturnService::rules())['lines'];
    }

    /**
     * รายการค่าใช้จ่ายจริง: ระบบคิดให้ พนักงานแก้จำนวนหรือยกเว้นได้ + ค่าใช้จ่ายอื่น
     * เก็บทั้ง "ยอดแนะนำ" และ "ยอดที่คิดจริง" เพื่อตรวจย้อนหลังได้ว่าพนักงานยกเว้น/แก้รายการไหน
     */
    private static function chargeLines(array $booking, array $parsed, array $in): array
    {
        $posted = is_array($in['chg'] ?? null) ? $in['chg'] : [];
        $lines = [];
        foreach (self::suggestedLines($booking, $parsed) as $line) {
            $form = $posted[$line['key']] ?? null;
            if (is_array($form)) {
                $amount = !empty($form['on']) ? self::amountOrDefault((string) ($form['amount'] ?? ''), (float) $line['amount']) : 0.0;
            } else {
                $amount = (float) $line['amount'];   // ฟอร์มไม่มีรายการนี้ (เกิดระหว่างเปิดหน้าค้างไว้) ให้คิดตามเงื่อนไข
            }
            $lines[] = [
                'key' => $line['key'], 'label' => $line['label'], 'detail' => $line['detail'],
                'suggested' => (float) $line['amount'], 'amount' => $amount, 'waived' => $amount <= 0,
            ];
        }

        $otherText = trim((string) ($in['other_amount'] ?? ''));
        if ($otherText !== '') {
            $other = PaymentService::parseAmount($otherText);
            if ($other === null || $other > self::MAX_EXTRA_AMOUNT) {
                throw new UserError('จำนวนเงินค่าใช้จ่ายอื่นไม่ถูกต้อง');
            }
            $label = mb_substr(trim((string) ($in['other_label'] ?? '')), 0, 120);
            $lines[] = ['key' => 'other', 'label' => $label !== '' ? $label : 'ค่าใช้จ่ายอื่น', 'detail' => '', 'suggested' => $other, 'amount' => $other, 'waived' => false];
        }
        return $lines;
    }

    private static function amountOrDefault(string $text, float $default): float
    {
        if (trim($text) === '') {
            return min($default, self::MAX_EXTRA_AMOUNT);
        }
        $amount = PaymentService::parseAmount($text, true);
        if ($amount === null || $amount > self::MAX_EXTRA_AMOUNT) {
            throw new UserError('ยอดค่าใช้จ่ายที่แก้ไม่ถูกต้อง');
        }
        return $amount;
    }

    /** ข้อความสรุปสั้นๆ ใน extra_note เช่น "น้ำมันขาด 2 ขีด 600 บ." */
    private static function summaryText(array $lines): ?string
    {
        $parts = [];
        foreach ($lines as $line) {
            $parts[] = $line['label'] . ' ' . ($line['waived'] ? '(ยกเว้น)' : number_format($line['amount'], 0) . ' บ.');
        }
        $text = implode(' · ', $parts);
        return $text === '' ? null : mb_substr($text, 0, 250);
    }

    /** ข้อความเมื่อทำขั้นตอนนี้ไม่ได้เพราะสถานะไม่ตรง (หน้าอาจเปิดค้างไว้) */
    private static function refusal(int $bookingId, ?array $booking = null): UserError
    {
        $booking ??= Booking::find($bookingId);
        if ($booking === null) {
            return new UserError('ไม่พบการจอง');
        }
        return new UserError('ทำรายการนี้ไม่ได้ เพราะการจองอยู่ในสถานะ "' . BookingStatus::STATUS[$booking['status']] . '" แล้ว — หน้านี้อาจเปิดค้างไว้ กรุณาตรวจสถานะล่าสุด');
    }
}
