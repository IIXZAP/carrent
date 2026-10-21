<?php
/**
 * การ์ดรถ 1 ใบ — ใช้ร่วมกันทุกหน้า
 * ตัวแปร: $c (แถวจากตาราง cars)
 * ตัวเลือก: $link (URL), $showTotal (bool — แสดงราคารวมจากการค้นหา)
 */
$link      = $link ?? url('cars/' . (int) $c['id']);
$showTotal = $showTotal ?? false;
?>
<a class="group flex flex-col overflow-hidden rounded-2xl border border-line bg-white transition hover:border-accent-100 hover:shadow-card" href="<?= e($link) ?>">
  <div class="relative grid aspect-[4/3] place-items-center bg-white p-2 xl:aspect-[16/11]">
    <?php if (upload_exists($c['cover_image'] ?? null)): ?>
      <img class="h-full w-full object-contain" src="<?= e(upload_url($c['cover_image'])) ?>"
           alt="เช่า <?= e($c['brand'] . ' ' . $c['model']) ?>" loading="lazy" width="900" height="640">
    <?php else: ?>
      <div class="flex h-full w-full flex-col items-center justify-center gap-1.5 rounded-lg bg-[repeating-linear-gradient(45deg,#f2f4f7,#f2f4f7_10px,#eceff3_10px,#eceff3_20px)] text-muted">
        <?= icon('car', 'h-[34px] w-[34px] opacity-55') ?><span class="text-[0.7rem]">ยังไม่มีรูป</span>
      </div>
    <?php endif; ?>
    <?php if (($c['status'] ?? '') === 'maintenance'): ?>
      <span class="absolute left-2 top-2 rounded-full bg-warn-bg px-[9px] py-[3px] text-[0.68rem] font-bold text-warn">เข้าศูนย์</span>
    <?php elseif ($showTotal): ?>
      <span class="absolute left-2 top-2 rounded-full bg-brand px-[9px] py-[3px] text-[0.68rem] font-bold text-white">ว่าง</span>
    <?php endif; ?>
  </div>

  <div class="flex flex-1 flex-col p-3 lg:p-3.5">
    <h3 class="mb-0.5 text-[0.95rem] leading-tight text-dark lg:text-base"><?= e($c['brand'] . ' ' . $c['model']) ?></h3>
    <div class="mb-[9px] text-[0.76rem] leading-snug text-muted"><?= e($c['segment'] ?: car_type_label($c['car_type'])) ?></div>

    <div class="mb-[11px] flex flex-wrap gap-x-2.5 gap-y-1 text-[0.73rem] text-ink-2">
      <span class="inline-flex max-w-full items-center gap-1 whitespace-nowrap"><?= icon('seat', 'h-[13px] w-[13px] text-muted') ?><?= (int) $c['seats'] ?> ที่นั่ง</span>
      <span class="inline-flex max-w-full items-center gap-1 whitespace-nowrap"><?= icon('gear', 'h-[13px] w-[13px] text-muted') ?><?= $c['gear'] === 'auto' ? 'เกียร์ออโต้' : 'เกียร์ธรรมดา' ?></span>
      <span class="inline-flex max-w-full items-center gap-1 whitespace-nowrap"><?= icon('fuel', 'h-[13px] w-[13px] text-muted') ?><?= e($c['fuel']) ?></span>
    </div>

    <div class="mt-auto border-t border-line-2 pt-2.5">
      <?php if ($showTotal && isset($c['_total'])): ?>
        <div class="mb-2.5 flex items-baseline gap-1">
          <span class="text-[1.18rem] font-extrabold leading-none text-dark"><?= money0($c['_total']) ?></span>
          <span class="text-[0.74rem] text-muted">บาท / <?= (int) $c['_days'] ?> วัน</span>
        </div>
        <div class="-mt-1 mb-2 text-[0.79rem] text-muted">เฉลี่ย <?= money0($c['_avg_day']) ?> บ./วัน</div>
      <?php else: ?>
        <div class="mb-2.5 flex items-baseline gap-1">
          <span class="text-[1.18rem] font-extrabold leading-none text-dark"><?= money0($c['price_day']) ?></span>
          <span class="text-[0.74rem] text-muted">บ./วัน</span>
        </div>
      <?php endif; ?>
      <span class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-line bg-surface p-[9px] text-[0.83rem] font-bold text-brand transition group-hover:border-brand group-hover:bg-brand group-hover:text-white">
        ดูรายละเอียด <?= icon('arrow', 'h-[15px] w-[15px]') ?>
      </span>
    </div>
  </div>
</a>
