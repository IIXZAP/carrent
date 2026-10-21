<?php
/** Layout หน้าเข้าสู่ระบบหลังบ้าน — การ์ดกลางจอบนพื้นไล่สีแบรนด์ */
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> | <?= e(setting('site_name', 'thonglorcarrent')) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= url('assets/img/favicon-32.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700;800&display=swap" rel="stylesheet">
<?= brand_style() ?>
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body>
<div class="grid min-h-screen place-items-center bg-gradient-to-br from-brand to-dark p-6">
  <div class="w-full max-w-[400px] rounded-2xl bg-white p-[34px] shadow-[0_20px_50px_rgba(0,0,0,.25)]">
    <?= $content ?>
  </div>
</div>
</body>
</html>
