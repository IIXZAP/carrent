<?php
/**
 * สร้าง rental_old.json จาก PricingService "ตัวเดิม" ของระบบเก่า (อยู่ใน branch main)
 * ใช้เป็นค่าที่ถูกต้องให้เทสต์เทียบกับตัวใหม่ — รันใหม่เฉพาะเมื่อกฎราคาตั้งใจเปลี่ยน:
 *
 *   git show main:app/Services/PricingService.php > /tmp/OldPricingService.php
 *   php tests/golden/generate_from_old.php /tmp/OldPricingService.php > tests/golden/rental_old.json
 */
$old = $argv[1] ?? '';
if ($old === '' || !is_file($old)) {
    fwrite(STDERR, "วิธีใช้: php tests/golden/generate_from_old.php <ไฟล์ PricingService ของระบบเดิม>\n");
    exit(1);
}
require $old;

use App\Services\PricingService;

date_default_timezone_set('Asia/Bangkok');

// [price_day, price_week, price_month] — 0 = ไม่ได้ตั้ง (ระบบเดิมใช้ 0 แทน NULL)
$cars = [[1000, 0, 0], [1000, 6000, 0], [1000, 6000, 20000], [1500, 8000, 25000], [800, 5000, 18000], [1200, 9000, 0], [999.5, 0, 25000]];

$rental = [];
foreach ($cars as [$day, $week, $month]) {
    for ($days = 1; $days <= 100; $days++) {
        $r = PricingService::calcRental(['price_day' => $day, 'price_week' => $week, 'price_month' => $month], $days);
        $rental[] = [
            'car' => [$day, $week, $month], 'days' => $days, 'total' => $r['total'],
            'lines' => array_map(fn($l) => [$l['qty'], $l['unit'], $l['amount']], $r['lines']),
        ];
    }
}

// จำนวนวันจากชั่วโมงที่เช่าจริง (เริ่ม 2030-01-10 10:00)
$days = [];
$start = '2030-01-10 10:00:00';
foreach ([0.5, 1, 2, 2.5, 3, 12, 23, 24, 25, 26, 26.5, 27, 48, 49, 50, 50.5, 72, 74, 74.5, 168, 170, 171, 720, 722, 723, 2000] as $h) {
    $end = date('Y-m-d H:i:s', strtotime($start) + (int) round($h * 3600));
    $days[] = ['hours' => $h, 'pickup' => $start, 'return' => $end, 'days' => PricingService::rentalDays($start, $end)];
}

echo json_encode(['rental' => $rental, 'days' => $days], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
