<?php
/**
 * ติดต่อเรา
 * ตัวแปร: phoneRaw
 */
\App\Core\View::layout('layouts/public', [
    'title'  => 'ติดต่อเรา',
    'desc'   => 'ติดต่อสอบถามบริการรถเช่า ' . setting('site_name'),
    'active' => 'contact',
]);
?>
<section class="bg-gradient-to-b from-[#eaf2fc] to-surface py-7">
  <div class="wrap">
    <div class="eyebrow">Contact</div>
    <h1 class="mb-1">ติดต่อเรา</h1>
    <p class="mb-0 text-muted">ทีมงานพร้อมให้บริการทุกวัน
      <?= e(setting('open_time')) ?> - <?= e(setting('close_time')) ?> น.</p>
  </div>
</section>

<section class="py-9 sm:py-11 lg:py-14">
  <div class="wrap">
    <!-- ช่องทางด่วน -->
    <div class="grid gap-3.5 sm:grid-cols-3 sm:gap-4">
      <a class="panel text-center" href="tel:<?= e($phoneRaw) ?>">
        <div class="ic-box mx-auto mb-3 rounded-full"><?= icon('phone') ?></div>
        <h3 class="mb-0.5">โทรหาเรา</h3>
        <p class="mb-0 text-[1.1rem] font-bold text-brand"><?= e(setting('phone')) ?></p>
        <?php if (setting('phone2')): ?>
          <p class="mb-0 text-sm text-muted"><?= e(setting('phone2')) ?></p>
        <?php endif; ?>
      </a>

      <?php if (setting('line_id')): ?>
        <div class="panel text-center">
          <div class="ic-box mx-auto mb-3 rounded-full bg-[#e8f8ed] text-[#06c755]"><?= icon('line') ?></div>
          <h3 class="mb-0.5">LINE Official</h3>
          <p class="mb-0 text-[1.1rem] font-bold text-[#06c755]"><?= e(setting('line_id')) ?></p>
          <p class="mb-0 text-sm text-muted">แอดไลน์สอบถามได้ตลอด</p>
        </div>
      <?php endif; ?>

      <?php if (setting('email')): ?>
        <a class="panel text-center" href="mailto:<?= e(setting('email')) ?>">
          <div class="ic-box mx-auto mb-3 rounded-full bg-accent-50 text-accent"><?= icon('mail') ?></div>
          <h3 class="mb-0.5">อีเมล</h3>
          <p class="mb-0 break-all text-sm font-semibold"><?= e(setting('email')) ?></p>
          <p class="mb-0 text-sm text-muted">ตอบกลับภายใน 1 วันทำการ</p>
        </a>
      <?php endif; ?>
    </div>

    <div class="main-side-grid">
      <div class="panel min-w-0">
        <h3>ข้อมูลบริษัท</h3>
        <div class="table-wrap">
          <table class="table">
            <tbody>
              <?php if (setting('company_name')): ?>
                <tr><th class="w-[36%]">บริษัท</th><td><?= e(setting('company_name')) ?></td></tr>
              <?php endif; ?>
              <?php if (setting('company_tax_id')): ?>
                <tr><th>เลขผู้เสียภาษี</th><td><?= e(setting('company_tax_id')) ?></td></tr>
              <?php endif; ?>
              <?php if (setting('company_address')): ?>
                <tr><th>ที่อยู่</th><td><?= nl2br(e(setting('company_address'))) ?></td></tr>
              <?php endif; ?>
              <tr><th>โทรศัพท์</th><td><a href="tel:<?= e($phoneRaw) ?>"><?= e(setting('phone')) ?></a>
                <?php if (setting('phone2')): ?> · <?= e(setting('phone2')) ?><?php endif; ?></td></tr>
              <?php if (setting('office_phone')): ?>
                <tr><th>สำนักงาน</th><td><?= e(setting('office_phone')) ?></td></tr>
              <?php endif; ?>
              <?php if (setting('facebook')): ?>
                <tr><th>Facebook</th><td><?= e(setting('facebook')) ?></td></tr>
              <?php endif; ?>
              <tr><th>เวลาทำการ</th><td>ทุกวัน <?= e(setting('open_time')) ?> – <?= e(setting('close_time')) ?> น.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="min-w-0">
        <div class="panel border-brand-100 bg-brand-50">
          <h3>พร้อมจองแล้ว?</h3>
          <p class="text-sm text-muted">เลือกวันรับ–คืนรถ ระบบจะแสดงเฉพาะรถที่ว่างจริงพร้อมราคาสุทธิทันที</p>
          <a class="btn btn-block" href="<?= url('/cars') ?>"><?= icon('search') ?> ดูรถว่างและจอง</a>
          <a class="btn btn-ghost btn-block mt-4" href="<?= url('/track') ?>">
            <?= icon('clipboard') ?> ตรวจสอบการจองเดิม</a>
        </div>

        <div class="panel mt-4">
          <h3 class="text-sm">ลิงก์ที่เป็นประโยชน์</h3>
          <div class="flex flex-col gap-2.5">
            <a href="<?= url('/how-to') ?>">วิธีการเช่ารถ</a>
            <a href="<?= url('/faq') ?>">คำถามที่พบบ่อย</a>
            <a href="<?= url('/terms') ?>">เงื่อนไขการเช่า</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
