<h1 class="text-2xl font-bold">ภาพรวม</h1>
<div class="grid gap-4 sm:grid-cols-3 mt-6">
  <div class="panel"><p class="eyebrow">พร้อมให้เช่า</p><p class="text-3xl font-bold mt-1"><?= e($counts['available']) ?></p></div>
  <div class="panel"><p class="eyebrow">ซ่อมบำรุง</p><p class="text-3xl font-bold mt-1"><?= e($counts['maintenance']) ?></p></div>
  <div class="panel"><p class="eyebrow">ปิดใช้งาน</p><p class="text-3xl font-bold mt-1"><?= e($counts['inactive']) ?></p></div>
</div>
<h2 class="text-lg font-semibold mt-8">การจอง</h2>
<div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6 mt-3">
  <?php foreach (\App\Services\BookingStatus::STATUS as $code => $label): ?>
    <a href="<?= e(url('/admin/bookings?status=' . $code)) ?>" class="panel hover:border-brand-500">
      <p class="eyebrow"><?= e($label) ?></p><p class="text-2xl font-bold mt-1"><?= e($bookingCounts[$code] ?? 0) ?></p>
    </a>
  <?php endforeach; ?>
</div>
<div class="flex gap-3 mt-6">
  <a class="btn btn-primary" href="<?= e(url('/admin/bookings/create')) ?>">+ สร้างการจอง</a>
  <a class="btn" href="<?= e(url('/admin/cars')) ?>">จัดการรถ</a>
</div>
