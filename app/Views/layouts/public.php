<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') . e(config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="bg-slate-50 text-slate-800 antialiased">
  <header class="bg-white border-b border-slate-200">
    <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
      <a href="<?= e(url('/')) ?>" class="font-semibold text-brand-700"><?= e(config('app_name')) ?></a>
      <nav class="flex items-center gap-4 text-sm text-slate-500">
        <a href="<?= e(url('/track')) ?>" class="hover:text-slate-800">ตรวจสอบการจอง</a>
        <a href="<?= e(url('/admin')) ?>" class="hover:text-slate-800">หลังบ้าน</a>
      </nav>
    </div>
  </header>
  <main class="max-w-5xl mx-auto px-4 py-8">
    <?php require __DIR__ . '/../partials_flash.php'; ?>
    <?= $content ?>
  </main>
</body>
</html>
