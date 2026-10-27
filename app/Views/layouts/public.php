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
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<?= brand_style() ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="<?= $hasBar ? 'pb-[74px] lg:pb-0' : '' ?>">

<?php if ($isTestSite): ?>
  <?php // แถบเตือนบนสุด — กันลูกค้าจริงเข้ามาจองผิดเว็บ ปิดได้ที่ หลังบ้าน → ตั้งค่า → is_test_site = 0 ?>
  <div class="flex items-center justify-center gap-3 bg-warn-bg px-4 py-2 text-sm text-warn print:hidden" role="status">
    <span><b>เวอร์ชันทดลอง</b> <span class="hidden sm:inline">— การจองที่นี่ไม่ใช่การจองจริง</span></span>
    <a class="font-bold underline" href="<?= e(setting('live_site_url', '/')) ?>">ไปเว็บจริง</a>
  </div>
<?php endif; ?>

<header class="sticky top-0 z-[100] border-b border-line bg-white print:hidden">
  <div class="wrap flex h-[60px] items-center gap-3 lg:h-[72px] lg:gap-2.5">
    <a class="flex min-h-[44px] min-w-0 flex-1 items-center lg:mr-5 lg:flex-none" href="<?= url('/') ?>" aria-label="<?= e($site) ?> — หน้าแรก">
      <?= brand_logo('lockup', 'h-[30px] w-auto max-w-full object-contain lg:h-[28px] xl:h-[34px]') ?>
    </a>

    <nav id="nav" data-open="false"
         class="fixed inset-x-0 top-[60px] z-[99] hidden max-h-[calc(100vh-60px)] flex-col overflow-y-auto border-b border-line bg-white px-4 pb-[18px] pt-2 shadow-card data-[open=true]:flex lg:static lg:flex lg:max-h-none lg:min-w-0 lg:flex-1 lg:flex-row lg:items-center lg:justify-center lg:gap-px lg:overflow-visible lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none">
      <?php foreach ($menu as $key => [$path, $label]):
          $on = $active === $key; ?>
        <a href="<?= url($path) ?>"
           class="relative border-b border-line-2 px-3 py-[13px] text-[0.97rem] font-medium last:border-b-0 hover:bg-brand-50 lg:whitespace-nowrap lg:rounded-lg lg:border-b-0 lg:px-2 lg:py-[9px] lg:text-[0.9rem] xl:px-3 <?= $on ? 'rounded-lg bg-brand-50 font-bold text-brand lg:bg-transparent lg:after:absolute lg:after:inset-x-3 lg:after:bottom-0.5 lg:after:h-0.5 lg:after:rounded lg:after:bg-accent' : 'text-ink lg:text-ink-2 lg:hover:text-brand' ?>">
          <?= e($label) ?>
        </a>
      <?php endforeach; ?>
      <a class="mt-2.5 flex items-center gap-2.5 rounded-xl bg-brand-50 p-3 text-dark lg:hidden" href="tel:<?= e($phoneRaw) ?>">
        <?= icon('phone') ?>
        <span><b class="block text-[1.05rem] text-brand"><?= e(setting('phone')) ?></b>
          <span class="text-[0.76rem] text-ink-2">บริการทุกวัน <?= e(setting('open_time')) ?> - <?= e(setting('close_time')) ?> น.</span></span>
      </a>
      <a class="mt-3 rounded-xl bg-brand p-3.5 text-center font-bold text-white lg:hidden" href="<?= url('cars') ?>">จองรถเลย</a>
    </nav>

    <div class="hidden flex-none items-center gap-3 lg:flex">
      <a class="flex items-center gap-[9px] whitespace-nowrap" href="tel:<?= e($phoneRaw) ?>">
        <span class="grid h-9 w-9 place-items-center rounded-full bg-brand-50 text-brand"><?= icon('phone') ?></span>
        <span class="hidden xl:block"><b class="block text-base leading-tight text-dark"><?= e(setting('phone')) ?></b>
          <span class="block text-[0.72rem] text-muted">บริการทุกวัน <?= e(setting('open_time')) ?> - <?= e(setting('close_time')) ?> น.</span></span>
      </a>
    </div>

    <div class="flex flex-none items-center gap-2 lg:hidden">
      <a class="grid h-10 w-10 place-items-center rounded-lg border border-line bg-white text-[1.15rem] text-brand hover:bg-brand-50" href="tel:<?= e($phoneRaw) ?>" aria-label="โทรหาเรา"><?= icon('phone') ?></a>
      <button class="grid h-10 w-10 cursor-pointer place-items-center rounded-lg border border-line bg-white p-0 text-[1.15rem] text-brand hover:bg-brand-50" id="navToggle" type="button" aria-label="เปิดเมนู" aria-expanded="false"><?= icon('menu') ?></button>
    </div>
  </div>
</header>

<?= $content ?>

<footer class="mt-10 bg-dark py-8 text-[0.88rem] text-white/70 print:hidden">
  <div class="wrap">
    <div class="grid gap-6 sm:grid-cols-[2fr_1fr_1fr]">
      <div>
        <div class="mb-3 flex items-center">
          <?= brand_logo('full', 'h-[46px] w-auto brightness-0 invert opacity-90') ?>
        </div>
        <p><?= e(setting('site_tagline')) ?></p>
        <?php if (setting('shop_name')): ?><p class="mb-0 text-sm"><?= e(setting('shop_name')) ?></p><?php endif; ?>
        <?php if (setting('shop_address')): ?><p class="text-sm"><?= e(setting('shop_address')) ?></p><?php endif; ?>
      </div>

      <div>
        <h4 class="mb-3 text-[0.9rem] text-white">เมนู</h4>
        <div class="flex flex-col [&>a]:block [&>a]:border-b [&>a]:border-white/10 [&>a]:py-[11px] [&>a]:text-white/80 [&>a:hover]:text-white sm:[&>a]:border-0 sm:[&>a]:py-[7px]">
          <a href="<?= url('cars') ?>">รถเช่าของเรา</a>
          <a href="<?= url('how-to') ?>">วิธีการเช่า</a>
          <a href="<?= url('faq') ?>">คำถามที่พบบ่อย</a>
          <a href="<?= url('track') ?>">ตรวจสอบการจอง</a>
          <a href="<?= url('terms') ?>">เงื่อนไขการเช่า</a>
        </div>
      </div>

      <div>
        <h4 class="mb-3 text-[0.9rem] text-white">ติดต่อ</h4>
        <div class="flex flex-col [&>a]:block [&>a]:border-b [&>a]:border-white/10 [&>a]:py-[11px] [&>a]:text-white/80 [&>a:hover]:text-white [&>span]:block [&>span]:py-[11px] sm:[&>a]:border-0 sm:[&>a]:py-[7px] sm:[&>span]:py-[7px]">
          <a href="tel:<?= e($phoneRaw) ?>"><?= e(setting('phone')) ?></a>
          <?php if (setting('phone2')): ?><a href="tel:<?= e(preg_replace('/\D/', '', setting('phone2'))) ?>"><?= e(setting('phone2')) ?></a><?php endif; ?>
          <?php if (setting('line_id')): ?><span>LINE <?= e(setting('line_id')) ?></span><?php endif; ?>
          <?php if (setting('email')): ?><a href="mailto:<?= e(setting('email')) ?>"><?= e(setting('email')) ?></a><?php endif; ?>
          <span>เปิดทุกวัน <?= e(setting('open_time')) ?> - <?= e(setting('close_time')) ?> น.</span>
        </div>
      </div>
    </div>

    <div class="mt-6 border-t border-white/10 pt-4 text-[0.79rem] leading-relaxed text-white/50">
      © <?= date('Y') + 543 ?> <?= e($site) ?> · สงวนลิขสิทธิ์ ·
      <a class="text-white/60 hover:text-white" href="<?= url('admin/login') ?>">สำหรับเจ้าหน้าที่</a>
    </div>
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
