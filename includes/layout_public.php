<?php
/**
 * Layout หน้าเว็บลูกค้า — header / footer / แถบล่างมือถือ
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/icons.php';

/**
 * @param array $opt  noindex, active (ชื่อไฟล์เมนูที่ active), mobile_bar (HTML แถบล่าง)
 */
function page_head(string $title = '', string $desc = '', array $opt = []): void
{
    $site   = setting('site_name', 'CAR RENT');
    $full   = $title ? "$title | $site" : ($site . ' — ' . setting('site_tagline'));
    $desc   = $desc ?: setting('site_tagline');
    $active = $opt['active'] ?? '';
    $GLOBALS['__mobile_bar'] = $opt['mobile_bar'] ?? '';

    $menu = [
        'index.php'   => 'หน้าแรก',
        'cars.php'    => 'รถเช่าของเรา',
        'how-to.php'  => 'วิธีการเช่า',
        'faq.php'     => 'คำถามที่พบบ่อย',
        'contact.php' => 'ติดต่อเรา',
    ];
    $phoneRaw = preg_replace('/\D/', '', setting('phone', ''));
    ?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e(setting('brand_primary', '#1b4e9b')) ?>">
<title><?= e($full) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<?php
// เวอร์ชันทดลองต้องไม่ให้ Google เก็บ — ไม่งั้นจะกลายเป็นหน้าซ้ำ (duplicate content)
// แย่งอันดับกับเว็บจริงของลูกค้าเอง
$__noindex = !empty($opt['noindex'])
          || (defined('SITE_VARIANT') && SITE_VARIANT !== 'A' && setting('is_test_site', '1') === '1');
?>
<meta name="robots" content="<?= $__noindex ? 'noindex,nofollow' : 'index,follow' ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($site) ?>">
<meta property="og:title" content="<?= e($full) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:locale" content="th_TH">

<script type="application/ld+json">
<?= json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'AutoRental',
    'name'        => $site,
    'description' => setting('site_tagline'),
    'telephone'   => setting('phone'),
    'email'       => setting('email'),
    'priceRange'  => '฿฿',
    'areaServed'  => 'Thailand',
    'openingHours'=> 'Mo-Su ' . setting('open_time', '08:00') . '-' . setting('close_time', '20:00'),
    'address'     => ['@type' => 'PostalAddress', 'streetAddress' => setting('company_address'), 'addressCountry' => 'TH'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT) ?>
</script>

<link rel="icon" type="image/png" sizes="32x32" href="<?= url('assets/img/favicon-32.png') ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= url('assets/img/icon-192.png') ?>">
<link rel="apple-touch-icon" href="<?= url('assets/img/apple-touch-icon.png') ?>">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<style>
:root{
  --brand:<?= e(setting('brand_primary', '#1b4e9b')) ?>;
  --accent:<?= e(setting('brand_accent', '#3b82f6')) ?>;
  --dark:<?= e(setting('brand_dark', '#0f2340')) ?>;
}
</style>
</head>
<body<?= $GLOBALS['__mobile_bar'] ? ' class="has-mobile-bar"' : '' ?>>

<?php
// แถบเตือนบนสุด — กันลูกค้าจริงเข้ามาจองผิดเว็บ
// ปิดได้ที่ หลังบ้าน → ตั้งค่า → is_test_site = 0
if (defined('SITE_VARIANT') && SITE_VARIANT !== 'A' && setting('is_test_site', '1') === '1'):
?>
  <div class="test-banner no-print" role="status">
    <span><b>เวอร์ชันทดลอง</b> <span class="tb-long">— การจองที่นี่ไม่ใช่การจองจริง</span></span>
    <a href="<?= e(setting('live_site_url', '/')) ?>">ไปเว็บจริง</a>
  </div>
<?php endif; ?>

<header class="site-head no-print">
  <div class="wrap bar">
    <a class="logo" href="<?= url('index.php') ?>" aria-label="<?= e($site) ?> — หน้าแรก">
      <?= brand_logo('lockup', 'logo-img') ?>
    </a>

    <nav class="nav" id="nav">
      <?php foreach ($menu as $file => $label): ?>
        <a href="<?= url($file) ?>" class="<?= $active === $file ? 'on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <a href="<?= url('track.php') ?>" class="<?= $active === 'track.php' ? 'on' : '' ?>">ตรวจสอบการจอง</a>
      <a class="nav-phone" href="tel:<?= e($phoneRaw) ?>">
        <?= icon('phone') ?>
        <span><b><?= e(setting('phone')) ?></b>
          <span>บริการทุกวัน <?= e(setting('open_time')) ?> - <?= e(setting('close_time')) ?> น.</span></span>
      </a>
      <a class="nav-cta" href="<?= url('cars.php') ?>">จองรถเลย</a>
    </nav>

    <div class="head-right hide-mobile">
      <a class="head-phone" href="tel:<?= e($phoneRaw) ?>">
        <span class="ic"><?= icon('phone') ?></span>
        <span><b><?= e(setting('phone')) ?></b>
          <span>บริการทุกวัน <?= e(setting('open_time')) ?> - <?= e(setting('close_time')) ?> น.</span></span>
      </a>
      <a class="btn btn-sm" href="<?= url('track.php') ?>"><?= icon('user') ?> เข้าสู่ระบบ</a>
    </div>

    <div class="head-actions show-mobile">
      <a class="icon-btn" href="tel:<?= e($phoneRaw) ?>" aria-label="โทรหาเรา"><?= icon('phone') ?></a>
      <button class="icon-btn nav-toggle" id="navToggle" aria-label="เปิดเมนู" aria-expanded="false"><?= icon('menu') ?></button>
    </div>
  </div>
</header>
<?php
}

function page_foot(): void
{
    $site     = setting('site_name', 'CAR RENT');
    $phoneRaw = preg_replace('/\D/', '', setting('phone', ''));
    ?>
<footer class="site-foot no-print">
  <div class="wrap">
    <div class="foot-grid">
      <div>
        <div class="foot-logo">
          <?= brand_logo('full', 'foot-logo-img') ?>
        </div>
        <p><?= e(setting('site_tagline')) ?></p>
        <?php if (setting('company_name')): ?><p class="small mb-0"><?= e(setting('company_name')) ?></p><?php endif; ?>
        <?php if (setting('company_address')): ?><p class="small"><?= e(setting('company_address')) ?></p><?php endif; ?>
      </div>

      <div>
        <h4>เมนู</h4>
        <div class="foot-links">
          <a href="<?= url('cars.php') ?>">รถเช่าของเรา</a>
          <a href="<?= url('how-to.php') ?>">วิธีการเช่า</a>
          <a href="<?= url('faq.php') ?>">คำถามที่พบบ่อย</a>
          <a href="<?= url('track.php') ?>">ตรวจสอบการจอง</a>
          <a href="<?= url('terms.php') ?>">เงื่อนไขการเช่า</a>
        </div>
      </div>

      <div>
        <h4>ติดต่อ</h4>
        <div class="foot-links">
          <a href="tel:<?= e($phoneRaw) ?>"><?= e(setting('phone')) ?></a>
          <?php if (setting('phone2')): ?><a href="tel:<?= e(preg_replace('/\D/', '', setting('phone2'))) ?>"><?= e(setting('phone2')) ?></a><?php endif; ?>
          <?php if (setting('line_id')): ?><span>LINE <?= e(setting('line_id')) ?></span><?php endif; ?>
          <?php if (setting('email')): ?><a href="mailto:<?= e(setting('email')) ?>"><?= e(setting('email')) ?></a><?php endif; ?>
          <span>เปิดทุกวัน <?= e(setting('open_time')) ?> - <?= e(setting('close_time')) ?> น.</span>
        </div>
      </div>
    </div>

    <div class="foot-bottom">
      © <?= date('Y') + 543 ?> <?= e($site) ?> · สงวนลิขสิทธิ์ ·
      <a href="<?= url('admin/login.php') ?>">สำหรับเจ้าหน้าที่</a>
    </div>
  </div>
</footer>

<?= $GLOBALS['__mobile_bar'] ?? '' ?>

<script>
(function () {
  // เมนูมือถือ
  var t = document.getElementById('navToggle'), n = document.getElementById('nav');
  if (t && n) {
    t.addEventListener('click', function () {
      var open = n.classList.toggle('open');
      t.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.body.style.overflow = open ? 'hidden' : '';
    });
    // ปิดเมนูเมื่อกดลิงก์
    n.addEventListener('click', function (e) {
      if (e.target.closest('a')) {
        n.classList.remove('open');
        t.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
      }
    });
  }
})();
</script>
</body>
</html>
<?php
}
