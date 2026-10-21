<?php
/**
 * แสดงข้อความแจ้งผลครั้งเดียว (ดึงออกจากเซสชันแล้วล้างทิ้ง)
 * โทน: ok | warn | danger | info
 */
foreach (\App\Core\Flash::pull() as $f):
    $tone = in_array($f['type'], ['ok', 'warn', 'danger', 'info'], true) ? $f['type'] : 'info'; ?>
  <div class="alert alert-<?= $tone ?>" role="status"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
