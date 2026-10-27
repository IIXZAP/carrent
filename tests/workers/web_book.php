<?php
/**
 * ตัวช่วยของ tests/BookingDoubleSubmitTest.php — ส่งฟอร์มจองหน้าเว็บ 1 ครั้ง (เหมือนกดปุ่ม) แล้วพิมพ์ผลบรรทัดเดียว
 *   php tests/workers/web_book.php <carId> <startAtEpochFloat> <phone>
 */
require __DIR__ . '/../../app/bootstrap.php';

use App\Services\WebBookingService;

[$script, $carId, $startAt, $phone] = $argv;

App\Models\Setting::all();
App\Core\Database::pdo();
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$result = WebBookingService::create([
    'car_id' => $carId, 'pickup' => '2031-08-10T10:00', 'return' => '2031-08-13T10:00', 'addons' => [], 'km' => '',
    'name' => 'ลูกค้ากดซ้ำ', 'phone' => $phone, 'email' => '', 'line' => '',
    'pick_type' => 'shop', 'ret_type' => 'shop', 'place_pick' => '', 'place_ret' => '', 'note' => '', 'accept' => true,
], '192.0.2.99');

echo $result['booking'] !== null ? 'OK ' . $result['booking']['booking_no'] : 'ERR ' . implode(' / ', $result['errors']);
echo "\n";
