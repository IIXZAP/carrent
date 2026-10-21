<?php
/**
 * API ของหน้า "การจองของฉัน" ในแอป LINE (LIFF) — ตอบเป็น JSON ทั้งหมด
 *
 *   ?act=session   ยืนยันตัวตนด้วย ID token จาก LINE แล้วคืนสถานะปัจจุบัน
 *   ?act=state     ขอข้อมูลล่าสุด
 *   ?act=link      ผูกบัญชีด้วย เลขที่จอง + เบอร์โทร
 *   ?act=slip      แจ้งโอนเงินพร้อมแนบสลิป
 *   ?act=unlink    ยกเลิกการผูกบัญชี
 *
 * หลักความปลอดภัย: LINE user id มาจากการตรวจ ID token กับเซิร์ฟเวอร์ LINE เท่านั้น
 * ไม่รับ user id ที่หน้าเว็บส่งมาเอง และลูกค้าเห็นได้เฉพาะการจองของตัวเอง
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/notify.php';

function liff_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function liff_fail(string $msg, int $code = 400): void
{
    liff_out(['ok' => false, 'error' => $msg], $code);
}

// ไฟล์ใหญ่เกิน post_max_size → PHP ทิ้งคำขอทั้งก้อน ต้องบอกสาเหตุให้ชัด
if (post_too_large()) {
    liff_fail('ไฟล์สลิปใหญ่เกินไป (รับได้ไม่เกิน ' . round(upload_limit_bytes() / 1048576, 1)
        . ' MB) กรุณาใช้ภาพหน้าจอสลิปแทนรูปถ่ายจากกล้อง', 413);
}

start_session();
$act = (string) ($_GET['act'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    liff_fail('POST only', 405);
}

// ------------------------------------------------------------
// เข้าสู่ระบบด้วย ID token
// ------------------------------------------------------------
if ($act === 'session') {
    // คำขอเข้าสู่ระบบต้องมาจากหน้าเว็บของร้านเองเท่านั้น
    // ถ้าไม่ตรวจ เว็บอื่นสามารถสั่งเบราว์เซอร์ของลูกค้าให้ "เข้าสู่ระบบด้วย LINE ของคนอื่น" อย่างเงียบๆ
    // แล้วการจองที่ลูกค้าทำต่อจากนั้นจะไปผูกกับ LINE ของคนนั้น
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $site   = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    $oHost  = $origin !== '' ? (string) parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') : '';
    if (($origin !== '' && strcasecmp($oHost, (string) ($_SERVER['HTTP_HOST'] ?? '')) !== 0)
        || ($site !== '' && !in_array($site, ['same-origin', 'none'], true))) {
        liff_fail('คำขอไม่ได้มาจากหน้าเว็บของร้าน', 403);
    }
    if (trim((string) setting('line_liff_id')) === '') {
        liff_fail('ร้านยังไม่ได้เปิดใช้งานส่วนนี้', 503);
    }
    $info = line_verify_idtoken((string) ($_POST['id_token'] ?? ''));
    if (!$info) {
        liff_fail('ยืนยันตัวตนกับ LINE ไม่สำเร็จ กรุณาปิดแล้วเปิดหน้านี้ใหม่', 401);
    }
    session_regenerate_id(true);
    $_SESSION['liff'] = ['uid' => $info['sub'], 'name' => $info['name'], 'picture' => $info['picture']];
    liff_out(liff_state());
}

// ---------- คำขออื่นทั้งหมดต้องยืนยันตัวตนแล้ว + มี CSRF token ----------
$uid = (string) ($_SESSION['liff']['uid'] ?? '');
if ($uid === '') {
    liff_fail('เซสชันหมดอายุ กรุณาเปิดหน้านี้ใหม่', 401);
}
try {
    csrf_check_ajax();
} catch (Throwable $e) {
    liff_fail($e->getMessage(), 419);
}

if ($act === 'state') {
    liff_out(liff_state());
}

// ------------------------------------------------------------
// ผูกบัญชี: ต้องรู้ทั้งเลขที่จองและเบอร์โทรที่ใช้จอง
// ------------------------------------------------------------
if ($act === 'link') {
    // กันเดาสุ่ม: ผิดเกิน 6 ครั้งใน 15 นาที พักก่อน
    $fails = (int) db_val(
        "SELECT COUNT(*) FROM activity_logs
         WHERE action = 'liff_link_fail' AND detail = :u AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
        [':u' => $uid]
    );
    // กันคนสลับบัญชี LINE หลายบัญชีมาเดาจากเครื่องเดียว: นับตามหมายเลข IP ด้วย
    $ipFails = (int) db_val(
        "SELECT COUNT(*) FROM activity_logs
         WHERE action = 'liff_link_fail' AND ip = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
        [':ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '')]
    );
    if ($fails >= 6 || $ipFails >= 20) {
        liff_fail('ลองผิดหลายครั้งเกินไป กรุณารอ 15 นาที หรือติดต่อร้าน ' . setting('phone'), 429);
    }

    $no    = strtoupper(trim((string) ($_POST['booking_no'] ?? '')));
    $phone = phone_digits((string) ($_POST['phone'] ?? ''));
    if ($no === '' || strlen($phone) < 9) {
        liff_fail('กรุณากรอกเลขที่จองและเบอร์โทรให้ครบ');
    }

    $b = db_one('SELECT * FROM bookings WHERE booking_no = :n', [':n' => $no]);
    $match = false;
    if ($b) {
        $match = phone_match((string) $b['contact_phone'], $phone);
    }
    if (!$b || !$match) {
        log_activity('liff_link_fail', null, null, $uid);
        liff_fail('ไม่พบการจองที่ตรงกับเลขที่และเบอร์โทรนี้ กรุณาตรวจสอบอีกครั้ง');
    }

    // ผูกเฉพาะการจองที่พิสูจน์แล้วรายการนี้ — การจองอื่นของเบอร์เดียวกันต้องยืนยันแยกทีละรายการ
    line_link_booking($uid, $b);
    log_activity('liff_linked', 'bookings', (int) $b['id'], $uid);

    // ข้อความยืนยันการผูกบัญชี — ส่งเป็น push 1 ข้อความ
    if (!line_tpl_off('linked') && setting('notify_line_enabled') === '1') {
        $b['line_user_id'] = $uid;
        try {
            line_push_to($uid, line_fill(line_tpl('linked'), line_booking_vars($b)));
        } catch (Throwable $e) {
        }
    }
    liff_out(liff_state() + ['notice' => 'ผูกบัญชีเรียบร้อย']);
}

if ($act === 'unlink') {
    line_unlink_user($uid);
    log_activity('liff_unlinked', null, null, $uid);
    liff_out(liff_state() + ['notice' => 'ยกเลิกการผูกบัญชีแล้ว']);
}

// ------------------------------------------------------------
// แจ้งโอนเงิน
// ------------------------------------------------------------
if ($act === 'slip') {
    if (!line_uid_linked($uid)) {
        liff_fail('กรุณาผูกบัญชีก่อนแจ้งโอนเงิน', 403);
    }
    // ต้องเป็นการจองที่ LINE นี้ยืนยันไว้เองเท่านั้น
    $b = db_one('SELECT * FROM bookings WHERE id = :i AND line_user_id = :u',
        [':i' => (int) ($_POST['booking_id'] ?? 0), ':u' => $uid]);
    if (!$b) {
        liff_fail('ไม่พบการจองนี้', 404);
    }
    // กันส่งสลิปรัวๆ จนพื้นที่เต็ม: มีสลิปรอตรวจได้ไม่เกิน 3 รายการต่อการจอง
    $waiting = (int) db_val("SELECT COUNT(*) FROM payments WHERE booking_id = :b AND status = 'pending'", [':b' => (int) $b['id']]);
    if ($waiting >= 3) {
        liff_fail('มีสลิปรอร้านตรวจสอบอยู่แล้ว ' . $waiting . ' รายการ กรุณารอผลก่อนส่งเพิ่ม');
    }
    try {
        booking_submit_slip($b, $_FILES['slip'] ?? [], round((float) ($_POST['amount'] ?? 0), 2));
    } catch (Throwable $e) {
        liff_fail(public_error($e, 'แจ้งโอนไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
    }
    liff_out(liff_state() + ['notice' => 'รับแจ้งโอนแล้ว ร้านจะตรวจสอบและแจ้งผลทาง LINE']);
}

liff_fail('ไม่รู้จักคำสั่งนี้', 404);

// ------------------------------------------------------------

/** ข้อมูลทั้งหมดที่หน้าจอต้องใช้ — ส่งเฉพาะของผู้ใช้ LINE คนนี้ */
function liff_state(): array
{
    $me   = $_SESSION['liff'] ?? [];
    $uid  = (string) ($me['uid'] ?? '');
    $linked = line_uid_linked($uid);

    $out = [
        'ok'      => true,
        'csrf'    => csrf_token(),
        'profile' => ['name' => (string) ($me['name'] ?? ''), 'picture' => (string) ($me['picture'] ?? '')],
        'linked'  => $linked,
        'shop'    => [
            'name'  => (string) setting('site_name', 'thonglorcarrent'),
            'phone' => (string) setting('phone'),
            'cars'  => site_url('cars.php'),
        ],
        'bank'    => [
            'name'      => (string) setting('bank_name'),
            'no'        => (string) setting('bank_account_no'),
            'holder'    => (string) setting('bank_account_name'),
            'promptpay' => (string) setting('promptpay_id'),
        ],
        'limit_mb' => round(upload_limit_bytes() / 1048576, 1),
        'bookings' => [],
    ];
    if (!$linked) {
        return $out;
    }

    // เฉพาะการจองที่ LINE นี้ยืนยันเลขที่จอง + เบอร์โทรไว้เอง (หรือจองจากในแอป LINE)
    $rows = db_all(
        "SELECT b.*, c.brand, c.model, c.plate, c.color, c.cover_image
         FROM bookings b JOIN cars c ON c.id = b.car_id
         WHERE b.line_user_id = :u
         ORDER BY (b.status IN ('pending','confirmed','picked_up')) DESC, b.pickup_at DESC
         LIMIT 12",
        [':u' => $uid]
    );
    $out['customer'] = ['name' => (string) ($rows[0]['contact_name'] ?? '')];
    foreach ($rows as $b) {
        [$sl, $sc] = booking_status_label($b['status']);
        [$pl, $pc] = payment_status_label($b['payment_status']);
        $due   = (float) $b['grand_total'] + (float) $b['extra_charge'];
        $bal   = max(0, $due - (float) $b['paid_amount']);
        $open  = !in_array($b['status'], ['cancelled', 'completed'], true);
        // ยอดที่แนะนำให้โอนตอนนี้: ยังไม่จ่ายเลย → มัดจำ, จ่ายมัดจำแล้ว → ส่วนที่เหลือ
        $suggest = (float) $b['paid_amount'] <= 0 ? min($bal, (float) $b['deposit_amount']) : $bal;

        $pays = [];
        foreach (db_all('SELECT amount, status, paid_at, reject_reason, kind FROM payments
                         WHERE booking_id = :b ORDER BY id DESC LIMIT 8', [':b' => (int) $b['id']]) as $p) {
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
            'id'        => (int) $b['id'],
            'no'        => $b['booking_no'],
            'car'       => trim($b['brand'] . ' ' . $b['model']),
            'plate'     => $b['plate'],
            'image'     => upload_exists($b['cover_image']) ? upload_url($b['cover_image']) : '',
            'pickup'    => thai_date($b['pickup_at'], true),
            'return'    => thai_date($b['return_at'], true),
            'pickup_place' => (string) ($b['pickup_place'] ?: 'ที่ร้าน'),
            'return_place' => (string) ($b['return_place'] ?: ($b['pickup_place'] ?: 'ที่ร้าน')),
            'days'      => (int) $b['rental_days'],
            'status'    => $b['status'], 'status_label' => $sl, 'status_tone' => $sc,
            'pay_label' => $pl, 'pay_tone' => $pc,
            'total'     => money0($due),
            'paid'      => money0($b['paid_amount']),
            'balance'   => money0($bal),
            'balance_raw' => $bal,
            'deposit'   => money0($b['deposit_amount']),
            'security'  => money0($b['security_deposit']),
            'extras'    => array_map(fn($l) => ['label' => $l['label'], 'amount' => money0($l['amount'])], booking_extra_lines($b, true)),
            'suggest'   => (int) round($suggest),
            'can_pay'   => $open && $bal > 0,
            'open'      => $open,
            'payments'  => $pays,
        ];
    }
    return $out;
}
