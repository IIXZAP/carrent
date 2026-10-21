<?php
/** หน้า /check เมื่อระบบถูกใช้งานแล้วและผู้เปิดไม่ใช่เจ้าของร้านที่เข้าสู่ระบบ */
$this->layout('layouts/focus', ['title' => $title, 'heading' => $heading, 'narrow' => $narrow]);
?>
<div class="panel">
  <p class="m-0">หน้าตรวจการติดตั้งเปิดได้เฉพาะเจ้าของร้านที่เข้าสู่ระบบอยู่
    <a href="<?= url('admin/login') ?>">เข้าสู่ระบบหลังบ้าน</a></p>
</div>
