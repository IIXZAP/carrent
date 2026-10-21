<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Notification;

/**
 * เชื่อมต่อ LINE Official Account
 *
 *  - เรียก Messaging API (push / multicast / reply / rich menu / โควตา)
 *  - ตรวจลายเซ็น webhook
 *  - ตรวจ ID token จาก LIFF (ยืนยันว่าเป็นผู้ใช้ LINE คนนั้นจริง)
 *  - แบบข้อความแจ้งเตือนถึงลูกค้า แก้ได้จากหลังบ้าน
 *
 * ไม่พึ่งไลบรารีภายนอก ใช้ cURL ที่มีใน cPanel ทุกเจ้า
 */
final class LineService
{
    // ------------------------------------------------------------
    // ที่อยู่ API — ปกติไม่ต้องแตะ
    // (เปิดให้กำหนดค่าคงที่ทับได้ เพื่อชี้ไปเซิร์ฟเวอร์จำลองตอนทดสอบ)
    // ------------------------------------------------------------
    public static function apiBase(): string
    {
        return defined('LINE_API_BASE') ? rtrim((string) LINE_API_BASE, '/') : 'https://api.line.me';
    }

    public static function dataBase(): string
    {
        return defined('LINE_DATA_BASE') ? rtrim((string) LINE_DATA_BASE, '/') : 'https://api-data.line.me';
    }

    /** ลิงก์เปิดหน้า "การจองของฉัน" ในแอป LINE — ถ้ายังไม่ตั้ง LIFF จะพาไปหน้าตรวจสอบการจองบนเว็บแทน */
    public static function liffUrl(array $query = []): string
    {
        $liff = trim((string) setting('line_liff_id'));
        $qs   = $query ? '?' . http_build_query($query) : '';
        return $liff !== '' ? 'https://liff.line.me/' . $liff . $qs : site_url('track') . $qs;
    }

    /** LIFF ID มีรูปแบบ "เลขแชนแนล-รหัส" — เลขแชนแนลคือ LINE Login channel ที่ใช้ตรวจ ID token */
    public static function loginChannelId(): string
    {
        $liff = trim((string) setting('line_liff_id'));
        return $liff !== '' ? explode('-', $liff)[0] : '';
    }

    public static function ready(): bool
    {
        return trim((string) setting('line_channel_token')) !== '';
    }

    // ------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------
    /** @return array{0:int,1:string,2:string} [http code, body, error] */
    public static function http(string $method, string $url, array $headers = [], $body = null, int $timeout = 15): array
    {
        if (!function_exists('curl_init')) {
            return [0, '', 'เซิร์ฟเวอร์นี้ไม่ได้เปิด cURL'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$code, $res === false ? '' : (string) $res, $err];
    }

    /**
     * เรียก Messaging API แบบ JSON
     * @return array{0:bool,1:?array,2:?string} [สำเร็จไหม, ข้อมูลตอบกลับ, ข้อความผิดพลาด]
     */
    public static function api(string $method, string $path, ?array $json = null): array
    {
        $token = trim((string) setting('line_channel_token'));
        if ($token === '') {
            return [false, null, 'ยังไม่ได้ตั้งค่า LINE Channel Access Token'];
        }
        $headers = ['Authorization: Bearer ' . $token];
        $body    = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $body      = json_encode($json, JSON_UNESCAPED_UNICODE);
        }
        [$code, $res, $err] = self::http($method, self::apiBase() . $path, $headers, $body);
        $data = $res !== '' ? json_decode($res, true) : null;
        if ($code >= 200 && $code < 300) {
            return [true, is_array($data) ? $data : [], null];
        }
        $msg = $err ?: ($data['message'] ?? mb_substr($res, 0, 200));
        if (!empty($data['details'][0]['message'])) {
            $msg .= ' — ' . $data['details'][0]['message'];
        }
        return [false, is_array($data) ? $data : null, 'LINE API ' . $code . ': ' . self::errorHint($code, (string) $msg)];
    }

    /** แปลข้อผิดพลาดที่เจอบ่อยให้เป็นคำที่แก้ได้เอง */
    private static function errorHint(int $code, string $msg): string
    {
        if ($code === 0) {
            return 'เชื่อมต่อ LINE ไม่ได้ (' . $msg . ')';
        }
        if ($code === 401) {
            return 'Channel Access Token ไม่ถูกต้องหรือหมดอายุ ให้ออกใหม่ใน LINE Developers';
        }
        if ($code === 429) {
            return 'ส่งข้อความเกินโควตาของแพ็กเกจ LINE OA เดือนนี้';
        }
        if ($code === 400 && str_contains($msg, 'not found')) {
            return 'ไม่พบผู้รับ — ผู้ใช้อาจยังไม่ได้เพิ่มเพื่อนกับ LINE OA ของร้าน';
        }
        return $msg;
    }

    /** ห่อข้อความธรรมดาให้เป็นรูปแบบที่ LINE ต้องการ (ตัดที่ 4,900 ตัวอักษร เผื่อขีดจำกัด 5,000) */
    private static function text(string $text): array
    {
        return ['type' => 'text', 'text' => mb_substr($text, 0, 4900)];
    }

    /**
     * ส่งข้อความหาผู้ใช้ — รับได้ทั้ง id เดียว หลาย id คั่นจุลภาค หรือ array
     * หลายคนจะส่งแบบ multicast ครั้งเดียว (ใช้กับเจ้าของร้าน + พนักงานที่รับแจ้งเตือนพร้อมกัน)
     * @return array{0:bool,1:?string}
     */
    public static function pushTo($to, string $message): array
    {
        $ids = is_array($to) ? $to : preg_split('/[\s,;]+/', (string) $to, -1, PREG_SPLIT_NO_EMPTY);
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
        if (!$ids) {
            return [false, 'ไม่มี LINE user id ปลายทาง'];
        }
        // คนเดียวใช้ push หลายคนใช้ multicast
        $path = count($ids) === 1 ? '/v2/bot/message/push' : '/v2/bot/message/multicast';
        $to   = count($ids) === 1 ? $ids[0] : $ids;
        [$ok, , $err] = self::api('POST', $path, ['to' => $to, 'messages' => [self::text($message)]]);
        return [$ok, $err];
    }

    /** ตอบกลับข้อความที่ลูกค้าส่งมา — ข้อความตอบกลับไม่นับโควตาของแพ็กเกจ */
    public static function reply(string $replyToken, string $message): array
    {
        [$ok, , $err] = self::api('POST', '/v2/bot/message/reply', [
            'replyToken' => $replyToken,
            'messages'   => [self::text($message)],
        ]);
        return [$ok, $err];
    }

    public static function profile(string $userId): ?array
    {
        [$ok, $data] = self::api('GET', '/v2/bot/profile/' . rawurlencode($userId));
        return $ok ? $data : null;
    }

    // ------------------------------------------------------------
    // ความปลอดภัย
    // ------------------------------------------------------------
    /** ตรวจว่าคำขอ webhook มาจาก LINE จริง (ลายเซ็น HMAC-SHA256 ด้วย Channel Secret) */
    public static function verifySignature(string $rawBody, string $signature): bool
    {
        $secret = trim((string) setting('line_channel_secret'));
        if ($secret === '' || $signature === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        return hash_equals($expected, $signature);
    }

    /**
     * ตรวจ ID token ที่ได้จาก LIFF กับเซิร์ฟเวอร์ของ LINE
     * ต้องตรวจฝั่งเซิร์ฟเวอร์เสมอ — ห้ามเชื่อ user id ที่หน้าเว็บส่งมาเอง เพราะปลอมได้
     * @return ?array ['sub' => LINE user id, 'name' => ..., 'picture' => ...]
     */
    public static function verifyIdToken(string $idToken): ?array
    {
        $clientId = self::loginChannelId();
        if ($clientId === '' || $idToken === '') {
            return null;
        }
        [$code, $res] = self::http(
            'POST',
            self::apiBase() . '/oauth2/v2.1/verify',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['id_token' => $idToken, 'client_id' => $clientId])
        );
        $data = json_decode($res, true);
        if ($code !== 200 || !is_array($data) || empty($data['sub'])) {
            return null;
        }
        if ((string) ($data['aud'] ?? '') !== $clientId) {
            return null;
        }
        if (isset($data['exp']) && (int) $data['exp'] < time()) {
            return null;
        }
        return [
            'sub'     => (string) $data['sub'],
            'name'    => (string) ($data['name'] ?? ''),
            'picture' => (string) ($data['picture'] ?? ''),
        ];
    }

    // ------------------------------------------------------------
    // แบบข้อความถึงลูกค้า
    // ------------------------------------------------------------
    /** @return array<string,array{0:string,1:string,2:string}> key => [ชื่อ, ส่งเมื่อไหร่, ข้อความตั้งต้น] */
    public static function templates(): array
    {
        return [
            'welcome' => [
                'ข้อความต้อนรับ',
                'ลูกค้าเพิ่มเพื่อนกับ LINE OA ของร้าน (ไม่นับโควตา)',
                "สวัสดีครับ ขอบคุณที่เพิ่มเพื่อนกับ {ร้าน} 🚗\n\n"
                . "กดลิงก์ด้านล่างเพื่อดูการจองและแจ้งโอนเงินได้ในแอป LINE เลย\n{ลิงก์}\n\n"
                . "สอบถามเพิ่มเติม โทร {โทร}",
            ],
            'linked' => [
                'ผูกบัญชีสำเร็จ',
                'ลูกค้ายืนยันเลขที่จองกับเบอร์โทรในหน้า "การจองของฉัน" (ทำ 1 ครั้งต่อการจอง)',
                "ผูกบัญชี LINE กับการจองเรียบร้อยครับ คุณ{ชื่อ}\n\n"
                . "จากนี้ร้านจะแจ้งความคืบหน้าการจองให้ทาง LINE นี้\n"
                . "ดูการจองทั้งหมด: {ลิงก์}",
            ],
            'booking_received' => [
                'รับการจองแล้ว',
                'ลูกค้าจองรถจากในแอป LINE (เปิดหน้าจองต่อจากหน้า "การจองของฉัน")',
                "ได้รับการจองแล้วครับ ✅\n\n"
                . "เลขที่จอง: {เลขจอง}\nรถ: {รถ}\nรับรถ: {วันรับ}\nคืนรถ: {วันคืน}\n\n"
                . "กรุณาโอนมัดจำ {มัดจำ} บาท แล้วแนบสลิปที่นี่\n{ลิงก์}",
            ],
            'confirmed' => [
                'ยืนยันการจอง',
                'ร้านกดยืนยันการจอง หรืออนุมัติสลิปมัดจำ',
                "ยืนยันการจองเรียบร้อยครับ ✅\n\n"
                . "เลขที่จอง: {เลขจอง}\nรถ: {รถ} ทะเบียน {ทะเบียน}\n"
                . "รับรถ: {วันรับ}\nจุดรับรถ: {จุดรับ}\nคืนรถ: {วันคืน}\n\n"
                . "วันรับรถเตรียมชำระ {คงเหลือ} บาท + เงินประกัน {เงินประกัน} บาท (ได้คืนเมื่อคืนรถ)\n"
                . "เอกสารที่ต้องนำมา: บัตรประชาชนและใบขับขี่ตัวจริง\n\n"
                . "ดูรายละเอียด: {ลิงก์}",
            ],
            'slip_approved' => [
                'ได้รับเงินแล้ว',
                'ร้านอนุมัติสลิปโอนเงิน',
                "ได้รับยอดโอน {จำนวน} บาท เรียบร้อยครับ 🙏\n\n"
                . "เลขที่จอง: {เลขจอง}\nยอดคงเหลือ: {คงเหลือ} บาท\n\nดูรายละเอียด: {ลิงก์}",
            ],
            'slip_rejected' => [
                'สลิปไม่ผ่าน',
                'ร้านปฏิเสธสลิปโอนเงิน',
                "สลิปที่แจ้งมายังตรวจไม่ผ่านครับ\n\n"
                . "เลขที่จอง: {เลขจอง}\nเหตุผล: {เหตุผล}\n\n"
                . "กรุณาแนบสลิปใหม่ที่ {ลิงก์}\nหรือโทร {โทร}",
            ],
            'pickup_reminder' => [
                'เตือนวันรับรถ',
                'ล่วงหน้า 1 วันก่อนรับรถ (ส่งครั้งเดียวต่อการจอง)',
                "เตือนนัดรับรถครับ 🚗\n\n"
                . "{รถ} ทะเบียน {ทะเบียน}\nรับรถ: {วันรับ}\nจุดรับรถ: {จุดรับ}\n\n"
                . "เตรียมชำระ {คงเหลือ} บาท + เงินประกัน {เงินประกัน} บาท\n"
                . "อย่าลืมบัตรประชาชนและใบขับขี่ตัวจริงนะครับ\n\nติดต่อร้าน: {โทร}",
            ],
            'return_reminder' => [
                'เตือนวันคืนรถ',
                'ล่วงหน้า 1 วันก่อนครบกำหนดคืนรถ (ส่งครั้งเดียวต่อการจอง)',
                "ใกล้ครบกำหนดคืนรถแล้วครับ\n\n"
                . "{รถ} ทะเบียน {ทะเบียน}\nคืนรถ: {วันคืน}\nจุดคืนรถ: {จุดคืน}\n\n"
                . "กรุณาเติมน้ำมันให้เท่ากับตอนรับรถ\n"
                . "หากต้องการเช่าต่อ แจ้งร้านล่วงหน้าได้ที่ {โทร}",
            ],
            'sign_request' => [
                'ขอให้เซ็นเอกสาร',
                'พนักงานกดปุ่ม "ส่งลิงก์ทาง LINE" ในกล่องลายเซ็นลูกค้าของหน้าการจอง',
                "กรุณาตรวจข้อมูลและเซ็น{เอกสาร}ครับ ✍️\n\n"
                . "เลขที่จอง: {เลขจอง}\nรถ: {รถ} ทะเบียน {ทะเบียน}\n\n"
                . "เซ็นได้บนมือถือ ใช้เวลาไม่ถึง 1 นาที\n{ลิงก์เซ็น}",
            ],
            'extended' => [
                'เช่าต่อ / เลื่อนวันคืน',
                'ร้านบันทึกการเช่าต่อ (เลื่อนกำหนดคืนรถออกไป)',
                "เลื่อนกำหนดคืนรถเรียบร้อยครับ ✅\n\n"
                . "เลขที่จอง: {เลขจอง}\nรถ: {รถ} ทะเบียน {ทะเบียน}\n"
                . "กำหนดคืนใหม่: {วันคืน}\nค่าเช่าเพิ่ม: {จำนวน} บาท\nยอดคงเหลือ: {คงเหลือ} บาท\n\n"
                . "ดูรายละเอียด: {ลิงก์}",
            ],
            'returned' => [
                'รับคืนรถแล้ว',
                'ร้านบันทึกรับคืนรถ — แจ้งค่าใช้จ่ายเพิ่มแยกรายการ (ถ้ามี)',
                "รับคืนรถเรียบร้อยครับ ขอบคุณที่ใช้บริการ {ร้าน} 🙏\n\n"
                . "เลขที่จอง: {เลขจอง}\nรถ: {รถ} ทะเบียน {ทะเบียน}\n\n"
                . "{รายการ}\n\nยอดคงเหลือ: {คงเหลือ} บาท\n"
                . "ดูรายละเอียด: {ลิงก์}",
            ],
            'deposit_refunded' => [
                'คืนเงินประกัน',
                'ร้านกดปิดเงินประกันหลังรับคืนรถ',
                "ปิดเงินประกันเรียบร้อยครับ\n\n"
                . "เลขที่จอง: {เลขจอง}\nคืนให้ลูกค้า: {คืนเงิน} บาท\nหักชำระค่าใช้จ่าย: {หัก} บาท\n\n"
                . "ขอบคุณที่ใช้บริการ {ร้าน} โอกาสหน้าเชิญใหม่ครับ 🙏",
            ],
        ];
    }

    /** ข้อความที่ใช้จริง = ที่แก้ไว้ในหลังบ้าน ถ้าไม่เคยแก้ใช้ข้อความตั้งต้น */
    public static function template(string $key): string
    {
        $all = self::templates();
        if (!isset($all[$key])) {
            return '';
        }
        return (string) setting('tpl_' . $key, $all[$key][2]);
    }

    /** แบบข้อความนี้ถูกปิดไว้หรือไม่ (ติ๊กปิดในหลังบ้าน) */
    public static function templateOff(string $key): bool
    {
        $off = preg_split('/[\s,]+/', (string) setting('tpl_off'), -1, PREG_SPLIT_NO_EMPTY);
        return in_array($key, $off, true);
    }

    /** แทนค่า {ตัวแปร} ในแบบข้อความ */
    public static function fill(string $template, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{' . $k . '}'] = (string) $v;
        }
        return strtr($template, $map);
    }

    /** ตัวแปรทั้งหมดที่ใช้ได้ในแบบข้อความ พร้อมคำอธิบาย (แสดงในหน้าแก้ข้อความ) */
    public static function templateVars(): array
    {
        return [
            'ชื่อ' => 'ชื่อผู้เช่า', 'เลขจอง' => 'เลขที่จอง', 'รถ' => 'ยี่ห้อ + รุ่น', 'ทะเบียน' => 'ทะเบียนรถ',
            'วันรับ' => 'วัน–เวลารับรถ', 'วันคืน' => 'วัน–เวลาคืนรถ', 'จุดรับ' => 'จุดรับรถ', 'จุดคืน' => 'จุดคืนรถ',
            'ยอดรวม' => 'ค่าเช่ารวม', 'มัดจำ' => 'มัดจำการจอง', 'คงเหลือ' => 'ยอดที่ยังไม่ได้ชำระ',
            'เงินประกัน' => 'เงินประกันความเสียหาย', 'จำนวน' => 'จำนวนเงินในสลิป', 'เหตุผล' => 'เหตุผลที่สลิปไม่ผ่าน',
            'รายการ' => 'รายการค่าใช้จ่ายเพิ่มตอนคืนรถ', 'คืนเงิน' => 'เงินประกันที่คืนลูกค้า', 'หัก' => 'เงินประกันที่หักชำระยอดค้าง',
            'เอกสาร' => 'ชื่อเอกสารที่ขอให้เซ็น (สัญญาเช่ารถ / ใบรับคืนรถ)', 'ลิงก์เซ็น' => 'ลิงก์หน้าเซ็นเอกสาร',
            'ร้าน' => 'ชื่อร้าน', 'โทร' => 'เบอร์โทรร้าน', 'ลิงก์' => 'ลิงก์หน้า "การจองของฉัน"',
        ];
    }

    /** เตรียมค่าตัวแปรจากข้อมูลการจอง */
    public static function bookingVars(array $b, ?array $car = null, array $extra = []): array
    {
        if ($car === null && !empty($b['car_id'])) {
            $car = Car::find((int) $b['car_id']) ?: [];
        }
        $due = (float) ($b['grand_total'] ?? 0) + (float) ($b['extra_charge'] ?? 0);
        return $extra + [
            'ชื่อ'       => (string) ($b['contact_name'] ?? ''),
            'เลขจอง'     => (string) ($b['booking_no'] ?? ''),
            'รถ'         => trim(($car['brand'] ?? '') . ' ' . ($car['model'] ?? '')),
            'ทะเบียน'    => (string) ($car['plate'] ?? ''),
            'วันรับ'     => thai_date($b['pickup_at'] ?? null, true),
            'วันคืน'     => thai_date($b['return_at'] ?? null, true),
            'จุดรับ'     => (string) (($b['pickup_place'] ?? '') ?: 'ที่ร้าน'),
            'จุดคืน'     => (string) (($b['return_place'] ?? '') ?: (($b['pickup_place'] ?? '') ?: 'ที่ร้าน')),
            'ยอดรวม'     => money0($due),
            'มัดจำ'      => money0($b['deposit_amount'] ?? 0),
            'คงเหลือ'    => money0(max(0, $due - (float) ($b['paid_amount'] ?? 0))),
            'เงินประกัน' => money0($b['security_deposit'] ?? 0),
            'จำนวน'      => '',
            'เหตุผล'     => '',
            'รายการ'     => '',
            'คืนเงิน'    => '0',
            'หัก'        => '0',
            'ลิงก์เซ็น'  => '',
            'เอกสาร'     => 'สัญญาเช่ารถ',
            'ร้าน'       => (string) setting('site_name', 'thonglorcarrent'),
            'โทร'        => (string) setting('phone'),
            'ลิงก์'      => self::liffUrl(),
        ];
    }

    /**
     * LINE user id ที่ผูกกับการจองนี้ (ถ้ามี)
     * ดูจากตราบนการจองเท่านั้น — ไม่ย้อนไปดูที่ระเบียนลูกค้า (เหตุผลอยู่ที่ linkBooking)
     */
    public static function userForBooking(array $b): ?string
    {
        if (array_key_exists('line_user_id', $b)) {
            return !empty($b['line_user_id']) ? (string) $b['line_user_id'] : null;
        }
        return !empty($b['id']) ? Booking::lineUserOf((int) $b['id']) : null;
    }

    /**
     * เข้าคิวแจ้งเตือนถึงลูกค้าทาง LINE
     *
     * ไม่ทำอะไรเลยถ้า: ปิดการแจ้งเตือนลูกค้า / ปิดแบบข้อความนี้ / ลูกค้ายังไม่ผูกบัญชี LINE
     * $once = true → การจองเดียวกันส่งแบบข้อความนี้ได้ครั้งเดียว (ใช้กับข้อความเตือนจาก cron)
     *
     * @return bool เข้าคิวแล้วหรือไม่ (ยังไม่ได้ส่งจริง — ต้องเรียก NotificationService::flush ต่อ)
     */
    public static function notifyCustomer(string $tplKey, array $b, ?array $car = null, array $extra = [], bool $once = false): bool
    {
        if (setting('line_notify_customer', '1') !== '1' || self::templateOff($tplKey)) {
            return false;
        }
        $uid = self::userForBooking($b);
        if (!$uid) {
            return false;
        }
        $key = 'cust_' . $tplKey;
        if ($once && Notification::alreadySent($key, (int) $b['id'])) {
            return false;
        }
        $text = self::fill(self::template($tplKey), self::bookingVars($b, $car, $extra));
        Notification::queue('line', $uid, $text, null, 'bookings', (int) $b['id'], $key);
        return true;
    }

    // ------------------------------------------------------------
    // ผูกบัญชี LINE กับลูกค้า
    // ------------------------------------------------------------
    /**
     * ผูก LINE กับ "การจองที่พิสูจน์แล้ว" 1 รายการ (ลูกค้ากรอกเลขที่จอง + เบอร์โทรถูกต้อง)
     *
     * ผูกทีละการจอง ไม่ผูกทั้งระเบียนลูกค้า เพราะระเบียนลูกค้ารวมการจองจากเบอร์โทรเดียวกัน
     * และเบอร์โทรไม่ใช่ความลับ: ถ้าผูกทั้งระเบียน คนที่รู้เบอร์ของลูกค้ารายอื่นจะจองปลอมด้วยเบอร์นั้น
     * แล้วใช้การจองปลอมของตัวเองเปิดดูประวัติการเช่าและรับข้อความแจ้งเตือนของลูกค้ารายนั้นได้ทั้งหมด
     */
    public static function linkBooking(string $uid, array $b): void
    {
        Booking::setLineUser((int) $b['id'], $uid);
        // ระเบียนลูกค้า: จดไว้ให้หลังบ้านเห็นว่าลูกค้ารายนี้ใช้ LINE — ไม่เขียนทับ LINE ที่ผูกไว้ก่อนแล้ว
        // (ช่องนี้ใช้แสดงผลเท่านั้น การส่งแจ้งเตือนและสิทธิ์ดูการจองดูจากตราบนการจองแต่ละรายการ)
        if (!empty($b['customer_id'])) {
            Customer::rememberLine((int) $b['customer_id'], $uid);
        }
    }

    public static function unlinkUser(string $uid): void
    {
        Customer::unlinkLine($uid);
        Booking::unlinkLine($uid);
    }

    // ------------------------------------------------------------
    // Rich menu (เมนูด้านล่างห้องแชต)
    // ------------------------------------------------------------
    /**
     * สร้างเมนู 3 ปุ่ม: จองรถ / การจองของฉัน / ติดต่อร้าน แล้วตั้งเป็นเมนูหลักของทุกคน
     * รูปเมนูอยู่ที่ assets/img/richmenu.png (2500×843)
     * @return array{0:bool,1:string} [สำเร็จไหม, ข้อความ]
     */
    public static function installRichMenu(): array
    {
        $img = APP_ROOT . '/assets/img/richmenu.png';
        if (!is_file($img)) {
            return [false, 'ไม่พบไฟล์รูปเมนู assets/img/richmenu.png'];
        }

        [$id, $error] = self::createRichMenu();
        if ($id === '') {
            return [false, $error];
        }
        $error = self::uploadRichMenuImage($id, $img);
        if ($error !== '') {
            self::api('DELETE', '/v2/bot/richmenu/' . rawurlencode($id));
            return [false, $error];
        }

        [$ok, , $err] = self::api('POST', '/v2/bot/user/all/richmenu/' . rawurlencode($id));
        if (!$ok) {
            return [false, 'ตั้งเป็นเมนูหลักไม่สำเร็จ: ' . $err];
        }

        self::deleteOldRichMenu($id);
        set_setting('line_richmenu_id', $id);
        return [true, 'ติดตั้งเมนูด้านล่างเรียบร้อย ลูกค้าจะเห็นเมื่อเปิดห้องแชตครั้งถัดไป'];
    }

    /** สร้างเมนูบน LINE (ยังไม่มีรูป) @return array{0:string,1:string} [id, ข้อความผิดพลาด] */
    private static function createRichMenu(): array
    {
        $width  = 2500;
        $height = 843;
        [$ok, $data, $err] = self::api('POST', '/v2/bot/richmenu', [
            'size'        => ['width' => $width, 'height' => $height],
            'selected'    => true,
            'name'        => 'thonglorcarrent-main',
            'chatBarText' => 'เมนู',
            'areas'       => self::richMenuAreas($width, $height),
        ]);
        if (!$ok || empty($data['richMenuId'])) {
            return ['', 'สร้างเมนูไม่สำเร็จ: ' . $err];
        }
        return [(string) $data['richMenuId'], ''];
    }

    /** ปุ่ม 3 ช่อง: จองรถ / การจองของฉัน / โทรหาร้าน */
    private static function richMenuAreas(int $width, int $height): array
    {
        $third = (int) floor($width / 3);
        $phone = preg_replace('/[^0-9+]/', '', (string) setting('phone'));

        $contact = ['type' => 'uri', 'label' => 'ติดต่อร้าน', 'uri' => site_url('contact')];
        if ($phone !== '') {
            $contact = ['type' => 'uri', 'label' => 'โทรหาร้าน', 'uri' => 'tel:' . $phone];
        }

        return [
            [
                'bounds' => ['x' => 0, 'y' => 0, 'width' => $third, 'height' => $height],
                'action' => ['type' => 'uri', 'label' => 'จองรถ', 'uri' => site_url('cars')],
            ],
            [
                'bounds' => ['x' => $third, 'y' => 0, 'width' => $third, 'height' => $height],
                'action' => ['type' => 'uri', 'label' => 'การจองของฉัน', 'uri' => self::liffUrl()],
            ],
            [
                'bounds' => ['x' => $third * 2, 'y' => 0, 'width' => $width - $third * 2, 'height' => $height],
                'action' => $contact,
            ],
        ];
    }

    /** อัปโหลดรูปเมนู — ใช้โดเมน api-data และส่งเป็นไฟล์ดิบ @return string ข้อความผิดพลาด ('' = สำเร็จ) */
    private static function uploadRichMenuImage(string $id, string $imagePath): string
    {
        [$code, $res, $curlError] = self::http(
            'POST',
            self::dataBase() . '/v2/bot/richmenu/' . rawurlencode($id) . '/content',
            ['Authorization: Bearer ' . trim((string) setting('line_channel_token')), 'Content-Type: image/png'],
            file_get_contents($imagePath),
            30
        );
        if ($code >= 200 && $code < 300) {
            return '';
        }
        return 'อัปโหลดรูปเมนูไม่สำเร็จ (' . $code . ') ' . ($curlError ?: mb_substr($res, 0, 150));
    }

    /** เมนูเก่าที่ระบบเคยสร้างไว้ไม่ใช้แล้ว — ลบออกเพื่อไม่ให้ค้างเกินโควตา 1,000 เมนู */
    private static function deleteOldRichMenu(string $newId): void
    {
        $old = trim((string) setting('line_richmenu_id'));
        if ($old !== '' && $old !== $newId) {
            self::api('DELETE', '/v2/bot/richmenu/' . rawurlencode($old));
        }
    }
}
