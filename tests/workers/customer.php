<?php
/**
 * ตัวช่วยของ tests/CustomerRaceTest.php — process ลูกที่เพิ่มลูกค้า 1 ราย แล้วพิมพ์ผลบรรทัดเดียว
 *   php tests/workers/customer.php <startAtEpochFloat> <phone> <name>
 */
require __DIR__ . '/../../app/bootstrap.php';

use App\Services\CustomerService;
use App\Services\UserError;

[$script, $startAt, $phone, $name] = $argv;
App\Models\Setting::all();
App\Core\Database::pdo();
while (microtime(true) < (float) $startAt) {
    usleep(200);
}
try {
    [$data, $errors] = CustomerService::validate(['full_name' => $name, 'phone' => $phone, 'type' => 'individual']);
    if ($errors) {
        throw new UserError('invalid ' . json_encode($errors));
    }
    echo 'OK ' . CustomerService::create($data, null) . "\n";
} catch (UserError $e) {
    echo 'REFUSED ' . $e->getMessage() . "\n";
} catch (\Throwable $e) {
    echo 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
