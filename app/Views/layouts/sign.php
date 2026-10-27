<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <title><?= e($title ?? '') ?> · <?= e(config('app_name')) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <?= brand_style() ?>
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
  <main class="max-w-2xl mx-auto px-4 py-6">
    <?php require __DIR__ . '/../partials_flash.php'; ?>
    <?= $content ?>
  </main>
</body>
</html>
