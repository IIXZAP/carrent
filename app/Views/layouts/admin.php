<?php
/**
 * Layout หลังบ้าน — เมนูด้านข้าง + แถบหัวเรื่อง
 *
 * ตัวแปรที่รับจากหน้า: title, active (คีย์เมนู เช่น bookings), bare (true = ไม่แสดงเมนู/หัว ใช้กับหน้าพิมพ์)
 * ส่วนเสริม: top_actions (ปุ่มด้านขวาของแถบหัวเรื่อง), after_main (สคริปต์ท้ายหน้า)
 */
$site   = (string) setting('site_name', 'thonglorcarrent');
$u      = \App\Services\Auth::user() ?? ['name' => '', 'role' => 'staff'];
$active = $active ?? '';
if ($active === '') {
    // เมนูที่ตรงกับ URL ปัจจุบัน (/admin/bookings/12 → bookings)
    $seg = explode('/', trim(request_path(), '/'))[1] ?? '';
    $active = $seg === '' ? 'dashboard' : $seg;
}
$menu   = \App\Services\AdminNav::menu();
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> | <?= e($site) ?> Admin</title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= url('assets/img/favicon-32.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700;800&display=swap" rel="stylesheet">
<?= brand_style() ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="bg-[#f2f4f8] print:bg-white">
<div class="flex min-h-screen">

  <aside id="side" class="fixed inset-y-0 left-0 z-40 flex w-[236px] -translate-x-full flex-col overflow-y-auto bg-dark text-white/75 transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 print:hidden [&.open]:translate-x-0">
    <div class="border-b border-white/10 px-[18px] pb-3.5 pt-[18px]">
      <a class="flex items-center gap-2 text-[1.05rem] font-extrabold text-white no-underline hover:no-underline" href="<?= url('/admin') ?>">
        <?= brand_logo('lockup', 'h-[26px] w-auto max-w-full opacity-95 brightness-0 invert') ?>
      </a>
      <small class="mt-1 block text-[0.72rem] text-white/45">ระบบจัดการรถเช่า</small>
    </div>
    <nav class="flex-1 px-2.5 pb-6 pt-2.5">
      <?php foreach ($menu as $group => $items): ?>
        <div class="side-heading"><?= e($group) ?></div>
        <?php foreach ($items as $it): ?>
          <a class="mb-0.5 flex items-center gap-2.5 rounded-lg px-[11px] py-[9px] text-[0.92rem] no-underline hover:bg-white/10 hover:text-white <?= $active === $it['key'] ? 'bg-brand font-semibold text-white' : 'text-white/75' ?>"
             href="<?= url($it['path']) ?>">
            <span class="w-[18px] flex-none text-center opacity-90"><?= $it['icon'] ?></span><?= e($it['label']) ?>
            <?php if ($it['count'] > 0): ?>
              <span class="ml-auto rounded-full bg-accent px-[7px] text-[0.72rem] font-extrabold text-dark"><?= (int) $it['count'] ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <div class="side-heading">อื่นๆ</div>
      <a class="side-link" href="<?= url('') ?>" target="_blank" rel="noopener">
        <span class="w-[18px] flex-none text-center opacity-90">🌐</span>ดูหน้าเว็บลูกค้า</a>
      <form method="post" action="<?= e(url('/admin/logout')) ?>">
        <?= csrf_field() ?>
        <button class="side-link w-full cursor-pointer border-0 bg-transparent text-left"><span class="w-[18px] flex-none text-center opacity-90">🚪</span>ออกจากระบบ</button>
      </form>
    </nav>
  </aside>

  <main class="flex min-w-0 flex-1 flex-col">
    <div class="sticky top-0 z-30 flex h-[62px] items-center gap-4 border-b border-line bg-white px-4 sm:px-6 print:hidden">
      <button type="button" class="cursor-pointer rounded-lg border border-line bg-transparent px-2.5 py-1.5 text-[1.1rem] lg:hidden" aria-label="เมนู"
              onclick="document.getElementById('side').classList.toggle('open')">☰</button>
      <h1 class="m-0 truncate text-[1.15rem] sm:text-[1.15rem]"><?= e($title) ?></h1>
      <div class="ml-auto flex items-center gap-3">
        <?= \App\Core\View::section('top_actions') ?>
        <div class="flex items-center gap-[9px] text-[0.9rem] text-ink-2">
          <span class="grid h-8 w-8 place-items-center rounded-full bg-brand text-[0.85rem] font-bold text-white"><?= e(mb_substr((string) $u['name'], 0, 1)) ?></span>
          <span class="hidden leading-tight sm:block"><?= e($u['name']) ?><br>
            <span class="text-[0.74rem] text-muted"><?= $u['role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'เจ้าหน้าที่' ?></span></span>
        </div>
      </div>
    </div>
    <div class="flex-1 p-4 sm:p-6">
      <?php require __DIR__ . '/../partials_flash.php'; ?>
      <?= $content ?>
    </div>
  </main>
</div>
<script src="<?= e(asset('js/upload-shrink.js')) ?>" defer></script>
<?= \App\Core\View::section('after_main') ?>
</body>
</html>
