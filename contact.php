<?php
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/layout_public.php';

$phoneRaw = preg_replace('/\D/', '', setting('phone'));
page_head('ติดต่อเรา', 'ติดต่อสอบถามบริการรถเช่า ' . setting('site_name'), ['active' => 'contact.php']);
?>

<section style="background:linear-gradient(170deg,#eaf2fc,var(--bg));padding:28px 0 26px">
  <div class="wrap">
    <div class="eyebrow">Contact</div>
    <h1 style="margin-bottom:.25em">ติดต่อเรา</h1>
    <p class="muted mb-0">ทีมงานพร้อมให้บริการทุกวัน
      <?= e(setting('open_time')) ?> - <?= e(setting('close_time')) ?> น.</p>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <!-- ช่องทางด่วน -->
    <div class="g3">
      <a class="panel" href="tel:<?= e($phoneRaw) ?>" style="text-align:center">
        <div class="ic-box ic-round" style="margin:0 auto 12px"><?= icon('phone') ?></div>
        <h3 style="margin-bottom:2px">โทรหาเรา</h3>
        <p class="mb-0" style="font-size:1.1rem;font-weight:700;color:var(--brand)"><?= e(setting('phone')) ?></p>
        <?php if (setting('phone2')): ?>
          <p class="small muted mb-0"><?= e(setting('phone2')) ?></p>
        <?php endif; ?>
      </a>

      <?php if (setting('line_id')): ?>
        <div class="panel" style="text-align:center">
          <div class="ic-box ic-round" style="background:#e8f8ed;color:#06c755;margin:0 auto 12px"><?= icon('line') ?></div>
          <h3 style="margin-bottom:2px">LINE Official</h3>
          <p class="mb-0" style="font-size:1.1rem;font-weight:700;color:#06c755"><?= e(setting('line_id')) ?></p>
          <p class="small muted mb-0">แอดไลน์สอบถามได้ตลอด</p>
        </div>
      <?php endif; ?>

      <?php if (setting('email')): ?>
        <a class="panel" href="mailto:<?= e(setting('email')) ?>" style="text-align:center">
          <div class="ic-box ic-round" style="background:var(--accent-50);color:var(--accent);margin:0 auto 12px"><?= icon('mail') ?></div>
          <h3 style="margin-bottom:2px">อีเมล</h3>
          <p class="mb-0 small" style="font-weight:600;word-break:break-all"><?= e(setting('email')) ?></p>
          <p class="small muted mb-0">ตอบกลับภายใน 1 วันทำการ</p>
        </a>
      <?php endif; ?>
    </div>

    <div class="split mt-3">
      <div class="panel">
        <h3>ข้อมูลบริษัท</h3>
        <div class="table-wrap">
          <table class="table">
            <tbody>
              <?php if (setting('company_name')): ?>
                <tr><th style="width:36%">บริษัท</th><td><?= e(setting('company_name')) ?></td></tr>
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

      <div>
        <div class="panel" style="background:var(--brand-50);border-color:var(--brand-100)">
          <h3>พร้อมจองแล้ว?</h3>
          <p class="small muted">เลือกวันรับ–คืนรถ ระบบจะแสดงเฉพาะรถที่ว่างจริงพร้อมราคาสุทธิทันที</p>
          <a class="btn btn-block" href="<?= url('cars.php') ?>"><?= icon('search') ?> ดูรถว่างและจอง</a>
          <a class="btn btn-ghost btn-block mt-2" href="<?= url('track.php') ?>">
            <?= icon('clipboard') ?> ตรวจสอบการจองเดิม</a>
        </div>

        <div class="panel mt-2">
          <h3 class="small">ลิงก์ที่เป็นประโยชน์</h3>
          <div class="foot-links" style="gap:10px">
            <a href="<?= url('how-to.php') ?>">วิธีการเช่ารถ</a>
            <a href="<?= url('faq.php') ?>">คำถามที่พบบ่อย</a>
            <a href="<?= url('terms.php') ?>">เงื่อนไขการเช่า</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<?php page_foot(); ?>
