<?php
/**
 * การ์ดรถ 1 ใบ — ใช้ร่วมกันทุกหน้า
 * ตัวแปรที่ต้องมี: $c (แถวจากตาราง cars)
 * ตัวเลือก: $cardLink (URL), $showTotal (bool — แสดงราคารวมจากการค้นหา)
 */
$cardLink  = $cardLink  ?? url('car.php?id=' . (int) $c['id']);
$showTotal = $showTotal ?? false;
?>
<a class="car-card" href="<?= e($cardLink) ?>">
  <div class="car-photo">
    <?php if (upload_exists($c['cover_image'] ?? null)): ?>
      <img src="<?= e(upload_url($c['cover_image'])) ?>"
           alt="เช่า <?= e($c['brand'] . ' ' . $c['model']) ?>" loading="lazy" width="900" height="640">
    <?php else: ?>
      <div class="ph"><?= icon('car') ?><span>ยังไม่มีรูป</span></div>
    <?php endif; ?>
    <?php if (($c['status'] ?? '') === 'maintenance'): ?>
      <span class="tag" style="background:var(--warn-bg);color:var(--warn)">เข้าศูนย์</span>
    <?php elseif ($showTotal): ?>
      <span class="tag">ว่าง</span>
    <?php endif; ?>
  </div>

  <div class="car-body">
    <h3><?= e($c['brand'] . ' ' . $c['model']) ?></h3>
    <div class="car-seg"><?= e($c['segment'] ?: car_type_label($c['car_type'])) ?></div>

    <div class="car-specs">
      <span class="sp"><?= icon('seat') ?><?= (int) $c['seats'] ?> ที่นั่ง</span>
      <span class="sp"><?= icon('gear') ?><?= $c['gear'] === 'auto' ? 'เกียร์ออโต้' : 'เกียร์ธรรมดา' ?></span>
      <span class="sp"><?= icon('fuel') ?><?= e($c['fuel']) ?></span>
    </div>

    <div class="car-foot">
      <?php if ($showTotal && isset($c['_total'])): ?>
        <div class="car-price">
          <span class="amt"><?= money0($c['_total']) ?></span>
          <span class="per">บาท / <?= (int) $c['_days'] ?> วัน</span>
        </div>
        <div class="tiny muted" style="margin:-4px 0 8px">เฉลี่ย <?= money0($c['_avg_day']) ?> บ./วัน</div>
      <?php else: ?>
        <div class="car-price">
          <span class="amt"><?= money0($c['price_day']) ?></span>
          <span class="per">บ./วัน</span>
        </div>
      <?php endif; ?>
      <span class="car-cta">ดูรายละเอียด <?= icon('arrow') ?></span>
    </div>
  </div>
</a>
