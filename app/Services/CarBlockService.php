<?php
namespace App\Services;

use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\CarBlock;

/** ปิดรถชั่วคราว (ซ่อม/เจ้าของใช้เอง) — ปิดทับการจองที่ยังกินคิวไม่ได้ */
final class CarBlockService
{
    /**
     * ล็อกแถวรถก่อนตรวจ (ลำดับเดียวกับการสร้างการจอง: รถ → การจอง) จึงไม่ชนกับคนที่กำลังจองช่วงเดียวกันพอดี
     * @throws UserError
     */
    public static function create(int $carId, string $start, string $end, string $reason, ?int $userId): int
    {
        $from = normalize_datetime($start);
        $to = normalize_datetime($end);
        if ($from === null || $to === null) {
            throw new UserError('กรุณาเลือกรถและช่วงเวลาให้ครบ');
        }
        if (strtotime($to) <= strtotime($from)) {
            throw new UserError('วันสิ้นสุดต้องหลังวันเริ่ม');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) > 250) {
            throw new UserError('เหตุผลยาวเกินไป (ไม่เกิน 250 ตัวอักษร)');
        }
        if ($reason === '') {
            $reason = 'ปิดการใช้งาน';
        }

        $id = Database::transaction(static function () use ($carId, $from, $to, $reason, $userId): int {
            $car = Car::lockForUpdate($carId);
            if ($car === null || $car['deleted_at'] !== null) {
                throw new UserError('ไม่พบรถที่เลือก');
            }
            $numbers = Booking::conflictNumbers($carId, $from, $to);
            if ($numbers !== []) {
                throw new UserError('ช่วงเวลานี้มีการจองรถคันนี้อยู่ ' . count($numbers) . ' รายการ (' . implode(', ', $numbers) . ') — จัดการการจองก่อนจึงจะปิดรถได้');
            }
            return CarBlock::insert($carId, $from, $to, $reason, $userId);
        });
        ActivityLog::log('car_blocked', 'cars', $carId, $reason, $userId, null);
        return $id;
    }

    /** @throws UserError */
    public static function remove(int $blockId, ?int $userId): void
    {
        $block = CarBlock::find($blockId);
        if ($block === null || !CarBlock::deleteById($blockId)) {
            throw new UserError('ไม่พบช่วงปิดรถนี้ (อาจถูกลบไปแล้ว)');
        }
        ActivityLog::log('car_unblocked', 'cars', (int) $block['car_id'], null, $userId, null);
    }
}
