<h1 class="text-2xl font-bold">ภาพรวม</h1>
<div class="grid gap-4 sm:grid-cols-3 mt-6">
  <div class="panel"><p class="eyebrow">พร้อมให้เช่า</p><p class="text-3xl font-bold mt-1"><?= e($counts['available']) ?></p></div>
  <div class="panel"><p class="eyebrow">ซ่อมบำรุง</p><p class="text-3xl font-bold mt-1"><?= e($counts['maintenance']) ?></p></div>
  <div class="panel"><p class="eyebrow">ปิดใช้งาน</p><p class="text-3xl font-bold mt-1"><?= e($counts['inactive']) ?></p></div>
</div>
<a class="btn btn-primary mt-6" href="<?= e(url('/admin/cars')) ?>">จัดการรถ</a>
