<?php
/**
 * หน้าแรก (Hallmark · แคตตาล็อก) — หัวหนังสือพิมพ์ + แถบค้นหา + รายการรถเป็นแถว + ขั้นตอน
 * ตัวแปร: cars, totalCars, minPrice, heroCar
 */
\App\Core\View::layout('layouts/public', [
    'title'  => '',
    'desc'   => setting('site_tagline'),
    'active' => 'home',
]);

$steps = [
    ['เลือกวันเวลา', 'ระบุวันรับ–คืนรถ และเลือกประเภทรถที่ต้องการ'],
    ['ค้นหารถว่าง', 'ระบบแสดงรถที่พร้อมให้บริการ พร้อมราคาทันที'],
    ['ยืนยันการจอง', 'กรอกข้อมูลและโอนมัดจำ แล้วแนบสลิปในหน้าตรวจสอบการจอง'],
    ['รับและออกเดินทาง', 'รับรถตามวันเวลาที่เลือก พร้อมเอกสารการเช่า'],
];
$why = [
    ['ตรวจสภาพก่อนส่งมอบทุกครั้ง', 'ถ่ายรูปสภาพรถทุกมุมตอนส่งมอบและตอนรับคืน เก็บไว้ทั้งสองฝ่าย'],
    ['เห็นยอดก่อนกดจอง', 'ค่าเช่า บริการเสริม และเงินประกันแสดงครบก่อนยืนยัน เช่ายาวระบบเลือกเรตสัปดาห์หรือเดือนที่ถูกที่สุดให้'],
    ['ส่งรถถึงที่ได้', 'บริการส่ง–รับรถถึงบ้าน ที่ทำงาน หรือสนามบิน คิดค่าบริการตามระยะทางที่แจ้งไว้ในหน้าจอง'],
];
?>
<section class="hm-wrap">
  <div class="hm-mast">
    <div>
      <h1>รถเช่าพร้อมใช้ <span>ราคาตรงไปตรงมา</span></h1>
      <p class="lede">จองออนไลน์ รู้ราคาทันที — เลือกวันรับ–คืน ดูรถที่ว่างจริงพร้อมยอดรวม แล้วจองได้ในไม่กี่นาที</p>
      <ul class="hm-facts">
        <li><b><?= (int) $totalCars ?></b><span>คันในระบบ</span></li>
        <li><b><?= $minPrice ? e(money0($minPrice)) : '—' ?></b><span>บาท/วัน ราคาเริ่มต้น</span></li>
        <li><b><?= e(setting('open_time')) ?>–<?= e(setting('close_time')) ?></b><span>เปิดทุกวัน</span></li>
      </ul>
    </div>
    <?php if ($heroCar && upload_exists($heroCar['cover_image'])): ?>
      <figure class="hm-plate">
        <img src="<?= e(upload_url($heroCar['cover_image'])) ?>" alt="<?= e($heroCar['brand'] . ' ' . $heroCar['model']) ?> รถเช่าพร้อมใช้" width="900" height="640" fetchpriority="high">
        <figcaption><b><?= e($heroCar['brand'] . ' ' . $heroCar['model']) ?></b><span><?= e(money0($heroCar['price_day'])) ?> บ./วัน</span></figcaption>
      </figure>
    <?php endif; ?>
  </div>

  <?= \App\Core\View::partial('partials/searchbar') ?>
</section>

<section class="hm-wrap">
  <div class="hm-sechead">
    <div>
      <h2>รถเช่าของเรา</h2>
      <p>เลือกรุ่นที่เหมาะกับการเดินทางของคุณ</p>
    </div>
    <a class="hm-row-go" href="<?= url('/cars') ?>">ดูรถทั้งหมด <?= icon('arrow', 'h-[15px] w-[15px]') ?></a>
  </div>
  <?php if ($cars): ?>
    <ul class="hm-catalogue">
      <?php foreach ($cars as $c): ?>
        <?= \App\Core\View::partial('partials/car_card', ['c' => $c]) ?>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="py-10 text-muted">ยังไม่มีรถพร้อมให้เช่าในขณะนี้</p>
  <?php endif; ?>
</section>

<section class="hm-wrap">
  <div class="hm-sechead"><div><h2>เช่ารถใน 4 ขั้นตอน</h2></div></div>
  <ol class="hm-steps">
    <?php foreach ($steps as $i => [$t, $d]): ?>
      <li><span class="n"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span><div><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div></li>
    <?php endforeach; ?>
  </ol>
</section>

<section class="hm-wrap">
  <div class="hm-sechead"><div><h2>ทำไมลูกค้าถึงเลือกเรา</h2></div></div>
  <div class="hm-why" style="margin-top:28px">
    <?php foreach ($why as [$t, $d]): ?>
      <div><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div>
    <?php endforeach; ?>
  </div>
</section>
