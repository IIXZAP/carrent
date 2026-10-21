<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Services\DeliveryFeeService;

/** ตั้งค่าระบบ — ข้อมูลร้าน สี บัญชีรับโอน เงื่อนไขเช่า แจ้งเตือน */
class SettingController extends AdminController
{
    /** คีย์ตั้งค่าที่แก้ได้จากหน้านี้ */
    private const KEYS = [
        // ข้อมูลร้าน
        'site_name', 'site_tagline', 'company_name', 'company_tax_id', 'company_address',
        'phone', 'phone2', 'office_phone', 'email', 'line_id', 'facebook',
        // แบรนด์
        'brand_primary', 'brand_accent', 'brand_dark',
        // การเงิน
        'vat_enabled', 'vat_rate', 'wht_rate', 'deposit_percent', 'security_deposit_default',
        'bank_name', 'bank_account_no', 'bank_account_name', 'promptpay_id',
        // เงื่อนไขเช่า
        'min_rental_days', 'booking_lead_hours', 'late_fee_per_hour', 'free_km_per_day', 'over_km_fee', 'open_time', 'close_time',
        'late_grace_hours', 'late_full_day_hours', 'fuel_fee_per_level', 'pending_expire_hours',
        'booking_gap_hours', 'airport_open_time', 'airport_close_time',
        'booking_deposit', 'free_delivery_km', 'delivery_tiers',
        // แจ้งเตือน
        'notify_email_enabled', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure',
        'smtp_from_name', 'notify_admin_email', 'reminder_days_before', 'cron_key',
    ];

    private const CHECKBOXES = ['vat_enabled', 'notify_email_enabled'];
    private const COLORS     = ['brand_primary', 'brand_accent', 'brand_dark'];

    public function index(): Response
    {
        return $this->view('admin/settings/index', [
            'title'     => 'ตั้งค่าระบบ',
            's'         => settings(),
            'tiersText' => DeliveryFeeService::tiersText(),
        ]);
    }

    public function update(): Response
    {
        $r = $this->request;

        if ($r->str('act') === 'test_notify') {
            $msg = '🔔 ทดสอบการแจ้งเตือนจาก ' . setting('site_name') . ' เวลา ' . date('H:i:s');
            NotificationService::queue('line', setting('line_admin_user_id'), $msg, 'ทดสอบ');
            NotificationService::queue('email', setting('notify_admin_email'), $msg, 'ทดสอบการแจ้งเตือน');
            $n = NotificationService::flush(5);
            return $this->done('admin/settings', $n > 0 ? 'ok' : 'warn',
                $n > 0 ? "ส่งทดสอบสำเร็จ $n ข้อความ" : 'ส่งไม่สำเร็จ: ' . (Notification::lastFailedError() ?? 'ตรวจสอบการเปิดใช้งานช่องทาง'));
        }

        foreach (self::KEYS as $k) {
            if (in_array($k, self::CHECKBOXES, true)) {
                set_setting($k, $r->has($k) ? '1' : '0');
            } elseif ($r->has($k)) {
                $v = trim((string) $r->post($k, ''));
                // ไม่ล้างรหัสผ่าน SMTP ถ้าปล่อยว่าง
                if ($k === 'smtp_pass' && $v === '') {
                    continue;
                }
                // สีต้องเป็นรหัส hex เท่านั้น (ค่านี้ถูกฝังลงใน CSS ของทุกหน้า)
                if (in_array($k, self::COLORS, true) && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                    continue;
                }
                set_setting($k, $v);
            }
        }
        ActivityLog::log('settings_updated');
        return $this->done('admin/settings', 'ok', 'บันทึกการตั้งค่าแล้ว');
    }
}
