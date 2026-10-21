<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Inspection;
use App\Models\Signature;
use App\Core\View;

/**
 * ลายเซ็นอิเล็กทรอนิกส์ของผู้เช่า
 *
 * หลักการ
 *   - ลูกค้าเซ็นผ่านลิงก์เฉพาะของการจอง (รหัสสุ่ม 128 บิต) ไม่ต้องล็อกอิน
 *   - ทุกครั้งที่เซ็น ระบบเก็บ "สำเนาข้อมูล ณ เวลาที่เซ็น" (ผู้เช่า รถ วัน–เวลา ยอดเงิน ข้อสัญญาทั้งหมด)
 *     พร้อมค่า hash เวลา IP และอุปกรณ์ — ถ้าภายหลังมีใครแก้ข้อมูลการจอง ระบบบอกได้ว่าแก้อะไรหลังลงนาม
 *   - ลายเซ็นไม่ถูกลบ: ขอเซ็นใหม่ = ทำเครื่องหมายยกเลิกฉบับเดิม (voided_at) แล้วเก็บไว้เป็นประวัติ
 *   - ไฟล์ลายเซ็นอยู่ในที่เก็บแบบปิด เปิดผ่านลิงก์ตรงไม่ได้
 */
final class SignatureService
{
    /** ชนิดเอกสารที่เซ็นได้ → [ชื่อเอกสาร, สถานะการจองที่เซ็นได้] */
    public static function kinds(): array
    {
        return [
            'contract' => ['สัญญาเช่ารถ', ['pending', 'confirmed', 'picked_up']],
            'return'   => ['ใบรับคืนรถ', ['returned', 'completed']],
        ];
    }

    /** ลิงก์หน้าเซ็นชื่อสำหรับลูกค้า (ลิงก์เต็ม ใช้ส่งทาง LINE ได้) */
    public static function url(array $b, string $kind = 'contract'): string
    {
        return site_url('sign') . '?no=' . rawurlencode((string) $b['booking_no']) . '&k=' . $kind . '&t=' . self::token($b);
    }

    /** รหัสลิงก์เซ็นชื่อของการจอง — สร้างครั้งแรกที่ต้องใช้ แล้วใช้รหัสเดิมตลอด */
    public static function token(array $b): string
    {
        if (!empty($b['sign_token'])) {
            return (string) $b['sign_token'];
        }
        return Booking::signToken((int) $b['id']);
    }

    /** เซ็นเอกสารชนิดนี้ได้ตอนนี้หรือไม่ — คืนข้อความเหตุผลถ้าไม่ได้ */
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

    /**
     * ข้อมูลสำคัญที่ผู้เช่ารับรองด้วยลายเซ็น — key => [ป้ายชื่อ, ค่าที่แสดง]
     * ใช้ทั้งแสดงในหน้าเซ็น เก็บเป็นสำเนา และเทียบว่ามีการแก้หลังลงนามหรือไม่
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
            $f += [
                'returned' => ['รับคืนรถเมื่อ', thai_date($b['returned_at'] ?? null, true)],
                'km_in'    => ['เลขไมล์ตอนคืน', !empty($b['mileage_in']) ? number_format((int) $b['mileage_in']) . ' กม.' : '-'],
                'fuel_in'  => ['น้ำมันตอนคืน', $b['fuel_in'] !== null && $b['fuel_in'] !== '' ? (int) $b['fuel_in'] . '/8' : '-'],
                'extra'    => ['ค่าใช้จ่ายเพิ่มตอนคืนรถ', money($b['extra_charge'] ?? 0) . ' บาท'],
                'damage'   => ['ความเสียหายที่บันทึก', trim((string) ($b['damage_note'] ?? '')) ?: 'ไม่มี'],
            ];
        }
        return $f;
    }

    /** รายการข้อมูลที่ถูกแก้หลังลงนาม — คืนชื่อช่องพร้อมค่าเดิมและค่าปัจจุบัน */
    public static function changes(array $sig, array $b, array $car): array
    {
        $snap = json_decode((string) $sig['snapshot'], true) ?: [];

        $out = self::changedFields($snap, $sig, $b, $car);
        $photos = self::changedPhotos($snap, $sig, $b);
        if ($photos) {
            $out[] = $photos;
        }
        if ($sig['kind'] === 'return') {
            $extras = self::changedExtras($snap, $b);
            if ($extras) {
                $out[] = $extras;
            }
        }
        return $out;
    }

    /** ช่องข้อมูลหลัก (ผู้เช่า รถ วันที่ ยอดเงิน ...) ที่ค่าไม่ตรงกับตอนเซ็น */
    private static function changedFields(array $snap, array $sig, array $b, array $car): array
    {
        $old = $snap['fields'] ?? [];
        $out = [];
        foreach (self::fields($b, $car, (string) $sig['kind']) as $key => [$label, $now]) {
            if (array_key_exists($key, $old) && (string) $old[$key] !== (string) $now) {
                $out[] = ['label' => $label, 'signed' => (string) $old[$key], 'now' => (string) $now];
            }
        }
        return $out;
    }

    /**
     * รูปสภาพรถ: ชุดรูปที่ลูกค้าเห็นตอนเซ็น ต้องเป็นชุดเดียวกับที่ใช้อยู่ตอนนี้
     * เทียบเฉพาะเมื่อตอนเซ็นมีรูปให้ลูกค้าดู — ถ้าเซ็นก่อนถ่ายรูป ลายเซ็นไม่ได้รับรองรูปอยู่แล้ว
     * @return ?array null = ไม่มีการเปลี่ยนแปลง
     */
    private static function changedPhotos(array $snap, array $sig, array $b): ?array
    {
        $oldPics = (array) ($snap['inspection']['files'] ?? []);
        if (!$oldPics) {
            return null;
        }
        $ins     = Inspection::get((int) $b['id'], $sig['kind'] === 'return' ? 'in' : 'out');
        $nowPics = $ins ? array_column($ins['photos'], 'file_path') : [];
        sort($nowPics);
        sort($oldPics);
        if ($nowPics === $oldPics) {
            return null;
        }
        return [
            'label'  => 'รูปสภาพรถ',
            'signed' => count($oldPics) . ' รูป',
            'now'    => count($nowPics) . ' รูป (มีการเพิ่มหรือถ่ายใหม่)',
        ];
    }

    /** รายการค่าใช้จ่ายตอนคืนรถ (ใช้กับใบรับคืนรถเท่านั้น) @return ?array null = ไม่มีการเปลี่ยนแปลง */
    private static function changedExtras(array $snap, array $b): ?array
    {
        $oldLines = (array) ($snap['extras'] ?? []);
        $nowLines = Booking::extraLines($b);
        if (self::extrasKey($oldLines) === self::extrasKey($nowLines)) {
            return null;
        }
        return [
            'label'  => 'รายการค่าใช้จ่ายเพิ่ม',
            'signed' => count($oldLines) . ' รายการ',
            'now'    => count($nowLines) . ' รายการ',
        ];
    }

    /** แปลงรายการค่าใช้จ่ายเป็นข้อความเดียว เอาไว้เทียบว่าเหมือนกันหรือไม่ */
    private static function extrasKey(array $lines): string
    {
        $simple = [];
        foreach ($lines as $line) {
            $simple[] = [(string) ($line['label'] ?? ''), round((float) ($line['amount'] ?? 0), 2), !empty($line['waived'])];
        }
        return json_encode($simple, JSON_UNESCAPED_UNICODE);
    }

    /** สำเนาเก็บไว้ตรงกับ hash หรือไม่ — false = ข้อมูลในฐานข้อมูลถูกแก้โดยตรง */
    public static function intact(array $sig): bool
    {
        return hash_equals((string) $sig['snapshot_hash'], hash('sha256', (string) $sig['snapshot']));
    }

    /**
     * ลายนิ้วมือของ "สิ่งที่แสดงให้ลูกค้าเห็น" ในหน้าเซ็น — ข้อมูลสรุป ข้อสัญญา รูปสภาพรถ รายการค่าใช้จ่าย
     * หน้าเซ็นฝังค่านี้ไว้ตอนแสดงผล แล้วเทียบอีกครั้งตอนกดยืนยัน: ถ้าร้านแก้ข้อมูลระหว่างที่ลูกค้าเปิดหน้าค้างไว้
     * ค่าจะไม่ตรง ระบบจะให้ลูกค้าตรวจข้อมูลใหม่ก่อน — ลายเซ็นจึงรับรองเฉพาะสิ่งที่ลูกค้าเห็นจริง
     */
    public static function viewHash(array $b, array $car, string $kind, string $termsText): string
    {
        $ins = Inspection::get((int) $b['id'], $kind === 'return' ? 'in' : 'out');
        return hash('sha256', json_encode([
            array_map(static fn($f) => $f[1], self::fields($b, $car, $kind)),
            $termsText,
            $ins ? array_column($ins['photos'], 'file_path') : [],
            $kind === 'return' ? Booking::extraLines($b) : [],
            (string) setting('pdpa_policy_text'),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** รหัสอ้างอิงสั้นของลายเซ็น (12 ตัวแรกของ hash) — พิมพ์ลงบนสัญญา */
    public static function ref(array $sig): string
    {
        return strtoupper(substr((string) $sig['snapshot_hash'], 0, 12));
    }

    /** ข้อสัญญาเช่ารถ (HTML) — ใช้ร่วมกันระหว่างหน้าพิมพ์สัญญาและหน้าเซ็นบนมือถือ ลูกค้าจึงเห็นข้อความชุดเดียวกับฉบับพิมพ์ */
    public static function contractTermsHtml(array $b, string $blank = '<span class="inline-block min-w-[130px] border-b border-dotted border-slate-500 px-1"></span>'): string
    {
        return View::make('partials/contract_terms', ['b' => $b, 'blank' => $blank]);
    }

    /** ข้อความล้วนของข้อสัญญา (เก็บเป็นหลักฐานคู่กับลายเซ็น) */
    public static function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</li>', '</p>'], "\n", $html)))));
    }

    /**
     * แปลงรูปลายเซ็นจากหน้าเว็บ (data URL) เป็นไฟล์ PNG ที่ปลอดภัย
     * ตรวจว่าเป็นรูปจริง ขนาดสมเหตุผล และมีเส้นเซ็นจริง (ไม่ใช่กระดาษเปล่า) แล้วเขียนใหม่บนพื้นขาว
     * การเขียนใหม่ทำให้ข้อมูลแปลกปลอมที่อาจแฝงมาในไฟล์หลุดออกทั้งหมด
     */
    public static function toPng(string $dataUrl): string
    {
        $bin = self::decodeDataUrl($dataUrl);
        self::checkPngSize($bin);

        if (!function_exists('imagecreatefromstring')) {
            return $bin;   // โฮสต์ไม่มี GD — เก็บตามที่ได้รับ (ผ่านการตรวจว่าเป็น PNG แล้ว)
        }
        $src = @imagecreatefromstring($bin);
        if (!$src) {
            throw new \RuntimeException(self::INVALID_SIGNATURE);
        }
        $image = self::onWhiteBackground($src);
        imagedestroy($src);

        if (self::countInk($image) < 60) {
            imagedestroy($image);
            throw new \RuntimeException('ลายเซ็นสั้นเกินไป กรุณาเซ็นชื่อให้ชัดเจนอีกครั้ง');
        }
        ob_start();
        imagepng($image, null, 7);
        $png = (string) ob_get_clean();
        imagedestroy($image);
        return $png;
    }

    private const INVALID_SIGNATURE = 'ลายเซ็นไม่ถูกต้อง กรุณาเซ็นใหม่อีกครั้ง';

    /** ถอดรหัส data URL (data:image/png;base64,...) เป็นข้อมูลไบนารี */
    private static function decodeDataUrl(string $dataUrl): string
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) {
            throw new \RuntimeException('กรุณาเซ็นชื่อในกรอบก่อนกดยืนยัน');
        }
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) < 200 || strlen($bin) > 600 * 1024) {
            throw new \RuntimeException(self::INVALID_SIGNATURE);
        }
        return $bin;
    }

    /** ต้องเป็น PNG จริงและมีขนาดภาพสมเหตุผล */
    private static function checkPngSize(string $bin): void
    {
        $info = @getimagesizefromstring($bin);
        if (!$info || $info[2] !== IMAGETYPE_PNG) {
            throw new \RuntimeException(self::INVALID_SIGNATURE);
        }
        [$width, $height] = [$info[0], $info[1]];
        if ($width < 120 || $height < 60 || $width > 2400 || $height > 1600) {
            throw new \RuntimeException(self::INVALID_SIGNATURE);
        }
    }

    /** วาดรูปลงบนพื้นขาวผืนใหม่ (พื้นโปร่งใสจะกลายเป็นสีขาว) */
    private static function onWhiteBackground($src)
    {
        $width  = imagesx($src);
        $height = imagesy($src);
        $image  = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagealphablending($image, true);
        imagecopy($image, $src, 0, 0, 0, 0, $width, $height);
        return $image;
    }

    /** นับจุดหมึกแบบสุ่มตัวอย่างทุก 3 พิกเซล — ลายเซ็นจริงมีหมึกหลายร้อยจุด จุดเดียวหรือขีดสั้นๆ ไม่ผ่าน */
    private static function countInk($image): int
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $ink    = 0;
        for ($y = 0; $y < $height; $y += 3) {
            for ($x = 0; $x < $width; $x += 3) {
                $color = imagecolorat($image, $x, $y);
                $sum   = (($color >> 16) & 255) + (($color >> 8) & 255) + ($color & 255);
                if ($sum < 500) {
                    $ink++;
                }
            }
        }
        return $ink;
    }

    /**
     * บันทึกลายเซ็น — คืน id
     * @param string $termsText ข้อความสัญญาทั้งหมดที่ลูกค้าเห็นตอนเซ็น (เก็บเป็นหลักฐาน)
     * @throws \RuntimeException เซ็นไม่ได้ (สถานะไม่ตรง / เซ็นไปแล้ว / ข้อมูลไม่ครบ)
     */
    public static function save(array $b, array $car, string $kind, string $dataUrl, string $name, bool $acceptTerms, bool $consent, string $termsText): int
    {
        $blocked = self::blocker($b, $kind);
        if ($blocked) {
            throw new \RuntimeException($blocked);
        }
        $name = self::checkSignerName($name);
        if (!$acceptTerms) {
            throw new \RuntimeException('กรุณาติ๊กยืนยันว่าได้อ่านและยอมรับเงื่อนไขแล้ว');
        }
        if (!$consent) {
            throw new \RuntimeException('กรุณาติ๊กยินยอมการใช้ข้อมูลส่วนบุคคลก่อนลงนาม');
        }
        $png      = self::toPng($dataUrl);
        $snapshot = self::buildSnapshot($b, $car, $kind, $name, $termsText);

        $id = self::insertSignature($b, $kind, $name, $png, $snapshot);
        ActivityLog::log('signature_signed', 'bookings', (int) $b['id'], ['kind' => $kind, 'signature_id' => $id, 'name' => $name]);
        return $id;
    }

    /** ตัดช่องว่างซ้ำและตรวจความยาวชื่อผู้ลงนาม */
    private static function checkSignerName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            throw new \RuntimeException('กรุณาพิมพ์ชื่อ–นามสกุลของผู้ลงนาม');
        }
        return $name;
    }

    /** สำเนาข้อมูล ณ เวลาที่เซ็น (JSON) */
    private static function buildSnapshot(array $b, array $car, string $kind, string $name, string $termsText): string
    {
        $fields = [];
        foreach (self::fields($b, $car, $kind) as $key => [, $value]) {
            $fields[$key] = $value;
        }
        $ins = Inspection::get((int) $b['id'], $kind === 'return' ? 'in' : 'out');

        $inspection = null;
        if ($ins) {
            $inspection = [
                'photos'    => count($ins['photos']),
                'locked_at' => $ins['locked_at'],
                'files'     => array_column($ins['photos'], 'file_path'),
            ];
        }
        return json_encode([
            'booking_no' => $b['booking_no'],
            'kind'       => $kind,
            'fields'     => $fields,
            'extras'     => $kind === 'return' ? Booking::extraLines($b) : [],
            'inspection' => $inspection,
            'pdpa_text'  => (string) setting('pdpa_policy_text'),
            'terms'      => $termsText,
            'signed_by'  => $name,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** เขียนไฟล์ลายเซ็นและแถวในฐานข้อมูลใน transaction เดียว */
    private static function insertSignature(array $b, string $kind, string $name, string $png, string $snapshot): int
    {
        $req = Request::current();
        Database::begin();
        try {
            // ล็อกแถวการจองไว้ก่อนตรวจ — กันกดยืนยันสองครั้งพร้อมกันแล้วได้ลายเซ็นซ้อนสองฉบับ
            Booking::lock((int) $b['id']);
            if (Signature::active((int) $b['id'], $kind)) {
                throw new \RuntimeException('เอกสารนี้ลงนามเรียบร้อยแล้ว');
            }
            $id = Signature::insert([
                'booking_id'    => (int) $b['id'],
                'kind'          => $kind,
                'file_path'     => PrivateFileService::put('signatures', $png, 'png'),
                'signer_name'   => $name,
                'consent_pdpa'  => 1,
                'snapshot'      => $snapshot,
                'snapshot_hash' => hash('sha256', $snapshot),
                'ip'            => mb_substr($req->ip(), 0, 45),
                'user_agent'    => mb_substr($req->userAgent(), 0, 250),
                'signed_at'     => date('Y-m-d H:i:s'),
            ]);
            Database::commit();
            return $id;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }
}
