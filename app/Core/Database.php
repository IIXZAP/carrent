<?php
namespace App\Core;

use PDO;

/** PDO ตัวเดียวทั้งระบบ — ทุกคำสั่งใช้ prepared statement (:name + array) */
final class Database
{
    private static ?PDO $pdo = null;

    /** ความลึกของ transaction ที่ซ้อนกัน (ชั้นในใช้ SAVEPOINT) */
    private static int $depth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $db  = config('db');
        $dsn = 'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4';
        self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        self::$pdo->exec("SET time_zone = '" . config('db_timezone') . "'");
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = [])
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    public static function inTransaction(): bool
    {
        return self::$depth > 0;
    }

    /**
     * รันงานที่เป็น transaction ทั้งก้อน ถ้า DB เลือกเราเป็นเหยื่อ deadlock (1213 / SQLSTATE 40001)
     * ลองใหม่ได้จำกัดรอบ — ปลอดภัยเพราะ DB ย้อน transaction ทั้งหมดให้แล้ว ยังไม่มีอะไรถูก commit
     * ลองใหม่ไม่ได้ถ้าเรียกซ้อนอยู่ใน transaction ของคนอื่น (ชั้นนอกต้องเป็นคนตัดสินใจเอง)
     * ข้อผิดพลาดอื่นไม่ลองซ้ำ
     */
    public static function retryOnDeadlock(callable $work, int $maxTries = 3)
    {
        for ($try = 1; ; $try++) {
            try {
                return $work();
            } catch (\PDOException $e) {
                $isDeadlock = (string) $e->getCode() === '40001' || (int) ($e->errorInfo[1] ?? 0) === 1213;
                if (!$isDeadlock || $try >= $maxTries || self::$depth > 0) {
                    throw $e;
                }
                usleep(random_int(10000, 80000));
            }
        }
    }

    /** เริ่ม transaction — เรียกซ้อนได้ (ชั้นในใช้ SAVEPOINT) เพื่อให้เทสต์ครอบด้วย transaction แล้ว rollback ได้ */
    public static function begin(): void
    {
        if (self::$depth === 0) {
            self::pdo()->beginTransaction();
        } else {
            self::pdo()->exec('SAVEPOINT sp' . self::$depth);
        }
        self::$depth++;
    }

    public static function commit(): void
    {
        if (self::$depth === 0) {
            throw new \LogicException('commit โดยไม่มี transaction');
        }
        self::$depth--;
        if (self::$depth === 0) {
            self::pdo()->commit();
        } else {
            self::pdo()->exec('RELEASE SAVEPOINT sp' . self::$depth);
        }
    }

    /** ย้อนกลับชั้นล่าสุด (ไม่มี transaction อยู่ = ไม่ทำอะไร) */
    public static function rollBack(): void
    {
        if (self::$depth === 0) {
            return;
        }
        self::$depth--;
        if (self::$depth === 0) {
            if (self::pdo()->inTransaction()) {
                self::pdo()->rollBack();
            }
            return;
        }
        self::pdo()->exec('ROLLBACK TO SAVEPOINT sp' . self::$depth);
        self::pdo()->exec('RELEASE SAVEPOINT sp' . self::$depth);
    }
}
