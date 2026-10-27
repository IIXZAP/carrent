<?php
/**
 * งานอัตโนมัติ: php bin/cron.php
 * ตั้งใน cPanel → Cron Jobs ให้รันทุกชั่วโมง เช่น   0 * * * *  /usr/bin/php /home/USER/public_html/bin/cron.php
 * (ไม่ตั้ง = การจองที่ไม่จ่ายมัดจำจะไม่ถูกยกเลิกอัตโนมัติ)
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../app/bootstrap.php';

$lines = App\Services\CronService::run();
echo $lines === [] ? "ไม่มีงานที่ต้องทำ\n" : implode("\n", $lines) . "\n";
