<?php \App\Core\View::layout('layouts/public', ['title' => 'ตรวจสอบการจอง', 'active' => 'track', 'noindex' => true]); ?>
<section class="bg-gradient-to-b from-[#eaf2fc] to-surface py-7">
  <div class="wrap">
    <div class="eyebrow">Track</div>
    <h1 class="mb-1">ตรวจสอบการจอง</h1>
    <p class="mb-0 text-muted">กรอกเลขที่จองและเบอร์โทรที่ใช้จอง เพื่อดูสถานะและแจ้งโอนเงิน</p>
  </div>
</section>
<div class="wrap max-w-[480px] py-8 pb-12">
  <form method="post" action="<?= e(url('/track')) ?>" class="panel">
    <?= csrf_field() ?>
    <div class="field"><label for="booking_no">เลขที่จอง</label>
      <input id="booking_no" name="booking_no" value="<?= e($no) ?>" placeholder="BK261026-001" required autocomplete="off"></div>
    <div class="field"><label for="phone">เบอร์โทรที่ใช้จอง</label>
      <input id="phone" name="phone" type="tel" required inputmode="tel"></div>
    <button class="btn btn-block btn-lg mt-2"><?= icon('search') ?> ตรวจสอบ</button>
  </form>
</div>
