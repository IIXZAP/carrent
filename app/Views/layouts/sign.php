<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <title><?= e($title ?? '') ?> · <?= e(config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="bg-slate-50 text-slate-800 antialiased">
  <main class="max-w-2xl mx-auto px-4 py-6">
    <?php require __DIR__ . '/../partials_flash.php'; ?>
    <?= $content ?>
  </main>
</body>
</html>
