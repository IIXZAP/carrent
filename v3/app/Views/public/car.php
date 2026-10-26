<?php use App\Services\CarService; ?>
<a href="<?= e(url('/')) ?>" class="text-sm text-slate-500 hover:text-slate-800">← รถทั้งหมด</a>
<div class="panel mt-4">
  <p class="eyebrow"><?= e($car['type_label']) ?></p>
  <h1 class="text-2xl font-bold mt-1"><?= e($car['brand'] . ' ' . $car['model']) ?></h1>
  <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6 text-sm">
    <div><dt class="text-slate-500">ที่นั่ง</dt><dd class="font-medium"><?= e($car['seats']) ?></dd></div>
    <div><dt class="text-slate-500">เกียร์</dt><dd class="font-medium"><?= e(CarService::GEARS[$car['gear']]) ?></dd></div>
    <div><dt class="text-slate-500">เชื้อเพลิง</dt><dd class="font-medium"><?= e(CarService::FUELS[$car['fuel']]) ?></dd></div>
    <div><dt class="text-slate-500">ปี / สี</dt><dd class="font-medium"><?= e(trim(($car['year'] ?? '') . ' ' . ($car['color'] ?? '')) ?: '-') ?></dd></div>
  </dl>
  <?php if (!empty($car['description'])): ?>
    <p class="mt-6 whitespace-pre-line"><?= e($car['description']) ?></p>
  <?php endif; ?>
  <table class="table mt-6">
    <tr><td>ราคาต่อวัน</td><td class="text-right font-semibold">฿<?= e(money($car['price_day'])) ?></td></tr>
    <?php if ($car['price_week'] !== null): ?><tr><td>ราคาต่อสัปดาห์</td><td class="text-right">฿<?= e(money($car['price_week'])) ?></td></tr><?php endif; ?>
    <?php if ($car['price_month'] !== null): ?><tr><td>ราคาต่อเดือน</td><td class="text-right">฿<?= e(money($car['price_month'])) ?></td></tr><?php endif; ?>
    <tr><td>เงินประกัน (คืนเมื่อคืนรถ)</td><td class="text-right">฿<?= e(money($car['security_deposit'])) ?></td></tr>
  </table>
  <p class="mt-6 text-sm text-slate-500">ระบบจองออนไลน์จะเปิดในขั้นถัดไป</p>
</div>
