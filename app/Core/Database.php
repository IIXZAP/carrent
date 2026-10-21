<?php

namespace App\Core;

use PDO;
use PDOException;

/**
 * เชื่อมต่อฐานข้อมูลด้วย PDO (prepared statement เท่านั้น — ไม่มี SQL string concat)
 *
 * หมายเหตุ: ตั้ง ATTR_EMULATE_PREPARES = false จึงต้องใช้ชื่อพารามิเตอร์ไม่ซ้ำกันในคำสั่งเดียว
 * (เช่น :q, :q2, :q3) ไม่ใช่ชื่อเดียวกันซ้ำหลายตำแหน่ง
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        try {
            $pdo = self::connect(DB_HOST, DB_NAME, DB_USER, DB_PASS);
        } catch (PDOException $e) {
            throw new DatabaseConnectionException($e->getMessage(), 0, $e);
        }

        // ให้ NOW() / CURRENT_TIMESTAMP ของฐานข้อมูลเป็นเวลาเดียวกับ PHP (เวลาไทย)
        // โฮสต์บางเจ้าตั้ง MySQL เป็น UTC: ถ้าไม่ตั้งตรงนี้ การเทียบ "รถเลยกำหนดคืน" การเตือนลูกค้า
        // และการล็อกบัญชีเมื่อใส่รหัสผิด จะคลาดไป 7 ชั่วโมง
        try {
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (\Throwable $e) {
            // ฐานข้อมูลไม่ยอมให้ตั้ง — ใช้เวลาของเซิร์ฟเวอร์ตามเดิม
        }
        return self::$pdo = $pdo;
    }

    /** สร้างการเชื่อมต่อใหม่ (ใช้ต่อฐานข้อมูลอีกชุดในหน้าเทียบผล UX) */
    public static function connect(string $host, string $name, string $user, string $pass, string $charset = DB_CHARSET): PDO
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset);
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE             => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES    => false,
            PDO::MYSQL_ATTR_INIT_COMMAND  => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]);
    }

    /** คืนแถวเดียว */
    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** คืนหลายแถว */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** คืนค่าเดียว (คอลัมน์แรกของแถวแรก) */
    public static function value(string $sql, array $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    /** รันคำสั่งที่ไม่ต้องการผลลัพธ์ คืนจำนวนแถวที่ได้รับผล */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** INSERT แล้วคืน id ล่าสุด */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', array_map(static fn($c) => "`$c`", $cols)),
            implode(',', array_map(static fn($c) => ":$c", $cols))
        );
        $st = self::pdo()->prepare($sql);
        foreach ($data as $k => $v) {
            $st->bindValue(':' . $k, $v);
        }
        $st->execute();
        return (int) self::pdo()->lastInsertId();
    }

    /** UPDATE ตามเงื่อนไขคอลัมน์เดียว (ส่วนใหญ่ใช้ id) */
    public static function update(string $table, array $data, string $whereCol, $whereVal): int
    {
        $sets = [];
        foreach (array_keys($data) as $c) {
            $sets[] = "`$c` = :$c";
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE `%s` = :__w', $table, implode(', ', $sets), $whereCol);
        $st  = self::pdo()->prepare($sql);
        foreach ($data as $k => $v) {
            $st->bindValue(':' . $k, $v);
        }
        $st->bindValue(':__w', $whereVal);
        $st->execute();
        return $st->rowCount();
    }

    // ---------- Transaction ----------
    public static function begin(): void
    {
        self::pdo()->beginTransaction();
    }

    public static function commit(): void
    {
        self::pdo()->commit();
    }

    /** ย้อนทรานแซกชันถ้ายังเปิดอยู่ (หลัง deadlock ฐานข้อมูลอาจปิดให้เองแล้ว เรียก rollBack ตรงๆ จะเกิด error ซ้อน) */
    public static function rollBack(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}
