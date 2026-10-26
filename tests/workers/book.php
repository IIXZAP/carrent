<?php
/**
 * ตัวช่วยของ tests/RaceTest.php — process ลูกที่พยายามจองรถ แล้วพิมพ์ผลบรรทัดเดียว
 *   php tests/workers/book.php <carId> <startAtEpochFloat> <phone> <pickup> <return>
 * ทุก worker รอจนถึงเวลาเริ่มเดียวกันแล้วยิงพร้อมกัน
 */
require __DIR__ . '/../../app/bootstrap.php';

use App\Models\Booking;
use App\Services\BookingService;
use App\Services\UserError;

[$script, $carId, $startAt, $phone, $pickup, $return] = $argv;

// อุ่น cache ของตั้งค่าก่อนถึงเวลาเริ่ม (อ่านนอก transaction) แล้วรอให้ถึงเวลาเดียวกัน
App\Models\Setting::all();
App\Models\Addon::activeCatalog();
App\Core\Database::pdo();
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $id = BookingService::createBooking((int) $carId, $pickup, $return, ['name' => 'worker ' . $phone, 'phone' => $phone, 'email' => '', 'line' => ''], ['source' => 'web']);
    echo 'OK ' . Booking::find($id)['booking_no'] . "\n";
} catch (UserError $e) {
    echo 'REFUSED ' . $e->getMessage() . "\n";
} catch (\Throwable $e) {
    echo 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
