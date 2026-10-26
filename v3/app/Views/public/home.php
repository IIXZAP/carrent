<h1 class="text-2xl font-bold">รถให้เช่า</h1>
<p class="text-slate-500 mt-1">เลือกรถที่ต้องการ แล้วดูรายละเอียดและราคา</p>

<?php if (!$cars): ?>
  <p class="mt-8 text-slate-500">ยังไม่มีรถพร้อมให้เช่าในขณะนี้</p>
<?php endif; ?>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 mt-6">
  <?php foreach ($cars as $car): ?>
    <a href="<?= e(url('/cars/' . $car['id'])) ?>" class="panel hover:border-brand-500 transition">
      <p class="eyebrow"><?= e($car['type_label']) ?></p>
      <h2 class="text-lg font-semibold mt-1"><?= e($car['brand'] . ' ' . $car['model']) ?></h2>
      <p class="text-sm text-slate-500 mt-1">
        <?= e($car['seats']) ?> ที่นั่ง · <?= e(\App\Services\CarService::GEARS[$car['gear']]) ?>
        · <?= e(\App\Services\CarService::FUELS[$car['fuel']]) ?>
      </p>
      <p class="mt-4"><span class="text-xl font-bold text-brand-700">฿<?= e(money($car['price_day'])) ?></span>
        <span class="text-sm text-slate-500">/ วัน</span></p>
    </a>
  <?php endforeach; ?>
</div>
