<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/icons.php';

if (auth_check()) {
    redirect('admin/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $pass  = (string) ($_POST['password'] ?? '');
    if (auth_attempt($email, $pass)) {
        redirect('admin/dashboard.php');
    }
    if (empty($_SESSION['flash'])) {
        flash('danger', 'อีเมลหรือรหัสผ่านไม่ถูกต้อง');
    }
    redirect('admin/login.php');
}
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>เข้าสู่ระบบ | <?= e(setting('site_name', 'thonglorcarrent')) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= url('assets/img/favicon-32.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
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
<body>
<div class="login-wrap">
  <div class="login-card">
    <?= brand_logo('full', 'login-logo') ?>
    <p class="muted small center">ระบบจัดการรถเช่า — สำหรับเจ้าหน้าที่</p>
    <?= flash_render() ?>
    <form method="post" class="mt-2">
      <?= csrf_field() ?>
      <div class="field">
        <label for="email">อีเมล</label>
        <input type="email" id="email" name="email" required autofocus autocomplete="username">
      </div>
      <div class="field">
        <label for="password">รหัสผ่าน</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button class="btn btn-block">เข้าสู่ระบบ</button>
    </form>
    <p class="tiny muted center mt-3 mb-0"><a href="<?= url('index.php') ?>">← กลับหน้าเว็บลูกค้า</a></p>
  </div>
</div>
</body>
</html>
