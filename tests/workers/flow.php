<?php
/**
 * ตัวช่วยของ tests/FlowRaceTest.php — process ลูกที่ทำขั้นตอนหลังบ้าน 1 ครั้ง แล้วพิมพ์ผลบรรทัดเดียว
 *   php tests/workers/flow.php <pickup|return|settle|complete> <bookingId> <startAtEpochFloat> <userId> [nowEpoch]
 */
require __DIR__ . '/../../app/bootstrap.php';

use App\Services\BookingFlowService;
use App\Services\SecurityDepositService;
use App\Services\UserError;

[$script, $action, $bookingId, $startAt, $userId] = $argv;
$now = isset($argv[5]) ? (int) $argv[5] : null;

App\Models\Setting::all();
App\Core\Database::pdo();
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    switch ($action) {
        case 'pickup':
            BookingFlowService::pickup((int) $bookingId, 10000, 8, (int) $userId, '', $now);
            break;
        case 'return':
            BookingFlowService::returnCar((int) $bookingId, ['mileage_in' => '10200', 'fuel_in' => '5', 'returned_at' => '', 'other_label' => 'ค่าทำความสะอาด', 'other_amount' => '300'], (int) $userId, '', $now);
            break;
        case 'settle':
            SecurityDepositService::settle((int) $bookingId, ['deduct' => '', 'refund_method' => 'cash'], (int) $userId);
            break;
        case 'complete':
            BookingFlowService::complete((int) $bookingId, (int) $userId);
            break;
        default:
            throw new InvalidArgumentException('action?');
    }
    echo "OK\n";
} catch (UserError $e) {
    echo 'REFUSED ' . $e->getMessage() . "\n";
} catch (\Throwable $e) {
    echo 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
