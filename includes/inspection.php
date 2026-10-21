<?php
/**
 * ตรวจสภาพรถก่อน–หลัง (ถ่ายรูปผูกกับใบจอง)
 *
 * ใช้เป็นหลักฐานเวลามีข้อพิพาทเรื่องรอยขีดข่วน น้ำมัน หรือเลขไมล์
 * รูปทุกใบผูกกับ "ใบจอง + รอบการตรวจ (ส่งมอบ/รับคืน)" และมีเวลาบันทึกกำกับ
 */
require_once __DIR__ . '/pricing.php';

/** มุมถ่ายมาตรฐาน 8 จุด — เรียงตามลำดับที่เดินรอบรถจริง */
function inspection_positions(): array
{
    return [
        'front'     => ['หน้ารถ',            'ถ่ายเต็มคันจากด้านหน้า ให้เห็นกันชนและไฟหน้า'],
        'right'     => ['ด้านขวา',           'ยืนห่างประมาณ 2 เมตร ให้เห็นประตูและล้อทั้ง 2 ข้าง'],
        'rear'      => ['ท้ายรถ',            'ให้เห็นกันชนท้าย ไฟท้าย และป้ายทะเบียน'],
        'left'      => ['ด้านซ้าย',          'ยืนห่างประมาณ 2 เมตร ให้เห็นประตูและล้อทั้ง 2 ข้าง'],
        'dashboard' => ['เลขไมล์ + น้ำมัน',  'ถ่ายหน้าปัดตอนเปิดสวิตช์ ให้อ่านเลขไมล์ได้ชัด'],
        'interior'  => ['ภายในห้องโดยสาร',   'ถ่ายจากประตูหน้า ให้เห็นเบาะและคอนโซล'],
        'tire'      => ['ยางและล้อ',         'ถ่ายล้อที่สภาพแย่ที่สุด ให้เห็นดอกยางและขอบกระทะ'],
        'damage'    => ['จุดที่มีตำหนิ',      'ถ่ายใกล้ทุกรอยที่พบ ถ้าไม่มีให้ข้ามได้'],
    ];
}

/** ป้ายชื่อรอบการตรวจ */
function inspection_kind_label(string $kind): string
{
    return $kind === 'out' ? 'ตอนส่งมอบรถ' : 'ตอนรับคืนรถ';
}

/** ดึงใบตรวจของการจอง (รอบเดียว) พร้อมรูปทั้งหมด */
function inspection_get(int $bookingId, string $kind): ?array
{
    $row = db_one(
        'SELECT i.*, u.name AS inspector, l.name AS locker
         FROM inspections i
         LEFT JOIN users u ON u.id = i.inspected_by
         LEFT JOIN users l ON l.id = i.locked_by
         WHERE i.booking_id = :b AND i.kind = :k',
        [':b' => $bookingId, ':k' => $kind]
    );
    if (!$row) {
        return null;
    }
    $all = db_all(
        'SELECT p.*, u.name AS uploader
         FROM inspection_photos p LEFT JOIN users u ON u.id = p.uploaded_by
         WHERE p.inspection_id = :i ORDER BY p.id',
        [':i' => (int) $row['id']]
    );
    // photos  = รูปที่ใช้เป็นผลตรวจปัจจุบัน (มุมละ 1 รูป)
    // history = รูปที่ถูกถ่ายแทน — ไม่ลบทิ้ง เก็บไว้ให้ตรวจย้อนหลังได้ว่าเคยมีรูปอะไร
    $row['photos']  = array_values(array_filter($all, fn($p) => empty($p['superseded_at'])));
    $row['history'] = array_values(array_filter($all, fn($p) => !empty($p['superseded_at'])));
    return $row;
}

/** ดึงทั้ง 2 รอบพร้อมกัน — ใช้ในหน้าเทียบก่อน–หลัง และในสัญญา */
function inspection_both(int $bookingId): array
{
    return ['out' => inspection_get($bookingId, 'out'), 'in' => inspection_get($bookingId, 'in')];
}

/** สร้างใบตรวจถ้ายังไม่มี แล้วคืน id (ใบเดิมจะไม่ถูกสร้างซ้ำ) */
function inspection_ensure(int $bookingId, string $kind): int
{
    $found = db_one('SELECT id FROM inspections WHERE booking_id = :b AND kind = :k',
        [':b' => $bookingId, ':k' => $kind]);
    if ($found) {
        return (int) $found['id'];
    }
    return db_insert('inspections', [
        'booking_id'   => $bookingId,
        'kind'         => $kind,
        'inspected_by' => auth_user()['id'] ?? null,
        'inspected_at' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * ย่อรูปฝั่งเซิร์ฟเวอร์ — กันกรณีเบราว์เซอร์ย่อไม่สำเร็จ
 *
 * ปกติหน้าเว็บย่อให้ก่อนส่งแล้ว แต่ถ้า JS ถูกปิด เบราว์เซอร์เก่า
 * หรือแอดมินอัปจากคอมโดยตรง รูปดิบ 4–5 MB จะกินพื้นที่ shared hosting เร็วมาก
 * ฟังก์ชันนี้จึงย่อซ้ำให้เสมอ และถ้าเครื่องไม่มี GD ก็ปล่อยไฟล์เดิมไว้ ไม่ทำให้ระบบพัง
 */
function inspection_shrink_file(string $fullPath, int $maxSide = 1600, int $quality = 82): void
{
    if (!function_exists('imagecreatefromjpeg') || !is_file($fullPath)) {
        return;
    }
    $info = @getimagesize($fullPath);
    if (!$info) {
        return;
    }
    [$w, $h, $type] = $info;
    if (max($w, $h) <= $maxSide) {
        return;   // เล็กอยู่แล้ว (เบราว์เซอร์ย่อมาให้แล้ว)
    }
    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($fullPath),
        IMAGETYPE_PNG  => @imagecreatefrompng($fullPath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fullPath) : null,
        default        => null,
    };
    if (!$src) {
        return;
    }
    $scale = $maxSide / max($w, $h);
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    @imagejpeg($dst, $fullPath, $quality);
    imagedestroy($src);
    imagedestroy($dst);
}

/** ผลตรวจรอบนี้ถูกล็อกแล้วหรือยัง — ล็อกแล้วเพิ่ม/เปลี่ยนรูปและแก้ตัวเลขไม่ได้อีก */
function inspection_is_locked(?array $inspection): bool
{
    return $inspection !== null && !empty($inspection['locked_at']);
}

/**
 * ผลตรวจรอบนี้ยังแก้ได้ตามขั้นตอนของการจองหรือไม่ — คืนข้อความเหตุผลถ้าปิดรอบแล้ว
 *   รอบส่งมอบ  แก้ได้ก่อนกดส่งมอบรถเท่านั้น
 *   รอบรับคืน   แก้ได้ระหว่างที่รถอยู่กับลูกค้า (ก่อนกดรับคืนรถ) เท่านั้น
 * กันกรณีส่งมอบรถโดยไม่ได้ถ่ายรูป แล้วมีคนย้อนมาถ่าย "สภาพรถวันส่งมอบ" ทีหลัง
 */
function inspection_phase_block(array $booking, string $kind): ?string
{
    $ok = $kind === 'out' ? ['pending', 'confirmed'] : ['picked_up'];
    if (in_array($booking['status'], $ok, true)) {
        return null;
    }
    if ($kind === 'in' && in_array($booking['status'], ['pending', 'confirmed'], true)) {
        return 'ยังไม่ได้ส่งมอบรถ — บันทึกสภาพรถตอนรับคืนได้หลังส่งมอบรถแล้ว';
    }
    return $kind === 'out' ? 'ส่งมอบรถไปแล้ว ผลตรวจรอบส่งมอบปิดแล้ว เพิ่มหรือแก้ไขไม่ได้'
                           : 'รับคืนรถไปแล้ว ผลตรวจรอบรับคืนปิดแล้ว เพิ่มหรือแก้ไขไม่ได้';
}

/**
 * ล็อกผลตรวจ — เรียกตอนกดยืนยันในหน้าถ่ายรูป หรืออัตโนมัติเมื่อบันทึกส่งมอบ/รับคืนรถ
 * ล็อกซ้ำไม่เปลี่ยนเวลาเดิม (เวลาล็อกครั้งแรกคือหลักฐาน)
 */
function inspection_lock(int $bookingId, string $kind): bool
{
    $ins = db_one('SELECT id, locked_at FROM inspections WHERE booking_id = :b AND kind = :k',
        [':b' => $bookingId, ':k' => $kind]);
    if (!$ins || !empty($ins['locked_at'])) {
        return false;
    }
    db_update('inspections', [
        'locked_at' => date('Y-m-d H:i:s'),
        'locked_by' => auth_user()['id'] ?? null,
    ], 'id', (int) $ins['id']);
    log_activity('inspection_locked', 'bookings', $bookingId, $kind);
    return true;
}

/**
 * บันทึกรูป 1 ใบ — ถ้ามุมนั้นมีรูปอยู่แล้ว รูปเดิมจะถูกเก็บเป็นประวัติ ไม่ถูกลบ
 * $file คือ element จาก $_FILES
 */
function inspection_save_photo(int $inspectionId, string $position, array $file, ?string $caption = null): ?string
{
    $locked = db_val('SELECT locked_at FROM inspections WHERE id = :i', [':i' => $inspectionId]);
    if ($locked) {
        throw new RuntimeException('ผลตรวจรอบนี้ถูกล็อกแล้ว เพิ่มหรือเปลี่ยนรูปไม่ได้');
    }
    $path = upload_file($file, 'inspections', 8);
    if (!$path) {
        return null;
    }
    // ย่อซ้ำฝั่งเซิร์ฟเวอร์ (ไม่ทำอะไรถ้าเบราว์เซอร์ย่อมาให้แล้ว)
    inspection_shrink_file(rtrim(UPLOAD_DIR, '/') . '/' . ltrim($path, '/'));
    // ถ่ายมุมเดิมซ้ำ = ตั้งใจถ่ายใหม่ → ประทับเวลาว่ารูปเดิมถูกแทนแล้ว
    // ไม่ลบทั้งแถวในฐานข้อมูลและไฟล์ เพราะหลักฐานที่ลบได้ไม่ใช่หลักฐาน
    db_exec(
        'UPDATE inspection_photos SET superseded_at = NOW()
         WHERE inspection_id = :i AND position = :p AND superseded_at IS NULL',
        [':i' => $inspectionId, ':p' => $position]
    );

    db_insert('inspection_photos', [
        'inspection_id' => $inspectionId,
        'position'      => $position,
        'file_path'     => $path,
        'caption'       => $caption ?: null,
        'uploaded_by'   => auth_user()['id'] ?? null,
    ]);
    return $path;
}

/** จำนวนรูปที่ถ่ายแล้ว / ทั้งหมดที่ควรมี (ไม่นับ "จุดที่มีตำหนิ" ซึ่งข้ามได้) */
function inspection_progress(?array $inspection): array
{
    $required = count(inspection_positions()) - 1;   // damage ไม่บังคับ
    if (!$inspection) {
        return ['done' => 0, 'required' => $required, 'complete' => false];
    }
    $taken = [];
    foreach ($inspection['photos'] as $p) {
        if ($p['position'] !== 'damage') {
            $taken[$p['position']] = true;
        }
    }
    $done = count($taken);
    return ['done' => $done, 'required' => $required, 'complete' => $done >= $required];
}

/** จัดรูปเป็น map มุม => แถวรูป เพื่อให้หน้าเว็บหยิบใช้ง่าย */
function inspection_photo_map(?array $inspection): array
{
    $map = [];
    if ($inspection) {
        foreach ($inspection['photos'] as $p) {
            $map[$p['position']] = $p;
        }
    }
    return $map;
}

/** ระดับน้ำมันเป็นข้อความ (ระบบเก็บเป็นขีด 0–8) */
function fuel_label(?int $level): string
{
    if ($level === null) {
        return '-';
    }
    $names = [0 => 'ถังว่าง', 2 => '1/4 ถัง', 4 => 'ครึ่งถัง', 6 => '3/4 ถัง', 8 => 'เต็มถัง'];
    return $names[$level] ?? ($level . '/8 ขีด');
}
