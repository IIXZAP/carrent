<?php use App\Services\CarService; ?>
<h1 class="text-2xl font-bold">รถให้เช่า</h1>
<p class="text-slate-500 mt-1">เลือกวันรับ–คืนรถ เพื่อดูรถที่ว่างและราคา</p>

<div class="mt-4">
  <?php $action = '/'; require __DIR__ . '/../partials/search_form.php'; ?>
</div>

<?php if ($error !== null): ?>
  <div class="alert alert-error mt-4"><?= e($error) ?></div>
<?php elseif ($searching && !$cars): ?>
  <p class="mt-8 text-slate-500">ไม่มีรถว่างในช่วงเวลาที่เลือก ลองเปลี่ยนวันหรือประเภทรถ</p>
<?php elseif (!$searching && !$cars): ?>
  <p class="mt-8 text-slate-500">ยังไม่มีรถพร้อมให้เช่าในขณะนี้</p>
<?php endif; ?>

<?php
// ส่งวันที่ที่เลือกต่อไปหน้ารายละเอียดรถ
$carQuery = ($pickup !== null && $return !== null && $error === null)
    ? '?' . http_build_query(['pickup' => input_datetime($pickup), 'return' => input_datetime($return)])
    : '';
?>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 mt-6">
  <?php foreach ($cars as $car): ?>
    <a href="<?= e(url('/cars/' . $car['id']) . $carQuery) ?>" class="panel hover:border-brand-500 transition">
      <p class="eyebrow"><?= e($car['type_label']) ?></p>
      <h2 class="text-lg font-semibold mt-1"><?= e($car['brand'] . ' ' . $car['model']) ?></h2>
      <p class="text-sm text-slate-500 mt-1">
        <?= e($car['seats']) ?> ที่นั่ง · <?= e(CarService::GEARS[$car['gear']]) ?>
        · <?= e(CarService::FUELS[$car['fuel']]) ?>
      </p>
      <?php if (isset($car['_total'])): ?>
        <p class="mt-4"><span class="text-xl font-bold text-brand-700">฿<?= e(money($car['_total'])) ?></span>
          <span class="text-sm text-slate-500">รวม <?= e($car['_days']) ?> วัน (เฉลี่ย ฿<?= e(money($car['_avg_day'])) ?>/วัน)</span></p>
      <?php else: ?>
        <p class="mt-4"><span class="text-xl font-bold text-brand-700">฿<?= e(money($car['price_day'])) ?></span>
          <span class="text-sm text-slate-500">/ วัน</span></p>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
