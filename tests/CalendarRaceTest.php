<?php
use App\Core\Database;

/** ปิดรถกับจองรถช่วงเดียวกันพร้อมกัน: ต้องไม่มีทั้งสองอย่างทับกันใน DB */

function t_calendar_race_run(array $jobs): array
{
    $startAt = microtime(true) + 1.5;
    $procs = [];
    foreach ($jobs as $cmdArgs) {
        [$script, $args] = $cmdArgs;
        $cmd = array_merge([PHP_BINARY, __DIR__ . '/workers/' . $script, ...[]], array_map(static fn($a) => $a === '@start' ? sprintf('%.4f', $startAt) : (string) $a, $args));
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

function test_race_block_vs_booking_never_overlap(): void
{
    t_booking_settings_committed();
    // หลายรอบ เพราะผลแพ้ชนะขึ้นกับจังหวะ — ทุกรอบต้องไม่เกิดการทับซ้อน
    for ($round = 0; $round < 3; $round++) {
        $car = t_make_car();
        $from = '2031-0' . (4 + $round) . '-10 10:00:00';
        $to = '2031-0' . (4 + $round) . '-12 10:00:00';
        try {
            $jobs = [];
            for ($i = 0; $i < 4; $i++) {
                $jobs[] = ['book.php', [$car, '@start', '08777' . $round . '000' . $i, $from, $to]];
            }
            for ($i = 0; $i < 3; $i++) {
                $jobs[] = ['block.php', [$car, '@start', $from, $to]];
            }
            $results = t_calendar_race_run($jobs);
            $errors = array_filter($results, static fn($r) => str_starts_with($r, 'ERROR'));
            assert_same([], array_values($errors), 'ต้องไม่มี error/deadlock: ' . json_encode($results, JSON_UNESCAPED_UNICODE));
            $bookings = (int) Database::value("SELECT COUNT(*) FROM bookings WHERE car_id = :c AND status IN ('pending','confirmed')", ['c' => $car]);
            $blocks = (int) Database::value('SELECT COUNT(*) FROM car_blocks WHERE car_id = :c', ['c' => $car]);
            assert_true($bookings === 0 || $blocks === 0, "จองได้ $bookings ใบ และปิดรถได้ $blocks ช่วง — ต้องไม่ทับกัน: " . json_encode($results, JSON_UNESCAPED_UNICODE));
            assert_true($bookings <= 1, 'จองได้ไม่เกิน 1 ใบ');
            assert_true($bookings + $blocks >= 1, 'ต้องมีผู้ชนะอย่างน้อยหนึ่งราย');
        } finally {
            Database::run('DELETE FROM car_blocks WHERE car_id = :c', ['c' => $car]);
            Database::run('DELETE FROM bookings WHERE car_id = :c', ['c' => $car]);
            Database::run('DELETE FROM cars WHERE id = :c', ['c' => $car]);
            Database::run("DELETE FROM customers WHERE phone LIKE '08777_000_'");
        }
    }
}
