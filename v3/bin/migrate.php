<?php
/**
 * รัน migration ที่ยังไม่เคยรัน: php bin/migrate.php
 * ไฟล์อยู่ใน database/migrations/*.sql เรียงตามชื่อ (001_..., 002_...)
 * ห้ามแก้ไฟล์ที่รันไปแล้ว — ถ้าจะเปลี่ยน schema ให้เพิ่มไฟล์ใหม่
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

Database::run(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        name VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$done = array_column(Database::all('SELECT name FROM schema_migrations'), 'name');
$files = glob(APP_ROOT . '/database/migrations/*.sql');
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $done, true)) {
        continue;
    }
    echo "รัน $name ... ";
    foreach (split_sql((string) file_get_contents($file)) as $statement) {
        Database::pdo()->exec($statement);
    }
    Database::run('INSERT INTO schema_migrations (name) VALUES (:n)', ['n' => $name]);
    echo "เสร็จ\n";
    $ran++;
}
echo $ran === 0 ? "ไม่มี migration ใหม่\n" : "รันแล้ว $ran ไฟล์\n";

/** ตัดคอมเมนต์ -- แล้วแยกคำสั่งด้วย ; (ไฟล์ migration ห้ามมี ; หรือ -- ในข้อความ string) */
function split_sql(string $sql): array
{
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    $out = [];
    foreach (explode(';', $sql) as $part) {
        $part = trim($part);
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return $out;
}
