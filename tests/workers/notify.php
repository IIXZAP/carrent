<?php
/**
 * ตัวช่วยของ tests/NotificationRaceTest.php
 *   php tests/workers/notify.php flush <startAtEpochFloat>
 *   php tests/workers/notify.php queue <startAtEpochFloat> <dedupeKey>
 */
require __DIR__ . '/../../app/bootstrap.php';

use App\Models\Notification;
use App\Services\NotificationService;

[$script, $mode, $startAt] = $argv;

App\Models\Setting::all();
App\Core\Database::pdo();
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    if ($mode === 'flush') {
        $r = NotificationService::flush(50, 20.0);
        echo 'SENT ' . $r['sent'] . "\n";
    } else {
        echo 'QUEUED ' . Notification::queue('line', 'U0123456789abcdef0123456789abcdef', 'race', null, null, $argv[3]) . "\n";
    }
} catch (\Throwable $e) {
    echo 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
