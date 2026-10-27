<?php
namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Setting;

/**
 * แจ้งเตือนร้านทาง LINE (จองใหม่ / สลิปใหม่ / ลูกค้าเซ็นแล้ว)
 *
 * หลักการ:
 *   1) เข้าคิวในตาราง notifications ก่อนเสมอ แล้วค่อยส่ง — LINE ล่มหรือช้าไม่ทำให้การจอง/สลิป/เซ็นล้มตาม
 *   2) ทุกจุดที่เรียก "ห้ามโยน exception" — แจ้งเตือนพังไม่กระทบงานหลัก (บันทึก error_log ไว้)
 *   3) ส่งหลังตอบหน้าเว็บเสร็จ (fastcgi_finish_request ถ้ามี) ที่เหลือ/ที่พลาดให้ cron ส่งต่อ ลองใหม่ถ่วงเวลา 1น → 5น → 30น → 2ชม.
 *   4) หลาย process ส่งพร้อมกันไม่ซ้ำ: claim แบบ atomic + dedupe_key + X-Line-Retry-Key ฝั่ง LINE
 */
final class NotificationService
{
    public const MAX_ATTEMPTS = 5;
    public const RECIPIENT_LIMIT = 10;
    /** วินาทีที่รอก่อนลองใหม่ หลังพลาดครั้งที่ 1, 2, 3, 4 */
    private const BACKOFF = [60, 300, 1800, 7200];

    /** เทสต์ปิดเพื่อควบคุมจังหวะส่งเอง */
    public static bool $autoFlush = true;
    private static bool $shutdownHooked = false;

    // ---------------------------------------------------------------- เหตุการณ์

    public static function newBooking(array $booking, array $car): void
    {
        self::guard(static function () use ($booking, $car): void {
            $text = "จองใหม่ " . $booking['booking_no'] . "\n"
                . 'รถ: ' . self::line($car['brand'] . ' ' . $car['model'], 60) . ' (' . self::line((string) $car['plate'], 20) . ")\n"
                . 'ลูกค้า: ' . self::line((string) $booking['contact_name'], 60) . ' โทร ' . self::line((string) $booking['contact_phone'], 20) . "\n"
                . 'รับ: ' . thai_date($booking['pickup_at'], true) . "\n"
                . 'คืน: ' . thai_date($booking['return_at'], true) . "\n"
                . 'ยอดรวม ฿' . money0($booking['grand_total']) . ' · มัดจำ ฿' . money0($booking['deposit_amount']) . "\n"
                . absolute_url('/admin/bookings/' . (int) $booking['id']);
            self::shop('new_booking', 'notify_new_booking', $text, 'bookings', (int) $booking['id'], (string) (int) $booking['id']);
        });
    }

    public static function newSlip(array $booking, int $paymentId, float $amount): void
    {
        self::guard(static function () use ($booking, $paymentId, $amount): void {
            $text = 'สลิปใหม่รอตรวจ ' . $booking['booking_no'] . "\n"
                . 'ลูกค้า: ' . self::line((string) $booking['contact_name'], 60) . "\n"
                . 'ยอดที่แจ้งโอน ฿' . money0($amount) . "\n"
                . absolute_url('/admin/payments');
            self::shop('new_slip', 'notify_new_slip', $text, 'payments', $paymentId, (string) $paymentId);
        });
    }

    public static function signed(array $booking, int $signatureId, string $kind, string $signerName): void
    {
        self::guard(static function () use ($booking, $signatureId, $kind, $signerName): void {
            $label = SignatureService::kinds()[$kind][0] ?? 'เอกสาร';
            $text = 'ลูกค้าเซ็นแล้ว: ' . $label . ' ' . $booking['booking_no'] . "\n"
                . 'ผู้เซ็น: ' . self::line($signerName, 60) . "\n"
                . absolute_url('/admin/bookings/' . (int) $booking['id'] . '/contract');
            self::shop('signed', 'notify_signed', $text, 'signatures', $signatureId, (string) $signatureId);
        });
    }

    // ---------------------------------------------------------------- ตั้งค่า

    public static function enabled(): bool
    {
        return Setting::get('notify_line_enabled', '0') === '1' && Setting::get('line_channel_token') !== '';
    }

    /** @return string[] รหัสผู้รับที่ถูกต้อง ไม่ซ้ำ (ผู้ใช้กรอกคั่นด้วยบรรทัด/คอมมา/ช่องว่าง) */
    public static function recipients(?string $raw = null): array
    {
        $parts = preg_split('/[\s,;]+/', $raw ?? Setting::get('line_admin_user_id'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ok = [];
        foreach ($parts as $p) {
            if (LineClient::validTarget($p) && !in_array($p, $ok, true)) {
                $ok[] = $p;
            }
        }
        return array_slice($ok, 0, self::RECIPIENT_LIMIT);
    }

    // ---------------------------------------------------------------- คิว

    /** @return int จำนวนแถวที่เข้าคิว */
    private static function shop(string $event, string $toggle, string $text, string $refTable, int $refId, string $dedupeId): int
    {
        if (!self::enabled() || Setting::get($toggle, '1') !== '1') {
            return 0;
        }
        $n = 0;
        foreach (self::recipients() as $to) {
            $n += Notification::queue('line', $to, $text, $refTable, $refId, $event . ':' . $dedupeId . ':' . $to) > 0 ? 1 : 0;
        }
        if ($n > 0) {
            self::flushAfterResponse();
        }
        return $n;
    }

    private static function guard(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            error_log('[notify] ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /** ส่งหลังตอบหน้าเว็บ — ลูกค้าไม่ต้องรอ LINE */
    private static function flushAfterResponse(): void
    {
        if (!self::$autoFlush || self::$shutdownHooked) {
            return;
        }
        self::$shutdownHooked = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
            }
            self::guard(static fn() => self::flush(5, 8.0));
        });
    }

    /**
     * ส่งแถวที่ถึงเวลา
     * @param float $budget วินาทีสูงสุดที่ใช้ — หมดแล้วหยุด ที่เหลือรอบหน้า
     * @return array{sent:int,retry:int,failed:int}
     */
    public static function flush(int $limit = 20, float $budget = 30.0): array
    {
        $out = ['sent' => 0, 'retry' => 0, 'failed' => 0];
        $start = microtime(true);
        foreach (Notification::dueIds($limit) as $id) {
            if (microtime(true) - $start >= $budget) {
                break;
            }
            if (!Notification::claim($id)) {
                continue;   // process อื่นจองไปแล้ว
            }
            $row = Notification::find($id);
            if ($row === null) {
                continue;
            }
            try {
                [$ok, $err, $retry] = self::deliver($row);
            } catch (\Throwable $e) {
                [$ok, $err, $retry] = [false, 'ข้อผิดพลาดภายใน: ' . get_class($e), true];
                error_log('[notify] ' . $e->getMessage());
            }
            if ($ok) {
                Notification::markSent($id);
                $out['sent']++;
                continue;
            }
            $attempts = (int) $row['attempts'];
            if ($retry && $attempts < self::MAX_ATTEMPTS) {
                Notification::markFailed($id, (string) $err, self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1]);
                $out['retry']++;
            } else {
                Notification::markFailed($id, (string) $err, null);
                $out['failed']++;
            }
        }
        return $out;
    }

    /** @return array{0:bool,1:?string,2:bool} */
    private static function deliver(array $row): array
    {
        if ($row['channel'] !== 'line') {
            return [false, 'ยังไม่รองรับช่องทางนี้', false];
        }
        if (!self::enabled()) {
            return [false, 'ปิดการแจ้งเตือน LINE อยู่ (หรือยังไม่ได้ใส่ token)', false];
        }
        return LineClient::push(Setting::get('line_channel_token'), (string) $row['recipient'], (string) $row['message'], $row['retry_key']);
    }

    /** ปุ่ม "ส่งข้อความทดสอบ" ในหลังบ้าน — ส่งตรง ไม่ผ่านคิว @return array{0:bool,1:?string} */
    public static function sendTest(string $to, ?int $userId): array
    {
        [$ok, $err] = LineClient::push(
            Setting::get('line_channel_token'),
            $to,
            'ทดสอบการแจ้งเตือนจาก ' . Setting::get('site_name', 'ระบบจองรถ') . ' — ถ้าเห็นข้อความนี้ แปลว่าตั้งค่าถูกต้อง',
            null,
            10
        );
        ActivityLog::log('line_test', null, null, $ok ? 'ok' : (string) $err, $userId);
        return [$ok, $err];
    }

    /** งานของ cron: คืนแถวที่ค้าง → ส่งที่ถึงเวลา → ลบประวัติเก่า @return string[] */
    public static function cron(): array
    {
        $lines = [];
        $stale = Notification::recoverStale(10);
        if ($stale > 0) {
            $lines[] = "notifications recovered from stuck 'sending': $stale";
        }
        $r = self::flush(50, 40.0);
        if ($r['sent'] + $r['retry'] + $r['failed'] > 0) {
            $lines[] = "notifications sent: {$r['sent']}, will retry: {$r['retry']}, failed: {$r['failed']}";
        }
        $purged = Notification::purge(60, 180);
        if ($purged > 0) {
            $lines[] = "notifications purged: $purged";
        }
        return $lines;
    }

    /** ข้อความบรรทัดเดียว ตัดอักขระควบคุม (กันลูกค้าแทรกบรรทัดปลอมในข้อความที่ส่งเข้า LINE ร้าน) */
    private static function line(string $s, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s)), 0, $max);
    }
}
