<?php
/**
 * แจ้งเตือน LINE Messaging API + Email (SMTP ผ่าน mail() หรือ socket SMTP)
 *
 * วิธีทำงาน: ทุกการแจ้งเตือนจะถูกใส่คิวลงตาราง notifications ก่อน
 * แล้วส่งทันที (ถ้าเปิดใช้งาน) — ถ้าส่งไม่ผ่าน งานอัตโนมัติรายวัน (cron) จะลองส่งซ้ำให้อีก 1 รอบ
 */
require_once __DIR__ . '/line.php';

/** ใส่คิวแจ้งเตือน */
function notify_queue(string $channel, ?string $recipient, string $message, ?string $subject = null, ?string $refTable = null, ?int $refId = null, ?string $tplKey = null): int
{
    // ผู้รับของร้านหลายคนรวมกันยาวเกินคอลัมน์ → เก็บเป็นค่าว่าง ตอนส่งระบบจะใช้รายชื่อผู้รับของร้านจากตั้งค่าเอง
    if ($recipient !== null && strlen($recipient) > 190) {
        $recipient = null;
    }
    return db_insert('notifications', [
        'channel'   => $channel,
        'tpl_key'   => $tplKey,
        'recipient' => $recipient,
        'subject'   => $subject,
        'message'   => $message,
        'ref_table' => $refTable,
        'ref_id'    => $refId,
        'status'    => 'queued',
    ]);
}

/** ส่ง LINE push ผ่าน Messaging API — ผู้รับหลายคนคั่นด้วยจุลภาคได้ */
function line_push(string $to, string $message): array
{
    return line_push_to($to, $message);
}

/** ส่งอีเมลผ่าน SMTP (ไม่ต้องลง PHPMailer — ใช้ socket ตรง) */
function smtp_send(string $to, string $subject, string $htmlBody): array
{
    $host = setting('smtp_host');
    $port = (int) setting('smtp_port', '587');
    $user = setting('smtp_user');
    $pass = setting('smtp_pass');
    $secure = setting('smtp_secure', 'tls');
    $fromName = setting('smtp_from_name', 'thonglorcarrent');

    // ไม่ได้ตั้งค่า SMTP → ใช้ mail() ของ cPanel
    if (!$host) {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= 'From: ' . mb_encode_mimeheader($fromName) . ' <' . setting('email', 'no-reply@localhost') . ">\r\n";
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
        return [$ok, $ok ? null : 'mail() ส่งไม่สำเร็จ'];
    }

    $transport = ($secure === 'ssl') ? 'ssl://' : '';
    $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 20);
    if (!$fp) {
        return [false, "เชื่อมต่อ SMTP ไม่ได้: $errstr"];
    }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = function (string $c) use ($fp, $read) {
        fwrite($fp, $c . "\r\n");
        return $read();
    };

    $read();
    $cmd('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    if ($secure === 'tls') {
        $cmd('STARTTLS');
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return [false, 'เปิด TLS ไม่สำเร็จ'];
        }
        $cmd('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    }
    if ($user) {
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($user));
        $r = $cmd(base64_encode($pass));
        if (!str_starts_with(trim($r), '235')) {
            fclose($fp);
            return [false, 'SMTP ยืนยันตัวตนไม่ผ่าน'];
        }
    }
    $from = $user ?: setting('email', 'no-reply@localhost');
    $cmd('MAIL FROM: <' . $from . '>');
    $r = $cmd('RCPT TO: <' . $to . '>');
    if (!preg_match('/^25\d/', trim($r))) {
        fclose($fp);
        return [false, 'ปลายทางไม่ถูกต้อง: ' . trim($r)];
    }
    $cmd('DATA');

    $headers  = 'From: ' . mb_encode_mimeheader($fromName) . " <$from>\r\n";
    $headers .= "To: <$to>\r\n";
    $headers .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body = chunk_split(base64_encode($htmlBody));

    fwrite($fp, $headers . $body . "\r\n.\r\n");
    $r = $read();
    $cmd('QUIT');
    fclose($fp);

    return [str_starts_with(trim($r), '250'), str_starts_with(trim($r), '250') ? null : trim($r)];
}

/** ประมวลผลคิวแจ้งเตือน (เรียกจาก cron หรือหลังบันทึกข้อมูล) */
function notify_flush(int $limit = 20): int
{
    $rows = db_all("SELECT * FROM notifications WHERE status = 'queued' ORDER BY id LIMIT $limit");
    $sent = 0;
    foreach ($rows as $n) {
        // จองแถวนี้ก่อนส่ง: ถ้ามีอีกคำขอกำลังส่งคิวพร้อมกัน (เช่น cron กับหน้าเว็บ) แถวเดียวกันจะไม่ถูกส่งซ้ำสองครั้ง
        // ระหว่างส่งสถานะเป็น failed + ข้อความ "กำลังส่ง" — ถ้าระบบล่มกลางทางจะค้างเป็น "ส่งไม่สำเร็จ" ให้เห็น ไม่หายเงียบ
        if (db_exec("UPDATE notifications SET status = 'failed', error = 'กำลังส่ง' WHERE id = :i AND status = 'queued'", [':i' => (int) $n['id']]) < 1) {
            continue;
        }
        $ok  = false;
        $err = 'ปิดการใช้งานช่องทางนี้';
        if ($n['channel'] === 'line' && setting('notify_line_enabled') === '1') {
            $to = $n['recipient'] ?: setting('line_admin_user_id');
            if ($to) {
                [$ok, $err] = line_push($to, $n['message']);
            } else {
                $err = 'ไม่มี LINE user id ปลายทาง';
            }
        } elseif ($n['channel'] === 'email' && setting('notify_email_enabled') === '1') {
            $to = $n['recipient'] ?: setting('notify_admin_email');
            if ($to) {
                [$ok, $err] = smtp_send($to, $n['subject'] ?: 'thonglorcarrent', nl2br(e($n['message'])));
            } else {
                $err = 'ไม่มีอีเมลปลายทาง';
            }
        }
        db_update('notifications', [
            'status'  => $ok ? 'sent' : 'failed',
            'error'   => $ok ? null : mb_substr((string) $err, 0, 250),
            'sent_at' => $ok ? date('Y-m-d H:i:s') : null,
        ], 'id', $n['id']);
        if ($ok) {
            $sent++;
        }
    }
    return $sent;
}

// ------------------------------------------------------------
// ข้อความสำเร็จรูป
// ------------------------------------------------------------

function notify_new_booking(array $b, array $car): void
{
    $msg = "🚗 มีการจองใหม่\n"
        . "เลขที่: {$b['booking_no']}\n"
        . "รถ: {$car['brand']} {$car['model']} ({$car['plate']})\n"
        . "ลูกค้า: {$b['contact_name']} {$b['contact_phone']}\n"
        . 'รับรถ: ' . thai_date($b['pickup_at'], true) . "\n"
        . 'คืนรถ: ' . thai_date($b['return_at'], true) . "\n"
        . 'ยอดรวม: ' . money($b['grand_total']) . " บาท\n"
        . 'มัดจำ: ' . money($b['deposit_amount']) . ' บาท';

    notify_queue('line', setting('line_admin_user_id'), $msg, null, 'bookings', (int) $b['id']);
    notify_queue('email', setting('notify_admin_email'), $msg, 'จองใหม่ ' . $b['booking_no'], 'bookings', (int) $b['id']);

    if (!empty($b['contact_email'])) {
        $cust = "ขอบคุณที่จองกับ " . setting('site_name', 'thonglorcarrent') . "\n\n" . $msg
            . "\n\nกรุณาโอนมัดจำ " . money($b['deposit_amount']) . " บาท\n"
            . 'บัญชี: ' . setting('bank_name') . ' ' . setting('bank_account_no') . ' (' . setting('bank_account_name') . ")\n"
            . 'PromptPay: ' . setting('promptpay_id') . "\n"
            . "แล้วอัปโหลดสลิปที่หน้าตรวจสอบการจอง\n"
            . 'สอบถาม: ' . setting('phone') . ' / LINE ' . setting('line_id');
        notify_queue('email', $b['contact_email'], $cust, 'ยืนยันการจอง ' . $b['booking_no'], 'bookings', (int) $b['id']);
    }
    line_notify_customer('booking_received', $b, $car);
    notify_flush();
}

function notify_booking_confirmed(array $b, array $car): void
{
    $msg = "✅ ยืนยันการจอง {$b['booking_no']} แล้ว\n"
        . "รถ: {$car['brand']} {$car['model']} ({$car['plate']})\n"
        . 'รับรถ: ' . thai_date($b['pickup_at'], true) . "\n"
        . 'คืนรถ: ' . thai_date($b['return_at'], true) . "\n"
        . 'จุดรับรถ: ' . ($b['pickup_place'] ?: '-') . "\n"
        . 'ยอดคงเหลือชำระวันรับรถ: ' . money($b['grand_total'] - $b['paid_amount']) . ' บาท';
    if (!empty($b['contact_email'])) {
        notify_queue('email', $b['contact_email'], $msg, 'ยืนยันการจอง ' . $b['booking_no'], 'bookings', (int) $b['id']);
    }
    notify_queue('line', setting('line_admin_user_id'), $msg, null, 'bookings', (int) $b['id']);
    // ลูกค้า: ข้อความยืนยันตามแบบที่ตั้งไว้ในหลังบ้าน (ส่งครั้งเดียวต่อการจอง)
    line_notify_customer('confirmed', $b, $car, [], true);
    notify_flush();
}

/**
 * แจ้งเมื่อรับคืนรถ
 *   ลูกค้า (LINE ที่ผูกไว้)  รายการค่าใช้จ่ายเพิ่มแยกบรรทัด + ยอดคงเหลือ
 *   ร้าน                     เฉพาะกรณีมีค่าใช้จ่ายเพิ่มหรือมีรายการที่พนักงานยกเว้น
 *                            เจ้าของจะเห็นทุกครั้งที่มีการยกเว้นค่าปรับ โดยไม่ต้องเปิดดูทีละใบจอง
 *
 * @param array<int,array{label:string,amount:float|int,waived:bool,suggested?:float|int}> $lines
 */
function notify_booking_returned(array $b, array $car, array $lines): void
{
    $charged = array_values(array_filter($lines, fn($l) => empty($l['waived']) && (float) $l['amount'] > 0));
    $waived  = array_values(array_filter($lines, fn($l) => !empty($l['waived'])));
    $extra   = round(array_sum(array_map(fn($l) => (float) $l['amount'], $charged)), 2);

    if ($charged) {
        $items = 'ค่าใช้จ่ายเพิ่ม ' . money0($extra) . " บาท";
        foreach ($charged as $l) {
            $items .= "\n• " . $l['label'] . ' ' . money0($l['amount']) . ' บาท';
        }
    } else {
        $items = 'ไม่มีค่าใช้จ่ายเพิ่ม';
    }
    $queued = line_notify_customer('returned', $b, $car, ['รายการ' => $items, 'จำนวน' => money0($extra)], true);

    if ($charged || $waived) {
        $due = (float) $b['grand_total'] + (float) $b['extra_charge'];
        $msg = "🔙 รับคืนรถ {$b['booking_no']}\n"
             . trim(($car['brand'] ?? '') . ' ' . ($car['model'] ?? '')) . ' (' . ($car['plate'] ?? '') . ")\n"
             . "ลูกค้า: {$b['contact_name']}\n";
        foreach ($charged as $l) {
            $msg .= '• ' . $l['label'] . ' ' . money0($l['amount']) . " บาท\n";
        }
        foreach ($waived as $l) {
            $msg .= '• ' . $l['label'] . ' — ยกเว้น (ระบบคิด ' . money0($l['suggested'] ?? 0) . " บาท)\n";
        }
        $msg .= 'ยอดค้างชำระ: ' . money0(max(0, $due - (float) $b['paid_amount'])) . ' บาท';
        notify_queue('line', setting('line_admin_user_id'), $msg, null, 'bookings', (int) $b['id']);
        $queued = true;
    }
    if ($queued) {
        notify_flush();
    }
}

/** แจ้งผลตรวจสลิปให้ลูกค้าทาง LINE — $approved = false คือสลิปไม่ผ่าน */
function notify_slip_result(array $b, float $amount, bool $approved, string $reason = ''): void
{
    $queued = line_notify_customer(
        $approved ? 'slip_approved' : 'slip_rejected',
        $b, null,
        ['จำนวน' => money0($amount), 'เหตุผล' => $reason !== '' ? $reason : '-']
    );
    if ($queued) {
        notify_flush();
    }
}

function notify_slip_uploaded(array $b, float $amount): void
{
    $msg = "💰 ลูกค้าแจ้งโอนเงิน\n"
        . "การจอง: {$b['booking_no']}\n"
        . "ลูกค้า: {$b['contact_name']}\n"
        . 'จำนวน: ' . money($amount) . " บาท\n"
        . 'กรุณาตรวจสอบและกดยืนยันในระบบหลังบ้าน';
    notify_queue('line', setting('line_admin_user_id'), $msg, null, 'bookings', (int) $b['id']);
    notify_queue('email', setting('notify_admin_email'), $msg, 'แจ้งโอน ' . $b['booking_no'], 'bookings', (int) $b['id']);
    notify_flush();
}

/**
 * รับแจ้งโอนเงินจากลูกค้า — สร้างรายการชำระเงินสถานะ "รอตรวจสอบ" แล้วแจ้งร้าน
 * ใช้ร่วมกันระหว่างหน้า LIFF และจุดอื่นที่รับสลิป
 * @throws RuntimeException เมื่อข้อมูลไม่ครบหรือการจองปิดไปแล้ว
 */
function booking_submit_slip(array $b, array $file, float $amount, ?string $paidAt = null, ?string $note = null): int
{
    if (in_array($b['status'], ['cancelled', 'completed'], true)) {
        throw new RuntimeException('การจองนี้ปิดแล้ว ไม่สามารถแจ้งโอนได้');
    }
    if ($amount <= 0) {
        throw new RuntimeException('กรุณาระบุจำนวนเงินที่โอน');
    }
    if ($amount > 2000000) {
        throw new RuntimeException('จำนวนเงินไม่ถูกต้อง');
    }
    $path = upload_file($file, 'slips', 8);
    if (!$path) {
        throw new RuntimeException('กรุณาแนบไฟล์สลิป');
    }
    $pid = db_insert('payments', [
        'booking_id' => (int) $b['id'],
        'kind'       => (float) $b['paid_amount'] > 0 ? 'balance' : 'deposit',
        'method'     => 'transfer',
        'amount'     => $amount,
        'paid_at'    => $paidAt ? date('Y-m-d H:i:s', strtotime($paidAt)) : date('Y-m-d H:i:s'),
        'slip_file'  => $path,
        'status'     => 'pending',
        'note'       => $note ?: null,
    ]);
    log_activity('slip_uploaded', 'bookings', (int) $b['id'], $amount);
    try {
        notify_slip_uploaded($b, $amount);
    } catch (Throwable $e) {
        // แจ้งเตือนล้มเหลวไม่ควรทำให้การแจ้งโอนหาย
    }
    return $pid;
}
