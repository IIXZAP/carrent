<?php
namespace App\Services;

use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Inspection;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Signature;

/**
 * ลายเซ็นอิเล็กทรอนิกส์ของผู้เช่า (port จากระบบเดิม ปรับให้ตรวจทั้งหมดใน transaction เดียวที่ล็อกการจอง)
 *
 *  - ลูกค้าเซ็นผ่านลิงก์เฉพาะของการจอง (รหัสสุ่ม 128 บิต) ไม่ต้องล็อกอิน
 *  - ตอนเซ็นเก็บ "สำเนา ณ เวลานั้น" (ข้อมูลสำคัญ + ข้อความสัญญา + ข้อความ PDPA + รายชื่อรูปตรวจสภาพ)
 *    พร้อม SHA-256, เวลา, IP, อุปกรณ์ — แก้ข้อมูลการจองภายหลังแล้วระบบบอกได้ว่าอะไรต่างจากที่เซ็น
 *  - ลายเซ็นรับรองเฉพาะสิ่งที่ลูกค้าเห็นจริง: หน้าเซ็นฝัง "hash ของสิ่งที่แสดง" แล้วเทียบอีกครั้งตอนยืนยัน
 *    (ในการล็อกการจอง) ถ้าร้านแก้ข้อมูลระหว่างนั้น ต้องให้ลูกค้าตรวจใหม่
 *  - ลายเซ็นไม่ถูกลบ: ขอเซ็นใหม่ = ยกเลิกฉบับเดิม (voided_at) แล้วเก็บประวัติไว้
 *  - ไฟล์ลายเซ็นอยู่ที่เก็บแบบปิด เปิดผ่านลิงก์ตรงไม่ได้
 */
final class SignatureService
{
    private const MIN_INK = 60;

    /** ชนิดเอกสารที่เซ็นได้ → [ชื่อ, สถานะการจองที่เซ็นได้] */
    public static function kinds(): array
    {
        return [
            'contract' => ['สัญญาเช่ารถ', ['pending', 'confirmed', 'picked_up']],
            'return'   => ['ใบรับคืนรถ', ['returned', 'completed']],
        ];
    }

    /** เหตุผลที่เซ็นไม่ได้ในตอนนี้ (null = เซ็นได้) */
    public static function blocker(array $b, string $kind): ?string
    {
        $kinds = self::kinds();
        if (!isset($kinds[$kind])) {
            return 'ไม่พบเอกสารที่ต้องการ';
        }
        if ($b['status'] === 'cancelled') {
            return 'การจองนี้ถูกยกเลิกแล้ว';
        }
        if (!in_array($b['status'], $kinds[$kind][1], true)) {
            return $kind === 'return' ? 'ยังไม่ถึงขั้นตอนคืนรถ ร้านจะส่งลิงก์ให้อีกครั้งเมื่อรับคืนรถแล้ว'
                                      : 'การจองนี้ผ่านขั้นตอนรับรถไปแล้ว ไม่สามารถเซ็นสัญญาย้อนหลังได้';
        }
        return null;
    }

    /** ลิงก์เซ็นสำหรับลูกค้า (path สัมพัทธ์ — ผู้เรียกต่อโดเมนเอง) */
    public static function path(array $b, string $kind = 'contract'): string
    {
        return '/sign?' . http_build_query(['no' => $b['booking_no'], 'k' => $kind, 't' => Booking::signToken((int) $b['id'])]);
    }

    // ------------------------------------------------------------
    // สิ่งที่ลูกค้าเห็น
    // ------------------------------------------------------------

    private static function extraLines(array $b): array
    {
        return array_values((array) json_decode((string) ($b['extra_breakdown'] ?? ''), true));
    }

    private static function inspectionOf(array $b, string $kind): ?array
    {
        return Inspection::get((int) $b['id'], $kind === 'return' ? 'in' : 'out');
    }

    /**
     * ข้อมูลสำคัญที่ผู้เช่ารับรองด้วยลายเซ็น — key => [ป้าย, ค่า]
     * ใช้แสดงในหน้าเซ็น เก็บเป็นสำเนา และเทียบว่ามีการแก้หลังลงนามหรือไม่
     */
    public static function fields(array $b, array $car, string $kind): array
    {
        $f = [
            'renter'  => ['ผู้เช่า', (string) $b['contact_name']],
            'car'     => ['รถ', trim(($car['brand'] ?? '') . ' ' . ($car['model'] ?? '')) . ' ทะเบียน ' . ($car['plate'] ?? '')],
            'pickup'  => ['รับรถ', thai_date($b['pickup_at'], true)],
            'return'  => ['กำหนดคืนรถ', thai_date($b['return_at'], true)],
            'total'   => ['ค่าเช่ารวม', money($b['grand_total']) . ' บาท'],
            'deposit' => ['เงินประกันความเสียหาย', money($b['security_deposit']) . ' บาท'],
        ];
        if ($kind === 'return') {
            $in = self::inspectionOf($b, 'return');
            $f += [
                'returned' => ['รับคืนรถเมื่อ', thai_date($b['returned_at'] ?? null, true)],
                'km_in'    => ['เลขไมล์ตอนคืน', $in && $in['mileage'] !== null ? number_format((int) $in['mileage']) . ' กม.' : '-'],
                'fuel_in'  => ['น้ำมันตอนคืน', $in && $in['fuel_level'] !== null ? (int) $in['fuel_level'] . '/8' : '-'],
                'extra'    => ['ค่าใช้จ่ายเพิ่มตอนคืนรถ', money($b['extra_charge'] ?? 0) . ' บาท'],
                'damage'   => ['ความเสียหายที่บันทึก', trim((string) ($b['damage_note'] ?? '')) ?: 'ไม่มี'],
            ];
        }
        return $f;
    }

    /**
     * ทุกอย่างที่หน้าเซ็นแสดง + hash ของมัน
     * @return array{fields:array,terms_html:string,terms_text:string,pdpa:string,photos:array,extras:array,hash:string,blocker:?string}
     */
    public static function view(array $b, string $kind): array
    {
        $car = Car::find((int) $b['car_id']) ?? [];
        $out = Inspection::get((int) $b['id'], 'out');
        $ctx = $b + [
            'brand' => $car['brand'] ?? '', 'model' => $car['model'] ?? '', 'plate' => $car['plate'] ?? '', 'province' => $car['province'] ?? '',
            'color' => $car['color'] ?? '', 'year' => $car['year'] ?? '',
            'mileage_out' => $out['mileage'] ?? null,
            'paid' => Payment::netPaid((int) $b['id']),
        ];
        $html = view_render('partials/contract_terms', ['b' => $ctx, 'blank' => '<span class="inline-block min-w-[130px] border-b border-dotted border-slate-500 px-1"></span>'], null);
        $text = self::plainText($html);
        $fields = self::fields($b, $car, $kind);
        $ins = self::inspectionOf($b, $kind);
        $photos = $ins ? array_column($ins['photos'], 'file_path') : [];
        $extras = $kind === 'return' ? self::extraLines($b) : [];
        $pdpa = Setting::get('pdpa_policy_text');
        return [
            'fields'     => $fields,
            'terms_html' => $html,
            'terms_text' => $text,
            'pdpa'       => $pdpa,
            'photos'     => $photos,
            'extras'     => $extras,
            'blocker'    => self::blocker($b, $kind),
            'hash'       => hash('sha256', json_encode([$kind, array_column($fields, 1), $text, $photos, $extras, $pdpa], JSON_UNESCAPED_UNICODE)),
        ];
    }

    private static function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</li>', '</p>'], "\n", $html)))));
    }

    // ------------------------------------------------------------
    // ตรวจหลังเซ็น
    // ------------------------------------------------------------

    /** สำเนาที่เก็บตรงกับ hash หรือไม่ — false = ถูกแก้ในฐานข้อมูลโดยตรง */
    public static function intact(array $sig): bool
    {
        return hash_equals((string) $sig['snapshot_hash'], hash('sha256', (string) $sig['snapshot']));
    }

    /** รหัสอ้างอิงสั้น (12 ตัวแรกของ hash) พิมพ์บนสัญญา */
    public static function ref(array $sig): string
    {
        return strtoupper(substr((string) $sig['snapshot_hash'], 0, 12));
    }

    /**
     * ข้อมูลที่ถูกแก้หลังลงนาม — [label, signed, now]
     * @param array $b ข้อมูลการจองปัจจุบัน
     */
    public static function changes(array $sig, array $b, array $car): array
    {
        $snap = json_decode((string) $sig['snapshot'], true) ?: [];
        $kind = (string) $sig['kind'];
        $out = [];
        $old = $snap['fields'] ?? [];
        foreach (self::fields($b, $car, $kind) as $key => [$label, $now]) {
            if (array_key_exists($key, $old) && (string) $old[$key] !== (string) $now) {
                $out[] = ['label' => $label, 'signed' => (string) $old[$key], 'now' => (string) $now];
            }
        }
        // รูปสภาพรถ: เทียบเฉพาะเมื่อตอนเซ็นมีรูปให้ลูกค้าดู
        $oldPics = (array) ($snap['inspection']['files'] ?? []);
        if ($oldPics) {
            $ins = self::inspectionOf($b, $kind);
            $nowPics = $ins ? array_column($ins['photos'], 'file_path') : [];
            sort($nowPics);
            sort($oldPics);
            if ($nowPics !== $oldPics) {
                $out[] = ['label' => 'รูปสภาพรถ', 'signed' => count($oldPics) . ' รูป', 'now' => count($nowPics) . ' รูป (มีการเพิ่มหรือถ่ายใหม่)'];
            }
        }
        if ($kind === 'return') {
            $key = static fn(array $lines): string => json_encode(array_map(
                static fn($l) => [(string) ($l['label'] ?? ''), round((float) ($l['amount'] ?? 0), 2)],
                $lines
            ), JSON_UNESCAPED_UNICODE);
            $oldLines = (array) ($snap['extras'] ?? []);
            $nowLines = self::extraLines($b);
            if ($key($oldLines) !== $key($nowLines)) {
                $out[] = ['label' => 'รายการค่าใช้จ่ายเพิ่ม', 'signed' => count($oldLines) . ' รายการ', 'now' => count($nowLines) . ' รายการ'];
            }
            // ยอดรวมของค่าใช้จ่ายเพิ่มอยู่ใน fields แล้ว (extra) จึงจับการแก้ยอดโดยไม่แตะรายการได้ด้วย
        }
        return $out;
    }

    // ------------------------------------------------------------
    // รูปลายเซ็น
    // ------------------------------------------------------------

    /**
     * แปลงรูปลายเซ็นจากหน้าเว็บ (data URL) เป็น PNG ที่ปลอดภัย: ตรวจว่าเป็น PNG จริง ขนาดสมเหตุผล และมีเส้นหมึกจริง
     * แล้ว "วาดใหม่" บนพื้นขาว — ข้อมูลแปลกปลอมที่แฝงมาในไฟล์หลุดออกทั้งหมด
     * @throws UserError
     */
    public static function toPng(string $dataUrl): string
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) {
            throw new UserError('กรุณาเซ็นชื่อในกรอบก่อนกดยืนยัน');
        }
        $invalid = new UserError('ลายเซ็นไม่ถูกต้อง กรุณาเซ็นใหม่อีกครั้ง');
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) < 200 || strlen($bin) > 600 * 1024) {
            throw $invalid;
        }
        $info = @getimagesizefromstring($bin);
        if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] < 120 || $info[1] < 60 || $info[0] > 2400 || $info[1] > 1600) {
            throw $invalid;
        }
        $src = @imagecreatefromstring($bin);
        if (!$src) {
            throw $invalid;
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagealphablending($image, true);
        imagecopy($image, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);

        $ink = 0;
        for ($y = 0; $y < $h; $y += 3) {
            for ($x = 0; $x < $w; $x += 3) {
                $c = imagecolorat($image, $x, $y);
                if ((($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255) < 500) {
                    $ink++;
                }
            }
        }
        if ($ink < self::MIN_INK) {
            imagedestroy($image);
            throw new UserError('ลายเซ็นสั้นเกินไป กรุณาเซ็นชื่อให้ชัดเจนอีกครั้ง');
        }
        ob_start();
        imagepng($image, null, 7);
        $png = (string) ob_get_clean();
        imagedestroy($image);
        return $png;
    }

    // ------------------------------------------------------------
    // เซ็น / ยกเลิก
    // ------------------------------------------------------------

    /**
     * บันทึกลายเซ็น — ตรวจทั้งหมดใน transaction ที่ล็อกแถวการจอง (กดยืนยันซ้ำ/สองเครื่องพร้อมกันได้ฉบับเดียว)
     * @param array $in  signature(data URL) name accept_terms consent seen(hash ที่หน้าเซ็นแสดง)
     * @param array $ctx ip ua
     * @throws UserError
     */
    public static function sign(int $bookingId, string $kind, array $in, array $ctx): int
    {
        if (!isset(self::kinds()[$kind])) {
            throw new UserError('ไม่พบเอกสารที่ต้องการ');
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            throw new UserError('กรุณาพิมพ์ชื่อ–นามสกุลของผู้ลงนาม');
        }
        if (empty($in['accept_terms'])) {
            throw new UserError('กรุณาติ๊กยืนยันว่าได้อ่านและยอมรับเงื่อนไขแล้ว');
        }
        if (empty($in['consent'])) {
            throw new UserError('กรุณาติ๊กยินยอมการใช้ข้อมูลส่วนบุคคลก่อนลงนาม');
        }
        $seen = (string) ($in['seen'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $seen)) {
            throw new UserError('หน้าเซ็นหมดอายุ กรุณารีเฟรชหน้านี้แล้วตรวจข้อมูลอีกครั้ง');
        }
        $png = self::toPng((string) ($in['signature'] ?? ''));   // งานหนัก ทำก่อนล็อกการจอง

        $path = UploadService::savePng($png, 'signatures');
        try {
            return (int) Database::transaction(static function () use ($bookingId, $kind, $name, $seen, $path, $ctx): int {
                $b = Booking::lockForUpdate($bookingId);
                if ($b === null) {
                    throw new UserError('ไม่พบการจอง');
                }
                if (($blocked = self::blocker($b, $kind)) !== null) {
                    throw new UserError($blocked);
                }
                if (Signature::active($bookingId, $kind) !== null) {
                    throw new UserError('เอกสารนี้ลงนามเรียบร้อยแล้ว');
                }
                $view = self::view($b, $kind);
                if (!hash_equals($view['hash'], $seen)) {
                    throw new UserError('ข้อมูลในสัญญามีการเปลี่ยนแปลงระหว่างที่คุณกำลังตรวจสอบ กรุณาตรวจข้อมูลอีกครั้งก่อนลงนาม');
                }
                $fields = [];
                foreach ($view['fields'] as $key => [, $value]) {
                    $fields[$key] = $value;
                }
                $ins = self::inspectionOf($b, $kind);
                $snapshot = json_encode([
                    'booking_no' => $b['booking_no'],
                    'kind'       => $kind,
                    'fields'     => $fields,
                    'extras'     => $view['extras'],
                    'inspection' => $ins ? ['photos' => count($view['photos']), 'locked_at' => $ins['locked_at'], 'files' => $view['photos']] : null,
                    'pdpa_text'  => $view['pdpa'],
                    'terms'      => $view['terms_text'],
                    'signed_by'  => $name,
                ], JSON_UNESCAPED_UNICODE);
                $id = Signature::insert([
                    'booking_id'    => $bookingId,
                    'kind'          => $kind,
                    'file_path'     => $path,
                    'signer_name'   => $name,
                    'consent_pdpa'  => 1,
                    'snapshot'      => $snapshot,
                    'snapshot_hash' => hash('sha256', $snapshot),
                    'ip'            => mb_substr((string) ($ctx['ip'] ?? ''), 0, 45) ?: null,
                    'user_agent'    => mb_substr((string) ($ctx['ua'] ?? ''), 0, 250) ?: null,
                    'signed_at'     => date('Y-m-d H:i:s'),
                ]);
                ActivityLog::log('signature_signed', 'bookings', $bookingId, json_encode(['kind' => $kind, 'signature_id' => $id], JSON_UNESCAPED_UNICODE), null, (string) ($ctx['ip'] ?? '') ?: null);
                return $id;
            });
        } catch (\Throwable $e) {
            UploadService::delete($path);   // ไฟล์นี้เพิ่งสร้างเองและยังไม่มีแถวอ้างถึง
            throw $e;
        }
    }

    /**
     * ยกเลิกลายเซ็นฉบับที่ใช้งานอยู่ (เพื่อให้ลูกค้าเซ็นใหม่) — เก็บประวัติและไฟล์เดิมไว้
     * @throws UserError
     */
    public static function void(int $signatureId, int $userId, string $reason, string $ip = ''): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new UserError('กรุณาระบุเหตุผลที่ยกเลิกลายเซ็น');
        }
        Database::transaction(static function () use ($signatureId, $userId, $reason, $ip): void {
            $sig = Signature::lockForUpdate($signatureId);
            if ($sig === null) {
                throw new UserError('ไม่พบลายเซ็น');
            }
            if (!Signature::void($signatureId, $userId, mb_substr($reason, 0, 250))) {
                throw new UserError('ลายเซ็นนี้ถูกยกเลิกไปแล้ว');
            }
            ActivityLog::log('signature_voided', 'bookings', (int) $sig['booking_id'], json_encode(['signature_id' => $signatureId, 'reason' => $reason], JSON_UNESCAPED_UNICODE), $userId, $ip !== '' ? $ip : null);
        });
    }
}
