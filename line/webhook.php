<?php
/**
 * LINE Webhook — ที่อยู่ที่ต้องนำไปใส่ใน LINE Developers → Messaging API → Webhook URL
 *     https://โดเมน/โฟลเดอร์ระบบ/line/webhook.php
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
require_once __DIR__ . '/../includes/line.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$raw = (string) file_get_contents('php://input');
$sig = (string) ($_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '');

// ไม่มีลายเซ็นหรือลายเซ็นไม่ตรง = ไม่ได้มาจาก LINE → ปฏิเสธ
if (!line_verify_signature($raw, $sig)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'bad signature']);
    exit;
}

$payload = json_decode($raw, true);
$events  = is_array($payload['events'] ?? null) ? $payload['events'] : [];

foreach ($events as $ev) {
    try {
        handle_line_event($ev);
    } catch (Throwable $e) {
        // เหตุการณ์หนึ่งพังไม่ควรทำให้ทั้งชุดล้ม — LINE จะส่งซ้ำทั้งก้อนถ้าเราตอบไม่ใช่ 200
        try {
            log_activity('line_webhook_error', null, null, mb_substr($e->getMessage(), 0, 200));
        } catch (Throwable $ignored) {
        }
    }
}

// ต้องตอบ 200 เสมอเมื่อลายเซ็นถูก (ปุ่ม Verify ของ LINE ส่ง events ว่างมาทดสอบ)
echo json_encode(['ok' => true]);

// ------------------------------------------------------------

function handle_line_event(array $ev): void
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
        $prof = line_profile($uid);
        $name = $prof['displayName'] ?? null;
    }
    db_insert('line_events', [
        'event_type'   => $type,
        'line_user_id' => $uid,
        'display_name' => $name ? mb_substr($name, 0, 120) : null,
        'text'         => $text !== '' ? mb_substr($text, 0, 500) : null,
    ]);

    if ($type === 'unfollow') {
        line_unlink_user($uid);
        return;
    }
    if ($redelivery || $reply === '') {
        return;
    }

    $base = [
        'ร้าน'   => (string) setting('site_name', 'thonglorcarrent'),
        'โทร'    => (string) setting('phone'),
        'ลิงก์'  => liff_url(),
        'ชื่อ'   => (string) ($name ?? ''),
    ];

    if ($type === 'follow') {
        if (!line_tpl_off('welcome')) {
            line_reply($reply, line_fill(line_tpl('welcome'), $base));
        }
        return;
    }

    // ---------- ข้อความ ----------
    $lower = mb_strtolower($text);

    // เจ้าของร้านใช้คำนี้ดู user id ของตัวเอง (หรือกดเลือกจากหน้า หลังบ้าน → LINE ก็ได้)
    if (in_array($lower, ['myid', 'my id', 'ไอดี', 'userid', 'user id'], true)) {
        line_reply($reply, "LINE user id ของคุณ:\n" . $uid);
        return;
    }

    // พิมพ์เลขที่จองมาตรงๆ → ส่งลิงก์ที่กรอกเลขจองให้แล้ว เหลือยืนยันเบอร์โทรอย่างเดียว
    if (preg_match('/\b([A-Z]{1,4}-?\d{4,}(?:-?\d+)?)\b/i', $text, $m)) {
        $found = db_one('SELECT booking_no FROM bookings WHERE booking_no = :n', [':n' => strtoupper($m[1])]);
        if ($found) {
            line_reply($reply, "พบการจอง {$found['booking_no']} ครับ\n"
                . "กดลิงก์นี้แล้วยืนยันเบอร์โทรเพื่อดูรายละเอียดและแจ้งโอนเงิน\n"
                . liff_url(['no' => $found['booking_no']]));
            return;
        }
    }

    foreach (['จอง', 'สถานะ', 'สลิป', 'โอน', 'เช็ค', 'เช็ก', 'ตรวจสอบ'] as $kw) {
        if (mb_strpos($lower, $kw) !== false) {
            line_reply($reply, "ดูการจองและแจ้งโอนเงินได้ที่นี่ครับ\n" . liff_url()
                . "\n\nดูรถว่างและจองรถ\n" . site_url('cars.php'));
            return;
        }
    }
    // ข้อความอื่น: ไม่ตอบอัตโนมัติ ให้พนักงานตอบเองในแอป LINE Official Account
}
