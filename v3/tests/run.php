<?php
/**
 * ตัวรันเทสต์แบบง่าย (ไม่ใช้ Composer):  php tests/run.php [คำค้นชื่อเทสต์]
 *
 * - ไฟล์ tests/*Test.php ประกาศฟังก์ชัน test_xxx() ; ใช้ assert_same / assert_true ในฟังก์ชัน
 * - ฟังก์ชันชื่อ test_db_xxx() ต้องใช้ฐานข้อมูล: รันภายใน transaction แล้ว rollback ให้เสมอ
 *   ตั้งค่าฐานทดสอบด้วย env  DB_NAME=carrent_v3_test DB_USER=... DB_PASS=...  (และรัน php bin/migrate.php ก่อน)
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

function assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\n      คาดหวัง: %s\n      ได้จริง: %s",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_true($condition, string $message = ''): void
{
    if ($condition !== true) {
        throw new RuntimeException($message !== '' ? $message : 'เงื่อนไขไม่เป็นจริง');
    }
}

function assert_money($expected, $actual, string $message = ''): void
{
    if (abs((float) $expected - (float) $actual) > 0.0001) {
        throw new RuntimeException(sprintf("%s\n      คาดหวัง: %s\n      ได้จริง: %s", $message, $expected, $actual));
    }
}

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$tests = [];
foreach (get_defined_functions()['user'] as $fn) {
    if (strncmp($fn, 'test_', 5) === 0 && ($filter === '' || strpos($fn, $filter) !== false)) {
        $tests[] = $fn;
    }
}
sort($tests);

// ต่อฐานข้อมูลไม่ได้ → ข้ามเทสต์ test_db_* (บอกชัดเจน ไม่นับว่าผ่าน)
$dbReady = true;
try {
    Database::pdo();
} catch (Throwable $e) {
    $dbReady = false;
    echo "  ข้ามเทสต์ที่ใช้ฐานข้อมูล: ต่อ DB ไม่ได้ (" . $e->getMessage() . ")\n";
}

$passed = 0;
$failed = 0;
$skipped = 0;
foreach ($tests as $fn) {
    $needsDb = strncmp($fn, 'test_db_', 8) === 0;
    if ($needsDb && !$dbReady) {
        $skipped++;
        echo "  skip $fn\n";
        continue;
    }
    try {
        if ($needsDb) {
            Database::begin();
        }
        $fn();
        $passed++;
        echo "  ok   $fn\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL $fn\n      " . $e->getMessage() . "\n";
    } finally {
        if ($needsDb) {
            Database::rollBack();
        }
    }
}

echo "\n$passed ผ่าน, $failed ไม่ผ่าน, $skipped ข้าม\n";
exit($failed === 0 && $skipped === 0 ? 0 : 1);
