<?php
/**
 * Layout หน้าเว็บลูกค้า — header / footer / แถบล่างมือถือ
 *
 * ตัวแปรที่รับจากหน้า: title, desc, active (home|cars|how-to|faq|contact|track), noindex
 * ส่วนเสริม: mobile_bar (แถบราคา + ปุ่มจองด้านล่างจอมือถือ)
 */
$site     = setting('site_name', 'CAR RENT');
$title    = $title ?? '';
$full     = $title ? "$title | $site" : ($site . ' — ' . setting('site_tagline'));
$desc     = ($desc ?? '') ?: setting('site_tagline');
$active   = $active ?? '';
$phoneRaw = preg_replace('/\D/', '', setting('phone', ''));
$hasBar   = \App\Core\View::hasSection('mobile_bar');

$menu = [
    'home'    => ['', 'หน้าแรก'],
    'cars'    => ['cars', 'รถเช่าของเรา'],
    'how-to'  => ['how-to', 'วิธีการเช่า'],
    'faq'     => ['faq', 'คำถามที่พบบ่อย'],
    'contact' => ['contact', 'ติดต่อเรา'],
    'track'   => ['track', 'ตรวจสอบการจอง'],
];

$isTestSite = false;
$noindex    = !empty($noindex);
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e(setting('brand_primary', '#1f2d3d')) ?>">
<title><?= e($full) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="robots" content="<?= $noindex ? 'noindex,nofollow' : 'index,follow' ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($site) ?>">
<meta property="og:title" content="<?= e($full) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:locale" content="th_TH">

<script type="application/ld+json">
<?= json_encode([
    '@context'     => 'https://schema.org',
    '@type'        => 'AutoRental',
    'name'         => $site,
    'description'  => setting('site_tagline'),
    'telephone'    => setting('phone'),
    'email'        => setting('email'),
    'priceRange'   => '฿฿',
    'areaServed'   => 'Thailand',
    'openingHours' => 'Mo-Su ' . setting('open_time', '08:00') . '-' . setting('close_time', '20:00'),
    'address'      => ['@type' => 'PostalAddress', 'streetAddress' => setting('shop_address'), 'addressCountry' => 'TH'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT) ?>
</script>

<link rel="icon" type="image/png" sizes="32x32" href="<?= url('assets/img/favicon-32.png') ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= url('assets/img/icon-192.png') ?>">
<link rel="apple-touch-icon" href="<?= url('assets/img/apple-touch-icon.png') ?>">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Noto+Serif+Thai:wght@600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<?= brand_style() ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="hm <?= $hasBar ? 'pb-[74px] lg:pb-0' : '' ?>">

<?php if ($isTestSite): ?>
  <?php // แถบเตือนบนสุด — กันลูกค้าจริงเข้ามาจองผิดเว็บ ปิดได้ที่ หลังบ้าน → ตั้งค่า → is_test_site = 0 ?>
  <div class="flex items-center justify-center gap-3 bg-warn-bg px-4 py-2 text-sm text-warn print:hidden" role="status">
    <span><b>เวอร์ชันทดลอง</b> <span class="hidden sm:inline">— การจองที่นี่ไม่ใช่การจองจริง</span></span>
    <a class="font-bold underline" href="<?= e(setting('live_site_url', '/')) ?>">ไปเว็บจริง</a>
  </div>
<?php endif; ?>

<header class="hm-head print:hidden">
  <div class="hm-util">
    <span>บริการทุกวัน <?= e(setting('open_time')) ?> – <?= e(setting('close_time')) ?> น.</span>
    <a href="tel:<?= e($phoneRaw) ?>"><?= e(setting('phone')) ?></a>
  </div>
  <div class="hm-bar">
    <a class="hm-brand" href="<?= url('/') ?>" aria-label="<?= e($site) ?> — หน้าแรก">
      <?= brand_logo('lockup', 'h-[28px] w-auto max-w-full object-contain lg:h-[32px]') ?>
    </a>

    <nav id="nav" data-open="false" class="hm-nav" aria-label="เมนูหลัก">
      <?php foreach ($menu as $key => [$path, $label]): ?>
        <a href="<?= url($path) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
      <a class="btn hm-cta" href="<?= url('cars') ?>">จองรถเลย</a>
    </nav>

    <a class="hm-iconbtn hm-phone-btn" href="tel:<?= e($phoneRaw) ?>" aria-label="โทรหาเรา"><?= icon('phone') ?></a>
    <button class="hm-iconbtn hm-toggle" id="navToggle" type="button" aria-label="เปิดเมนู" aria-expanded="false"><?= icon('menu') ?></button>
  </div>
</header>

<?= $content ?>

<footer class="hm-foot print:hidden">
  <div class="hm-wrap">
    <p class="hm-foot-statement"><?= e(setting('site_tagline')) ?></p>
    <div class="hm-foot-cols">
      <div>
        <h4>เมนู</h4>
        <a href="<?= url('cars') ?>">รถเช่าของเรา</a>
        <a href="<?= url('how-to') ?>">วิธีการเช่า</a>
        <a href="<?= url('faq') ?>">คำถามที่พบบ่อย</a>
        <a href="<?= url('track') ?>">ตรวจสอบการจอง</a>
        <a href="<?= url('terms') ?>">เงื่อนไขการเช่า</a>
      </div>
      <div>
        <h4>ติดต่อ</h4>
        <a href="tel:<?= e($phoneRaw) ?>"><?= e(setting('phone')) ?></a>
        <?php if (setting('phone2')): ?><a href="tel:<?= e(preg_replace('/\D/', '', setting('phone2'))) ?>"><?= e(setting('phone2')) ?></a><?php endif; ?>
        <?php if (setting('line_id')): ?><span>LINE <?= e(setting('line_id')) ?></span><?php endif; ?>
        <?php if (setting('email')): ?><a href="mailto:<?= e(setting('email')) ?>"><?= e(setting('email')) ?></a><?php endif; ?>
        <span>เปิดทุกวัน <?= e(setting('open_time')) ?> – <?= e(setting('close_time')) ?> น.</span>
      </div>
      <div>
        <h4><?= e($site) ?></h4>
        <?php if (setting('shop_name')): ?><span><?= e(setting('shop_name')) ?></span><?php endif; ?>
        <?php if (setting('shop_address')): ?><span class="whitespace-pre-line"><?= e(setting('shop_address')) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="hm-foot-legal">© <?= date('Y') + 543 ?> <?= e($site) ?> · สงวนลิขสิทธิ์ · <a href="<?= url('admin/login') ?>">สำหรับเจ้าหน้าที่</a></div>
  </div>
</footer>

<?= \App\Core\View::section('mobile_bar') ?>

<script src="<?= e(asset('js/upload-shrink.js')) ?>" defer></script>

<script>
(function () {
  // เมนูมือถือ
  var t = document.getElementById('navToggle'), n = document.getElementById('nav');
  if (!t || !n) return;
  function setOpen(open) {
    n.setAttribute('data-open', open ? 'true' : 'false');
    t.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.style.overflow = open ? 'hidden' : '';
  }
  t.addEventListener('click', function () { setOpen(n.getAttribute('data-open') !== 'true'); });
  // ปิดเมนูเมื่อกดลิงก์
  n.addEventListener('click', function (e) { if (e.target.closest('a')) setOpen(false); });
})();
</script>
</body>
</html>
