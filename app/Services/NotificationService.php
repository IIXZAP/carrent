<?php

namespace App\Services;

use App\Models\Notification;

/**
 * แจ้งเตือน LINE Messaging API + Email (SMTP ผ่าน mail() หรือ socket SMTP)
 *
 * วิธีทำงาน: ทุกการแจ้งเตือนจะถูกใส่คิวลงตาราง notifications ก่อน
 * แล้วส่งทันที (ถ้าเปิดใช้งาน) — ถ้าส่งไม่ผ่าน งานอัตโนมัติรายวัน (cron) จะลองส่งซ้ำให้อีก 1 รอบ
 */
final class NotificationService
{
    /** ใส่คิวแจ้งเตือน */
    public static function queue(string $channel, ?string $recipient, string $message, ?string $subject = null, ?string $refTable = null, ?int $refId = null, ?string $tplKey = null): int
    {
        return Notification::queue($channel, $recipient, $message, $subject, $refTable, $refId, $tplKey);
    }

    /** ส่งอีเมลผ่าน SMTP (ไม่ต้องลง PHPMailer — ใช้ socket ตรง) */
    public static function smtpSend(string $to, string $subject, string $htmlBody): array
    {
        $host     = setting('smtp_host');
        $port     = (int) setting('smtp_port', '587');
        $user     = setting('smtp_user');
        $pass     = setting('smtp_pass');
        $secure   = setting('smtp_secure', 'tls');
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

    /** ประมวลผลคิวแจ้งเตือน (เรียกจาก cron หรือหลังบันทึกข้อมูล) คืนจำนวนที่ส่งสำเร็จ */
    public static function flush(int $limit = 20): int
    {
        $sent = 0;
        foreach (Notification::queued($limit) as $n) {
            // จองแถวนี้ก่อนส่ง: ถ้ามีอีกคำขอกำลังส่งคิวพร้อมกัน แถวเดียวกันจะไม่ถูกส่งซ้ำสองครั้ง
            if (!Notification::claim((int) $n['id'])) {
                continue;
            }
            $ok  = false;
            $err = 'ปิดการใช้งานช่องทางนี้';
            if ($n['channel'] === 'line' && setting('notify_line_enabled') === '1') {
                $to = $n['recipient'] ?: setting('line_admin_user_id');
                if ($to) {
                    [$ok, $err] = LineService::pushTo($to, $n['message']);
                } else {
                    $err = 'ไม่มี LINE user id ปลายทาง';
                }
            } elseif ($n['channel'] === 'email' && setting('notify_email_enabled') === '1') {
                $to = $n['recipient'] ?: setting('notify_admin_email');
                if ($to) {
                    [$ok, $err] = self::smtpSend($to, $n['subject'] ?: 'thonglorcarrent', nl2br(e($n['message'])));
                } else {
                    $err = 'ไม่มีอีเมลปลายทาง';
                }
            }
            Notification::markResult((int) $n['id'], (bool) $ok, $err);
            if ($ok) {
                $sent++;
            }
        }
        return $sent;
    }

    // ------------------------------------------------------------
    // ข้อความสำเร็จรูป
    // ------------------------------------------------------------
    public static function newBooking(array $b, array $car): void
    {
        $msg = "🚗 มีการจองใหม่\n"
            . "เลขที่: {$b['booking_no']}\n"
            . "รถ: {$car['brand']} {$car['model']} ({$car['plate']})\n"
            . "ลูกค้า: {$b['contact_name']} {$b['contact_phone']}\n"
            . 'รับรถ: ' . thai_date($b['pickup_at'], true) . "\n"
            . 'คืนรถ: ' . thai_date($b['return_at'], true) . "\n"
            . 'ยอดรวม: ' . money($b['grand_total']) . " บาท\n"
            . 'มัดจำ: ' . money($b['deposit_amount']) . ' บาท';

        self::queue('line', setting('line_admin_user_id'), $msg, null, 'bookings', (int) $b['id']);
        self::queue('email', setting('notify_admin_email'), $msg, 'จองใหม่ ' . $b['booking_no'], 'bookings', (int) $b['id']);

        if (!empty($b['contact_email'])) {
            $cust = 'ขอบคุณที่จองกับ ' . setting('site_name', 'thonglorcarrent') . "\n\n" . $msg
                . "\n\nกรุณาโอนมัดจำ " . money($b['deposit_amount']) . " บาท\n"
                . 'บัญชี: ' . setting('bank_name') . ' ' . setting('bank_account_no') . ' (' . setting('bank_account_name') . ")\n"
                . 'PromptPay: ' . setting('promptpay_id') . "\n"
                . "แล้วอัปโหลดสลิปที่หน้าตรวจสอบการจอง\n"
                . 'สอบถาม: ' . setting('phone') . ' / LINE ' . setting('line_id');
            self::queue('email', $b['contact_email'], $cust, 'ยืนยันการจอง ' . $b['booking_no'], 'bookings', (int) $b['id']);
        }
        LineService::notifyCustomer('booking_received', $b, $car);
        self::flush();
    }

    public static function bookingConfirmed(array $b, array $car): void
    {
        $msg = "✅ ยืนยันการจอง {$b['booking_no']} แล้ว\n"
            . "รถ: {$car['brand']} {$car['model']} ({$car['plate']})\n"
            . 'รับรถ: ' . thai_date($b['pickup_at'], true) . "\n"
            . 'คืนรถ: ' . thai_date($b['return_at'], true) . "\n"
            . 'จุดรับรถ: ' . ($b['pickup_place'] ?: '-') . "\n"
            . 'ยอดคงเหลือชำระวันรับรถ: ' . money($b['grand_total'] - $b['paid_amount']) . ' บาท';
        if (!empty($b['contact_email'])) {
            self::queue('email', $b['contact_email'], $msg, 'ยืนยันการจอง ' . $b['booking_no'], 'bookings', (int) $b['id']);
        }
        self::queue('line', setting('line_admin_user_id'), $msg, null, 'bookings', (int) $b['id']);
        // ลูกค้า: ข้อความยืนยันตามแบบที่ตั้งไว้ในหลังบ้าน (ส่งครั้งเดียวต่อการจอง)
        LineService::notifyCustomer('confirmed', $b, $car, [], true);
        self::flush();
    }

    /**
     * แจ้งเมื่อรับคืนรถ
     *   ลูกค้า (LINE ที่ผูกไว้)  รายการค่าใช้จ่ายเพิ่มแยกบรรทัด + ยอดคงเหลือ
     *   ร้าน                     เฉพาะกรณีมีค่าใช้จ่ายเพิ่มหรือมีรายการที่พนักงานยกเว้น
     *                            เจ้าของจะเห็นทุกครั้งที่มีการยกเว้นค่าปรับ โดยไม่ต้องเปิดดูทีละใบจอง
     *
     * @param array<int,array{label:string,amount:float|int,waived:bool,suggested?:float|int}> $lines
     */
    public static function bookingReturned(array $b, array $car, array $lines): void
    {
        $charged = array_values(array_filter($lines, fn($l) => empty($l['waived']) && (float) $l['amount'] > 0));
        $waived  = array_values(array_filter($lines, fn($l) => !empty($l['waived'])));
        $extra   = round(array_sum(array_map(fn($l) => (float) $l['amount'], $charged)), 2);

        if ($charged) {
            $items = 'ค่าใช้จ่ายเพิ่ม ' . money0($extra) . ' บาท';
            foreach ($charged as $l) {
                $items .= "\n• " . $l['label'] . ' ' . money0($l['amount']) . ' บาท';
            }
        } else {
            $items = 'ไม่มีค่าใช้จ่ายเพิ่ม';
        }
        $queued = LineService::notifyCustomer('returned', $b, $car, ['รายการ' => $items, 'จำนวน' => money0($extra)], true);

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
            self::queue('line', setting('line_admin_user_id'), $msg, null, 'bookings', (int) $b['id']);
            $queued = true;
        }
        if ($queued) {
            self::flush();
        }
    }

    /** แจ้งผลตรวจสลิปให้ลูกค้าทาง LINE — $approved = false คือสลิปไม่ผ่าน */
    public static function slipResult(array $b, float $amount, bool $approved, string $reason = ''): void
    {
        $queued = LineService::notifyCustomer(
            $approved ? 'slip_approved' : 'slip_rejected',
            $b,
            null,
            ['จำนวน' => money0($amount), 'เหตุผล' => $reason !== '' ? $reason : '-']
        );
        if ($queued) {
            self::flush();
        }
    }

    public static function slipUploaded(array $b, float $amount): void
    {
        $msg = "💰 ลูกค้าแจ้งโอนเงิน\n"
            . "การจอง: {$b['booking_no']}\n"
            . "ลูกค้า: {$b['contact_name']}\n"
            . 'จำนวน: ' . money($amount) . " บาท\n"
            . 'กรุณาตรวจสอบและกดยืนยันในระบบหลังบ้าน';
        self::queue('line', setting('line_admin_user_id'), $msg, null, 'bookings', (int) $b['id']);
        self::queue('email', setting('notify_admin_email'), $msg, 'แจ้งโอน ' . $b['booking_no'], 'bookings', (int) $b['id']);
        self::flush();
    }
}
