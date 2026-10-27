<?php
/**
 * สร้าง late_old.json จาก LateReturnService "ตัวเดิม" ของระบบเก่า (อยู่ใน branch main / ประวัติ git)
 * ใช้เป็นค่าที่ถูกต้องให้เทสต์เทียบกับตัวใหม่ — รันใหม่เฉพาะเมื่อตั้งใจเปลี่ยนกฎค่าปรับ:
 *
 *   git show 79b85ac:app/Services/PricingService.php    > /path/old/PricingService.php
 *   git show 79b85ac:app/Services/LateReturnService.php > /path/old/LateReturnService.php
 *   php tests/golden/generate_late_from_old.php /path/old > tests/golden/late_old.json
 *
 * (79b85ac = commit สุดท้ายของระบบเดิมก่อนเริ่มเขียนใหม่)
 */
$dir = $argv[1] ?? '';
if ($dir === '' || !is_file("$dir/LateReturnService.php") || !is_file("$dir/PricingService.php")) {
    fwrite(STDERR, "วิธีใช้: php tests/golden/generate_late_from_old.php <โฟลเดอร์ที่มี PricingService.php และ LateReturnService.php ของระบบเดิม>\n");
    exit(1);
}

date_default_timezone_set('Asia/Bangkok');

/** ค่าตั้งที่ระบบเดิมอ่านผ่าน setting() — เปลี่ยนต่อเคส */
$GLOBALS['settings'] = [];
function setting(string $key, $default = '')
{
    return $GLOBALS['settings'][$key] ?? $default;
}
function money0($n): string
{
    return number_format((float) $n, 0);
}

require "$dir/PricingService.php";
require "$dir/LateReturnService.php";

use App\Services\LateReturnService;

$returnAt = '2030-03-10 10:00:00';
$lateMinutes = [-30, 0, 60, 119, 120, 121, 150, 180, 240, 300, 359, 360, 361, 400, 479, 480, 481, 600, 1380, 1440, 1441, 1500, 1559, 1560, 1561, 1800, 2880, 2890, 4300, 5000];
$policies = [
    ['late_grace_hours' => 2, 'late_full_day_hours' => 6, 'late_fee_per_hour' => 200],
    ['late_grace_hours' => 0, 'late_full_day_hours' => 4, 'late_fee_per_hour' => 150],
    ['late_grace_hours' => 3, 'late_full_day_hours' => 8, 'late_fee_per_hour' => 0],
    ['late_grace_hours' => 1, 'late_full_day_hours' => 1, 'late_fee_per_hour' => 500],
];
$prices = [800.0, 1000.0, 2500.0];
$fuels = [[8, 8], [8, 5], [4, 6], [8, 0], [null, 3], [8, null], [6, 6]];
$fuelFees = [300, 0, 150];
$mileages = [[10000, 10300, 2, 100, 4], [10000, 10150, 2, 100, 4], [10000, null, 2, 100, 4], [0, 500, 2, 100, 4], [10000, 10300, 2, 0, 4], [10000, 10900, 3, 100, 0], [10000, 10000, 1, 50, 4]];

$cases = [];
foreach ($policies as $policy) {
    foreach ($prices as $price) {
        foreach ($lateMinutes as $minutes) {
            $GLOBALS['settings'] = $policy + ['fuel_fee_per_level' => '300', 'free_km_per_day' => '0'];
            $returnedAt = date('Y-m-d H:i:s', strtotime($returnAt) + $minutes * 60);
            $booking = ['return_at' => $returnAt, 'fuel_out' => null, 'mileage_out' => null, 'rental_days' => 2];
            $cases[] = ['kind' => 'late', 'settings' => $GLOBALS['settings'], 'booking' => $booking, 'price_day' => $price, 'returned_at' => $returnedAt, 'mileage_in' => null, 'fuel_in' => null,
                        'result' => LateReturnService::calculate($booking, ['price_day' => $price], $returnedAt, null, null)];
        }
    }
}
foreach ($fuelFees as $fee) {
    foreach ($fuels as [$out, $in]) {
        $GLOBALS['settings'] = ['fuel_fee_per_level' => (string) $fee, 'late_grace_hours' => 2, 'late_full_day_hours' => 6, 'late_fee_per_hour' => 200, 'free_km_per_day' => '0'];
        $booking = ['return_at' => $returnAt, 'fuel_out' => $out, 'mileage_out' => null, 'rental_days' => 2];
        $cases[] = ['kind' => 'fuel', 'settings' => $GLOBALS['settings'], 'booking' => $booking, 'price_day' => 1000.0, 'returned_at' => $returnAt, 'mileage_in' => null, 'fuel_in' => $in,
                    'result' => LateReturnService::calculate($booking, ['price_day' => 1000.0], $returnAt, null, $in)];
    }
}
foreach ($mileages as [$out, $in, $days, $free, $fee]) {
    $GLOBALS['settings'] = ['fuel_fee_per_level' => '300', 'late_grace_hours' => 2, 'late_full_day_hours' => 6, 'late_fee_per_hour' => 200, 'free_km_per_day' => (string) $free, 'over_km_fee' => (string) $fee];
    $booking = ['return_at' => $returnAt, 'fuel_out' => null, 'mileage_out' => $out, 'rental_days' => $days];
    $cases[] = ['kind' => 'km', 'settings' => $GLOBALS['settings'], 'booking' => $booking, 'price_day' => 1000.0, 'returned_at' => $returnAt, 'mileage_in' => $in, 'fuel_in' => null,
                'result' => LateReturnService::calculate($booking, ['price_day' => 1000.0], $returnAt, $in, null)];
}
// ผสมทั้งสามอย่างในใบเดียว
$GLOBALS['settings'] = ['late_grace_hours' => 2, 'late_full_day_hours' => 6, 'late_fee_per_hour' => 200, 'fuel_fee_per_level' => '300', 'free_km_per_day' => '100', 'over_km_fee' => '4'];
$returnedAt = date('Y-m-d H:i:s', strtotime($returnAt) + 200 * 60);
$booking = ['return_at' => $returnAt, 'fuel_out' => 8, 'mileage_out' => 10000, 'rental_days' => 2];
$cases[] = ['kind' => 'mixed', 'settings' => $GLOBALS['settings'], 'booking' => $booking, 'price_day' => 1500.0, 'returned_at' => $returnedAt, 'mileage_in' => 10500, 'fuel_in' => 3,
            'result' => LateReturnService::calculate($booking, ['price_day' => 1500.0], $returnedAt, 10500, 3)];

echo json_encode($cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
