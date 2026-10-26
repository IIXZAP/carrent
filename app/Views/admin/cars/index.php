<?php use App\Services\CarService; ?>
<div class="flex items-center justify-between">
  <h1 class="text-2xl font-bold">รถ</h1>
  <a class="btn btn-primary" href="<?= e(url('/admin/cars/create')) ?>">+ เพิ่มรถ</a>
</div>
<div class="panel mt-6 overflow-x-auto p-0">
  <table class="table">
    <thead><tr><th>ทะเบียน</th><th>รถ</th><th>ประเภท</th><th class="text-right">ราคา/วัน</th><th>สถานะ</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cars as $car): ?>
      <tr>
        <td class="font-medium"><?= e($car['plate']) ?></td>
        <td><?= e($car['brand'] . ' ' . $car['model']) ?></td>
        <td><?= e($car['type_label']) ?></td>
        <td class="text-right">฿<?= e(money($car['price_day'])) ?></td>
        <td><span class="badge badge-<?= e($car['status']) ?>"><?= e(CarService::STATUSES[$car['status']]) ?></span></td>
        <td class="text-right whitespace-nowrap">
          <a class="text-brand-700 hover:underline" href="<?= e(url('/admin/cars/' . $car['id'] . '/edit')) ?>">แก้ไข</a>
          <?php if (\App\Services\Auth::isAdmin()): ?>
          <form class="inline" method="post" action="<?= e(url('/admin/cars/' . $car['id'] . '/delete')) ?>"
                onsubmit="return confirm('ลบรถ <?= e($car['plate']) ?> ?')">
            <?= csrf_field() ?>
            <button class="text-red-600 hover:underline ml-3">ลบ</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$cars): ?><tr><td colspan="6" class="text-center text-slate-500 py-8">ยังไม่มีรถ</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
