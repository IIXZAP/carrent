<?php
namespace App\Controllers\Admin;

use App\Models\Setting;
use App\Services\DeliveryFeeService;

/** ตั้งค่าร้าน (เฉพาะเจ้าของร้าน) — แก้ได้เฉพาะ key ที่ระบุไว้ที่นี่ */
final class SettingController
{
    /** key => [ป้าย, ชนิด, กลุ่ม] ชนิด: text | time | hours | days | km | percent | money | bool | tiers */
    public const FIELDS = [
        'phone'                    => ['เบอร์โทรร้าน', 'text', 'ติดต่อ / บัญชีรับโอน'],
        'bank_name'                => ['ธนาคาร', 'text', 'ติดต่อ / บัญชีรับโอน'],
        'bank_account_no'          => ['เลขที่บัญชี', 'text', 'ติดต่อ / บัญชีรับโอน'],
        'bank_account_name'        => ['ชื่อบัญชี', 'text', 'ติดต่อ / บัญชีรับโอน'],
        'promptpay'                => ['พร้อมเพย์ (เบอร์/เลขผู้เสียภาษี)', 'text', 'ติดต่อ / บัญชีรับโอน'],
        'open_time'                => ['ร้านเปิด (HH:MM)', 'time', 'เวลาและกติกาการจอง'],
        'close_time'               => ['ร้านปิด (HH:MM)', 'time', 'เวลาและกติกาการจอง'],
        'booking_lead_hours'       => ['จองล่วงหน้าอย่างน้อย (ชม.)', 'hours', 'เวลาและกติกาการจอง'],
        'booking_gap_hours'        => ['เวลาพักรถระหว่างงาน (ชม.)', 'hours', 'เวลาและกติกาการจอง'],
        'min_rental_days'          => ['เช่าขั้นต่ำ (วัน)', 'days', 'เวลาและกติกาการจอง'],
        'late_grace_hours'         => ['ผ่อนผันคืนช้า (ชม.)', 'hours', 'เวลาและกติกาการจอง'],
        'pending_expire_hours'     => ['ยกเลิกอัตโนมัติถ้าไม่จ่ายมัดจำภายใน (ชม., 0 = ปิด — ต้องตั้ง cron ด้วย)', 'hours', 'เวลาและกติกาการจอง'],
        'booking_deposit'          => ['มัดจำจองเริ่มต้น (บาท)', 'money', 'เงิน'],
        'security_deposit_default' => ['เงินประกันเริ่มต้น (บาท)', 'money', 'เงิน'],
        'vat_enabled'              => ['คิด VAT', 'bool', 'เงิน'],
        'vat_rate'                 => ['อัตรา VAT (%)', 'percent', 'เงิน'],
        'late_full_day_hours'      => ['คืนช้าเกินกี่ชั่วโมงคิดเป็นค่าเช่า 1 วันเต็ม', 'hours', 'ค่าปรับตอนรับคืนรถ'],
        'late_fee_per_hour'        => ['ค่าปรับคืนช้า ชั่วโมงละ (บาท)', 'money', 'ค่าปรับตอนรับคืนรถ'],
        'fuel_fee_per_level'       => ['ค่าน้ำมันที่ขาด ขีดละ (บาท)', 'money', 'ค่าปรับตอนรับคืนรถ'],
        'free_km_per_day'          => ['ระยะทางที่รวมในค่าเช่า กม./วัน (0 = ไม่จำกัดระยะ)', 'km', 'ค่าปรับตอนรับคืนรถ'],
        'over_km_fee'              => ['ค่ากิโลเมตรที่เกิน กม.ละ (บาท)', 'money', 'ค่าปรับตอนรับคืนรถ'],
        'free_delivery_km'         => ['ส่งรถฟรีในระยะ (กม.)', 'km', 'ค่าส่งรถ'],
        'delivery_tiers'           => ['ตารางค่าส่ง "ถึงกม.:ราคา" คั่นด้วย , เช่น 30:0,50:500,80:1000', 'tiers', 'ค่าส่งรถ'],
    ];

    public function index(): void
    {
        view('admin/settings', ['title' => 'ตั้งค่าร้าน', 'fields' => self::FIELDS, 'values' => Setting::all(), 'errors' => []], 'layouts/admin');
    }

    public function save(): void
    {
        $clean = [];
        $errors = [];
        foreach (self::FIELDS as $key => [$label, $type]) {
            $raw = $type === 'bool' ? (isset($_POST[$key]) ? '1' : '0') : post($key);
            $error = self::validate($type, $raw);
            if ($error !== null) {
                $errors[$key] = $error;
            }
            $clean[$key] = $type === 'money' ? str_replace(',', '', $raw) : $raw;
        }
        if ($errors !== []) {
            view('admin/settings', ['title' => 'ตั้งค่าร้าน', 'fields' => self::FIELDS, 'values' => $clean, 'errors' => $errors], 'layouts/admin');
            return;
        }
        foreach ($clean as $key => $value) {
            if ((string) Setting::get($key) !== $value) {
                Setting::set($key, $value);
            }
        }
        flash('success', 'บันทึกการตั้งค่าแล้ว');
        redirect('/admin/settings');
    }

    private static function intInRange(string $value, int $min, int $max): bool
    {
        return ctype_digit($value) && (int) $value >= $min && (int) $value <= $max;
    }

    /** ตรวจค่าตามชนิด — null = ผ่าน */
    public static function validate(string $type, string $value): ?string
    {
        switch ($type) {
            case 'text':
                return mb_strlen($value) <= 200 ? null : 'ยาวเกินไป';
            case 'time':
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $value) === 1 ? null : 'รูปแบบเวลาต้องเป็น HH:MM (00:00–24:00)';
            case 'hours':
                return self::intInRange($value, 0, 720) ? null : 'ต้องเป็นจำนวนเต็ม 0–720 ชั่วโมง';
            case 'days':
                return self::intInRange($value, 1, 365) ? null : 'ต้องเป็นจำนวนเต็ม 1–365 วัน';
            case 'km':
                return self::intInRange($value, 0, 2000) ? null : 'ต้องเป็นจำนวนเต็ม 0–2,000 กม.';
            case 'percent':
                return preg_match('/^\d+(\.\d{1,2})?$/', $value) === 1 && (float) $value <= 100 ? null : 'ต้องเป็นตัวเลข 0–100';
            case 'money':
                $clean = str_replace(',', '', $value);
                return preg_match('/^\d+(\.\d{1,2})?$/', $clean) === 1 && (float) $clean <= 9999999.99 ? null : 'จำนวนเงินไม่ถูกต้อง';
            case 'bool':
                return null;
            case 'tiers':
                return $value !== '' && DeliveryFeeService::parseTiers($value) !== [] ? null : 'รูปแบบไม่ถูกต้อง (ตัวอย่าง 30:0,50:500)';
        }
        return 'ชนิดไม่รู้จัก';
    }
}
