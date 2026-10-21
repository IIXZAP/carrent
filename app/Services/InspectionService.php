<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Inspection;

/**
 * ตรวจสภาพรถก่อน–หลัง (ถ่ายรูปผูกกับใบจอง)
 *
 * ใช้เป็นหลักฐานเวลามีข้อพิพาทเรื่องรอยขีดข่วน น้ำมัน หรือเลขไมล์
 * รูปทุกใบผูกกับ "ใบจอง + รอบการตรวจ (ส่งมอบ/รับคืน)" และมีเวลาบันทึกกำกับ
 */
final class InspectionService
{
    /** มุมถ่ายมาตรฐาน 8 จุด — เรียงตามลำดับที่เดินรอบรถจริง */
    public static function positions(): array
    {
        return [
            'front'     => ['หน้ารถ',            'ถ่ายเต็มคันจากด้านหน้า ให้เห็นกันชนและไฟหน้า'],
            'right'     => ['ด้านขวา',           'ยืนห่างประมาณ 2 เมตร ให้เห็นประตูและล้อทั้ง 2 ข้าง'],
            'rear'      => ['ท้ายรถ',            'ให้เห็นกันชนท้าย ไฟท้าย และป้ายทะเบียน'],
            'left'      => ['ด้านซ้าย',          'ยืนห่างประมาณ 2 เมตร ให้เห็นประตูและล้อทั้ง 2 ข้าง'],
            'dashboard' => ['เลขไมล์ + น้ำมัน',  'ถ่ายหน้าปัดตอนเปิดสวิตช์ ให้อ่านเลขไมล์ได้ชัด'],
            'interior'  => ['ภายในห้องโดยสาร',   'ถ่ายจากประตูหน้า ให้เห็นเบาะและคอนโซล'],
            'tire'      => ['ยางและล้อ',         'ถ่ายล้อที่สภาพแย่ที่สุด ให้เห็นดอกยางและขอบกระทะ'],
            'damage'    => ['จุดที่มีตำหนิ',      'ถ่ายใกล้ทุกรอยที่พบ ถ้าไม่มีให้ข้ามได้'],
        ];
    }

    /** ป้ายชื่อรอบการตรวจ */
    public static function kindLabel(string $kind): string
    {
        return $kind === 'out' ? 'ตอนส่งมอบรถ' : 'ตอนรับคืนรถ';
    }

    /** ดึงทั้ง 2 รอบพร้อมกัน — ใช้ในหน้าเทียบก่อน–หลัง และในสัญญา */
    public static function both(int $bookingId): array
    {
        return ['out' => Inspection::get($bookingId, 'out'), 'in' => Inspection::get($bookingId, 'in')];
    }

    /** ผลตรวจรอบนี้ถูกล็อกแล้วหรือยัง — ล็อกแล้วเพิ่ม/เปลี่ยนรูปและแก้ตัวเลขไม่ได้อีก */
    public static function isLocked(?array $inspection): bool
    {
        return $inspection !== null && !empty($inspection['locked_at']);
    }

    /**
     * ผลตรวจรอบนี้ยังแก้ได้ตามขั้นตอนของการจองหรือไม่ — คืนข้อความเหตุผลถ้าปิดรอบแล้ว
     *   รอบส่งมอบ  แก้ได้ก่อนกดส่งมอบรถเท่านั้น
     *   รอบรับคืน   แก้ได้ระหว่างที่รถอยู่กับลูกค้า (ก่อนกดรับคืนรถ) เท่านั้น
     * กันกรณีส่งมอบรถโดยไม่ได้ถ่ายรูป แล้วมีคนย้อนมาถ่าย "สภาพรถวันส่งมอบ" ทีหลัง
     */
    public static function phaseBlock(array $booking, string $kind): ?string
    {
        $ok = $kind === 'out' ? ['pending', 'confirmed'] : ['picked_up'];
        if (in_array($booking['status'], $ok, true)) {
            return null;
        }
        if ($kind === 'in' && in_array($booking['status'], ['pending', 'confirmed'], true)) {
            return 'ยังไม่ได้ส่งมอบรถ — บันทึกสภาพรถตอนรับคืนได้หลังส่งมอบรถแล้ว';
        }
        return $kind === 'out' ? 'ส่งมอบรถไปแล้ว ผลตรวจรอบส่งมอบปิดแล้ว เพิ่มหรือแก้ไขไม่ได้'
                               : 'รับคืนรถไปแล้ว ผลตรวจรอบรับคืนปิดแล้ว เพิ่มหรือแก้ไขไม่ได้';
    }

    /** ล็อกผลตรวจ (บันทึกประวัติเมื่อเพิ่งล็อกจริง) */
    public static function lock(int $bookingId, string $kind): bool
    {
        $locked = Inspection::lock($bookingId, $kind, AuthService::id());
        if ($locked) {
            ActivityLog::log('inspection_locked', 'bookings', $bookingId, $kind);
        }
        return $locked;
    }

    /**
     * บันทึกรูป 1 ใบ — ถ้ามุมนั้นมีรูปอยู่แล้ว รูปเดิมจะถูกเก็บเป็นประวัติ ไม่ถูกลบ
     * @param array $file element จาก $_FILES
     * @return string|null path ของรูป หรือ null ถ้าไม่ได้แนบไฟล์
     */
    public static function savePhoto(int $inspectionId, string $position, array $file, ?string $caption = null): ?string
    {
        if (Inspection::isLockedRow($inspectionId)) {
            throw new \RuntimeException('ผลตรวจรอบนี้ถูกล็อกแล้ว เพิ่มหรือเปลี่ยนรูปไม่ได้');
        }
        $path = UploadService::save($file, 'inspections', 8);
        if (!$path) {
            return null;
        }
        // ย่อซ้ำฝั่งเซิร์ฟเวอร์ (ไม่ทำอะไรถ้าเบราว์เซอร์ย่อมาให้แล้ว)
        UploadService::shrink(rtrim(UPLOAD_DIR, '/') . '/' . ltrim($path, '/'));
        Inspection::addPhoto($inspectionId, $position, $path, $caption, AuthService::id());
        return $path;
    }

    /** จำนวนรูปที่ถ่ายแล้ว / ทั้งหมดที่ควรมี (ไม่นับ "จุดที่มีตำหนิ" ซึ่งข้ามได้) */
    public static function progress(?array $inspection): array
    {
        $required = count(self::positions()) - 1;   // damage ไม่บังคับ
        if (!$inspection) {
            return ['done' => 0, 'required' => $required, 'complete' => false];
        }
        $taken = [];
        foreach ($inspection['photos'] as $p) {
            if ($p['position'] !== 'damage') {
                $taken[$p['position']] = true;
            }
        }
        $done = count($taken);
        return ['done' => $done, 'required' => $required, 'complete' => $done >= $required];
    }

    /** จัดรูปเป็น map มุม => แถวรูป เพื่อให้หน้าเว็บหยิบใช้ง่าย */
    public static function photoMap(?array $inspection): array
    {
        $map = [];
        if ($inspection) {
            foreach ($inspection['photos'] as $p) {
                $map[$p['position']] = $p;
            }
        }
        return $map;
    }
}
