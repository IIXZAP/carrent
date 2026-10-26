<?php
use App\Core\Database;

/**
 * จองพร้อมกันด้วย process จริงหลายตัว (ไม่ใช่เทสต์ใน transaction เดียว — worker commit จริง แล้วเทสต์ลบข้อมูลทิ้งเอง)
 * พิสูจน์ว่า "ล็อกแถวรถก่อนตรวจรถว่าง" กันจองซ้อนได้จริง
 */

const T_RACE_WORKERS = 8;
const T_RACE_PHONE_PREFIX = '0877770';

/** รัน worker พร้อมกันทั้งหมด คืนผลบรรทัดเดียวต่อ worker */
function t_race_run(array $carIds, string $pickup, string $return): array
{
    $startAt = microtime(true) + 1.5;
    $procs = [];
    foreach ($carIds as $i => $carId) {
        $phone = T_RACE_PHONE_PREFIX . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $cmd = [PHP_BINARY, __DIR__ . '/workers/book.php', (string) $carId, sprintf('%.4f', $startAt), $phone, $pickup, $return];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        assert_true(is_resource($proc), 'เปิด worker ไม่ได้');
        $procs[] = [$proc, $pipes];
    }
    $out = [];
    foreach ($procs as [$proc, $pipes]) {
        $out[] = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    return $out;
}

function t_race_cleanup(array $carIds): void
{
    if ($carIds !== []) {
        $in = implode(',', array_map('intval', $carIds));
        Database::run("DELETE FROM bookings WHERE car_id IN ($in)");
        Database::run("DELETE FROM cars WHERE id IN ($in)");
    }
    Database::run('DELETE FROM customers WHERE phone LIKE :p', ['p' => T_RACE_PHONE_PREFIX . '%']);
}

function test_race_same_car_only_one_booking(): void
{
    $car = t_make_car();
    try {
        $results = t_race_run(array_fill(0, T_RACE_WORKERS, $car), '2031-03-10 10:00:00', '2031-03-12 10:00:00');

        $ok = array_filter($results, static fn(string $r): bool => strncmp($r, 'OK ', 3) === 0);
        $refused = array_filter($results, static fn(string $r): bool => strncmp($r, 'REFUSED ', 8) === 0);
        assert_same(1, count($ok), "ต้องจองสำเร็จ 1 ใบเท่านั้น ได้จริง: " . json_encode($results, JSON_UNESCAPED_UNICODE));
        assert_same(T_RACE_WORKERS - 1, count($refused), 'ที่เหลือถูกปฏิเสธด้วยข้อความที่อ่านได้ (ไม่ใช่ deadlock/error อื่น): ' . json_encode($results, JSON_UNESCAPED_UNICODE));

        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM bookings WHERE car_id = :c', ['c' => $car]), 'ใน DB มี 1 ใบ');
        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM customers WHERE phone LIKE :p', ['p' => T_RACE_PHONE_PREFIX . '%']), 'ลูกค้าของคำขอที่ถูกปฏิเสธไม่ถูกสร้างค้าง');
    } finally {
        t_race_cleanup([$car]);
    }
}

function test_race_different_cars_all_succeed_with_unique_numbers(): void
{
    $cars = [];
    for ($i = 0; $i < T_RACE_WORKERS; $i++) {
        $cars[] = t_make_car();
    }
    try {
        $results = t_race_run($cars, '2031-04-10 10:00:00', '2031-04-12 10:00:00');
        $ok = array_filter($results, static fn(string $r): bool => strncmp($r, 'OK ', 3) === 0);
        assert_same(T_RACE_WORKERS, count($ok), 'ทุกคันจองสำเร็จ (ไม่ deadlock): ' . json_encode($results, JSON_UNESCAPED_UNICODE));
        $numbers = array_map(static fn(string $r): string => substr($r, 3), array_values($ok));
        assert_same(T_RACE_WORKERS, count(array_unique($numbers)), 'เลขที่จองไม่ซ้ำ: ' . implode(',', $numbers));
    } finally {
        t_race_cleanup($cars);
    }
}
