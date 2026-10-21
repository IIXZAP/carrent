<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\LineEvent;
use App\Models\Notification;
use App\Services\LineService;

/** เชื่อมต่อ LINE Official Account — ตั้งค่า ทดสอบ แก้ข้อความแจ้งเตือน ติดตั้งเมนู */
class LineController extends AdminController
{
    public function index(): Response
    {
        // ที่อยู่เว็บเต็ม — งาน cron ไม่รู้ชื่อโดเมนเอง จึงจำไว้ตั้งแต่ครั้งแรกที่เจ้าของร้านเปิดหน้านี้
        if (trim((string) setting('site_url')) === '' && $this->request->host() !== '') {
            set_setting('site_url', site_url());
        }

        $s         = settings();
        $hasToken  = trim((string) ($s['line_channel_token'] ?? '')) !== '';
        $hasSecret = trim((string) ($s['line_channel_secret'] ?? '')) !== '';
        $liffId    = trim((string) ($s['line_liff_id'] ?? ''));
        $adminIds  = preg_split('/[\s,;]+/', (string) ($s['line_admin_user_id'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $enabled   = ($s['notify_line_enabled'] ?? '0') === '1';
        $webhookUrl = site_url('line/webhook');

        $steps = [
            ['ใส่ Channel Access Token', $hasToken],
            ['ใส่ Channel Secret', $hasSecret],
            ['ใส่ LIFF ID', $liffId !== ''],
            ['เลือกผู้รับแจ้งเตือนของร้าน', (bool) $adminIds],
            ['เปิดใช้งานการแจ้งเตือน', $enabled],
            ['ติดตั้งเมนูด้านล่าง', trim((string) ($s['line_richmenu_id'] ?? '')) !== ''],
        ];

        // ตัวอย่างข้อความ: ใช้การจองล่าสุดถ้ามี ไม่มีก็ใช้ข้อมูลสมมติ
        $sample = \App\Core\Database::one('SELECT * FROM bookings ORDER BY id DESC LIMIT 1');
        $more = ['รายการ' => "ค่าใช้จ่ายเพิ่ม 800 บาท\n• คืนรถช้า 3 ชม. 200 บาท\n• น้ำมันขาด 2 ขีด 600 บาท",
                 'คืนเงิน' => '2,200', 'หัก' => '800', 'ลิงก์เซ็น' => site_url() . '/sign?no=…', 'เอกสาร' => 'สัญญาเช่ารถ'];
        $sampleVars = $sample
            ? LineService::bookingVars($sample, null, $more + ['จำนวน' => money0($sample['deposit_amount']), 'เหตุผล' => 'ยอดเงินในสลิปไม่ตรง'])
            : ['ชื่อ' => 'สมชาย ใจดี', 'เลขจอง' => 'BK' . date('ymd') . '-001', 'รถ' => 'Toyota Fortuner', 'ทะเบียน' => '1กข 1234',
               'วันรับ' => thai_date(date('Y-m-d 10:00', strtotime('+2 days')), true),
               'วันคืน' => thai_date(date('Y-m-d 10:00', strtotime('+5 days')), true),
               'จุดรับ' => 'ที่ร้าน', 'จุดคืน' => 'ที่ร้าน', 'ยอดรวม' => '6,600', 'มัดจำ' => '1,000', 'คงเหลือ' => '5,600',
               'เงินประกัน' => '10,000', 'จำนวน' => '1,000', 'เหตุผล' => 'ยอดเงินในสลิปไม่ตรง',
               'ร้าน' => (string) setting('site_name'), 'โทร' => (string) setting('phone'), 'ลิงก์' => LineService::liffUrl()] + $more;

        $templates = LineService::templates();
        $tplView = [];
        foreach ($templates as $key => [$label, $when, $default]) {
            $custom  = trim((string) ($s['tpl_' . $key] ?? ''));
            $current = $custom !== '' ? $custom : $default;
            $tplView[$key] = [
                'label'   => $label,
                'when'    => $when,
                'custom'  => $custom !== '',
                'current' => $current,
                'on'      => !LineService::templateOff($key),
                'preview' => LineService::fill($current, $sampleVars),
            ];
        }

        return $this->view('admin/line', [
            'title'      => 'เชื่อมต่อ LINE',
            's'          => $s,
            'hasToken'   => $hasToken,
            'liffId'     => $liffId,
            'adminIds'   => $adminIds,
            'enabled'    => $enabled,
            'custOn'     => ($s['line_notify_customer'] ?? '1') === '1',
            'webhookUrl' => $webhookUrl,
            'liffEndUrl' => site_url('liff'),
            'isHttps'    => str_starts_with($webhookUrl, 'https://'),
            'steps'      => $steps,
            'doneSteps'  => count(array_filter(array_column($steps, 1))),
            'linked'     => Booking::linkedLineAccounts(),
            'sentMonth'  => Notification::lineSentSince(date('Y-m-01 00:00:00')),
            'failed7'    => Notification::lineFailedLast7Days(),
            'people'     => LineEvent::latestPeople(12),
            'logs'       => Notification::recentLine(12),
            'tplView'    => $tplView,
            'tplVars'    => LineService::templateVars(),
        ]);
    }

    /** act = save | test | test_push | add_admin | remove_admin | tpl_save | tpl_reset | richmenu */
    public function update(): Response
    {
        $r   = $this->request;
        $act = $r->str('act');

        switch ($act) {
            case 'save':
                // ช่องรหัสลับ: เว้นว่าง = คงค่าเดิม (ไม่แสดงค่าเดิมกลับมาบนหน้าเว็บ)
                foreach (['line_channel_token', 'line_channel_secret'] as $k) {
                    $v = $r->str($k);
                    if ($v !== '') {
                        set_setting($k, $v);
                    }
                }
                set_setting('line_liff_id', $r->str('line_liff_id'));
                set_setting('line_admin_user_id', implode(',', preg_split('/[\s,;]+/', (string) $r->post('line_admin_user_id', ''), -1, PREG_SPLIT_NO_EMPTY)));
                set_setting('notify_line_enabled', $r->has('notify_line_enabled') ? '1' : '0');
                set_setting('line_notify_customer', $r->has('line_notify_customer') ? '1' : '0');
                $su = rtrim($r->str('site_url'), '/');
                if ($su === '' || preg_match('#^https?://[^\s/]+#i', $su)) {
                    set_setting('site_url', $su);
                }
                ActivityLog::log('line_settings_updated');
                $this->flash('ok', 'บันทึกการตั้งค่า LINE แล้ว');
                break;

            case 'test':
                [$ok, $info, $err] = LineService::api('GET', '/v2/bot/info');
                if ($ok) {
                    $msg = 'เชื่อมต่อสำเร็จ — บัญชี "' . ($info['displayName'] ?? '-') . '" (' . ($info['basicId'] ?? '-') . ')';
                    [$qOk, $q] = LineService::api('GET', '/v2/bot/message/quota');
                    [$cOk, $c] = LineService::api('GET', '/v2/bot/message/quota/consumption');
                    if ($qOk && $cOk) {
                        $msg .= ' · เดือนนี้ส่งไปแล้ว ' . number_format((int) ($c['totalUsage'] ?? 0)) . ' ข้อความ'
                              . (($q['type'] ?? '') === 'limited' ? ' จากโควตา ' . number_format((int) ($q['value'] ?? 0)) : ' (ไม่จำกัดโควตา)');
                    }
                    $this->flash('ok', $msg);
                } else {
                    $this->flash('danger', 'เชื่อมต่อไม่สำเร็จ — ' . $err);
                }
                break;

            case 'test_push':
                [$ok, $err] = LineService::pushTo((string) setting('line_admin_user_id'),
                    '🔔 ทดสอบการแจ้งเตือนจาก ' . setting('site_name') . ' เวลา ' . date('H:i:s') . ' น.');
                $this->flash($ok ? 'ok' : 'danger', $ok ? 'ส่งข้อความทดสอบถึงผู้รับแจ้งเตือนของร้านแล้ว' : 'ส่งไม่สำเร็จ — ' . $err);
                break;

            case 'add_admin':
            case 'remove_admin':
                $uid = $r->str('uid');
                $ids = preg_split('/[\s,;]+/', (string) setting('line_admin_user_id'), -1, PREG_SPLIT_NO_EMPTY);
                if (preg_match('/^[UCR][0-9a-f]{32}$/', $uid)) {
                    $ids = $act === 'add_admin' ? array_unique(array_merge($ids, [$uid])) : array_diff($ids, [$uid]);
                    set_setting('line_admin_user_id', implode(',', $ids));
                    ActivityLog::log('line_admin_' . ($act === 'add_admin' ? 'added' : 'removed'), null, null, $uid);
                    $this->flash('ok', $act === 'add_admin' ? 'เพิ่มเป็นผู้รับแจ้งเตือนของร้านแล้ว' : 'เอาออกจากผู้รับแจ้งเตือนแล้ว');
                } else {
                    $this->flash('danger', 'LINE user id ไม่ถูกรูปแบบ');
                }
                break;

            case 'tpl_save':
                $off  = [];
                $post = (array) $r->arr('tpl');
                $on   = (array) $r->arr('on');
                foreach (LineService::templates() as $key => [, , $default]) {
                    $text = str_replace("\r\n", "\n", trim((string) ($post[$key] ?? '')));
                    // ตรงกับข้อความตั้งต้น หรือเว้นว่าง → ไม่เก็บ เพื่อให้ได้ข้อความตั้งต้นรุ่นใหม่เมื่ออัปเดตระบบ
                    set_setting('tpl_' . $key, ($text === '' || $text === $default) ? '' : $text);
                    if (empty($on[$key])) {
                        $off[] = $key;
                    }
                }
                set_setting('tpl_off', implode(',', $off));
                ActivityLog::log('line_templates_updated');
                $this->flash('ok', 'บันทึกข้อความแจ้งเตือนแล้ว');
                break;

            case 'tpl_reset':
                $key = $r->str('key');
                $templates = LineService::templates();
                if (isset($templates[$key])) {
                    set_setting('tpl_' . $key, '');
                    $this->flash('ok', 'คืนข้อความ "' . $templates[$key][0] . '" เป็นค่าตั้งต้นแล้ว');
                }
                break;

            case 'richmenu':
                [$ok, $msg] = LineService::installRichMenu();
                ActivityLog::log('line_richmenu', null, null, $msg);
                $this->flash($ok ? 'ok' : 'danger', $msg);
                break;
        }
        return $this->redirect('admin/line' . (in_array($act, ['tpl_save', 'tpl_reset'], true) ? '#templates' : ''));
    }
}
