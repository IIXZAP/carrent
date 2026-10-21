<?php
/**
 * เชื่อมต่อฐานข้อมูลด้วย PDO
 * ตัวอย่างนี้ใช้ SQLite (เป็นไฟล์เดียว ไม่ต้องติดตั้ง MySQL) — โปรเจกต์จริงเปลี่ยนเป็น MySQL ได้โดยแก้ DSN
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('sqlite:' . __DIR__ . '/../storage/app.sqlite');
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);        // ผิดพลาดให้โยน Exception
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);   // ผลลัพธ์เป็น array ชื่อคอลัมน์
            self::createTables();
        }
        return self::$pdo;
    }

    /** ดึงหลายแถว */
    public static function all(string $sql, array $params = []): array
    {
        $statement = self::pdo()->prepare($sql);   // prepared statement: กัน SQL injection
        $statement->execute($params);
        return $statement->fetchAll();
    }

    /** ดึงแถวเดียว (ไม่พบ = null) */
    public static function one(string $sql, array $params = []): ?array
    {
        $rows = self::all($sql, $params);
        return $rows[0] ?? null;
    }

    /** สั่งคำสั่งที่ไม่ต้องการผลลัพธ์ เช่น INSERT / UPDATE */
    public static function run(string $sql, array $params = []): void
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
    }

    /** สร้างตาราง + ข้อมูลตัวอย่างครั้งแรกที่รัน */
    private static function createTables(): void
    {
        $pdo = self::$pdo;
        $pdo->exec('CREATE TABLE IF NOT EXISTS cars (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            brand     TEXT NOT NULL,
            model     TEXT NOT NULL,
            price_day INTEGER NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS bookings (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_no TEXT NOT NULL UNIQUE,
            car_id     INTEGER NOT NULL REFERENCES cars(id),
            name       TEXT NOT NULL,
            phone      TEXT NOT NULL,
            pickup_at  TEXT NOT NULL,
            return_at  TEXT NOT NULL,
            total      INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )');

        if ((int) $pdo->query('SELECT COUNT(*) FROM cars')->fetchColumn() === 0) {
            $pdo->exec("INSERT INTO cars (brand, model, price_day) VALUES
                ('Toyota', 'Yaris Ativ', 1200),
                ('Honda', 'City', 1300),
                ('Toyota', 'Fortuner', 2200)");
        }
    }
}
