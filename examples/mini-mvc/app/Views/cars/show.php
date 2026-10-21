<?php /** รายละเอียดรถ + ฟอร์มจอง — ตัวแปรที่ได้รับ: $car */ ?>
<h2><?= e($car['brand']) ?> <?= e($car['model']) ?></h2>
<p>ราคา ฿<?= number_format($car['price_day']) ?> / วัน</p>

<form method="post" action="/booking" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="car_id" value="<?= (int) $car['id'] ?>">

  <label>ชื่อ-นามสกุล <input type="text" name="name" required></label>
  <label>เบอร์โทร <input type="text" name="phone" required></label>
  <label>วัน-เวลารับรถ <input type="datetime-local" name="pickup_at" required></label>
  <label>วัน-เวลาคืนรถ <input type="datetime-local" name="return_at" required></label>

  <button>ยืนยันการจอง</button>
</form>
<p><a href="/">← กลับ</a></p>
