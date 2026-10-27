<?php
namespace App\Services;

/**
 * เรียก LINE Messaging API (push) ด้วย cURL — ไม่ใช้ไลบรารี
 * ผลลัพธ์ [ok, error, retry]: retry = ลองใหม่ภายหลังได้ (เครือข่ายล่ม/โดนจำกัด/ฝั่ง LINE ขัดข้อง)
 * ส่วน 4xx อื่น (token ผิด, user id ผิด) ลองซ้ำก็ไม่หาย → เลิกและให้เจ้าของแก้ค่าตั้ง
 * token ไม่ถูกเขียนลง error/log ที่ไหนทั้งสิ้น
 */
final class LineClient
{
    public const MAX_TEXT = 4900;   // LINE รับข้อความละไม่เกิน 5,000 ตัวอักษร

    public static function base(): string
    {
        return rtrim((string) config('line_api_base', 'https://api.line.me'), '/');
    }

    /** เฉพาะ user id (U...), group id (C...), room id (R...) ที่เป็น hex 32 ตัว */
    public static function validTarget(string $id): bool
    {
        return preg_match('/^[UCR][0-9a-f]{32}\z/', $id) === 1;
    }

    /** @return array{0:bool,1:?string,2:bool} */
    public static function push(string $token, string $to, string $text, ?string $retryKey = null, int $timeout = 6): array
    {
        if ($token === '') {
            return [false, 'ยังไม่ได้ใส่ Channel access token', false];
        }
        if (!self::validTarget($to)) {
            return [false, 'รหัสผู้รับ LINE ไม่ถูกต้อง', false];
        }
        if (!function_exists('curl_init')) {
            return [false, 'เซิร์ฟเวอร์ไม่มี PHP cURL', false];
        }
        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
        if ($retryKey !== null) {
            $headers[] = 'X-Line-Retry-Key: ' . $retryKey;
        }
        $body = json_encode([
            'to'       => $to,
            'messages' => [['type' => 'text', 'text' => mb_substr($text, 0, self::MAX_TEXT)]],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::base() . '/v2/bot/message/push');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return [false, 'เชื่อมต่อ LINE ไม่ได้: ' . mb_substr($err, 0, 120), true];
        }
        if ($code >= 200 && $code < 300) {
            return [true, null, false];
        }
        if ($code === 409 && $retryKey !== null) {
            return [true, null, false];   // retry key นี้เคยส่งสำเร็จแล้ว — LINE ไม่ส่งซ้ำ
        }
        $detail = '';
        $json = json_decode((string) $resp, true);
        if (is_array($json) && isset($json['message']) && is_string($json['message'])) {
            $detail = mb_substr($json['message'], 0, 120);
        }
        $retry = $code === 429 || $code >= 500;
        return [false, self::hint($code) . ($detail !== '' ? " ($detail)" : ''), $retry];
    }

    private static function hint(int $code): string
    {
        switch ($code) {
            case 401:
                return 'LINE ปฏิเสธ token (HTTP 401) — ตรวจ Channel access token';
            case 400:
                return 'LINE ปฏิเสธคำขอ (HTTP 400) — ตรวจรหัสผู้รับ และผู้รับต้องเพิ่มบัญชี LINE OA ของร้านเป็นเพื่อนก่อน';
            case 403:
                return 'LINE ไม่อนุญาต (HTTP 403)';
            case 429:
                return 'LINE จำกัดจำนวนข้อความ/โควตาเต็ม (HTTP 429)';
            default:
                return 'LINE ตอบกลับผิดปกติ (HTTP ' . $code . ')';
        }
    }
}
