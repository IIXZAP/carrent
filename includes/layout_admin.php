<?php
/**
 * Layout หลังบ้าน
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/icons.php';

function admin_head(string $title, string $active = ''): void
{
    $u    = auth_user();
    $site = setting('site_name', 'thonglorcarrent');

    // ตัวเลขแจ้งเตือนบนเมนู
    $cntPending = (int) db_val("SELECT COUNT(*) FROM bookings WHERE status = 'pending'");
    $cntSlip    = (int) db_val("SELECT COUNT(*) FROM payments WHERE status = 'pending'");
    $cntMaint   = (int) db_val(
        "SELECT COUNT(*) FROM maintenances WHERE status = 'pending' AND due_date IS NOT NULL AND due_date <= :d",
        [':d' => date('Y-m-d', strtotime('+' . (int) setting('reminder_days_before', '30') . ' days'))]
    );

    // องค์ประกอบที่ 5 = true → เห็นเฉพาะเจ้าของร้าน (role admin)
    $menu = [
        'งานประจำวัน' => [
            ['dashboard.php', '📊', 'ภาพรวม', 0],
            ['bookings.php',  '📅', 'การจอง', $cntPending],
            ['calendar.php',  '🗓️', 'ปฏิทินรถ', 0],
            ['payments.php',  '💰', 'ชำระเงิน', $cntSlip],
        ],
        'ข้อมูลหลัก' => [
            ['cars.php',      '🚗', 'รถ', 0],
            ['customers.php', '👤', 'ลูกค้า', 0],
            ['maintenance.php','🔧', 'งานประจำรถ', $cntMaint],
        ],
        'เอกสาร & รายงาน' => [
            ['documents.php', '📄', 'ใบเสนอราคา/ใบเสร็จ', 0],
            ['reports.php',   '📈', 'รายงาน', 0, true],
            ['monthly-report.php', '🗒️', 'รายงานรายเดือน', 0, true],
            ['ab-report.php', '🆎', 'เทียบผล UX', 0, true],
        ],
        'ระบบ' => [
            ['line.php',      '💬', 'เชื่อมต่อ LINE', 0, true],
            ['settings.php',  '⚙️', 'ตั้งค่า', 0, true],
            ['users.php',     '🔑', 'ผู้ใช้งาน', 0, true],
            ['backups.php',   '🗄️', 'สำรองข้อมูล', 0, true],
            ['health.php',    '🩺', 'สุขภาพระบบ', 0, true],
        ],
    ];
    // พนักงานไม่เห็นเมนูของเจ้าของ — กลุ่มที่ไม่เหลือเมนูเลยก็ไม่ต้องแสดงหัวข้อ
    if (($u['role'] ?? '') !== 'admin') {
        foreach ($menu as $grp => $items) {
            $menu[$grp] = array_values(array_filter($items, fn($it) => empty($it[4])));
            if (!$menu[$grp]) {
                unset($menu[$grp]);
            }
        }
    }
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
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
<style>
:root{
  --brand:<?= e(setting('brand_primary', '#0f3d68')) ?>;
  --accent:<?= e(setting('brand_accent', '#e8a33d')) ?>;
  --dark:<?= e(setting('brand_dark', '#10202f')) ?>;
}
</style>
</head>
<body class="admin">
<div class="adm">
  <aside class="adm-side" id="side">
    <div class="adm-brand">
      <a href="<?= url('admin/dashboard.php') ?>"><?= brand_logo('lockup', 'adm-logo') ?></a>
      <small style="margin-left:0">ระบบจัดการรถเช่า</small>
    </div>
    <nav class="adm-nav">
      <?php foreach ($menu as $grp => $items): ?>
        <div class="grp"><?= e($grp) ?></div>
        <?php foreach ($items as [$file, $ic, $label, $cnt]): ?>
          <a class="<?= $active === $file ? 'on' : '' ?>" href="<?= url('admin/' . $file) ?>">
            <span class="ic"><?= $ic ?></span><?= e($label) ?>
            <?php if ($cnt > 0): ?><span class="cnt"><?= $cnt ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <div class="grp">อื่นๆ</div>
      <a href="<?= url('index.php') ?>" target="_blank"><span class="ic">🌐</span>ดูหน้าเว็บลูกค้า</a>
      <a href="<?= url('admin/logout.php') ?>"><span class="ic">🚪</span>ออกจากระบบ</a>
    </nav>
  </aside>

  <main class="adm-main">
    <div class="adm-top">
      <button class="burger no-print" onclick="document.getElementById('side').classList.toggle('open')">☰</button>
      <h1><?= e($title) ?></h1>
      <div class="spacer"></div>
      <div class="adm-user no-print">
        <span class="av"><?= e(mb_substr($u['name'] ?? '?', 0, 1)) ?></span>
        <span class="nm"><?= e($u['name'] ?? '') ?><br><span class="tiny muted"><?= $u['role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'เจ้าหน้าที่' ?></span></span>
      </div>
    </div>
    <div class="adm-body">
      <?= flash_render() ?>
<?php
}

function admin_foot(): void
{
    ?>
    </div>
  </main>
</div>
<script src="<?= e(asset('assets/js/upload-shrink.js')) ?>" defer></script>
</body>
</html>
<?php
}
