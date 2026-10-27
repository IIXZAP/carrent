<?php
/**
 * ตัวช่วยของ tests/PaymentRaceTest.php — process ลูกที่พยายามอนุมัติสลิป แล้วพิมพ์ผลบรรทัดเดียว
 *   php tests/workers/approve.php <paymentId> <startAtEpochFloat> <userId>
 */
require __DIR__ . '/../../app/bootstrap.php';

use App\Services\PaymentService;

[$script, $paymentId, $startAt, $userId] = $argv;

App\Core\Database::pdo();
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    echo PaymentService::approve((int) $paymentId, (int) $userId) ? "APPROVED\n" : "SKIPPED\n";
} catch (\Throwable $e) {
    echo 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
