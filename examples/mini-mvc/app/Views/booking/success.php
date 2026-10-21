<?php /** หน้าจองสำเร็จ — ตัวแปรที่ได้รับ: $booking */ ?>
<h2>✅ จองสำเร็จ</h2>
<div class="card">
  เลขที่จอง: <b><?= e($booking['booking_no']) ?></b><br>
  รถ: <?= e($booking['brand']) ?> <?= e($booking['model']) ?><br>
  ผู้จอง: <?= e($booking['name']) ?> (<?= e($booking['phone']) ?>)<br>
  รับรถ: <?= e($booking['pickup_at']) ?><br>
  คืนรถ: <?= e($booking['return_at']) ?><br>
  ยอดรวม: <b>฿<?= number_format($booking['total']) ?></b>
</div>
<p><a href="/">← กลับหน้าแรก</a></p>
