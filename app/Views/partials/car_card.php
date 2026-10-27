<?php
/**
 * รถ 1 รายการในแคตตาล็อก (แถว) — ใช้ร่วมกันทุกหน้า
 * ตัวแปร: $c (แถวจากตาราง cars)
 * ตัวเลือก: $link (URL), $showTotal (bool — แสดงราคารวมจากการค้นหา)
 */
$link      = $link ?? url('/cars/' . (int) $c['id']);
$showTotal = $showTotal ?? false;
$name      = $c['brand'] . ' ' . $c['model'];
?>
<li>
  <a class="hm-row" href="<?= e($link) ?>">
    <div class="hm-row-img">
      <?php if (upload_exists($c['cover_image'] ?? null)): ?>
        <img src="<?= e(upload_url($c['cover_image'])) ?>" alt="เช่า <?= e($name) ?>" loading="lazy" width="900" height="640">
      <?php else: ?>
        <span class="text-[0.7rem] text-muted">ยังไม่มีรูป</span>
      <?php endif; ?>
    </div>

    <div class="min-w-0">
      <h3 class="hm-row-name"><?= e($name) ?><?php if (($c['status'] ?? '') === 'maintenance'): ?><span class="hm-tag">เข้าศูนย์</span><?php elseif ($showTotal): ?><span class="hm-tag">ว่าง</span><?php endif; ?></h3>
      <p class="hm-row-type"><?= e($c['type_label'] ?? '') ?></p>
      <div class="hm-row-spec">
        <span><?= (int) $c['seats'] ?> ที่นั่ง</span>
        <span><?= e(\App\Services\CarService::GEARS[$c['gear']] ?? $c['gear']) ?></span>
        <span><?= e(\App\Services\CarService::FUELS[$c['fuel']] ?? $c['fuel']) ?></span>
      </div>
    </div>

    <div class="hm-row-price">
      <?php if ($showTotal && isset($c['_total'])): ?>
        <div><b><?= e(money0($c['_total'])) ?></b> <small>บาท / <?= (int) $c['_days'] ?> วัน</small>
          <small class="block">เฉลี่ย <?= e(money0($c['_avg_day'])) ?> บ./วัน</small></div>
      <?php else: ?>
        <div><b><?= e(money0($c['price_day'])) ?></b> <small>บ./วัน</small></div>
      <?php endif; ?>
      <span class="hm-row-go">ดูรายละเอียด <?= icon('arrow', 'h-[15px] w-[15px]') ?></span>
    </div>
  </a>
</li>
