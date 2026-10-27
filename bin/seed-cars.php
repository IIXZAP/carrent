<?php
/**
 * ใส่รถตัวอย่าง 8 คัน (ชุดเดียวกับระบบเดิม พร้อมรูปปกที่มากับโปรเจกต์ใน uploads/cars/)
 *   php bin/seed-cars.php
 * ปลอดภัยที่จะรันซ้ำ: ข้ามทะเบียนที่มีอยู่แล้ว ไม่แก้/ลบอะไร — ใช้เริ่มต้นเว็บใหม่ แล้วแก้ราคา/ทะเบียนจริงที่ หลังบ้าน → รถ
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Models\Car;

$cars = [
    ['1กก 1001', 'Toyota', 'Yaris Ativ', 'ขาว', 'eco', 5, 'petrol', 1200, 7500, 26000, 3000, 'yaris-ativ'],
    ['1กก 1002', 'Honda', 'City', 'เทา', 'eco', 5, 'petrol', 1300, 8100, 28000, 3000, 'honda-city'],
    ['2ขข 2001', 'Toyota', 'Corolla Altis', 'ขาว', 'sedan', 5, 'petrol', 1500, 9400, 32000, 5000, 'corolla-altis'],
    ['2ขข 2002', 'Honda', 'Civic', 'เทา', 'sedan', 5, 'petrol', 1600, 10000, 34000, 5000, 'honda-civic'],
    ['3คค 3001', 'Toyota', 'Fortuner', 'ขาว', 'suv', 7, 'diesel', 2200, 13800, 47000, 5000, 'fortuner'],
    ['3คค 3002', 'Mitsubishi', 'Pajero Sport', 'ดำ', 'suv', 7, 'diesel', 2200, 13800, 47000, 5000, 'pajero-sport'],
    ['4งง 4001', 'Isuzu', 'D-Max Cab 4', 'เงิน', 'pickup', 5, 'diesel', 1800, 11300, 38000, 5000, 'dmax-cab4'],
    ['5จจ 5001', 'Toyota', 'Commuter', 'ขาว', 'van', 14, 'diesel', 2500, 15600, 53000, 5000, 'commuter'],
];

$added = 0;
foreach ($cars as $i => [$plate, $brand, $model, $color, $type, $seats, $fuel, $day, $week, $month, $security, $img]) {
    if (Car::plateExists($plate, 0)) {
        echo "ข้าม $plate (มีอยู่แล้ว)\n";
        continue;
    }
    $id = Car::create([
        'plate' => $plate, 'province' => 'กรุงเทพมหานคร', 'brand' => $brand, 'model' => $model, 'year' => 2023, 'color' => $color,
        'car_type_code' => $type, 'seats' => $seats, 'gear' => 'auto', 'fuel' => $fuel,
        'price_day' => $day, 'price_week' => $week, 'price_month' => $month,
        'security_deposit' => $security, 'booking_deposit' => 1000, 'status' => 'available',
    ]);
    $file = "cars/$img.webp";
    Database::run('UPDATE cars SET cover_image = :c, sort_order = :s WHERE id = :id', ['c' => is_file(APP_ROOT . '/uploads/' . $file) ? $file : null, 's' => $i + 1, 'id' => $id]);
    echo "เพิ่ม $brand $model ($plate)\n";
    $added++;
}
echo "เสร็จ — เพิ่ม $added คัน\n";
