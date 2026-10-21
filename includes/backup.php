<?php
/**
 * สำรองฐานข้อมูล — เขียนด้วย PHP ล้วน ไม่พึ่งคำสั่ง mysqldump
 * (shared hosting หลายเจ้าปิด exec() หรือไม่มี mysqldump ให้ใช้)
 *
 * ไฟล์สำรองเก็บที่ storage/backups/ ซึ่งปิดไม่ให้เปิดผ่านเว็บโดยตรง
 * ดาวน์โหลดได้ทางหน้า หลังบ้าน → สำรองข้อมูล เท่านั้น (ต้องเป็นเจ้าของร้าน)
 *
 * หมายเหตุ: สำรองเฉพาะฐานข้อมูล ไม่รวมไฟล์รูปในโฟลเดอร์ uploads
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/private_files.php';

function backup_dir(): string
{
    $dir = APP_ROOT . '/storage/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    // ปิดการเข้าถึงผ่านเว็บ 2 ชั้น: .htaccess (Apache/LiteSpeed) + ชื่อไฟล์มีรหัสสุ่มเดาไม่ได้
    $root = APP_ROOT . '/storage';
    if (is_dir($root) && !is_file($root . '/.htaccess')) {
        @file_put_contents($root . '/.htaccess',
            "# ปิดการเข้าถึงโฟลเดอร์นี้ผ่านเว็บทั้งหมด — ไฟล์สำรองดาวน์โหลดได้ทางหลังบ้านเท่านั้น\n"
            . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    if (is_dir($root) && !is_file($root . '/index.html')) {
        @file_put_contents($root . '/index.html', '');
    }
    return $dir;
}

/** รายการไฟล์สำรอง ใหม่สุดก่อน */
function backup_list(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/db_*.sql*') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'path' => $f, 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/**
 * สร้างไฟล์สำรองใหม่
 * @return array{name:string,size:int,tables:int,rows:int}
 */
function backup_create(): array
{
    $dir = backup_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('เขียนไฟล์ลงโฟลเดอร์ storage/backups ไม่ได้ กรุณาตรวจสิทธิ์โฟลเดอร์ (ควรเป็น 755)');
    }
    $gz   = function_exists('gzopen');
    $name = 'db_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.sql' . ($gz ? '.gz' : '');
    $path = $dir . '/' . $name;
    // เขียนลงไฟล์ชั่วคราวก่อน เสร็จสมบูรณ์แล้วจึงเปลี่ยนชื่อเป็นไฟล์สำรองจริง
    // ถ้าพื้นที่เต็มกลางทาง จะไม่มีไฟล์สำรองครึ่งๆ กลางๆ ปนอยู่กับไฟล์ที่ใช้กู้ได้จริง
    $tmp  = $dir . '/part_' . bin2hex(random_bytes(6)) . '.tmp';
    // ไฟล์ชั่วคราวที่ค้างจากรอบก่อน (สคริปต์ถูกตัดกลางทางเพราะหมดเวลา) — เก็บกวาดเมื่อเก่ากว่า 1 ชั่วโมง
    foreach (glob($dir . '/part_*.tmp') ?: [] as $old) {
        if (filemtime($old) < time() - 3600) {
            @unlink($old);
        }
    }

    $fh = $gz ? gzopen($tmp, 'wb6') : fopen($tmp, 'wb');
    if (!$fh) {
        throw new RuntimeException('สร้างไฟล์สำรองไม่สำเร็จ');
    }
    $w = function (string $s) use ($gz, $fh): void {
        $n = $gz ? gzwrite($fh, $s) : fwrite($fh, $s);
        if ($n === false || $n < strlen($s)) {
            throw new RuntimeException('เขียนไฟล์สำรองไม่ครบ — พื้นที่บนเซิร์ฟเวอร์อาจเต็ม');
        }
    };

  try {
    $pdo = db();
    $w("-- thonglorcarrent database backup\n-- สร้างเมื่อ " . date('Y-m-d H:i:s') . "\n-- ฐานข้อมูล " . DB_NAME . "\n\n");
    $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

    $tables = $rows = 0;
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $t) {
        if (($t[1] ?? 'BASE TABLE') !== 'BASE TABLE') {
            continue;
        }
        $table  = $t[0];
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);

        $w("-- ----------------------------\n-- $table\n-- ----------------------------\n");
        $w("DROP TABLE IF EXISTS $quoted;\n" . $create[1] . ";\n\n");

        $stmt  = $pdo->query('SELECT * FROM ' . $quoted);
        $batch = [];
        $cols  = null;
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === null) {
                $cols = '(' . implode(',', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($r))) . ')';
            }
            $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $r)) . ')';
            $rows++;
            if (count($batch) >= 200) {
                $w("INSERT INTO $quoted $cols VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            $w("INSERT INTO $quoted $cols VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        $w("\n");
        $tables++;
    }
    $w("SET FOREIGN_KEY_CHECKS = 1;\n-- จบไฟล์สำรอง: $tables ตาราง $rows แถว\n");
    if (!($gz ? gzclose($fh) : fclose($fh))) {
        $fh = null;
        throw new RuntimeException('ปิดไฟล์สำรองไม่สำเร็จ — พื้นที่บนเซิร์ฟเวอร์อาจเต็ม');
    }
    $fh = null;
    if (!@rename($tmp, $path)) {
        throw new RuntimeException('บันทึกไฟล์สำรองไม่สำเร็จ');
    }
  } catch (Throwable $e) {
    if ($fh) {
        $gz ? @gzclose($fh) : @fclose($fh);
    }
    @unlink($tmp);     // ไฟล์ชั่วคราวที่ฟังก์ชันนี้เพิ่งสร้างเองและเขียนไม่จบ — ไม่ใช่ไฟล์สำรอง
    throw $e;
  }
    @chmod($path, 0640);

    set_setting('backup_last_at', date('Y-m-d H:i:s'));
    return ['name' => $name, 'size' => (int) filesize($path), 'tables' => $tables, 'rows' => $rows];
}

/**
 * ลบไฟล์สำรองที่เก่าเกินจำนวนวันที่ตั้งไว้ (ค่าตั้งต้น 90 วัน)
 * เก็บไฟล์ล่าสุดไว้อย่างน้อย 7 ไฟล์เสมอ — กันกรณี cron หยุดทำงานนานแล้วไฟล์ถูกลบจนหมด
 * @return int จำนวนไฟล์ที่ลบ
 */
function backup_prune(): int
{
    $keep = max(7, (int) setting('backup_keep_days', '90'));
    $cut  = time() - $keep * 86400;
    $n    = 0;
    foreach (backup_list() as $i => $f) {
        if ($i < 7) {
            continue;
        }
        if ($f['time'] < $cut && @unlink($f['path'])) {
            $n++;
        }
    }
    return $n;
}

/** หาไฟล์สำรองจากชื่อ — รับเฉพาะชื่อที่อยู่ในรายการจริง กันการขอไฟล์นอกโฟลเดอร์ */
function backup_find(string $name): ?array
{
    foreach (backup_list() as $f) {
        if (hash_equals($f['name'], $name)) {
            return $f;
        }
    }
    return null;
}

function human_bytes(float $b): string
{
    foreach (['B', 'KB', 'MB', 'GB'] as $u) {
        if ($b < 1024 || $u === 'GB') {
            return ($u === 'B' ? number_format($b) : number_format($b, 1)) . ' ' . $u;
        }
        $b /= 1024;
    }
    return '';
}

// ------------------------------------------------------------
// ตรวจสุขภาพระบบ — ใช้ทั้งหน้า หลังบ้าน → สุขภาพระบบ และ health.php
// ------------------------------------------------------------
/**
 * @return array<int,array{0:string,1:string,2:string,3:string}> [หัวข้อ, ระดับ ok|warn|fail, ผลที่พบ, คำแนะนำ]
 */
function health_checks(): array
{
    $c = [];
    $add = function (string $title, string $level, string $found, string $advice = '') use (&$c) {
        $c[] = [$title, $level, $found, $advice];
    };

    // --- PHP ---
    $add('เวอร์ชัน PHP', version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'warn', PHP_VERSION,
        version_compare(PHP_VERSION, '8.1', '>=') ? '' : 'อัปเป็น PHP 8.1 ขึ้นไปใน cPanel → Select PHP Version');
    $miss = array_values(array_filter(['pdo_mysql', 'curl', 'gd', 'fileinfo', 'mbstring', 'zlib'], fn($x) => !extension_loaded($x)));
    $add('ส่วนขยาย PHP ที่ระบบใช้', $miss ? 'fail' : 'ok', $miss ? 'ขาด: ' . implode(', ', $miss) : 'ครบ',
        $miss ? 'เปิดส่วนขยายที่ขาดใน cPanel → Select PHP Version → Extensions' : '');

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || str_starts_with((string) setting('site_url'), 'https://');
    $add('การเข้ารหัส HTTPS', $https ? 'ok' : 'fail', $https ? 'เปิดอยู่' : 'ไม่ได้ใช้ HTTPS',
        $https ? '' : 'ติดตั้ง SSL ใน cPanel → SSL/TLS Status — LINE และการกรอกข้อมูลลูกค้าต้องใช้ HTTPS');
    $add('โหมดแสดงข้อผิดพลาด', APP_DEBUG ? 'fail' : 'ok', APP_DEBUG ? 'APP_DEBUG เปิดอยู่' : 'ปิด (ถูกต้อง)',
        APP_DEBUG ? 'ตั้ง APP_DEBUG เป็น false ใน config/config.php — เปิดไว้ผู้ใช้จะเห็นรายละเอียดภายในระบบ' : '');

    // --- ฐานข้อมูล ---
    try {
        $size = (float) db_val('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.TABLES WHERE table_schema = DATABASE()');
        $add('ฐานข้อมูล', 'ok', 'เชื่อมต่อได้ · ขนาด ' . human_bytes($size));
        $v4 = (int) db_val("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                            AND ((TABLE_NAME = 'inspections' AND COLUMN_NAME = 'locked_at')
                              OR (TABLE_NAME = 'notifications' AND COLUMN_NAME = 'tpl_key')
                              OR (TABLE_NAME = 'inspection_photos' AND COLUMN_NAME = 'superseded_at'))");
        $v5 = (int) db_val("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                            AND ((TABLE_NAME = 'bookings' AND COLUMN_NAME IN ('extra_breakdown', 'sign_token', 'delivery_km'))
                              OR (TABLE_NAME = 'signatures' AND COLUMN_NAME = 'voided_at')
                              OR (TABLE_NAME = 'customer_documents' AND COLUMN_NAME = 'mime'))");
        // ตารางของเวอร์ชัน 3 (รูปสภาพรถ) — ถ้าข้ามไฟล์นั้นมา update-v4.sql จะรันไม่ผ่านตั้งแต่บรรทัดแรก
        $v3 = (int) db_val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME IN ('inspections', 'inspection_photos')");
        $dbOk = $v3 === 2 && $v4 === 3 && $v5 === 5;
        $need = $v3 < 2 ? 'update-v3.sql → update-v4.sql → update-v5.sql' : ($v4 < 3 ? 'update-v4.sql → update-v5.sql' : 'update-v5.sql');
        $add('โครงสร้างฐานข้อมูล', $dbOk ? 'ok' : 'fail',
            $dbOk ? 'เวอร์ชัน 5 (ล่าสุด)' : 'ยังไม่ได้อัปเดตเป็นเวอร์ชัน ' . ($v3 < 2 ? '3' : ($v4 < 3 ? '4' : '5')),
            $dbOk ? '' : 'นำเข้าไฟล์ในโฟลเดอร์ database/ ตามลำดับ: ' . $need . ' ใน phpMyAdmin (ทุกไฟล์รันซ้ำได้ ไม่ลบข้อมูล)');
    } catch (Throwable $e) {
        $add('ฐานข้อมูล', 'fail', 'เชื่อมต่อไม่ได้', 'ตรวจค่าใน config/config.php');
    }

    // --- ไฟล์ ---
    $up = rtrim(UPLOAD_DIR, '/');
    $upOk = is_dir($up) && is_writable($up);
    [$bytes, $files] = dir_usage($up);
    $add('โฟลเดอร์รูปและเอกสาร (uploads)', $upOk ? 'ok' : 'fail',
        $upOk ? number_format($files) . ' ไฟล์ · ' . human_bytes($bytes) : 'เขียนไฟล์ไม่ได้',
        $upOk ? '' : 'ตั้งสิทธิ์โฟลเดอร์ uploads เป็น 755');
    // ที่เก็บแบบปิด: ลายเซ็นลูกค้า + สำเนาบัตร/ใบขับขี่
    try {
        $pv   = private_dir('');
        $pvOk = is_writable($pv);
        [$pb, $pf] = dir_usage($pv);
    } catch (Throwable $e) {
        $pvOk = false; $pb = 0; $pf = 0;
    }
    $add('ที่เก็บไฟล์แบบปิด (ลายเซ็น / เอกสารลูกค้า)', $pvOk ? 'ok' : 'fail',
        $pvOk ? number_format($pf) . ' ไฟล์ · ' . human_bytes($pb) : 'เขียนไฟล์ไม่ได้',
        $pvOk ? '' : 'ตั้งสิทธิ์โฟลเดอร์ storage เป็น 755 — ไม่งั้นลูกค้าเซ็นสัญญาและแนบเอกสารไม่ได้');
    try {
        $legacy = legacy_docs_count();
        $add('เอกสารลูกค้าในโฟลเดอร์สาธารณะ', $legacy > 0 ? 'warn' : 'ok',
            $legacy > 0 ? $legacy . ' ไฟล์ยังอยู่ใน uploads/docs' : 'ไม่มี',
            $legacy > 0 ? 'กดปุ่ม "ย้ายเข้าที่เก็บแบบปิด" ด้านล่างของหน้านี้ — สำเนาบัตรประชาชนไม่ควรอยู่ในโฟลเดอร์ที่เปิดผ่านเว็บได้' : '');
    } catch (Throwable $e) {
        // ฐานข้อมูลยังไม่ถึงเวอร์ชันที่มีคอลัมน์นี้ — ข้อ "โครงสร้างฐานข้อมูล" แจ้งไว้แล้ว
    }
    $free = @disk_free_space(APP_ROOT);
    if ($free !== false) {
        $add('พื้นที่ว่างบนเซิร์ฟเวอร์', $free < 500 * 1048576 ? ($free < 100 * 1048576 ? 'fail' : 'warn') : 'ok', human_bytes((float) $free),
            $free < 500 * 1048576 ? 'พื้นที่เหลือน้อย — ลบไฟล์สำรองเก่าหรือขยายแพ็กเกจโฮสติ้ง' : '');
    }
    $lim = upload_limit_bytes();
    $add('ขนาดไฟล์ที่อัปโหลดได้', $lim < 2 * 1048576 ? 'warn' : 'ok', human_bytes((float) $lim) . ' ต่อครั้ง',
        $lim < 2 * 1048576 ? 'ต่ำกว่า 2 MB — เพิ่ม upload_max_filesize และ post_max_size ใน cPanel → MultiPHP INI Editor' : 'ระบบย่อรูปก่อนส่งให้อัตโนมัติ');

    // --- งานอัตโนมัติ ---
    $cron = (string) setting('cron_last_at');
    $age  = $cron !== '' ? (time() - strtotime($cron)) / 3600 : null;
    $add('งานอัตโนมัติรายวัน (cron)', $age === null ? 'fail' : ($age > 36 ? 'fail' : 'ok'),
        $age === null ? 'ยังไม่เคยทำงาน' : 'ทำงานล่าสุด ' . thai_date($cron, true),
        ($age === null || $age > 36) ? 'ตั้ง Cron Job ใน cPanel ให้เรียก cron/daily.php วันละครั้ง — ไม่ทำงาน = ไม่มีเตือนลูกค้า ไม่มีสำรองข้อมูล' : '');
    $defKey = (string) setting('cron_key', 'CHANGE-THIS-CRON-KEY') === 'CHANGE-THIS-CRON-KEY';
    $add('รหัสป้องกัน cron', $defKey ? 'fail' : 'ok', $defKey ? 'ยังเป็นค่าตั้งต้น' : 'เปลี่ยนแล้ว',
        $defKey ? 'เปลี่ยน Cron Key ที่ หลังบ้าน → ตั้งค่า — ค่าตั้งต้นใครก็เรียกงานอัตโนมัติได้' : '');

    $bk    = (string) setting('backup_last_at');
    $bkAge = $bk !== '' ? (time() - strtotime($bk)) / 3600 : null;
    $list  = backup_list();
    $add('สำรองฐานข้อมูล', $bkAge === null ? 'fail' : ($bkAge > 36 ? 'warn' : 'ok'),
        $bkAge === null ? 'ยังไม่เคยสำรอง' : 'ล่าสุด ' . thai_date($bk, true) . ' · เก็บอยู่ ' . count($list) . ' ไฟล์',
        ($bkAge === null || $bkAge > 36) ? 'ตรวจว่า cron ทำงาน หรือกด "สำรองตอนนี้" ที่หน้า สำรองข้อมูล' : '');

    // --- แจ้งเตือน ---
    $fail = (int) db_val("SELECT COUNT(*) FROM notifications WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stuck = (int) db_val("SELECT COUNT(*) FROM notifications WHERE status = 'queued' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $add('การส่งแจ้งเตือน 7 วันล่าสุด', $fail + $stuck > 0 ? 'warn' : 'ok',
        $fail + $stuck > 0 ? "ส่งไม่สำเร็จ $fail · ค้างคิว $stuck" : 'ปกติ',
        $fail + $stuck > 0 ? 'ดูสาเหตุที่ หลังบ้าน → เชื่อมต่อ LINE → ประวัติการส่ง' : '');
    $lineOk = trim((string) setting('line_channel_token')) !== '' && trim((string) setting('line_channel_secret')) !== ''
           && trim((string) setting('line_liff_id')) !== '' && setting('notify_line_enabled') === '1';
    $add('การเชื่อมต่อ LINE', $lineOk ? 'ok' : 'warn', $lineOk ? 'ตั้งค่าครบและเปิดใช้งาน' : 'ยังตั้งค่าไม่ครบหรือยังไม่เปิดใช้',
        $lineOk ? '' : 'ตั้งค่าที่ หลังบ้าน → เชื่อมต่อ LINE');

    // --- บัญชีผู้ใช้ ---
    $admins = (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
    $add('บัญชีเจ้าของร้าน', $admins >= 1 ? 'ok' : 'fail', $admins . ' บัญชี',
        $admins === 1 ? 'มีบัญชีเดียว — ถ้าลืมรหัสผ่านจะต้องแก้ผ่านฐานข้อมูล แนะนำให้มีสำรองอีก 1 บัญชี' : '');
    $over = (int) db_val("SELECT COUNT(*) FROM bookings WHERE status = 'picked_up' AND return_at < NOW()");
    $add('รถเลยกำหนดคืนที่ยังไม่บันทึกรับคืน', $over > 0 ? 'warn' : 'ok', $over . ' คัน',
        $over > 0 ? 'คันเหล่านี้ถูกกันไม่ให้จองต่อ — บันทึกรับคืนที่หน้า ภาพรวม' : '');

    return $c;
}

/** ขนาดรวมและจำนวนไฟล์ในโฟลเดอร์ (หยุดนับที่ 20,000 ไฟล์ กันช้า) */
function dir_usage(string $dir): array
{
    $bytes = 0; $files = 0;
    if (!is_dir($dir)) {
        return [0, 0];
    }
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $bytes += $f->getSize();
                if (++$files >= 20000) {
                    break;
                }
            }
        }
    } catch (Throwable $e) {
    }
    return [$bytes, $files];
}
