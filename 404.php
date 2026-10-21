<?php
require_once __DIR__ . '/includes/layout_public.php';
http_response_code(404);
page_head('ไม่พบหน้าที่ต้องการ', '', ['noindex' => true]);
?>
<div class="wrap section">
  <div class="panel center" style="padding:52px 24px">
    <div class="ic-box ic-round" style="background:var(--brand-50);color:var(--brand);margin:0 auto 12px"><?= icon('car') ?></div>
    <h1>ไม่พบหน้าที่คุณต้องการ</h1>
    <p class="muted">หน้านี้อาจถูกย้ายหรือลบไปแล้ว</p>
    <a class="btn" href="<?= url('index.php') ?>">กลับหน้าแรก</a>
    <a class="btn btn-ghost" href="<?= url('cars.php') ?>">ดูรถทั้งหมด</a>
  </div>
</div>
<?php page_foot(); ?>
