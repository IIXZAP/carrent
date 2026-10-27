<?php
namespace App\Controllers\Admin;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Setting;
use App\Services\Auth;
use App\Services\LineClient;
use App\Services\NotificationService;

/** ตั้งค่าแจ้งเตือน LINE ของร้าน (เฉพาะเจ้าของร้าน) */
final class LineController
{
    public function index(): void
    {
        $this->render([], null);
    }

    public function save(): void
    {
        $errors = [];
        $enabled = isset($_POST['notify_line_enabled']);
        $newToken = post('line_channel_token');
        $token = Setting::get('line_channel_token');
        if (isset($_POST['clear_token'])) {
            $token = '';
        } elseif ($newToken !== '') {
            if (!preg_match('/^[\x21-\x7E]{20,600}\z/', $newToken)) {
                $errors[] = 'Channel access token ต้องเป็นอักษรอังกฤษ/ตัวเลข/สัญลักษณ์ ความยาว 20–600 ตัว และห้ามมีช่องว่าง';
            } else {
                $token = $newToken;
            }
        }

        $rawIds = (string) ($_POST['line_admin_user_id'] ?? '');
        $all = preg_split('/[\s,;]+/', $rawIds, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $bad = array_values(array_filter($all, static fn(string $id): bool => !LineClient::validTarget($id)));
        if ($bad !== []) {
            $errors[] = 'รหัสผู้รับไม่ถูกต้อง: ' . implode(', ', array_map(static fn(string $b): string => mb_substr($b, 0, 40), array_slice($bad, 0, 3)))
                . ' — ต้องขึ้นต้นด้วย U (ผู้ใช้) / C (กลุ่ม) / R (ห้อง) ตามด้วยตัวเลข-ตัวอักษร a–f รวม 33 ตัว';
        }
        $ids = NotificationService::recipients($rawIds);
        if (count($all) > NotificationService::RECIPIENT_LIMIT) {
            $errors[] = 'ใส่ผู้รับได้ไม่เกิน ' . NotificationService::RECIPIENT_LIMIT . ' รายการ';
        }
        if ($enabled && $token === '') {
            $errors[] = 'ต้องใส่ Channel access token ก่อนเปิดการแจ้งเตือน';
        }
        if ($enabled && $ids === []) {
            $errors[] = 'ต้องใส่รหัสผู้รับอย่างน้อย 1 รายการก่อนเปิดการแจ้งเตือน';
        }

        if ($errors !== []) {
            $this->render($errors, [
                'notify_line_enabled' => $enabled ? '1' : '0',
                'line_admin_user_id'  => $rawIds,
                'notify_new_booking'  => isset($_POST['notify_new_booking']) ? '1' : '0',
                'notify_new_slip'     => isset($_POST['notify_new_slip']) ? '1' : '0',
                'notify_signed'       => isset($_POST['notify_signed']) ? '1' : '0',
            ]);
            return;
        }

        Setting::set('line_channel_token', $token);
        Setting::set('line_admin_user_id', implode("\n", $ids));
        Setting::set('notify_line_enabled', $enabled ? '1' : '0');
        foreach (['notify_new_booking', 'notify_new_slip', 'notify_signed'] as $key) {
            Setting::set($key, isset($_POST[$key]) ? '1' : '0');
        }
        ActivityLog::log('line_settings', null, null, $enabled ? 'enabled' : 'disabled', (int) Auth::user()['id'], client_ip());
        flash('success', 'บันทึกการตั้งค่า LINE แล้ว');
        redirect('/admin/line');
    }

    public function test(): void
    {
        $ids = NotificationService::recipients();
        if (Setting::get('line_channel_token') === '' || $ids === []) {
            flash('error', 'ต้องบันทึก token และรหัสผู้รับก่อนจึงจะทดสอบได้');
            redirect('/admin/line');
        }
        $problems = [];
        foreach ($ids as $to) {
            [$ok, $err] = NotificationService::sendTest($to, (int) Auth::user()['id']);
            if (!$ok) {
                $problems[] = substr($to, 0, 6) . '…: ' . $err;
            }
        }
        if ($problems === []) {
            flash('success', 'ส่งข้อความทดสอบถึง ' . count($ids) . ' ผู้รับแล้ว — ตรวจใน LINE ว่าได้รับ');
        } else {
            flash('error', 'ส่งไม่สำเร็จ ' . count($problems) . '/' . count($ids) . ' ราย: ' . implode(' | ', $problems));
        }
        redirect('/admin/line');
    }

    public function retry(string $id): void
    {
        if (Notification::requeueFailed((int) $id)) {
            NotificationService::flush(5, 15.0);
            flash('success', 'ส่งใหม่แล้ว ดูผลในตารางด้านล่าง');
        } else {
            flash('error', 'รายการนี้ไม่ได้อยู่ในสถานะส่งไม่สำเร็จ');
        }
        redirect('/admin/line');
    }

    /** ส่งรายการที่ค้างในคิวตอนนี้ (ปกติ cron ทำให้) */
    public function flush(): void
    {
        $r = NotificationService::flush(20, 20.0);
        flash('success', "ส่งสำเร็จ {$r['sent']} · รอลองใหม่ {$r['retry']} · ไม่สำเร็จ {$r['failed']}");
        redirect('/admin/line');
    }

    private function render(array $errors, ?array $override): void
    {
        $token = Setting::get('line_channel_token');
        view('admin/line', [
            'title'     => 'แจ้งเตือน LINE',
            'errors'    => $errors,
            'v'         => $override ?? Setting::all(),
            'tokenHint' => $token === '' ? null : '••••' . substr($token, -4),
            'counts'    => Notification::countByStatus(),
            'recent'    => Notification::recent(40),
            'hasCurl'   => function_exists('curl_init'),
        ], 'layouts/admin');
    }
}
