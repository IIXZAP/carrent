<?php
/**
 * หน้าไม่พบ (404)
 * ตัวแปร: title, message (ไม่บังคับ), backUrl / backLabel (ไม่บังคับ)
 */
$this->layout('layouts/public', ['title' => $title ?? 'ไม่พบหน้าที่ต้องการ', 'noindex' => true]);
$message   = $message ?? 'หน้านี้อาจถูกย้ายหรือลบไปแล้ว';
$backUrl   = $backUrl ?? url('');
$backLabel = $backLabel ?? 'กลับหน้าแรก';
?>
<div class="wrap py-9 sm:py-11 lg:py-14">
  <div class="panel px-6 py-[52px] text-center">
    <div class="ic-box mx-auto mb-3 rounded-full"><?= icon('car') ?></div>
    <h1><?= e($title ?? 'ไม่พบหน้าที่ต้องการ') ?></h1>
    <p class="text-muted"><?= e($message) ?></p>
    <a class="btn" href="<?= e($backUrl) ?>"><?= e($backLabel) ?></a>
    <a class="btn btn-ghost" href="<?= url('cars') ?>">ดูรถทั้งหมด</a>
  </div>
</div>
