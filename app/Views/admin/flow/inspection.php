<?php
use App\Services\InspectionService;
/** ตรวจสภาพรถ: $booking $kind $ins $positions $progress $block */
$photosBy = [];
foreach ($ins['photos'] ?? [] as $p) { $photosBy[$p['position']][] = $p; }
$locked = $ins !== null && $ins['locked_at'] !== null;
?>
<p class="mb-3 text-sm"><a href="<?= e(url('/admin/bookings/' . $booking['id'])) ?>">← <?= e($booking['booking_no']) ?></a></p>

<div class="mt-3 flex flex-wrap gap-2 text-sm">
  <?php foreach (['out', 'in'] as $k): ?>
    <a href="<?= e(url('/admin/bookings/' . $booking['id'] . '/inspection/' . $k)) ?>" class="rounded-full border px-3 py-1 <?= $kind === $k ? 'bg-slate-900 text-white border-slate-900' : 'bg-white border-line hover:bg-surface' ?>"><?= e(InspectionService::kindLabel($k)) ?></a>
  <?php endforeach; ?>
</div>

<div class="panel mt-4 text-sm">
  ถ่ายแล้ว <strong><?= e($progress['done']) ?>/<?= e($progress['required']) ?></strong> มุม
  <?php if ($ins): ?>· ไมล์ <?= $ins['mileage'] !== null ? e(number_format((int) $ins['mileage'])) : '-' ?> · น้ำมัน <?= $ins['fuel_level'] !== null ? e($ins['fuel_level']) . '/8' : '-' ?><?php endif; ?>
  <?php if ($locked): ?><span class="badge badge-ok ml-2">ล็อกแล้ว <?= e(date('d/m/Y H:i', strtotime($ins['locked_at']))) ?><?= $ins['locker'] ? ' โดย ' . e($ins['locker']) : '' ?></span><?php endif; ?>
  <?php if ($block !== null && !$locked): ?><p class="mt-2 text-warn"><?= e($block) ?></p><?php endif; ?>
</div>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mt-4">
  <?php foreach ($positions as $code => [$label, $hint]): ?>
    <div class="panel p-3">
      <p class="font-medium text-sm"><?= e($label) ?></p>
      <p class="text-xs text-muted"><?= e($hint) ?></p>
      <?php foreach ($photosBy[$code] ?? [] as $p): ?>
        <a href="<?= e(url('/admin/inspection-photos/' . $p['id'])) ?>" target="_blank" rel="noopener">
          <img class="mt-2 rounded-lg w-full h-36 object-cover" loading="lazy" src="<?= e(url('/admin/inspection-photos/' . $p['id'])) ?>" alt="<?= e($label) ?>">
        </a>
        <p class="text-xs text-muted mt-1"><?= e(date('d/m H:i', strtotime($p['created_at']))) ?><?= $p['uploader'] ? ' · ' . e($p['uploader']) : '' ?><?= $p['caption'] ? ' · ' . e($p['caption']) : '' ?></p>
      <?php endforeach; ?>
      <?php if (empty($photosBy[$code])): ?><div class="mt-2 rounded-lg bg-surface h-20 flex items-center justify-center text-xs text-muted">ยังไม่มีรูป</div><?php endif; ?>
      <?php if ($block === null): ?>
        <form method="post" action="<?= e(url('/admin/bookings/' . $booking['id'] . '/inspection/' . $kind)) ?>" enctype="multipart/form-data" class="mt-2 space-y-1">
          <?= csrf_field() ?><input type="hidden" name="position" value="<?= e($code) ?>">
          <input class="text-xs" type="file" data-shrink name="photo" accept="image/jpeg,image/png,image/webp" capture="environment" required>
          <input class="text-xs" name="caption" maxlength="200" placeholder="คำอธิบาย (ไม่บังคับ)">
          <button class="btn btn-ghost !py-1 w-full text-xs"><?= !empty($photosBy[$code]) && $code !== 'damage' ? 'ถ่ายแทน' : 'เพิ่มรูป' ?></button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if (!empty($ins['history'])): ?>
<div class="panel mt-6">
  <h2 class="font-semibold">ประวัติรูปที่ถูกถ่ายแทน (เก็บไว้เป็นหลักฐาน ไม่ลบ)</h2>
  <div class="grid gap-3 sm:grid-cols-4 mt-3">
    <?php foreach ($ins['history'] as $p): ?>
      <a href="<?= e(url('/admin/inspection-photos/' . $p['id'])) ?>" target="_blank" rel="noopener" class="text-xs text-muted">
        <img class="rounded-lg w-full h-24 object-cover" loading="lazy" src="<?= e(url('/admin/inspection-photos/' . $p['id'])) ?>" alt="">
        <?= e($positions[$p['position']][0] ?? $p['position']) ?> · ถ่าย <?= e(date('d/m H:i', strtotime($p['created_at']))) ?> · แทนที่ <?= e(date('d/m H:i', strtotime($p['superseded_at']))) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
