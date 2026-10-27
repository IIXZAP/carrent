<?php
/**
 * ตัวช่วยของ tests/CarImageRaceTest.php — process ลูกที่เพิ่มรูปแกลเลอรี 1 รูป แล้วพิมพ์ผลบรรทัดเดียว
 *   php tests/workers/carimage.php <carId> <startAtEpochFloat> <imagePath>
 */
require __DIR__ . '/../../app/bootstrap.php';

use App\Services\CarImageService;
use App\Services\UserError;

[$script, $carId, $startAt, $path] = $argv;
$tmp = tempnam(sys_get_temp_dir(), 'car');
copy($path, $tmp);
App\Core\Database::pdo();
while (microtime(true) < (float) $startAt) {
    usleep(200);
}
try {
    CarImageService::addGallery((int) $carId, ['name' => 'a.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], false);
    echo "OK\n";
} catch (UserError $e) {
    echo 'REFUSED ' . $e->getMessage() . "\n";
} catch (\Throwable $e) {
    echo 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
@unlink($tmp);
