<?php
/** ข้อความแจ้งผลครั้งเดียว — ชนิดที่ระบบใช้ (success/error/warning/info) แปลงเป็นโทนสี ok/danger/warn/info */
$tones = ['success' => 'ok', 'error' => 'danger', 'warning' => 'warn', 'info' => 'info'];
foreach (\App\Core\Flash::pull() as $f):
    $tone = $tones[$f['type']] ?? 'info'; ?>
  <div class="alert alert-<?= e($tone) ?>" role="status"><?= e($f['message']) ?></div>
<?php endforeach; ?>
