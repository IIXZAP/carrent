<?php $me = \App\Services\Auth::user(); ?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= e($title ?? '') ?> · หลังบ้าน</title>
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="bg-slate-50 text-slate-800 antialiased">
  <header class="bg-slate-900 text-white">
    <div class="max-w-6xl mx-auto px-4 h-14 flex items-center gap-6">
      <a href="<?= e(url('/admin')) ?>" class="font-semibold">หลังบ้าน</a>
      <nav class="flex gap-4 text-sm text-slate-300">
        <a href="<?= e(url('/admin')) ?>" class="hover:text-white">ภาพรวม</a>
        <a href="<?= e(url('/admin/bookings')) ?>" class="hover:text-white">การจอง</a>
        <a href="<?= e(url('/admin/cars')) ?>" class="hover:text-white">รถ</a>
      </nav>
      <div class="ml-auto flex items-center gap-3 text-sm">
        <span class="text-slate-300"><?= e($me['name'] ?? '') ?></span>
        <form method="post" action="<?= e(url('/admin/logout')) ?>">
          <?= csrf_field() ?>
          <button class="text-slate-300 hover:text-white">ออกจากระบบ</button>
        </form>
      </div>
    </div>
  </header>
  <main class="max-w-6xl mx-auto px-4 py-8">
    <?php require __DIR__ . '/../partials_flash.php'; ?>
    <?= $content ?>
  </main>
</body>
</html>
