<?php
/**
 * Layout หน้าสั้นๆ สำหรับมือถือ (หน้าเซ็นเอกสาร / การจองของฉันใน LINE) — หัวสีแบรนด์ + เนื้อหากลางจอ
 * ตัวแปร: title, heading, sub (เลขที่เอกสาร), narrow (ความกว้างสูงสุด), who (ชื่อผู้ใช้ LINE ในหัว — ไม่บังคับ)
 */
$site = (string) setting('site_name', 'thonglorcarrent');
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> — <?= e($site) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" sizes="32x32" href="<?= url('assets/img/favicon-32.png') ?>">
<?= brand_style() ?>
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="bg-[#f3f5f8] pb-[calc(28px+env(safe-area-inset-bottom,0px))] text-base print:bg-white">

<header class="bg-brand px-4 pb-4 pt-[calc(14px+env(safe-area-inset-top,0px))] text-white print:border-b-2 print:border-black print:bg-white print:text-black">
  <div class="mx-auto <?= e($narrow ?? 'max-w-[620px]') ?>">
    <div class="text-[0.8rem] tracking-wide opacity-75"><?= e($site) ?></div>
    <h1 class="mb-0 mt-0.5 text-[1.3rem] font-bold leading-tight text-white print:text-black"><?= e($heading) ?></h1>
    <?php if (!empty($sub)): ?><div class="mt-0.5 text-[0.9rem] opacity-90"><?= e($sub) ?></div><?php endif; ?>
    <?php if (isset($who)): ?>
      <div class="mt-2.5 flex items-center gap-2 text-[0.88rem] opacity-90" id="who" hidden>
        <img class="h-7 w-7 rounded-full bg-white/20 object-cover" id="whoPic" alt="">
        <span id="whoName"></span>
      </div>
    <?php endif; ?>
  </div>
</header>

<main class="mx-auto <?= e($narrow ?? 'max-w-[620px]') ?> px-3.5 pt-3.5" id="app">
  <?= $content ?>
</main>

<?= $this->section('after_main') ?>
</body>
</html>
