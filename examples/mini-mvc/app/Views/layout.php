<?php /** โครงหน้ากลาง: ทุกหน้าถูกครอบด้วยไฟล์นี้ ($content คือเนื้อหาของหน้านั้น) */ ?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เช่ารถ (ตัวอย่าง MVC)</title>
  <style>
    body { font-family: sans-serif; max-width: 760px; margin: 0 auto; padding: 16px; color: #222; }
    a { color: #1f5fbf; } .card { border: 1px solid #ddd; border-radius: 10px; padding: 14px; margin: 10px 0; }
    .flash { background: #fff3cd; border: 1px solid #ffe08a; padding: 10px; border-radius: 8px; margin: 10px 0; }
    label { display: block; margin-top: 10px; } input { width: 100%; padding: 8px; box-sizing: border-box; }
    button { margin-top: 14px; padding: 10px 18px; background: #1f5fbf; color: #fff; border: 0; border-radius: 8px; cursor: pointer; }
  </style>
</head>
<body>
  <h1><a href="/" style="text-decoration:none">🚗 เช่ารถ</a></h1>
  <?php if ($message = flash()): ?>
    <div class="flash"><?= e($message) ?></div>
  <?php endif; ?>
  <?= $content ?>
</body>
</html>
