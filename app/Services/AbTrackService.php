<?php

namespace App\Services;

use App\Models\AbEvent;

/**
 * เก็บสถิติเทียบ UX สองเวอร์ชัน (A = ดีไซน์เดิม, B = ดีไซน์ใหม่)
 *
 * หลักการ: เทียบดีไซน์ด้วยความรู้สึกไม่ได้ ต้องดูว่าคนหลุดตรงไหน
 * จึงเก็บแค่ 5 จุดในกรวยการจอง ไม่เก็บข้อมูลส่วนบุคคลใดๆ
 *
 *   view_home   → เข้าหน้าแรก
 *   view_car    → เปิดดูรายละเอียดรถ (เริ่มสนใจจริง)
 *   start_form  → แตะช่องแรกในฟอร์มจอง (ตั้งใจจอง)
 *   submit      → กดยืนยันการจอง
 *   booked      → จองสำเร็จ
 *
 * ทั้งสองเวอร์ชันเขียนลงตารางเดียวกันในฐานข้อมูลของตัวเอง แล้วหน้า เทียบผล UX ดึงมารวมเทียบกัน
 */
final class AbTrackService
{
    /** ไอดีผู้เยี่ยมชมแบบไม่ระบุตัวตน — สุ่มขึ้นมา ไม่ผูกกับคนจริง */
    public static function visitor(): string
    {
        if (empty($_SESSION['ab_vid'])) {
            $_SESSION['ab_vid'] = bin2hex(random_bytes(8));
        }
        return $_SESSION['ab_vid'];
    }

    /** บันทึก 1 เหตุการณ์ — ล้มเหลวเงียบๆ ไม่ให้กระทบหน้าเว็บ */
    public static function track(string $step, ?int $carId = null): void
    {
        if (!defined('SITE_VARIANT')) {
            return;
        }
        try {
            $ua     = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            $device = preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 'mobile' : 'desktop';
            AbEvent::insert([
                'variant'    => SITE_VARIANT,
                'step'       => $step,
                'visitor_id' => self::visitor(),
                'car_id'     => $carId,
                'device'     => $device,
            ]);
        } catch (\Throwable $e) {
            // สถิติพังไม่ควรทำให้ลูกค้าจองไม่ได้
        }
    }

    /** กันนับซ้ำ — 1 คน 1 ขั้น นับครั้งเดียวต่อเซสชัน */
    public static function trackOnce(string $step, ?int $carId = null): void
    {
        $key = 'ab_done_' . $step . ($carId ? '_' . $carId : '');
        if (!empty($_SESSION[$key])) {
            return;
        }
        $_SESSION[$key] = 1;
        self::track($step, $carId);
    }

    /** ชื่อขั้นตอนเป็นภาษาไทย */
    public static function stepLabel(string $step): string
    {
        return [
            'view_home'  => 'เข้าหน้าแรก',
            'view_car'   => 'เปิดดูรถ',
            'start_form' => 'เริ่มกรอกฟอร์ม',
            'submit'     => 'กดยืนยัน',
            'booked'     => 'จองสำเร็จ',
        ][$step] ?? $step;
    }
}
