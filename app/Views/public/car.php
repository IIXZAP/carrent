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
</div>

<div class="panel mt-6">
  <h2 class="font-semibold">คำนวณราคา</h2>
  <form method="get" action="<?= e(url('/cars/' . $car['id'])) ?>" class="mt-4 space-y-4">
    <div class="grid gap-4 sm:grid-cols-2">
      <div>
        <label class="label" for="pickup">วัน–เวลารับรถ</label>
        <input class="field" id="pickup" type="datetime-local" name="pickup" value="<?= e(input_datetime($pickup)) ?>" required>
      </div>
      <div>
        <label class="label" for="return">วัน–เวลาคืนรถ</label>
        <input class="field" id="return" type="datetime-local" name="return" value="<?= e(input_datetime($return)) ?>" required>
      </div>
    </div>

    <?php if ($catalog): ?>
    <fieldset>
      <legend class="label">บริการเสริม</legend>
      <div class="grid gap-2 sm:grid-cols-2">
        <?php foreach ($catalog as $code => $addon): ?>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="addons[<?= e($code) ?>]" value="1" <?= isset($selected[$code]) ? 'checked' : '' ?>>
            <?= e($addon['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="sm:w-1/2">
      <label class="label" for="km">ระยะทางส่ง–รับรถจากกรุงเทพ (กม.) <span class="text-slate-400">เฉพาะเมื่อเลือกส่ง/รับรถถึงที่</span></label>
      <input class="field" id="km" type="number" min="0" step="1" name="km" value="<?= e($km > 0 ? $km : '') ?>">
    </div>
    <?php endif; ?>

    <button class="btn btn-primary">คำนวณราคา</button>
  </form>

  <?php if ($error !== null): ?>
    <div class="alert alert-error mt-4"><?= e($error) ?></div>
  <?php endif; ?>
  <?php if ($quote !== null): ?>
    <?php require __DIR__ . '/../partials/quote_box.php'; ?>
    <a class="btn btn-primary mt-4" href="<?= e(url('/booking') . '?' . http_build_query([
        'car' => $car['id'], 'pickup' => input_datetime($pickup), 'return' => input_datetime($return),
        'addons' => $selected, 'km' => $km > 0 ? $km : '',
    ])) ?>">จองรถคันนี้</a>
  <?php endif; ?>
</div>

<?php if ($busy): ?>
<div class="panel mt-6">
  <h2 class="font-semibold">ช่วงที่รถไม่ว่าง (3 เดือนข้างหน้า)</h2>
  <ul class="mt-3 text-sm text-slate-600 space-y-1">
    <?php foreach ($busy as $b): ?>
      <li><?= e(date('d/m/Y H:i', strtotime($b['start_at']))) ?> – <?= e(date('d/m/Y H:i', strtotime($b['end_at']))) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
