<?php
/**
 * รายการรถ + ผลการค้นหารถว่างตามช่วงวัน
 * ตัวแปร: title, searching, error, days, cars, pickup, ret, sbPick, sbRet, sbType
 */
$this->layout('layouts/public', [
    'title'  => $title,
    'desc'   => 'เลือกวันรับ–คืนรถ เพื่อดูรถที่ว่างจริงพร้อมราคาสุทธิ ไม่มีค่าใช้จ่ายแอบแฝง',
    'active' => 'cars',
]);
?>
<section class="bg-gradient-to-b from-[#eaf2fc] to-surface pb-0 pt-[26px]">
  <div class="wrap">
    <div class="mb-[5px] text-[0.72rem] font-extrabold uppercase tracking-[0.14em] text-accent-600">Our Cars</div>
    <h1 class="mb-1">รถเช่าของเรา</h1>
    <p class="mb-0 text-muted">เลือกวันรับ–คืนรถ ระบบจะแสดงเฉพาะคันที่ว่างจริง พร้อมราคาสุทธิ</p>
  </div>
  <div class="wrap">
    <?= $this->partial('partials/searchbar', ['sbPick' => $sbPick, 'sbRet' => $sbRet, 'sbType' => $sbType]) ?>
  </div>
</section>

<section class="pb-9 pt-[22px] sm:pb-11 lg:pb-14">
  <div class="wrap">
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= icon('close') ?><span><?= e($error) ?></span></div>
    <?php elseif ($searching): ?>
      <div class="alert alert-info">
        <?= icon('checkbig') ?>
        <span>พบรถว่าง <b><?= count($cars) ?></b> คัน · เช่า <b><?= (int) $days ?></b> วัน
          (<?= e(thai_date($pickup, true)) ?> → <?= e(thai_date($ret, true)) ?>)</span>
      </div>
    <?php endif; ?>

    <?php if (empty($cars)): ?>
      <div class="panel px-5 py-9 text-center">
        <div class="ic-box mx-auto mb-3.5 rounded-full bg-surface text-muted"><?= icon('car') ?></div>
        <h3>ไม่พบรถว่างในช่วงเวลานี้</h3>
        <p class="text-sm text-muted">ลองเปลี่ยนวันหรือประเภทรถ หรือโทรสอบถามเจ้าหน้าที่</p>
        <a class="btn" href="tel:<?= e(preg_replace('/\D/', '', setting('phone'))) ?>">
          <?= icon('phone') ?> <?= e(setting('phone')) ?></a>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4 lg:gap-[18px]">
        <?php foreach ($cars as $c):
            $link = url('cars/' . (int) $c['id'])
                  . ($searching ? '?pickup=' . urlencode($pickup) . '&ret=' . urlencode($ret) : ''); ?>
          <?= $this->partial('partials/car_card', ['c' => $c, 'link' => $link, 'showTotal' => $searching]) ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
