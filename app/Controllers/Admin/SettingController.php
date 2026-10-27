<?php
namespace App\Controllers\Admin;

use App\Models\Setting;
use App\Services\DeliveryFeeService;

/** ตั้งค่าร้าน (เฉพาะเจ้าของร้าน) — แก้ได้เฉพาะ key ที่ระบุไว้ที่นี่ */
final class SettingController
{
    /** key => [ป้าย, ชนิด, กลุ่ม] ชนิด: text | time | int | money | bool | tiers */
    public const FIELDS = [
        'phone'                    => ['เบอร์โทรร้าน', 'text', 'ติดต่อ / บัญชีรับโอน'],
        'bank_name'                => ['ธนาคาร', 'text', 'ติดต่อ / บัญชีรับโอน'],
        'bank_account_no'          => ['เลขที่บัญชี', 'text', 'ติดต่อ / บัญชีรับโอน'],
        'bank_account_name'        => ['ชื่อบัญชี', 'text', 'ติดต่อ / บัญชีรับโอน'],
        'promptpay'                => ['พร้อมเพย์ (เบอร์/เลขผู้เสียภาษี)', 'text', 'ติดต่อ / บัญชีรับโอน'],
        'open_time'                => ['ร้านเปิด (HH:MM)', 'time', 'เวลาและกติกาการจอง'],
        'close_time'               => ['ร้านปิด (HH:MM)', 'time', 'เวลาและกติกาการจอง'],
        'booking_lead_hours'       => ['จองล่วงหน้าอย่างน้อย (ชม.)', 'int', 'เวลาและกติกาการจอง'],
        'booking_gap_hours'        => ['เวลาพักรถระหว่างงาน (ชม.)', 'int', 'เวลาและกติกาการจอง'],
        'min_rental_days'          => ['เช่าขั้นต่ำ (วัน)', 'int', 'เวลาและกติกาการจอง'],
        'late_grace_hours'         => ['ผ่อนผันคืนช้า (ชม.)', 'int', 'เวลาและกติกาการจอง'],
        'booking_deposit'          => ['มัดจำจองเริ่มต้น (บาท)', 'money', 'เงิน'],
        'security_deposit_default' => ['เงินประกันเริ่มต้น (บาท)', 'money', 'เงิน'],
        'vat_enabled'              => ['คิด VAT', 'bool', 'เงิน'],
        'vat_rate'                 => ['อัตรา VAT (%)', 'money', 'เงิน'],
        'free_delivery_km'         => ['ส่งรถฟรีในระยะ (กม.)', 'int', 'ค่าส่งรถ'],
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

    /** ตรวจค่าตามชนิด — null = ผ่าน */
    public static function validate(string $type, string $value): ?string
    {
        switch ($type) {
            case 'text':
                return mb_strlen($value) <= 200 ? null : 'ยาวเกินไป';
            case 'time':
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $value) === 1 ? null : 'รูปแบบเวลาต้องเป็น HH:MM (00:00–24:00)';
            case 'int':
                return ctype_digit($value) && (int) $value <= 1000 ? null : 'ต้องเป็นจำนวนเต็มไม่ติดลบ';
            case 'money':
                $clean = str_replace(',', '', $value);
                return preg_match('/^\d+(\.\d{1,2})?$/', $clean) === 1 && (float) $clean <= 99999999 ? null : 'จำนวนเงินไม่ถูกต้อง';
            case 'bool':
                return null;
            case 'tiers':
                return $value !== '' && DeliveryFeeService::parseTiers($value) !== [] ? null : 'รูปแบบไม่ถูกต้อง (ตัวอย่าง 30:0,50:500)';
        }
        return 'ชนิดไม่รู้จัก';
    }
}
