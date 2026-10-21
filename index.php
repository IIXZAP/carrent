<?php
/**
 * หน้าแรก — hero + ค้นหารถว่าง + รถทั้งหมด + 4 ขั้นตอน
 */
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/layout_public.php';
require_once __DIR__ . '/includes/abtrack.php';
ab_track_once('view_home');

$cars      = db_all("SELECT * FROM cars WHERE status <> 'inactive' ORDER BY sort_order, price_day");
$totalCars = count($cars);
$minPrice  = (float) db_val("SELECT MIN(price_day) FROM cars WHERE status = 'available'");
$heroCar   = db_one("SELECT * FROM cars WHERE cover_image IS NOT NULL AND status = 'available'
                     ORDER BY sort_order LIMIT 1 OFFSET 2") ?: ($cars[0] ?? null);

page_head('', setting('site_tagline'), ['active' => 'index.php']);
?>

<!-- ================= HERO ================= -->
<section class="hero">
  <div class="wrap hero-inner">
    <div>
      <h1>
        รถเช่าพร้อมใช้
        <span class="hl">ราคาตรงไปตรงมา</span>
        <span class="sub-line">จองออนไลน์ รู้ราคาทันที</span>
      </h1>
      <p class="lead">
        รถเช่าคุณภาพ ดูแลด้วยมาตรฐาน บริการด้วยความใส่ใจ
        เดินทางได้อย่างมั่นใจ ในทุกเส้นทางของคุณ
      </p>

      <div class="hero-stats">
        <div class="hstat">
          <span class="ic"><?= icon('car') ?></span>
          <span><b><?= $totalCars ?> คัน</b><span>รถในระบบ</span></span>
        </div>
        <div class="hstat">
          <span class="ic"><?= icon('tag') ?></span>
          <span><b>เริ่มต้น <?= money0($minPrice ?: 0) ?> บ./วัน</b><span>ราคาชัดเจน ไม่มีค่าซ่อนเร้น</span></span>
        </div>
        <div class="hstat">
          <span class="ic"><?= icon('percent') ?></span>
          <span><b>เช่าระยะยาว</b><span>รับส่วนลดพิเศษ</span></span>
        </div>
      </div>
    </div>

    <div class="hero-car">
      <?php if ($heroCar && upload_exists($heroCar['cover_image'])): ?>
        <img src="<?= e(upload_url($heroCar['cover_image'])) ?>"
             alt="<?= e($heroCar['brand'] . ' ' . $heroCar['model']) ?> รถเช่าพร้อมใช้"
             width="900" height="640" fetchpriority="high">
      <?php endif; ?>

      <div class="hero-badges">
        <div class="hbadge"><?= icon('shield') ?>
          <span><b>ราคาจริง</b><span>ไม่มีค่าซ่อมเร้น</span></span></div>
        <div class="hbadge"><?= icon('calendar') ?>
          <span><b>เช็คสถานะรถได้ทันที</b><span>อัปเดตแบบเรียลไทม์</span></span></div>
        <div class="hbadge"><?= icon('lock') ?>
          <span><b>จองออนไลน์ปลอดภัย</b><span>ข้อมูลของคุณถูกปกป้อง</span></span></div>
      </div>
    </div>
  </div>
</section>

<!-- ================= ค้นหารถว่าง ================= -->
<div class="wrap">
  <?php include __DIR__ . '/includes/_searchbar.php'; ?>
</div>

<!-- ================= รถเช่าของเรา ================= -->
<section class="section">
  <div class="wrap">
    <div class="section-head">
      <div>
        <div class="eyebrow">Our Cars</div>
        <h2>รถเช่าของเรา</h2>
        <p>เลือกประเภทรถที่เหมาะกับการเดินทางของคุณ</p>
      </div>
      <a class="link" href="<?= url('cars.php') ?>">ดูรถทั้งหมด <?= icon('arrow') ?></a>
    </div>

    <div class="car-grid">
      <?php foreach ($cars as $c) { include __DIR__ . '/includes/_car_card.php'; } ?>
    </div>
  </div>
</section>

<!-- ================= 4 ขั้นตอน ================= -->
<section class="section how">
  <div class="wrap">
    <div class="section-head">
      <div>
        <div class="eyebrow">How it works</div>
        <h2>4 ขั้นตอนง่ายๆ ในการเช่ารถ</h2>
        <p>จองรถออนไลน์ได้ภายในไม่กี่นาที พร้อมออกเดินทาง</p>
      </div>
    </div>

    <div class="how-grid">
      <?php
      $steps = [
          ['calendar',  'เลือกวันเวลา',   'ระบุวันรับ–คืนรถ และเลือกประเภทรถที่ต้องการ'],
          ['search',    'ค้นหารถว่าง',    'ระบบจะแสดงรถที่พร้อมให้บริการ พร้อมราคาทันที'],
          ['clipboard', 'ยืนยันการจอง',   'กรอกข้อมูลและชำระเงิน ผ่านระบบที่ปลอดภัย'],
          ['car',       'รับและออกเดินทาง','รับรถตามวันเวลาที่เลือก พร้อมเอกสารการเช่า'],
      ];
      foreach ($steps as $i => [$ic, $t, $d]): ?>
        <div class="how-step">
          <span class="n"><?= $i + 1 ?></span>
          <div>
            <h4><?= e($t) ?></h4>
            <p><?= e($d) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="center mt-3">
      <a class="btn btn-lg" href="<?= url('cars.php') ?>"><?= icon('search') ?> เริ่มค้นหารถว่าง</a>
    </div>
  </div>
</section>

<!-- ================= ทำไมต้องเรา ================= -->
<section class="section">
  <div class="wrap">
    <div class="section-head">
      <div>
        <div class="eyebrow">Why us</div>
        <h2>ทำไมลูกค้าถึงเลือกเรา</h2>
      </div>
    </div>
    <div class="g3">
      <?php
      $why = [
          ['shield', 'รถทุกคันมีประกันชั้น 1', 'ตรวจเช็กสภาพก่อนส่งมอบทุกครั้ง เข้าศูนย์ตามระยะ ขับได้อย่างมั่นใจ'],
          ['money',  'ราคาชัดเจนตั้งแต่ต้น',   'เห็นยอดสุทธิก่อนกดจอง เช่ายาวได้เรตสัปดาห์–เดือนอัตโนมัติ'],
          ['pin',    'ส่งรถถึงที่ได้',         'บริการส่ง–รับรถถึงบ้าน ที่ทำงาน หรือสนามบิน ตามที่นัดหมาย'],
      ];
      foreach ($why as [$ic, $t, $d]): ?>
        <div class="panel">
          <div class="ic-box" style="background:var(--brand-50);color:var(--brand)">
            <?= icon($ic) ?>
          </div>
          <h3><?= e($t) ?></h3>
          <p class="small muted mb-0"><?= e($d) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php page_foot(); ?>
