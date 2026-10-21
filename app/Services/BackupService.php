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
            @file_put_contents($root . '/.htaccess', self::denyRules());
        }
        if (is_dir($root) && !is_file($root . '/index.html')) {
            @file_put_contents($root . '/index.html', '');
        }
        return $dir;
    }

    private static function denyRules(): string
    {
        return "# ปิดการเข้าถึงโฟลเดอร์นี้ผ่านเว็บทั้งหมด — ไฟล์สำรองดาวน์โหลดได้ทางหลังบ้านเท่านั้น\n"
             . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
             . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    }

    /** รายการไฟล์สำรอง ใหม่สุดก่อน */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/db_*.sql*') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'path' => $f, 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
        }
        usort($out, [self::class, 'newestFirst']);
        return $out;
    }

    private static function newestFirst(array $a, array $b): int
    {
        return $b['time'] <=> $a['time'];
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
        self::removeStaleTempFiles($dir);

        $gz   = function_exists('gzopen');
        $name = 'db_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.sql' . ($gz ? '.gz' : '');
        $path = $dir . '/' . $name;
        // เขียนลงไฟล์ชั่วคราวก่อน เสร็จสมบูรณ์แล้วจึงเปลี่ยนชื่อเป็นไฟล์สำรองจริง
        // ถ้าพื้นที่เต็มกลางทาง จะไม่มีไฟล์สำรองครึ่งๆ กลางๆ ปนอยู่กับไฟล์ที่ใช้กู้ได้จริง
        $tmp = $dir . '/part_' . bin2hex(random_bytes(6)) . '.tmp';

        $handle = $gz ? gzopen($tmp, 'wb6') : fopen($tmp, 'wb');
        if (!$handle) {
            throw new \RuntimeException('สร้างไฟล์สำรองไม่สำเร็จ');
        }

        try {
            $count = self::dumpAll($handle, $gz);
            self::closeHandle($handle, $gz);
            $handle = null;
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException('บันทึกไฟล์สำรองไม่สำเร็จ');
            }
        } catch (\Throwable $e) {
            if ($handle) {
                $gz ? @gzclose($handle) : @fclose($handle);
            }
            @unlink($tmp);     // ไฟล์ชั่วคราวที่ฟังก์ชันนี้เพิ่งสร้างเองและเขียนไม่จบ — ไม่ใช่ไฟล์สำรอง
            throw $e;
        }
        @chmod($path, 0640);

        set_setting('backup_last_at', date('Y-m-d H:i:s'));
        return ['name' => $name, 'size' => (int) filesize($path), 'tables' => $count['tables'], 'rows' => $count['rows']];
    }

    /** ไฟล์ชั่วคราวที่ค้างจากรอบก่อน (สคริปต์ถูกตัดกลางทางเพราะหมดเวลา) — เก็บกวาดเมื่อเก่ากว่า 1 ชั่วโมง */
    private static function removeStaleTempFiles(string $dir): void
    {
        foreach (glob($dir . '/part_*.tmp') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }
    }

    private static function closeHandle($handle, bool $gz): void
    {
        $closed = $gz ? gzclose($handle) : fclose($handle);
        if (!$closed) {
            throw new \RuntimeException('ปิดไฟล์สำรองไม่สำเร็จ — พื้นที่บนเซิร์ฟเวอร์อาจเต็ม');
        }
    }

    /** เขียนข้อความลงไฟล์สำรอง — ถ้าเขียนไม่ครบแปลว่าพื้นที่เต็ม */
    private static function write($handle, bool $gz, string $text): void
    {
        $written = $gz ? gzwrite($handle, $text) : fwrite($handle, $text);
        if ($written === false || $written < strlen($text)) {
            throw new \RuntimeException('เขียนไฟล์สำรองไม่ครบ — พื้นที่บนเซิร์ฟเวอร์อาจเต็ม');
        }
    }

    /** เขียนทุกตารางลงไฟล์ @return array{tables:int,rows:int} */
    private static function dumpAll($handle, bool $gz): array
    {
        $pdo = Database::pdo();
        self::write($handle, $gz, "-- thonglorcarrent database backup\n-- สร้างเมื่อ " . date('Y-m-d H:i:s') . "\n-- ฐานข้อมูล " . DB_NAME . "\n\n");
        self::write($handle, $gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = 0;
        $rows   = 0;
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM) as $t) {
            if (($t[1] ?? 'BASE TABLE') !== 'BASE TABLE') {
                continue;   // ข้าม view
            }
            $rows += self::dumpTable($handle, $gz, $t[0]);
            $tables++;
        }
        self::write($handle, $gz, "SET FOREIGN_KEY_CHECKS = 1;\n-- จบไฟล์สำรอง: $tables ตาราง $rows แถว\n");
        return ['tables' => $tables, 'rows' => $rows];
    }

    /** เขียนโครงสร้าง + ข้อมูลของตารางเดียว @return int จำนวนแถว */
    private static function dumpTable($handle, bool $gz, string $table): int
    {
        $pdo    = Database::pdo();
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(\PDO::FETCH_NUM);

        self::write($handle, $gz, "-- ----------------------------\n-- $table\n-- ----------------------------\n");
        self::write($handle, $gz, "DROP TABLE IF EXISTS $quoted;\n" . $create[1] . ";\n\n");

        $stmt    = $pdo->query('SELECT * FROM ' . $quoted);
        $batch   = [];
        $columns = null;
        $rows    = 0;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ($columns === null) {
                $columns = self::columnList(array_keys($row));
            }
            $batch[] = self::valueList($pdo, $row);
            $rows++;
            if (count($batch) >= 200) {
                self::write($handle, $gz, "INSERT INTO $quoted $columns VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            self::write($handle, $gz, "INSERT INTO $quoted $columns VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        self::write($handle, $gz, "\n");
        return $rows;
    }

    /** (`col1`,`col2`,...) */
    private static function columnList(array $names): string
    {
        $quoted = [];
        foreach ($names as $name) {
            $quoted[] = '`' . str_replace('`', '``', $name) . '`';
        }
        return '(' . implode(',', $quoted) . ')';
    }

    /** ('ค่า1',NULL,...) */
    private static function valueList(\PDO $pdo, array $row): string
    {
        $values = [];
        foreach ($row as $value) {
            $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
        }
        return '(' . implode(',', $values) . ')';
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
