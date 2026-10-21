<?php /** รายการรถ — ตัวแปรที่ได้รับ: $cars */ ?>
<h2>เลือกรถที่ต้องการ</h2>
<?php foreach ($cars as $car): ?>
  <div class="card">
    <b><?= e($car['brand']) ?> <?= e($car['model']) ?></b>
    — ฿<?= number_format($car['price_day']) ?> / วัน
    <br><a href="/cars/<?= (int) $car['id'] ?>">ดูรายละเอียดและจอง</a>
  </div>
<?php endforeach; ?>
