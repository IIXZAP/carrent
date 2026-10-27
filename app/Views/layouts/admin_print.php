<?php
/**
 * Layout หน้าพิมพ์ของหลังบ้าน (สัญญาเช่า ใบเสนอราคา/ใบเสร็จ) — กระดาษขาว ไม่มีเมนู
 * ตัวแปร: title, bodyClass (ไม่บังคับ)
 * ส่วนเสริม: toolbar (แถบปุ่มด้านบน ซ่อนตอนพิมพ์)
 */
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700;800&display=swap" rel="stylesheet">
<?= brand_style() ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="bg-[#e9edf2] print:bg-white <?= e($bodyClass ?? '') ?>">
<div class="mx-auto flex max-w-[820px] flex-wrap items-center gap-2.5 px-4 pb-0 pt-4 print:hidden">
  <?= \App\Core\View::section('toolbar') ?>
</div>
<?= $content ?>
<?= \App\Core\View::section('after_main') ?>
</body>
</html>
