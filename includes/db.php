<?php
/**
 * เชื่อมต่อฐานข้อมูลด้วย PDO (prepared statement เท่านั้น — ไม่มี SQL string concat)
 */
require_once __DIR__ . '/../config/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
    } catch (PDOException $e) {
        if (defined('DB_THROW')) {
            throw $e;   // health.php ต้องการตอบ FAIL เอง ไม่ใช่หน้าข้อความ
        }
        if (APP_DEBUG) {
            die('DB Error: ' . $e->getMessage());
        }
        http_response_code(500);
        die('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาติดต่อผู้ดูแลระบบ');
    }
    // ให้ NOW() / CURRENT_TIMESTAMP ของฐานข้อมูลเป็นเวลาเดียวกับ PHP (เวลาไทย)
    // โฮสต์บางเจ้าตั้ง MySQL เป็น UTC: ถ้าไม่ตั้งตรงนี้ การเทียบ "รถเลยกำหนดคืน" การเตือนลูกค้า
    // และการล็อกบัญชีเมื่อใส่รหัสผิด จะคลาดไป 7 ชั่วโมง
    try {
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    } catch (Throwable $e) {
        // ฐานข้อมูลไม่ยอมให้ตั้ง — ใช้เวลาของเซิร์ฟเวอร์ตามเดิม
    }
    return $pdo;
}

/** คืนแถวเดียว */
function db_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** คืนหลายแถว */
function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** คืนค่าเดียว (คอลัมน์แรก) */
function db_val(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

/** รัน INSERT/UPDATE/DELETE คืนจำนวนแถวที่กระทบ */
function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** INSERT แล้วคืน id ใหม่ */
function db_insert(string $table, array $data): int
{
    $cols  = array_keys($data);
    $holds = array_map(fn($c) => ':' . $c, $cols);
    $sql   = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        '`' . implode('`,`', $cols) . '`',
        implode(',', $holds)
    );
    $st = db()->prepare($sql);
    foreach ($data as $k => $v) {
        $st->bindValue(':' . $k, $v);
    }
    $st->execute();
    return (int) db()->lastInsertId();
}

/** UPDATE ตาม id */
function db_update(string $table, array $data, string $whereCol, $whereVal): int
{
    $sets = [];
    foreach (array_keys($data) as $c) {
        $sets[] = "`$c` = :$c";
    }
    $sql = sprintf('UPDATE `%s` SET %s WHERE `%s` = :__w', $table, implode(', ', $sets), $whereCol);
    $st  = db()->prepare($sql);
    foreach ($data as $k => $v) {
        $st->bindValue(':' . $k, $v);
    }
    $st->bindValue(':__w', $whereVal);
    $st->execute();
    return $st->rowCount();
}
