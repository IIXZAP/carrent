<?php
/**
 * คำถามที่พบบ่อย — accordion ใช้ <details> ไม่ต้องใช้ JS
 * ตัวแปร: faqs (รายการ [คำถาม, คำตอบ])
 */
$this->layout('layouts/public', [
    'title'  => 'คำถามที่พบบ่อย',
    'desc'   => 'รวมคำถามที่ลูกค้าถามบ่อยเกี่ยวกับการเช่ารถ เอกสาร เงินประกัน และเงื่อนไข',
    'active' => 'faq',
]);
?>
<section class="bg-gradient-to-b from-[#eaf2fc] to-surface py-7">
  <div class="wrap">
    <div class="eyebrow">FAQ</div>
    <h1 class="mb-1">คำถามที่พบบ่อย</h1>
    <p class="mb-0 text-muted">รวมเรื่องที่ลูกค้าถามบ่อยที่สุด ถ้าไม่พบคำตอบ โทรถามเราได้เลย</p>
  </div>
</section>

<section class="py-9 sm:py-11 lg:py-14">
  <div class="wrap">
    <div class="panel !px-[18px] !py-1.5">
      <?php foreach ($faqs as $i => [$q, $a]): ?>
        <details class="group border-b border-line-2 last:border-b-0"<?= $i === 0 ? ' open' : '' ?>>
          <summary class="summary-plain relative cursor-pointer py-[15px] pr-[30px] font-semibold leading-normal text-dark">
            <?= e($q) ?>
            <span class="absolute right-0 top-[15px] inline-block text-[1.2rem] font-normal leading-[1.2] text-brand transition-transform group-open:rotate-45">+</span>
          </summary>
          <p class="m-0 pb-4 text-sm leading-[1.7] text-muted"><?= e($a) ?></p>
        </details>
      <?php endforeach; ?>
    </div>

    <div class="panel mt-6 border-brand-100 bg-brand-50 text-center">
      <h3>ยังไม่พบคำตอบที่ต้องการ?</h3>
      <p class="text-sm text-muted">ทีมงานพร้อมให้คำแนะนำทุกวัน
        <?= e(setting('open_time')) ?> - <?= e(setting('close_time')) ?> น.</p>
      <div class="flex flex-wrap justify-center gap-2.5">
        <a class="btn" href="tel:<?= e(preg_replace('/\D/', '', setting('phone'))) ?>">
          <?= icon('phone') ?> <?= e(setting('phone')) ?></a>
        <a class="btn btn-ghost" href="<?= url('contact') ?>">ช่องทางติดต่ออื่น</a>
      </div>
    </div>
  </div>
</section>
