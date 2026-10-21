<?php
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
require_once __DIR__ . '/private_files.php';
require_once __DIR__ . '/line.php';
require_once __DIR__ . '/inspection.php';

/** ชนิดเอกสารที่เซ็นได้ → [ชื่อเอกสาร, สถานะการจองที่เซ็นได้] */
function signature_kinds(): array
{
    return [
        'contract' => ['สัญญาเช่ารถ', ['pending', 'confirmed', 'picked_up']],
        'return'   => ['ใบรับคืนรถ', ['returned', 'completed']],
    ];
}

/** รหัสลิงก์เซ็นชื่อของการจอง — สร้างครั้งแรกที่ต้องใช้ แล้วใช้รหัสเดิมตลอด */
function sign_token(array $b): string
{
    if (!empty($b['sign_token'])) {
        return (string) $b['sign_token'];
    }
    $t = bin2hex(random_bytes(16));
    db_exec('UPDATE bookings SET sign_token = :t WHERE id = :i AND (sign_token IS NULL OR sign_token = "")', [':t' => $t, ':i' => (int) $b['id']]);
    return (string) db_val('SELECT sign_token FROM bookings WHERE id = :i', [':i' => (int) $b['id']]);
}

/** ลิงก์หน้าเซ็นชื่อสำหรับลูกค้า (ลิงก์เต็ม ใช้ส่งทาง LINE ได้) */
function sign_url(array $b, string $kind = 'contract'): string
{
    return site_url('sign.php') . '?no=' . rawurlencode((string) $b['booking_no']) . '&k=' . $kind . '&t=' . sign_token($b);
}

/** ลายเซ็นที่ใช้อยู่ (ยังไม่ถูกยกเลิก) ของการจองนี้ */
function signature_get(int $bookingId, string $kind): ?array
{
    $s = db_one(
        'SELECT * FROM signatures WHERE booking_id = :b AND kind = :k AND voided_at IS NULL ORDER BY id DESC LIMIT 1',
        [':b' => $bookingId, ':k' => $kind]
    );
    return $s ?: null;
}

/** ลายเซ็นที่ถูกยกเลิกไปแล้ว (ประวัติ) */
function signature_voided(int $bookingId): array
{
    return db_all(
        'SELECT s.*, u.name AS voider FROM signatures s LEFT JOIN users u ON u.id = s.voided_by
         WHERE s.booking_id = :b AND s.voided_at IS NOT NULL ORDER BY s.id DESC',
        [':b' => $bookingId]
    );
}

/** เซ็นเอกสารชนิดนี้ได้ตอนนี้หรือไม่ — คืนข้อความเหตุผลถ้าไม่ได้ */
function signature_blocker(array $b, string $kind): ?string
{
    $kinds = signature_kinds();
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
function signature_fields(array $b, array $car, string $kind): array
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
function signature_changes(array $sig, array $b, array $car): array
{
    $snap = json_decode((string) $sig['snapshot'], true) ?: [];
    $old  = $snap['fields'] ?? [];
    $out  = [];
    foreach (signature_fields($b, $car, (string) $sig['kind']) as $k => [$label, $now]) {
        if (array_key_exists($k, $old) && (string) $old[$k] !== (string) $now) {
            $out[] = ['label' => $label, 'signed' => (string) $old[$k], 'now' => (string) $now];
        }
    }
    // รูปสภาพรถ: ชุดรูปที่ลูกค้าเห็นตอนเซ็น ต้องเป็นชุดเดียวกับที่ใช้อยู่ตอนนี้
    // เทียบเฉพาะเมื่อตอนเซ็นมีรูปให้ลูกค้าดู — ถ้าเซ็นก่อนถ่ายรูป (เช่น ส่งลิงก์ทาง LINE ล่วงหน้า) ลายเซ็นไม่ได้รับรองรูปอยู่แล้ว
    // การถ่ายรูปทีหลังจึงไม่ใช่ "การแก้ไขหลังลงนาม" (ใบแนบสภาพรถของฉบับพิมพ์จะเว้นช่องให้เซ็นมือแทน)
    $oldPics = (array) ($snap['inspection']['files'] ?? []);
    $ins     = $oldPics ? inspection_get((int) $b['id'], $sig['kind'] === 'return' ? 'in' : 'out') : null;
    $nowPics = $ins ? array_column($ins['photos'], 'file_path') : [];
    sort($nowPics); sort($oldPics);
    if ($oldPics && $nowPics !== $oldPics) {
        $out[] = ['label' => 'รูปสภาพรถ', 'signed' => count($oldPics) . ' รูป', 'now' => count($nowPics) . ' รูป (มีการเพิ่มหรือถ่ายใหม่)'];
    }
    // รายการค่าใช้จ่ายตอนคืนรถ
    if ($sig['kind'] === 'return') {
        $norm = static fn(array $ls): string => json_encode(array_map(
            static fn($l) => [(string) ($l['label'] ?? ''), round((float) ($l['amount'] ?? 0), 2), !empty($l['waived'])], $ls), JSON_UNESCAPED_UNICODE);
        if ($norm((array) ($snap['extras'] ?? [])) !== $norm(booking_extra_lines($b))) {
            $out[] = ['label' => 'รายการค่าใช้จ่ายเพิ่ม', 'signed' => count((array) ($snap['extras'] ?? [])) . ' รายการ', 'now' => count(booking_extra_lines($b)) . ' รายการ'];
        }
    }
    return $out;
}

/** สำเนาเก็บไว้ตรงกับ hash หรือไม่ — false = ข้อมูลในฐานข้อมูลถูกแก้โดยตรง */
function signature_intact(array $sig): bool
{
    return hash_equals((string) $sig['snapshot_hash'], hash('sha256', (string) $sig['snapshot']));
}

/**
 * ลายนิ้วมือของ "สิ่งที่แสดงให้ลูกค้าเห็น" ในหน้าเซ็น — ข้อมูลสรุป ข้อสัญญา รูปสภาพรถ รายการค่าใช้จ่าย
 * หน้าเซ็นฝังค่านี้ไว้ตอนแสดงผล แล้วเทียบอีกครั้งตอนกดยืนยัน: ถ้าร้านแก้ข้อมูลระหว่างที่ลูกค้าเปิดหน้าค้างไว้
 * ค่าจะไม่ตรง ระบบจะให้ลูกค้าตรวจข้อมูลใหม่ก่อน — ลายเซ็นจึงรับรองเฉพาะสิ่งที่ลูกค้าเห็นจริง
 */
function signature_view_hash(array $b, array $car, string $kind, string $termsText): string
{
    $ins = inspection_get((int) $b['id'], $kind === 'return' ? 'in' : 'out');
    return hash('sha256', json_encode([
        array_map(static fn($f) => $f[1], signature_fields($b, $car, $kind)),
        $termsText,
        $ins ? array_column($ins['photos'], 'file_path') : [],
        $kind === 'return' ? booking_extra_lines($b) : [],
        (string) setting('pdpa_policy_text'),
    ], JSON_UNESCAPED_UNICODE));
}

/** รหัสอ้างอิงสั้นของลายเซ็น (12 ตัวแรกของ hash) — พิมพ์ลงบนสัญญา */
function signature_ref(array $sig): string
{
    return strtoupper(substr((string) $sig['snapshot_hash'], 0, 12));
}

/**
 * แปลงรูปลายเซ็นจากหน้าเว็บ (data URL) เป็นไฟล์ PNG ที่ปลอดภัย
 * ตรวจว่าเป็นรูปจริง ขนาดสมเหตุผล และมีเส้นเซ็นจริง (ไม่ใช่กระดาษเปล่า) แล้วเขียนใหม่บนพื้นขาว
 * การเขียนใหม่ทำให้ข้อมูลแปลกปลอมที่อาจแฝงมาในไฟล์หลุดออกทั้งหมด
 */
function signature_png(string $dataUrl): string
{
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) {
        throw new RuntimeException('กรุณาเซ็นชื่อในกรอบก่อนกดยืนยัน');
    }
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) < 200 || strlen($bin) > 600 * 1024) {
        throw new RuntimeException('ลายเซ็นไม่ถูกต้อง กรุณาเซ็นใหม่อีกครั้ง');
    }
    $info = @getimagesizefromstring($bin);
    if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] < 120 || $info[1] < 60 || $info[0] > 2400 || $info[1] > 1600) {
        throw new RuntimeException('ลายเซ็นไม่ถูกต้อง กรุณาเซ็นใหม่อีกครั้ง');
    }
    if (!function_exists('imagecreatefromstring')) {
        return $bin;   // โฮสต์ไม่มี GD — เก็บตามที่ได้รับ (ผ่านการตรวจว่าเป็น PNG แล้ว)
    }
    $src = @imagecreatefromstring($bin);
    if (!$src) {
        throw new RuntimeException('ลายเซ็นไม่ถูกต้อง กรุณาเซ็นใหม่อีกครั้ง');
    }
    [$w, $h] = [imagesx($src), imagesy($src)];
    $dst = imagecreatetruecolor($w, $h);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagealphablending($dst, true);
    imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);

    // นับจุดหมึกแบบสุ่มตัวอย่างทุก 3 พิกเซล — ลายเซ็นจริงมีหมึกหลายร้อยจุด จุดเดียวหรือขีดสั้นๆ ไม่ผ่าน
    $ink = 0;
    for ($y = 0; $y < $h; $y += 3) {
        for ($x = 0; $x < $w; $x += 3) {
            $c = imagecolorat($dst, $x, $y);
            if ((($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255) < 500) {
                $ink++;
            }
        }
    }
    imagedestroy($src);
    if ($ink < 60) {
        imagedestroy($dst);
        throw new RuntimeException('ลายเซ็นสั้นเกินไป กรุณาเซ็นชื่อให้ชัดเจนอีกครั้ง');
    }
    ob_start();
    imagepng($dst, null, 7);
    $out = (string) ob_get_clean();
    imagedestroy($dst);
    return $out;
}

/**
 * บันทึกลายเซ็น — คืน id
 * @param string $termsText ข้อความสัญญาทั้งหมดที่ลูกค้าเห็นตอนเซ็น (เก็บเป็นหลักฐาน)
 * @throws RuntimeException เซ็นไม่ได้ (สถานะไม่ตรง / เซ็นไปแล้ว / ข้อมูลไม่ครบ)
 */
function signature_save(array $b, array $car, string $kind, string $dataUrl, string $name, bool $acceptTerms, bool $consent, string $termsText): int
{
    if ($msg = signature_blocker($b, $kind)) {
        throw new RuntimeException($msg);
    }
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
        throw new RuntimeException('กรุณาพิมพ์ชื่อ–นามสกุลของผู้ลงนาม');
    }
    if (!$acceptTerms) {
        throw new RuntimeException('กรุณาติ๊กยืนยันว่าได้อ่านและยอมรับเงื่อนไขแล้ว');
    }
    if (!$consent) {
        throw new RuntimeException('กรุณาติ๊กยินยอมการใช้ข้อมูลส่วนบุคคลก่อนลงนาม');
    }
    $png = signature_png($dataUrl);

    $fields = [];
    foreach (signature_fields($b, $car, $kind) as $k => [, $v]) {
        $fields[$k] = $v;
    }
    $ins = inspection_get((int) $b['id'], $kind === 'return' ? 'in' : 'out');
    $snapshot = json_encode([
        'booking_no' => $b['booking_no'],
        'kind'       => $kind,
        'fields'     => $fields,
        'extras'     => $kind === 'return' ? booking_extra_lines($b) : [],
        'inspection' => $ins ? ['photos' => count($ins['photos']), 'locked_at' => $ins['locked_at'],
                                'files' => array_column($ins['photos'], 'file_path')] : null,
        'pdpa_text'  => (string) setting('pdpa_policy_text'),
        'terms'      => $termsText,
        'signed_by'  => $name,
    ], JSON_UNESCAPED_UNICODE);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // ล็อกแถวการจองไว้ก่อนตรวจ — กันกดยืนยันสองครั้งพร้อมกันแล้วได้ลายเซ็นซ้อนสองฉบับ
        db_one('SELECT id FROM bookings WHERE id = :i FOR UPDATE', [':i' => (int) $b['id']]);
        if (signature_get((int) $b['id'], $kind)) {
            throw new RuntimeException('เอกสารนี้ลงนามเรียบร้อยแล้ว');
        }
        $path = private_put('signatures', $png, 'png');
        $id = db_insert('signatures', [
            'booking_id'    => (int) $b['id'],
            'kind'          => $kind,
            'file_path'     => $path,
            'signer_name'   => $name,
            'consent_pdpa'  => 1,
            'snapshot'      => $snapshot,
            'snapshot_hash' => hash('sha256', $snapshot),
            'ip'            => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent'    => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
            'signed_at'     => date('Y-m-d H:i:s'),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }
    log_activity('signature_signed', 'bookings', (int) $b['id'], ['kind' => $kind, 'signature_id' => $id, 'name' => $name]);
    return $id;
}

/** ยกเลิกลายเซ็นเพื่อให้ลูกค้าเซ็นใหม่ — ฉบับเดิมยังเก็บไว้เป็นประวัติ */
function signature_void(int $signatureId, int $userId, string $reason): bool
{
    $n = db_exec(
        'UPDATE signatures SET voided_at = NOW(), voided_by = :u, void_reason = :r WHERE id = :i AND voided_at IS NULL',
        [':u' => $userId, ':r' => mb_substr($reason, 0, 250), ':i' => $signatureId]
    );
    return (bool) $n;
}
