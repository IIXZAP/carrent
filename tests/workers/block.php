<?php
/**
 * ตัวช่วยของ tests/CalendarRaceTest.php — process ลูกที่พยายามปิดรถ แล้วพิมพ์ผลบรรทัดเดียว
 *   php tests/workers/block.php <carId> <startAtEpochFloat> <from> <to>
 */
require __DIR__ . '/../../app/bootstrap.php';

use App\Services\CarBlockService;
use App\Services\UserError;

[$script, $carId, $startAt, $from, $to] = $argv;

App\Models\Setting::all();
App\Core\Database::pdo();
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    CarBlockService::create((int) $carId, $from, $to, 'worker', null);
    echo "OK\n";
} catch (UserError $e) {
    echo 'REFUSED ' . $e->getMessage() . "\n";
} catch (\Throwable $e) {
    echo 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
