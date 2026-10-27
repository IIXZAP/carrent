<?php
use App\Core\Database;
use App\Services\CarImageService;

function test_race_gallery_never_exceeds_the_limit_and_leaves_no_orphan_files(): void
{
    $car = t_make_car();
    $img = t_img_file('jpg');
    $filesBefore = count(glob(APP_ROOT . '/uploads/cars/*') ?: []);
    try {
        $startAt = microtime(true) + 1.5;
        $procs = [];
        for ($i = 0; $i < 12; $i++) {
            $proc = proc_open([PHP_BINARY, __DIR__ . '/workers/carimage.php', (string) $car, sprintf('%.4f', $startAt), $img['tmp_name']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $procs[] = [$proc, $pipes];
        }
        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $results[] = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
        $ok = count(array_filter($results, fn($r) => $r === 'OK'));
        assert_same(CarImageService::MAX_GALLERY, $ok, 'ต้องสำเร็จพอดีเท่าจำนวนสูงสุด: ' . implode(' / ', $results));
        assert_same(12 - $ok, count(array_filter($results, fn($r) => str_starts_with($r, 'REFUSED'))), implode(' / ', $results));
        assert_same(CarImageService::MAX_GALLERY, (int) Database::value('SELECT COUNT(*) FROM car_images WHERE car_id = :c', ['c' => $car]));
        $sorts = array_column(Database::all('SELECT sort_order FROM car_images WHERE car_id = :c ORDER BY sort_order', ['c' => $car]), 'sort_order');
        assert_same(count($sorts), count(array_unique($sorts)), 'ลำดับต้องไม่ซ้ำ');
        assert_same($filesBefore + $ok, count(glob(APP_ROOT . '/uploads/cars/*') ?: []), 'ไฟล์ของคำขอที่แพ้ต้องถูกลบ ไม่ค้างเป็นขยะ');
    } finally {
        foreach (Database::all('SELECT path FROM car_images WHERE car_id = :c', ['c' => $car]) as $row) {
            $full = CarImageService::absPath($row['path']);
            if ($full) {
                @unlink($full);
            }
        }
        Database::run('DELETE FROM cars WHERE id = :c', ['c' => $car]);
        @unlink($img['tmp_name']);
    }
}
