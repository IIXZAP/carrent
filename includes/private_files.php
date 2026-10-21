<?php
/**
 * ที่เก็บไฟล์แบบปิด — storage/private/
 *
 * ใช้กับไฟล์ที่ห้ามเปิดผ่านลิงก์ตรง: สำเนาบัตรประชาชน ใบขับขี่ ลายเซ็นลูกค้า
 * กันไว้ 3 ชั้น
 *   1) อยู่ใต้ storage/ ซึ่ง .htaccess ปิดการเข้าถึงผ่านเว็บทั้งโฟลเดอร์
 *   2) ชื่อไฟล์เป็นรหัสสุ่ม 32 ตัว เดาไม่ได้
 *   3) เปิดดูได้ผ่านหน้า PHP ที่ตรวจสิทธิ์และบันทึกประวัติการเปิดเท่านั้น
 *
 * ไฟล์นี้ไม่มีคำสั่งลบไฟล์ใดๆ
 */
require_once __DIR__ . '/functions.php';

/** ชนิดไฟล์ที่รับ → นามสกุล */
function private_allowed_mimes(): array
{
    return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
}

function private_root(): string
{
    return APP_ROOT . '/storage/private';
}

/** โฟลเดอร์ย่อยในที่เก็บแบบปิด — สร้างให้ถ้ายังไม่มี พร้อมไฟล์กันการเข้าถึง */
function private_dir(string $sub): string
{
    $sub = trim(preg_replace('/[^a-z0-9_\/-]/', '', strtolower($sub)), '/');
    $dir = private_root() . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('สร้างโฟลเดอร์เก็บไฟล์ไม่สำเร็จ กรุณาตรวจสิทธิ์โฟลเดอร์ storage');
    }
    $deny = "# ปิดการเข้าถึงโฟลเดอร์นี้ผ่านเว็บทั้งหมด\n"
          . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    foreach ([APP_ROOT . '/storage', private_root()] as $d) {
        if (is_dir($d) && !is_file($d . '/.htaccess')) {
            @file_put_contents($d . '/.htaccess', $deny);
        }
        if (is_dir($d) && !is_file($d . '/index.html')) {
            @file_put_contents($d . '/index.html', '');
        }
    }
    return $dir;
}

/** เขียนข้อมูลลงที่เก็บแบบปิด คืน path แบบสัมพัทธ์ เช่น signatures/202610/ab12….png */
function private_put(string $sub, string $bytes, string $ext): string
{
    $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext));
    $rel = trim($sub, '/') . '/' . date('Ym');
    $dir = private_dir($rel);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (@file_put_contents($dir . '/' . $name, $bytes, LOCK_EX) === false) {
        throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ กรุณาตรวจสิทธิ์โฟลเดอร์ storage');
    }
    @chmod($dir . '/' . $name, 0640);
    return $rel . '/' . $name;
}

/**
 * รับไฟล์อัปโหลดเข้าที่เก็บแบบปิด — ตรวจชนิดจริงด้วย finfo ไม่เชื่อนามสกุลที่ส่งมา
 * @return array{path:string,mime:string,size:int}|null  null = ไม่ได้แนบไฟล์
 */
function private_store_upload(array $file, string $sub, int $maxMB = 8): ?array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (ขีดจำกัด ' . ini_get('upload_max_filesize') . ') กรุณาถ่ายผ่านหน้าเว็บบนมือถือ ระบบจะย่อรูปให้อัตโนมัติ',
            UPLOAD_ERR_PARTIAL => 'อัปโหลดไม่ครบ สัญญาณอาจหลุดกลางคัน กรุณาลองใหม่',
            default            => 'อัปโหลดไฟล์ไม่สำเร็จ (code ' . $file['error'] . ')',
        });
    }
    if ((int) $file['size'] > $maxMB * 1024 * 1024) {
        throw new RuntimeException("ไฟล์ใหญ่เกิน {$maxMB} MB");
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ok   = private_allowed_mimes();
    if (!isset($ok[$mime])) {
        throw new RuntimeException('รองรับเฉพาะไฟล์ JPG, PNG, WEBP และ PDF');
    }
    $rel  = trim($sub, '/') . '/' . date('Ym');
    $dir  = private_dir($rel);
    $name = bin2hex(random_bytes(16)) . '.' . $ok[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ กรุณาตรวจสิทธิ์โฟลเดอร์ storage');
    }
    @chmod($dir . '/' . $name, 0640);
    return ['path' => $rel . '/' . $name, 'mime' => $mime, 'size' => (int) $file['size']];
}

/** path จริงของไฟล์ในที่เก็บแบบปิด — คืน null ถ้าไม่มีไฟล์หรือ path พยายามออกนอกโฟลเดอร์ */
function private_path(?string $rel): ?string
{
    if (!$rel || str_contains($rel, "\0")) {
        return null;
    }
    $root = realpath(private_root());
    $full = realpath(private_root() . '/' . ltrim($rel, '/'));
    if ($root === false || $full === false || !is_file($full) || !str_starts_with($full, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $full;
}

/** path จริงของไฟล์เดิมใน uploads/ (เอกสารที่อัปโหลดก่อนเวอร์ชัน 5) พร้อมกันการออกนอกโฟลเดอร์ */
function legacy_upload_path(?string $rel): ?string
{
    if (!$rel || str_contains($rel, "\0")) {
        return null;
    }
    $root = realpath(rtrim(UPLOAD_DIR, '/'));
    $full = realpath(rtrim(UPLOAD_DIR, '/') . '/' . ltrim($rel, '/'));
    if ($root === false || $full === false || !is_file($full) || !str_starts_with($full, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $full;
}

/**
 * ส่งไฟล์ให้เบราว์เซอร์แล้วจบการทำงาน — ผู้เรียกต้องตรวจสิทธิ์มาก่อนแล้ว
 * ส่งเฉพาะชนิดที่อยู่ในรายการอนุญาต และบังคับไม่ให้เบราว์เซอร์เดาชนิดเอง (กันไฟล์ปลอมเป็น HTML)
 */
function send_private_file(string $fullPath, string $downloadName = 'file'): void
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($fullPath);
    $ok   = private_allowed_mimes();
    if (!isset($ok[$mime])) {
        http_response_code(415);
        exit('ชนิดไฟล์ไม่รองรับ');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $downloadName) . '.' . $ok[$mime];
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($fullPath));
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    readfile($fullPath);
    exit;
}

/** ไฟล์เล็กในที่เก็บแบบปิด → data URI สำหรับฝังในหน้า (ใช้กับลายเซ็น) */
function private_data_uri(?string $rel): string
{
    $full = private_path($rel);
    if (!$full || filesize($full) > 600 * 1024) {
        return '';
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($full);
    return isset(private_allowed_mimes()[$mime]) ? 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($full)) : '';
}

// ------------------------------------------------------------
// เอกสารลูกค้า
// ------------------------------------------------------------
/** ชนิดเอกสารลูกค้า → ชื่อที่แสดง */
function customer_doc_kinds(): array
{
    return ['id_card' => 'บัตรประชาชน', 'driver_license' => 'ใบขับขี่', 'passport' => 'หนังสือเดินทาง', 'other' => 'เอกสารอื่น'];
}

/** ปิดการเปิดไฟล์ใน uploads/docs ผ่านลิงก์ตรง (เอกสารที่อัปโหลดก่อนเวอร์ชัน 5 ยังอยู่ที่นั่น) */
function legacy_docs_protect(): void
{
    $dir = rtrim(UPLOAD_DIR, '/') . '/docs';
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess',
            "# สำเนาบัตรและใบขับขี่ของลูกค้า — ห้ามเปิดผ่านลิงก์ตรง เปิดดูได้ทางหลังบ้านเท่านั้น\n"
            . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
}

/** จำนวนเอกสารลูกค้าที่ยังอยู่ในโฟลเดอร์ uploads (ที่เก็บแบบเดิม) */
function legacy_docs_count(): int
{
    return (int) db_val("SELECT COALESCE(SUM((id_card_file IS NOT NULL AND id_card_file <> '') + (license_file IS NOT NULL AND license_file <> '')), 0) FROM customers");
}

/**
 * ย้ายเอกสารลูกค้าเดิมจาก uploads/docs เข้าที่เก็บแบบปิด
 * เป็นการ "ย้าย" ทีละไฟล์ (rename) ไม่มีการลบ — ถ้าย้ายไฟล์ไหนไม่สำเร็จ ไฟล์นั้นอยู่ที่เดิมและข้อมูลไม่ถูกแตะ
 * @return array{moved:int, missing:int, failed:int}
 */
function legacy_docs_migrate(?int $userId = null): array
{
    $out = ['moved' => 0, 'missing' => 0, 'failed' => 0];
    $map = ['id_card_file' => 'id_card', 'license_file' => 'driver_license'];
    foreach (db_all("SELECT id, id_card_file, license_file FROM customers
                     WHERE (id_card_file IS NOT NULL AND id_card_file <> '') OR (license_file IS NOT NULL AND license_file <> '')") as $c) {
        foreach ($map as $col => $kind) {
            if (empty($c[$col])) {
                continue;
            }
            $src = legacy_upload_path($c[$col]);
            if (!$src) {
                $out['missing']++;          // มีชื่อไฟล์ในฐานข้อมูลแต่ไม่พบไฟล์จริง — คงข้อมูลไว้ให้ตรวจ
                continue;
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($src);
            $ext  = private_allowed_mimes()[$mime] ?? null;
            if ($ext === null) {
                $out['failed']++;
                continue;
            }
            $rel  = 'docs/' . date('Ym');
            $dest = private_dir($rel) . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
            $size = (int) filesize($src);
            if (!@rename($src, $dest)) {
                $out['failed']++;
                continue;
            }
            @chmod($dest, 0640);
            // บันทึกฐานข้อมูลไม่สำเร็จ → ย้ายไฟล์กลับที่เดิม ไม่ปล่อยให้ไฟล์ค้างอยู่โดยไม่มีข้อมูลชี้ถึง
            $pdo = db();
            $pdo->beginTransaction();
            try {
                db_insert('customer_documents', [
                    'customer_id' => (int) $c['id'], 'doc_kind' => $kind, 'file_path' => $rel . '/' . basename($dest),
                    'mime' => $mime, 'file_size' => $size, 'note' => 'ย้ายจากที่เก็บเดิม', 'uploaded_by' => $userId,
                ]);
                db_exec("UPDATE customers SET $col = NULL WHERE id = :i", [':i' => (int) $c['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                db_rollback();
                @rename($dest, $src);
                $out['failed']++;
                continue;
            }
            $out['moved']++;
        }
    }
    return $out;
}
