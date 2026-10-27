<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= e($title ?? '') ?></title>
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <style>@page { size: A4; margin: 14mm; } @media print { .no-print { display: none !important; } body { background: #fff; } }</style>
</head>
<body class="bg-slate-100 text-slate-900 antialiased">
  <div class="no-print max-w-[210mm] mx-auto px-4 pt-4 flex gap-2">
    <button onclick="window.print()" class="btn btn-primary">พิมพ์ / บันทึกเป็น PDF</button>
    <button onclick="history.length > 1 ? history.back() : window.close()" class="btn">กลับ</button>
  </div>
  <main class="max-w-[210mm] mx-auto my-4 bg-white p-8 shadow print:shadow-none print:my-0 print:p-0 text-sm leading-relaxed">
    <?= $content ?>
  </main>
</body>
</html>
