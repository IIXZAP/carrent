<?php
namespace App\Services;

use App\Core\Database;
use App\Models\Booking;
use App\Models\Inspection;

/**
 * ตรวจสภาพรถก่อน–หลัง (ถ่ายรูปผูกกับใบจอง) — ใช้เป็นหลักฐานเวลามีข้อพิพาทเรื่องรอยขีดข่วน น้ำมัน หรือเลขไมล์
 * รูปผูกกับ "ใบจอง + รอบการตรวจ (ส่งมอบ/รับคืน)" ถ่ายแทนได้แต่ไม่ลบรูปเดิม และล็อกเมื่อจบรอบ
 * รูปเก็บใน storage/private/inspections (เปิดผ่านเว็บตรงๆ ไม่ได้) ดูผ่านหลังบ้านเท่านั้น
 */
final class InspectionService
{
    public const MAX_PHOTOS = 40;
    public const KINDS = ['out', 'in'];

    /** มุมถ่ายมาตรฐาน 8 จุด — เรียงตามลำดับที่เดินรอบรถจริง */
    public static function positions(): array
    {
        return [
            'front'     => ['หน้ารถ',           'ถ่ายเต็มคันจากด้านหน้า ให้เห็นกันชนและไฟหน้า'],
            'right'     => ['ด้านขวา',          'ยืนห่างประมาณ 2 เมตร ให้เห็นประตูและล้อทั้ง 2 ข้าง'],
            'rear'      => ['ท้ายรถ',           'ให้เห็นกันชนท้าย ไฟท้าย และป้ายทะเบียน'],
            'left'      => ['ด้านซ้าย',         'ยืนห่างประมาณ 2 เมตร ให้เห็นประตูและล้อทั้ง 2 ข้าง'],
            'dashboard' => ['เลขไมล์ + น้ำมัน', 'ถ่ายหน้าปัดตอนเปิดสวิตช์ ให้อ่านเลขไมล์และขีดน้ำมันได้'],
            'interior'  => ['ภายในห้องโดยสาร',  'ถ่ายจากประตูหน้า ให้เห็นเบาะและคอนโซล'],
            'tire'      => ['ยางและล้อ',        'ถ่ายล้อที่สภาพแย่ที่สุด ให้เห็นดอกยางและขอบกระทะล้อ'],
            'damage'    => ['จุดที่มีตำหนิ',     'ถ่ายใกล้ทุกรอยที่พบ (ถ่ายได้หลายรูป) ถ้าไม่มีให้ข้ามได้'],
        ];
    }

    public static function kindLabel(string $kind): string
    {
        return $kind === 'out' ? 'ตอนส่งมอบรถ' : 'ตอนรับคืนรถ';
    }

    /**
     * ผลตรวจรอบนี้ยังแก้ได้ตามขั้นตอนของการจองหรือไม่ — คืนข้อความเหตุผลถ้าไม่ได้
     *   รอบส่งมอบ  แก้ได้ก่อนกดส่งมอบรถเท่านั้น
     *   รอบรับคืน   แก้ได้ระหว่างที่รถอยู่กับลูกค้า (ก่อนกดรับคืนรถ) เท่านั้น
     * กันกรณีส่งมอบโดยไม่ได้ถ่ายรูป แล้วมีคนย้อนมาถ่าย "สภาพรถวันส่งมอบ" ทีหลัง
     */
    public static function phaseBlock(array $booking, string $kind): ?string
    {
        $open = $kind === 'out' ? ['pending', 'confirmed'] : ['picked_up'];
        if (in_array($booking['status'], $open, true)) {
            return null;
        }
        if ($kind === 'in' && in_array($booking['status'], ['pending', 'confirmed'], true)) {
            return 'ยังไม่ได้ส่งมอบรถ — บันทึกสภาพรถตอนรับคืนได้หลังส่งมอบรถแล้ว';
        }
        return $kind === 'out'
            ? 'ส่งมอบรถไปแล้ว ผลตรวจรอบส่งมอบปิดแล้ว เพิ่มหรือแก้ไขไม่ได้'
            : 'รับคืนรถไปแล้ว ผลตรวจรอบรับคืนปิดแล้ว เพิ่มหรือแก้ไขไม่ได้';
    }

    /** จำนวนมุมที่ถ่ายแล้ว / ที่ควรมี (ไม่นับ "จุดที่มีตำหนิ" ซึ่งข้ามได้) */
    public static function progress(?array $inspection): array
    {
        $required = count(self::positions()) - 1;
        $taken = [];
        foreach ($inspection['photos'] ?? [] as $photo) {
            if ($photo['position'] !== 'damage') {
                $taken[$photo['position']] = true;
            }
        }
        return ['done' => count($taken), 'required' => $required, 'complete' => count($taken) >= $required];
    }

    /**
     * บันทึกรูป 1 ใบ — มุมเดิมที่มีรูปอยู่แล้ว รูปเก่าถูกเก็บเป็นประวัติ (จุดมีตำหนิถ่ายเพิ่มได้หลายรูป)
     * @return int id ของรูป
     * @throws UserError
     */
    public static function savePhoto(int $bookingId, string $kind, string $position, array $file, ?string $caption, int $userId, bool $viaHttp = true): int
    {
        if (!in_array($kind, self::KINDS, true) || !array_key_exists($position, self::positions())) {
            throw new UserError('รอบหรือมุมถ่ายไม่ถูกต้อง');
        }
        $booking = Booking::find($bookingId);
        if ($booking === null) {
            throw new UserError('ไม่พบการจอง');
        }
        $block = self::phaseBlock($booking, $kind);
        if ($block !== null) {
            throw new UserError($block);
        }
        $caption = $caption !== null && trim($caption) !== '' ? mb_substr(trim($caption), 0, 200) : null;

        $path = UploadService::saveImage($file, 'inspections', $viaHttp);
        try {
            return Database::retryOnDeadlock(static function () use ($bookingId, $kind, $position, $path, $caption, $userId): int {
                Database::begin();
                try {
                    // ล็อกการจองก่อน: กันถ่ายรูปพร้อมกับตอนกดส่งมอบ/รับคืน (ที่ล็อกผลตรวจ)
                    $locked = Booking::lockForUpdate($bookingId);
                    $block = $locked === null ? 'ไม่พบการจอง' : self::phaseBlock($locked, $kind);
                    if ($block !== null) {
                        throw new UserError($block);
                    }
                    $inspectionId = Inspection::ensure($bookingId, $kind, $userId);
                    if (Inspection::photoCount($inspectionId) >= self::MAX_PHOTOS) {
                        throw new UserError('ถ่ายรูปครบจำนวนสูงสุดของรอบนี้แล้ว (' . self::MAX_PHOTOS . ' รูป)');
                    }
                    $photoId = Inspection::addPhoto($inspectionId, $position, $path, $caption, $userId, $position !== 'damage');
                    Database::commit();
                    return $photoId;
                } catch (\Throwable $e) {
                    Database::rollBack();
                    throw $e;
                }
            });
        } catch (\Throwable $e) {
            UploadService::delete($path);   // ไม่ทิ้งไฟล์กำพร้า
            throw $e;
        }
    }
}
