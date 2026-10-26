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

    /** ส่งอีเมลผ่าน SMTP (ไม่ต้องลง PHPMailer — ใช้ socket ตรง) @return array{0:bool,1:?string} */
    public static function smtpSend(string $to, string $subject, string $htmlBody): array
    {
        // ต้องเป็นที่อยู่อีเมลล้วนๆ (ปฏิเสธขึ้นบรรทัดใหม่ที่อาจใช้ฉีดหัวจดหมายเพิ่ม)
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [false, 'อีเมลปลายทางไม่ถูกต้อง'];
        }

        // ไม่ได้ตั้งค่า SMTP → ใช้ mail() ของ cPanel
        if (!setting('smtp_host')) {
            return self::sendWithMail($to, $subject, $htmlBody);
        }
        return self::sendWithSmtp($to, $subject, $htmlBody);
    }

    /** ส่งด้วยฟังก์ชัน mail() ของ PHP */
    private static function sendWithMail(string $to, string $subject, string $htmlBody): array
    {
        $fromName = setting('smtp_from_name', 'thonglorcarrent');
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= 'From: ' . mb_encode_mimeheader($fromName) . ' <' . setting('email', 'no-reply@localhost') . ">\r\n";
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
        return [$ok, $ok ? null : 'mail() ส่งไม่สำเร็จ'];
    }

    /** ส่งด้วย SMTP: เชื่อมต่อ → (TLS) → ล็อกอิน → ระบุผู้รับ → ส่งเนื้อหา */
    private static function sendWithSmtp(string $to, string $subject, string $htmlBody): array
    {
        $host   = setting('smtp_host');
        $port   = (int) setting('smtp_port', '587');
        $user   = setting('smtp_user');
        $pass   = setting('smtp_pass');
        $secure = setting('smtp_secure', 'tls');

        $transport = ($secure === 'ssl') ? 'ssl://' : '';
        $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 20);
        if (!$fp) {
            return [false, "เชื่อมต่อ SMTP ไม่ได้: $errstr"];
        }
        stream_set_timeout($fp, 20);

        self::smtpRead($fp);   // ข้อความต้อนรับจากเซิร์ฟเวอร์
        $hello = 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        self::smtpCommand($fp, $hello);

        if ($secure === 'tls') {
            self::smtpCommand($fp, 'STARTTLS');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                return [false, 'เปิด TLS ไม่สำเร็จ'];
            }
            self::smtpCommand($fp, $hello);
        }

        if ($user) {
            self::smtpCommand($fp, 'AUTH LOGIN');
            self::smtpCommand($fp, base64_encode($user));
            $reply = self::smtpCommand($fp, base64_encode($pass));
            if (!str_starts_with(trim($reply), '235')) {
                fclose($fp);
                return [false, 'SMTP ยืนยันตัวตนไม่ผ่าน'];
            }
        }

        $from = $user ?: setting('email', 'no-reply@localhost');
        self::smtpCommand($fp, 'MAIL FROM: <' . $from . '>');
        $reply = self::smtpCommand($fp, 'RCPT TO: <' . $to . '>');
        if (!preg_match('/^25\d/', trim($reply))) {
            fclose($fp);
            return [false, 'ปลายทางไม่ถูกต้อง: ' . trim($reply)];
        }
        self::smtpCommand($fp, 'DATA');

        fwrite($fp, self::mailContent($from, $to, $subject, $htmlBody) . "\r\n.\r\n");
        $reply = trim(self::smtpRead($fp));
        self::smtpCommand($fp, 'QUIT');
        fclose($fp);

        $ok = str_starts_with($reply, '250');
        return [$ok, $ok ? null : $reply];
    }

    /** อ่านคำตอบของเซิร์ฟเวอร์ SMTP (คำตอบหลายบรรทัดจบที่บรรทัดที่ตัวอักษรที่ 4 เป็นช่องว่าง) */
    private static function smtpRead($fp): string
    {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    }

    /** ส่งคำสั่งหนึ่งบรรทัดแล้วรอคำตอบ */
    private static function smtpCommand($fp, string $command): string
    {
        fwrite($fp, $command . "\r\n");
        return self::smtpRead($fp);
    }

    /** หัวจดหมาย + เนื้อหา (เข้ารหัส base64) */
    private static function mailContent(string $from, string $to, string $subject, string $htmlBody): string
    {
        $fromName = setting('smtp_from_name', 'thonglorcarrent');
        $headers  = 'From: ' . mb_encode_mimeheader($fromName) . " <$from>\r\n";
        $headers .= "To: <$to>\r\n";
        $headers .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n\r\n";
        return $headers . chunk_split(base64_encode($htmlBody));
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
            [$ok, $err] = self::deliver($n);
            Notification::markResult((int) $n['id'], (bool) $ok, $err);
            if ($ok) {
                $sent++;
            }
        }
        return $sent;
    }

    /** ส่งแจ้งเตือน 1 รายการตามช่องทาง @return array{0:bool,1:?string} */
    private static function deliver(array $n): array
    {
        if ($n['channel'] === 'line' && setting('notify_line_enabled') === '1') {
            $to = $n['recipient'] ?: setting('line_admin_user_id');
            if (!$to) {
                return [false, 'ไม่มี LINE user id ปลายทาง'];
            }
            return LineService::pushTo($to, $n['message']);
        }
        if ($n['channel'] === 'email' && setting('notify_email_enabled') === '1') {
            $to = $n['recipient'] ?: setting('notify_admin_email');
            if (!$to) {
                return [false, 'ไม่มีอีเมลปลายทาง'];
            }
            return self::smtpSend($to, $n['subject'] ?: 'thonglorcarrent', nl2br(e($n['message'])));
        }
        return [false, 'ปิดการใช้งานช่องทางนี้'];
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
        // แยกรายการที่เก็บเงินจริง กับรายการที่พนักงานยกเว้น
        $charged = [];
        $waived  = [];
        $extra   = 0.0;
        foreach ($lines as $line) {
            if (!empty($line['waived'])) {
                $waived[] = $line;
            } elseif ((float) $line['amount'] > 0) {
                $charged[] = $line;
                $extra += (float) $line['amount'];
            }
        }
        $extra = round($extra, 2);

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
