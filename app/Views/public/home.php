<?php
/**
 * หน้าแรก — hero + ค้นหารถว่าง + รถทั้งหมด + 4 ขั้นตอน
 * ตัวแปร: cars, totalCars, minPrice, heroCar
 */
$this->layout('layouts/public', [
    'title'  => '',
    'desc'   => setting('site_tagline'),
    'active' => 'home',
]);

$steps = [
    ['calendar',  'เลือกวันเวลา',     'ระบุวันรับ–คืนรถ และเลือกประเภทรถที่ต้องการ'],
    ['search',    'ค้นหารถว่าง',      'ระบบจะแสดงรถที่พร้อมให้บริการ พร้อมราคาทันที'],
    ['clipboard', 'ยืนยันการจอง',     'กรอกข้อมูลและชำระเงิน ผ่านระบบที่ปลอดภัย'],
    ['car',       'รับและออกเดินทาง', 'รับรถตามวันเวลาที่เลือก พร้อมเอกสารการเช่า'],
];
$why = [
    ['shield', 'รถทุกคันมีประกันชั้น 1', 'ตรวจเช็กสภาพก่อนส่งมอบทุกครั้ง เข้าศูนย์ตามระยะ ขับได้อย่างมั่นใจ'],
    ['money',  'ราคาชัดเจนตั้งแต่ต้น',   'เห็นยอดสุทธิก่อนกดจอง เช่ายาวได้เรตสัปดาห์–เดือนอัตโนมัติ'],
    ['pin',    'ส่งรถถึงที่ได้',         'บริการส่ง–รับรถถึงบ้าน ที่ทำงาน หรือสนามบิน ตามที่นัดหมาย'],
];
?>

<!-- ================= HERO ================= -->
<section class="relative overflow-hidden bg-[radial-gradient(110%_85%_at_88%_12%,#fbeed8_0%,transparent_52%),radial-gradient(120%_95%_at_12%_25%,#e7ecf2_0%,transparent_58%),linear-gradient(170deg,#eef1f5_0%,#f7f8fa_55%,#f5f7f9_100%)] pt-[22px] lg:pt-11">
  <div class="wrap relative z-[2] lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)] lg:items-center lg:gap-6">
    <div>
      <h1 class="mb-1.5 text-[1.7rem] leading-[1.24] tracking-tight sm:text-[2.2rem] lg:text-[2.7rem] xl:text-[3rem]">
        รถเช่าพร้อมใช้
        <span class="block text-accent-600">ราคาตรงไปตรงมา</span>
        <span class="mt-1.5 block text-[1.15rem] font-semibold tracking-normal text-brand lg:text-[1.35rem]">จองออนไลน์ รู้ราคาทันที</span>
      </h1>
      <p class="mb-3.5 max-w-[34em] text-[0.92rem] text-ink-2 lg:text-base">
        รถเช่าคุณภาพ ดูแลด้วยมาตรฐาน บริการด้วยความใส่ใจ
        เดินทางได้อย่างมั่นใจ ในทุกเส้นทางของคุณ
      </p>

      <div class="mb-3.5 flex flex-wrap gap-2">
        <?php
        $stats = [
            ['car',     $totalCars . ' คัน',                                 'รถในระบบ'],
            ['tag',     'เริ่มต้น ' . money0($minPrice ?: 0) . ' บ./วัน',    'ราคาชัดเจน ไม่มีค่าซ่อนเร้น'],
            ['percent', 'เช่าระยะยาว',                                       'รับส่วนลดพิเศษ'],
        ];
        foreach ($stats as [$ic, $big, $small]): ?>
          <div class="flex items-center gap-[9px] rounded-xl border border-white/90 bg-white/75 px-[13px] py-[9px] shadow-soft backdrop-blur">
            <span class="grid h-8 w-8 flex-none place-items-center rounded-[9px] bg-brand-50 text-brand"><?= icon($ic, 'h-[17px] w-[17px]') ?></span>
            <span><b class="block text-[0.93rem] leading-tight text-dark"><?= e($big) ?></b><span class="block text-[0.72rem] leading-snug text-ink-2"><?= e($small) ?></span></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="relative -mx-4 px-2 sm:-mx-6 lg:mx-0 lg:px-0 lg:pb-[38px]">
      <?php if ($heroCar && upload_exists($heroCar['cover_image'])): ?>
        <img class="h-auto max-h-[34vh] w-full object-contain drop-shadow-[0_16px_22px_rgba(22,33,46,.22)] sm:max-h-[40vh] lg:max-h-none"
             src="<?= e(upload_url($heroCar['cover_image'])) ?>"
             alt="<?= e($heroCar['brand'] . ' ' . $heroCar['model']) ?> รถเช่าพร้อมใช้"
             width="900" height="640" fetchpriority="high">
      <?php endif; ?>

      <?php
      $badges = [
          ['shield',   'ราคาจริง',               'ไม่มีค่าซ่อมเร้น'],
          ['calendar', 'เช็คสถานะรถได้ทันที',    'อัปเดตแบบเรียลไทม์'],
          ['lock',     'จองออนไลน์ปลอดภัย',     'ข้อมูลของคุณถูกปกป้อง'],
      ];
      ?>
      <div class="scroll-hide flex gap-2 overflow-x-auto px-4 pb-1 sm:flex-wrap sm:px-6 lg:absolute lg:bottom-[46px] lg:right-0 lg:max-w-[min(100%,560px)] lg:justify-end lg:gap-[7px] lg:overflow-visible lg:p-0 xl:max-w-none xl:flex-nowrap">
        <?php foreach ($badges as [$ic, $b1, $b2]): ?>
          <div class="flex flex-none items-center gap-2 rounded-xl bg-dark px-[13px] py-[9px] text-white lg:px-[11px] lg:py-2 lg:shadow-[0_6px_18px_rgba(22,33,46,.24)]">
            <?= icon($ic, 'h-[17px] w-[17px] opacity-90') ?>
            <span><b class="block whitespace-nowrap text-[0.78rem] font-semibold leading-snug lg:text-[0.73rem]"><?= e($b1) ?></b><span class="block whitespace-nowrap text-[0.68rem] leading-snug text-white/70 lg:text-[0.65rem]"><?= e($b2) ?></span></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ================= ค้นหารถว่าง ================= -->
<div class="wrap">
  <?= $this->partial('partials/searchbar') ?>
</div>

<!-- ================= รถเช่าของเรา ================= -->
<section class="py-9 sm:py-11 lg:py-14">
  <div class="wrap">
    <div class="mb-[18px] lg:flex lg:items-end lg:justify-between lg:gap-5">
      <div>
        <div class="mb-[5px] text-[0.72rem] font-extrabold uppercase tracking-[0.14em] text-accent-600">Our Cars</div>
        <h2 class="mb-1">รถเช่าของเรา</h2>
        <p class="m-0 text-[0.9rem] text-ink-2">เลือกประเภทรถที่เหมาะกับการเดินทางของคุณ</p>
      </div>
      <a class="mt-1.5 inline-flex min-h-[44px] items-center gap-[5px] py-2.5 text-[0.88rem] font-semibold lg:mt-0" href="<?= url('cars') ?>">ดูรถทั้งหมด <?= icon('arrow', 'h-[15px] w-[15px]') ?></a>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4 lg:gap-[18px]">
      <?php foreach ($cars as $c): ?>
        <?= $this->partial('partials/car_card', ['c' => $c]) ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= 4 ขั้นตอน ================= -->
<section class="border-y border-line bg-white py-9 sm:py-11 lg:py-14">
  <div class="wrap">
    <div class="mb-[18px]">
      <div class="mb-[5px] text-[0.72rem] font-extrabold uppercase tracking-[0.14em] text-accent-600">How it works</div>
      <h2 class="mb-1">4 ขั้นตอนง่ายๆ ในการเช่ารถ</h2>
      <p class="m-0 text-[0.9rem] text-ink-2">จองรถออนไลน์ได้ภายในไม่กี่นาที พร้อมออกเดินทาง</p>
    </div>

    <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
      <?php foreach ($steps as $i => [$ic, $t, $d]): ?>
        <div class="flex items-start gap-3 rounded-xl border border-line-2 bg-surface p-3.5">
          <span class="grid h-[30px] w-[30px] flex-none place-items-center rounded-[9px] bg-accent text-[0.86rem] font-extrabold text-brand-700"><?= $i + 1 ?></span>
          <div>
            <h4 class="mb-[3px] text-[0.93rem]"><?= e($t) ?></h4>
            <p class="m-0 text-[0.82rem] leading-relaxed text-ink-2"><?= e($d) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="mt-6 text-center">
      <a class="btn btn-lg" href="<?= url('cars') ?>"><?= icon('search') ?> เริ่มค้นหารถว่าง</a>
    </div>
  </div>
</section>

<!-- ================= ทำไมต้องเรา ================= -->
<section class="py-9 sm:py-11 lg:py-14">
  <div class="wrap">
    <div class="mb-[18px]">
      <div class="mb-[5px] text-[0.72rem] font-extrabold uppercase tracking-[0.14em] text-accent-600">Why us</div>
      <h2 class="mb-1">ทำไมลูกค้าถึงเลือกเรา</h2>
    </div>
    <div class="grid gap-3.5 sm:grid-cols-3 sm:gap-4">
      <?php foreach ($why as [$ic, $t, $d]): ?>
        <div class="panel">
          <div class="ic-box"><?= icon($ic) ?></div>
          <h3><?= e($t) ?></h3>
          <p class="mb-0 text-sm text-muted"><?= e($d) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
