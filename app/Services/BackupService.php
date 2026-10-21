<?php

namespace App\Services;

use App\Core\Database;

/**
 * สำรองฐานข้อมูล — เขียนด้วย PHP ล้วน ไม่พึ่งคำสั่ง mysqldump
 * (shared hosting หลายเจ้าปิด exec() หรือไม่มี mysqldump ให้ใช้)
 *
 * ไฟล์สำรองเก็บที่ storage/backups/ ซึ่งปิดไม่ให้เปิดผ่านเว็บโดยตรง
 * ดาวน์โหลดได้ทางหน้า หลังบ้าน → สำรองข้อมูล เท่านั้น (ต้องเป็นเจ้าของร้าน)
 *
 * หมายเหตุ: สำรองเฉพาะฐานข้อมูล ไม่รวมไฟล์รูปในโฟลเดอร์ uploads
 */
final class BackupService
{
    public static function dir(): string
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
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/db_*.sql*') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'path' => $f, 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
        }
        usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }

    /**
     * สร้างไฟล์สำรองใหม่
     * @return array{name:string,size:int,tables:int,rows:int}
     */
    public static function create(): array
    {
        $dir = self::dir();
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException('เขียนไฟล์ลงโฟลเดอร์ storage/backups ไม่ได้ กรุณาตรวจสิทธิ์โฟลเดอร์ (ควรเป็น 755)');
        }
        $gz   = function_exists('gzopen');
        $name = 'db_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.sql' . ($gz ? '.gz' : '');
        $path = $dir . '/' . $name;
        // เขียนลงไฟล์ชั่วคราวก่อน เสร็จสมบูรณ์แล้วจึงเปลี่ยนชื่อเป็นไฟล์สำรองจริง
        // ถ้าพื้นที่เต็มกลางทาง จะไม่มีไฟล์สำรองครึ่งๆ กลางๆ ปนอยู่กับไฟล์ที่ใช้กู้ได้จริง
        $tmp = $dir . '/part_' . bin2hex(random_bytes(6)) . '.tmp';
        // ไฟล์ชั่วคราวที่ค้างจากรอบก่อน (สคริปต์ถูกตัดกลางทางเพราะหมดเวลา) — เก็บกวาดเมื่อเก่ากว่า 1 ชั่วโมง
        foreach (glob($dir . '/part_*.tmp') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }

        $fh = $gz ? gzopen($tmp, 'wb6') : fopen($tmp, 'wb');
        if (!$fh) {
            throw new \RuntimeException('สร้างไฟล์สำรองไม่สำเร็จ');
        }
        $w = function (string $s) use ($gz, $fh): void {
            $n = $gz ? gzwrite($fh, $s) : fwrite($fh, $s);
            if ($n === false || $n < strlen($s)) {
                throw new \RuntimeException('เขียนไฟล์สำรองไม่ครบ — พื้นที่บนเซิร์ฟเวอร์อาจเต็ม');
            }
        };

        try {
            $pdo = Database::pdo();
            $w("-- thonglorcarrent database backup\n-- สร้างเมื่อ " . date('Y-m-d H:i:s') . "\n-- ฐานข้อมูล " . DB_NAME . "\n\n");
            $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

            $tables = $rows = 0;
            foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM) as $t) {
                if (($t[1] ?? 'BASE TABLE') !== 'BASE TABLE') {
                    continue;
                }
                $table  = $t[0];
                $quoted = '`' . str_replace('`', '``', $table) . '`';
                $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(\PDO::FETCH_NUM);

                $w("-- ----------------------------\n-- $table\n-- ----------------------------\n");
                $w("DROP TABLE IF EXISTS $quoted;\n" . $create[1] . ";\n\n");

                $stmt  = $pdo->query('SELECT * FROM ' . $quoted);
                $batch = [];
                $cols  = null;
                while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
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
                throw new \RuntimeException('ปิดไฟล์สำรองไม่สำเร็จ — พื้นที่บนเซิร์ฟเวอร์อาจเต็ม');
            }
            $fh = null;
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException('บันทึกไฟล์สำรองไม่สำเร็จ');
            }
        } catch (\Throwable $e) {
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
    public static function prune(): int
    {
        $keep = max(7, (int) setting('backup_keep_days', '90'));
        $cut  = time() - $keep * 86400;
        $n    = 0;
        foreach (self::list() as $i => $f) {
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
    public static function find(string $name): ?array
    {
        foreach (self::list() as $f) {
            if (hash_equals($f['name'], $name)) {
                return $f;
            }
        }
        return null;
    }
}
