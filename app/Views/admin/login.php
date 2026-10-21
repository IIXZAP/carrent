<?php
/** เข้าสู่ระบบหลังบ้าน */
$this->layout('layouts/admin_login', ['title' => $title]);
?>
<?= brand_logo('full', 'mx-auto mb-[18px] block h-auto w-[min(230px,80%)]') ?>
<p class="mb-4 text-center text-sm text-muted">ระบบจัดการรถเช่า — สำหรับเจ้าหน้าที่</p>
<?= $this->partial('partials/flash') ?>
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
<p class="mb-0 mt-4 text-center text-xs text-muted"><a href="<?= url('') ?>">← กลับหน้าเว็บลูกค้า</a></p>
