<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\LineEvent;
use App\Services\LineService;

/**
 * LINE Webhook — ที่อยู่ที่ต้องนำไปใส่ใน LINE Developers → Messaging API → Webhook URL
 *     https://โดเมน/โฟลเดอร์ระบบ/line/webhook
 *
 * หน้าที่:
 *   - เพิ่มเพื่อน      → ส่งข้อความต้อนรับพร้อมลิงก์ "การจองของฉัน"
 *   - เลิกติดตาม      → ยกเลิกการผูกบัญชี (จะได้ไม่ส่งข้อความหาคนที่บล็อกร้านแล้ว เปลืองโควตา)
 *   - พิมพ์ข้อความ    → ตอบเฉพาะคำที่ระบบรู้จัก (myid / จอง / สถานะ / เลขที่จอง)
 *                      ข้อความอื่นไม่ตอบ ปล่อยให้พนักงานคุยเองในแอป LINE OA
 *
 * ทุกเหตุการณ์ถูกบันทึกลงตาราง line_events เพื่อให้เจ้าของร้านหยิบ user id ของตัวเอง
 * ไปตั้งเป็นผู้รับแจ้งเตือนได้จากหน้า หลังบ้าน → LINE
 */
class LineWebhookController extends Controller
{
    public function handle(): Response
    {
        $raw = $this->request->rawBody();
        $sig = $this->request->header('X-Line-Signature');

        // ไม่มีลายเซ็นหรือลายเซ็นไม่ตรง = ไม่ได้มาจาก LINE → ปฏิเสธ
        if (!LineService::verifySignature($raw, $sig)) {
            return $this->json(['ok' => false, 'error' => 'bad signature'], 401);
        }

        $payload = json_decode($raw, true);
        $events  = is_array($payload['events'] ?? null) ? $payload['events'] : [];

        foreach ($events as $ev) {
            try {
                $this->event($ev);
            } catch (\Throwable $e) {
                // เหตุการณ์หนึ่งพังไม่ควรทำให้ทั้งชุดล้ม — LINE จะส่งซ้ำทั้งก้อนถ้าเราตอบไม่ใช่ 200
                ActivityLog::log('line_webhook_error', null, null, mb_substr($e->getMessage(), 0, 200));
            }
        }

        // ต้องตอบ 200 เสมอเมื่อลายเซ็นถูก (ปุ่ม Verify ของ LINE ส่ง events ว่างมาทดสอบ)
        return $this->json(['ok' => true]);
    }

    /** GET/อื่นๆ — แจ้งว่ารับเฉพาะ POST */
    public function notPost(): Response
    {
        return $this->json(['ok' => false, 'error' => 'POST only'], 405);
    }

    private function event(array $ev): void
    {
        $type  = (string) ($ev['type'] ?? '');
        $uid   = (string) ($ev['source']['userId'] ?? '');
        $reply = (string) ($ev['replyToken'] ?? '');
        // LINE ส่งเหตุการณ์เดิมซ้ำได้เมื่อครั้งก่อนเราตอบช้า — บันทึกไว้แต่ไม่ตอบซ้ำ
        $redelivery = !empty($ev['deliveryContext']['isRedelivery']);

        if ($uid === '' || !in_array($type, ['follow', 'unfollow', 'message'], true)) {
            return;
        }

        $text = '';
        if ($type === 'message') {
            if (($ev['message']['type'] ?? '') !== 'text') {
                return;   // รูป สติกเกอร์ ไฟล์ — ไม่เกี่ยวกับระบบ ไม่ต้องบันทึก
            }
            $text = trim((string) ($ev['message']['text'] ?? ''));
        }

        $name = null;
        if ($type !== 'unfollow') {
            $prof = LineService::profile($uid);
            $name = $prof['displayName'] ?? null;
        }
        LineEvent::insert([
            'event_type'   => $type,
            'line_user_id' => $uid,
            'display_name' => $name ? mb_substr($name, 0, 120) : null,
            'text'         => $text !== '' ? mb_substr($text, 0, 500) : null,
        ]);

        if ($type === 'unfollow') {
            LineService::unlinkUser($uid);
            return;
        }
        if ($redelivery || $reply === '') {
            return;
        }

        $base = [
            'ร้าน'  => (string) setting('site_name', 'thonglorcarrent'),
            'โทร'   => (string) setting('phone'),
            'ลิงก์' => LineService::liffUrl(),
            'ชื่อ'  => (string) ($name ?? ''),
        ];

        if ($type === 'follow') {
            if (!LineService::templateOff('welcome')) {
                LineService::reply($reply, LineService::fill(LineService::template('welcome'), $base));
            }
            return;
        }

        // ---------- ข้อความ ----------
        $lower = mb_strtolower($text);

        // เจ้าของร้านใช้คำนี้ดู user id ของตัวเอง (หรือกดเลือกจากหน้า หลังบ้าน → LINE ก็ได้)
        if (in_array($lower, ['myid', 'my id', 'ไอดี', 'userid', 'user id'], true)) {
            LineService::reply($reply, "LINE user id ของคุณ:\n" . $uid);
            return;
        }

        // พิมพ์เลขที่จองมาตรงๆ → ส่งลิงก์ที่กรอกเลขจองให้แล้ว เหลือยืนยันเบอร์โทรอย่างเดียว
        if (preg_match('/\b([A-Z]{1,4}-?\d{4,}(?:-?\d+)?)\b/i', $text, $m)) {
            $found = Booking::findByNo(strtoupper($m[1]));
            if ($found) {
                LineService::reply($reply, "พบการจอง {$found['booking_no']} ครับ\n"
                    . "กดลิงก์นี้แล้วยืนยันเบอร์โทรเพื่อดูรายละเอียดและแจ้งโอนเงิน\n"
                    . LineService::liffUrl(['no' => $found['booking_no']]));
                return;
            }
        }

        foreach (['จอง', 'สถานะ', 'สลิป', 'โอน', 'เช็ค', 'เช็ก', 'ตรวจสอบ'] as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                LineService::reply($reply, "ดูการจองและแจ้งโอนเงินได้ที่นี่ครับ\n" . LineService::liffUrl()
                    . "\n\nดูรถว่างและจองรถ\n" . site_url('cars'));
                return;
            }
        }
        // ข้อความอื่น: ไม่ตอบอัตโนมัติ ให้พนักงานตอบเองในแอป LINE Official Account
    }
}
