<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\BookingService;
use App\Services\LineService;
use App\Services\PaymentService;
use App\Services\UploadService;

/**
 * หน้า "การจองของฉัน" ในแอป LINE (LIFF)
 *
 *   GET  /liff        หน้าเว็บ (ใส่เป็น Endpoint URL ของ LIFF app)
 *   POST /liff/api    API ตอบเป็น JSON ทั้งหมด
 *     ?act=session   ยืนยันตัวตนด้วย ID token จาก LINE แล้วคืนสถานะปัจจุบัน
 *     ?act=state     ขอข้อมูลล่าสุด
 *     ?act=link      ผูกบัญชีด้วย เลขที่จอง + เบอร์โทร
 *     ?act=slip      แจ้งโอนเงินพร้อมแนบสลิป
 *     ?act=unlink    ยกเลิกการผูกบัญชี
 *
 * หลักความปลอดภัย: LINE user id มาจากการตรวจ ID token กับเซิร์ฟเวอร์ LINE เท่านั้น
 * ไม่รับ user id ที่หน้าเว็บส่งมาเอง และลูกค้าเห็นได้เฉพาะการจองของตัวเอง
 */
class LiffController extends Controller
{
    public function index(): Response
    {
        $res = $this->view('public/liff', [
            'title'   => 'การจองของฉัน',
            'heading' => 'การจองของฉัน',
            'who'     => true,
            'narrow'  => 'max-w-[560px]',
            'liffId'  => trim((string) setting('line_liff_id')),
        ]);
        return $res->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function api(): Response
    {
        $r = $this->request;

        // ไฟล์ใหญ่เกิน post_max_size → PHP ทิ้งคำขอทั้งก้อน ต้องบอกสาเหตุให้ชัด
        if ($r->postTooLarge()) {
            return $this->fail('ไฟล์สลิปใหญ่เกินไป (รับได้ไม่เกิน ' . round(UploadService::limitBytes() / 1048576, 1)
                . ' MB) กรุณาใช้ภาพหน้าจอสลิปแทนรูปถ่ายจากกล้อง', 413);
        }
        if (!$r->isPost()) {
            return $this->fail('POST only', 405);
        }

        $act = $r->query('act', '');

        if ($act === 'session') {
            return $this->session();
        }

        // คำขออื่นทั้งหมดต้องยืนยันตัวตนแล้ว + มี CSRF token
        $uid = (string) ($_SESSION['liff']['uid'] ?? '');
        if ($uid === '') {
            return $this->fail('เซสชันหมดอายุ กรุณาเปิดหน้านี้ใหม่', 401);
        }
        if (!Csrf::valid($r)) {
            return $this->fail('หน้าเว็บหมดอายุ กรุณารีเฟรชแล้วลองใหม่', 419);
        }

        return match ($act) {
            'state'  => $this->json($this->state()),
            'link'   => $this->link($uid),
            'unlink' => $this->unlink($uid),
            'slip'   => $this->slip($uid),
            default  => $this->fail('ไม่รู้จักคำสั่งนี้', 404),
        };
    }

    // ------------------------------------------------------------

    private function fail(string $message, int $status = 400): Response
    {
        return $this->json(['ok' => false, 'error' => $message], $status)->header('Cache-Control', 'no-store');
    }

    private function out(array $data): Response
    {
        return $this->json($data)->header('Cache-Control', 'no-store');
    }

    /** เข้าสู่ระบบด้วย ID token */
    private function session(): Response
    {
        // คำขอเข้าสู่ระบบต้องมาจากหน้าเว็บของร้านเองเท่านั้น
        // ถ้าไม่ตรวจ เว็บอื่นสามารถสั่งเบราว์เซอร์ของลูกค้าให้ "เข้าสู่ระบบด้วย LINE ของคนอื่น" อย่างเงียบๆ
        // แล้วการจองที่ลูกค้าทำต่อจากนั้นจะไปผูกกับ LINE ของคนนั้น
        $origin = $this->request->header('Origin');
        $site   = $this->request->header('Sec-Fetch-Site');
        $oHost  = '';
        if ($origin !== '') {
            $oHost = (string) parse_url($origin, PHP_URL_HOST);
            if (parse_url($origin, PHP_URL_PORT)) {
                $oHost .= ':' . parse_url($origin, PHP_URL_PORT);
            }
        }
        if (($origin !== '' && strcasecmp($oHost, $this->request->host()) !== 0)
            || ($site !== '' && !in_array($site, ['same-origin', 'none'], true))) {
            return $this->fail('คำขอไม่ได้มาจากหน้าเว็บของร้าน', 403);
        }
        if (trim((string) setting('line_liff_id')) === '') {
            return $this->fail('ร้านยังไม่ได้เปิดใช้งานส่วนนี้', 503);
        }
        $info = LineService::verifyIdToken((string) $this->request->post('id_token', ''));
        if (!$info) {
            return $this->fail('ยืนยันตัวตนกับ LINE ไม่สำเร็จ กรุณาปิดแล้วเปิดหน้านี้ใหม่', 401);
        }
        Session::regenerate();
        $_SESSION['liff'] = ['uid' => $info['sub'], 'name' => $info['name'], 'picture' => $info['picture']];
        return $this->out($this->state());
    }

    /** ผูกบัญชี: ต้องรู้ทั้งเลขที่จองและเบอร์โทรที่ใช้จอง */
    private function link(string $uid): Response
    {
        // กันเดาสุ่ม: ผิดเกิน 6 ครั้งใน 15 นาที พักก่อน / นับตาม IP ด้วยเพื่อกันการสลับบัญชี LINE
        $fails   = ActivityLog::liffFailsByUser($uid);
        $ipFails = ActivityLog::liffFailsByIp($this->request->ip());
        if ($fails >= 6 || $ipFails >= 20) {
            return $this->fail('ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที หรือติดต่อร้าน ' . setting('phone'), 429);
        }

        $no    = strtoupper($this->request->str('booking_no'));
        $phone = phone_digits($this->request->str('phone'));
        if ($no === '' || strlen($phone) < 9) {
            return $this->fail('กรุณากรอกเลขที่จองและเบอร์โทรให้ครบ');
        }

        $b = Booking::findByNo($no);
        if (!$b || !phone_match((string) $b['contact_phone'], $phone)) {
            ActivityLog::log('liff_link_fail', null, null, $uid);
            return $this->fail('ไม่พบการจองที่ตรงกับเลขที่และเบอร์โทรนี้ กรุณาตรวจสอบอีกครั้ง');
        }

        // ผูกเฉพาะการจองที่พิสูจน์แล้วรายการนี้ — การจองอื่นของเบอร์เดียวกันต้องยืนยันแยกทีละรายการ
        LineService::linkBooking($uid, $b);
        ActivityLog::log('liff_linked', 'bookings', (int) $b['id'], $uid);

        // ข้อความยืนยันการผูกบัญชี — ส่งเป็น push 1 ข้อความ
        if (!LineService::templateOff('linked') && setting('notify_line_enabled') === '1') {
            $b['line_user_id'] = $uid;
            try {
                LineService::pushTo($uid, LineService::fill(LineService::template('linked'), LineService::bookingVars($b)));
            } catch (\Throwable $e) {
                // ส่งข้อความยืนยันไม่ได้ไม่ควรทำให้การผูกบัญชีล้มเหลว
            }
        }
        return $this->out($this->state() + ['notice' => 'ผูกบัญชีเรียบร้อย']);
    }

    private function unlink(string $uid): Response
    {
        LineService::unlinkUser($uid);
        ActivityLog::log('liff_unlinked', null, null, $uid);
        return $this->out($this->state() + ['notice' => 'ยกเลิกการผูกบัญชีแล้ว']);
    }

    /** แจ้งโอนเงิน */
    private function slip(string $uid): Response
    {
        if (!Booking::isLineLinked($uid)) {
            return $this->fail('กรุณาผูกบัญชีก่อนแจ้งโอนเงิน', 403);
        }
        // ต้องเป็นการจองที่ LINE นี้ยืนยันไว้เองเท่านั้น
        $b = Booking::findOfLineUser($this->request->int('booking_id'), $uid);
        if (!$b) {
            return $this->fail('ไม่พบการจองนี้', 404);
        }
        // กันส่งสลิปรัวๆ จนพื้นที่เต็ม: มีสลิปรอตรวจได้ไม่เกิน 3 รายการต่อการจอง
        $waiting = Payment::pendingCountForBooking((int) $b['id']);
        if ($waiting >= 3) {
            return $this->fail('มีสลิปรอร้านตรวจสอบอยู่แล้ว ' . $waiting . ' รายการ กรุณารอผลก่อนส่งเพิ่ม');
        }
        try {
            PaymentService::submitSlip($b, $this->request->file('slip'), round($this->request->float('amount'), 2));
        } catch (\Throwable $e) {
            return $this->fail(BookingService::publicError($e, 'แจ้งโอนไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
        }
        return $this->out($this->state() + ['notice' => 'รับแจ้งโอนแล้ว ร้านจะตรวจสอบและแจ้งผลทาง LINE']);
    }

    /** ข้อมูลทั้งหมดที่หน้าจอต้องใช้ — ส่งเฉพาะของผู้ใช้ LINE คนนี้ */
    private function state(): array
    {
        $me     = $_SESSION['liff'] ?? [];
        $uid    = (string) ($me['uid'] ?? '');
        $linked = Booking::isLineLinked($uid);

        $out = [
            'ok'       => true,
            'csrf'     => Csrf::token(),
            'profile'  => ['name' => (string) ($me['name'] ?? ''), 'picture' => (string) ($me['picture'] ?? '')],
            'linked'   => $linked,
            'shop'     => [
                'name'  => (string) setting('site_name', 'thonglorcarrent'),
                'phone' => (string) setting('phone'),
                'cars'  => site_url('cars'),
            ],
            'bank'     => [
                'name'      => (string) setting('bank_name'),
                'no'        => (string) setting('bank_account_no'),
                'holder'    => (string) setting('bank_account_name'),
                'promptpay' => (string) setting('promptpay_id'),
            ],
            'limit_mb' => round(UploadService::limitBytes() / 1048576, 1),
            'bookings' => [],
        ];
        if (!$linked) {
            return $out;
        }

        // เฉพาะการจองที่ LINE นี้ยืนยันเลขที่จอง + เบอร์โทรไว้เอง (หรือจองจากในแอป LINE)
        $rows = Booking::forLineUser($uid, 12);
        $out['customer'] = ['name' => (string) ($rows[0]['contact_name'] ?? '')];
        foreach ($rows as $b) {
            [$sl, $sc] = booking_status_label($b['status']);
            [$pl, $pc] = payment_status_label($b['payment_status']);
            $due  = (float) $b['grand_total'] + (float) $b['extra_charge'];
            $bal  = max(0, $due - (float) $b['paid_amount']);
            $open = !in_array($b['status'], ['cancelled', 'completed'], true);
            // ยอดที่แนะนำให้โอนตอนนี้: ยังไม่จ่ายเลย → มัดจำ, จ่ายมัดจำแล้ว → ส่วนที่เหลือ
            $suggest = (float) $b['paid_amount'] <= 0 ? min($bal, (float) $b['deposit_amount']) : $bal;

            $pays = [];
            foreach (Payment::recentForBooking((int) $b['id'], 8) as $p) {
                $pays[] = [
                    'amount' => money0($p['amount']),
                    'status' => $p['status'],
                    'label'  => ['pending' => 'รอตรวจสอบ', 'approved' => 'ยืนยันแล้ว', 'rejected' => 'ไม่ผ่าน'][$p['status']] ?? $p['status'],
                    'date'   => thai_date($p['paid_at'], true),
                    'reason' => (string) ($p['reject_reason'] ?? ''),
                    'kind'   => $p['kind'],
                ];
            }
            $out['bookings'][] = [
                'id'           => (int) $b['id'],
                'no'           => $b['booking_no'],
                'car'          => trim($b['brand'] . ' ' . $b['model']),
                'plate'        => $b['plate'],
                'image'        => upload_exists($b['cover_image']) ? upload_url($b['cover_image']) : '',
                'pickup'       => thai_date($b['pickup_at'], true),
                'return'       => thai_date($b['return_at'], true),
                'pickup_place' => (string) ($b['pickup_place'] ?: 'ที่ร้าน'),
                'return_place' => (string) ($b['return_place'] ?: ($b['pickup_place'] ?: 'ที่ร้าน')),
                'days'         => (int) $b['rental_days'],
                'status'       => $b['status'], 'status_label' => $sl, 'status_tone' => $sc,
                'pay_label'    => $pl, 'pay_tone' => $pc,
                'total'        => money0($due),
                'paid'         => money0($b['paid_amount']),
                'balance'      => money0($bal),
                'balance_raw'  => $bal,
                'deposit'      => money0($b['deposit_amount']),
                'security'     => money0($b['security_deposit']),
                'extras'       => array_map(fn($l) => ['label' => $l['label'], 'amount' => money0($l['amount'])], Booking::extraLines($b, true)),
                'suggest'      => (int) round($suggest),
                'can_pay'      => $open && $bal > 0,
                'open'         => $open,
                'payments'     => $pays,
            ];
        }
        return $out;
    }
}
