<?php
/**
 * วิธีการเช่า — ขั้นตอน เอกสารที่ต้องใช้ เงื่อนไขย่อ
 */
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/layout_public.php';

page_head('วิธีการเช่า', 'ขั้นตอนการเช่ารถ เอกสารที่ต้องใช้ และเงื่อนไขการรับ–คืนรถ',
          ['active' => 'how-to.php']);
?>

<section style="background:linear-gradient(170deg,#eaf2fc,var(--bg));padding:28px 0 26px">
  <div class="wrap">
    <div class="eyebrow">How it works</div>
    <h1 style="margin-bottom:.25em">วิธีการเช่ารถ</h1>
    <p class="muted mb-0">จองออนไลน์ได้ภายในไม่กี่นาที พร้อมออกเดินทาง</p>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <div class="how-grid">
      <?php
      $steps = [
          ['calendar',  'เลือกวันเวลา',    'ระบุวันรับ–คืนรถ และเลือกประเภทรถที่ต้องการ'],
          ['search',    'ค้นหารถว่าง',     'ระบบจะแสดงรถที่พร้อมให้บริการ พร้อมราคาทันที'],
          ['clipboard', 'ยืนยันการจอง',    'กรอกข้อมูลและโอนมัดจำ พร้อมอัปโหลดสลิปในระบบ'],
          ['car',       'รับและออกเดินทาง','รับรถตามวันเวลาที่เลือก พร้อมเอกสารการเช่า'],
      ];
      foreach ($steps as $i => [$ic, $t, $d]): ?>
        <div class="how-step">
          <span class="n"><?= $i + 1 ?></span>
          <div><h4><?= e($t) ?></h4><p><?= e($d) ?></p></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="g2 mt-3">
      <div class="panel">
        <h3><?= icon('doc') ?> เอกสารที่ต้องเตรียม</h3>
        <h4 class="small mt-2" style="color:var(--brand)">บุคคลธรรมดา</h4>
        <ul class="small">
          <li>บัตรประชาชนตัวจริง</li>
          <li>ใบขับขี่ตัวจริง (ยังไม่หมดอายุ)</li>
          <li>อายุ 21 ปีขึ้นไป มีใบขับขี่ไม่น้อยกว่า 1 ปี</li>
        </ul>
        <h4 class="small mt-2" style="color:var(--brand)">นิติบุคคล</h4>
        <ul class="small mb-0">
          <li>หนังสือรับรองบริษัท อายุไม่เกิน 6 เดือน</li>
          <li>บัตรประชาชนผู้มีอำนาจลงนาม</li>
          <li>บัตรประชาชน + ใบขับขี่ของผู้ขับขี่</li>
        </ul>
      </div>

      <div class="panel">
        <h3><?= icon('money') ?> การชำระเงิน</h3>
        <ul class="small">
          <li><b>มัดจำการจอง <?= money0(setting('booking_deposit','1000')) ?> บาท</b> — โอนเพื่อล็อกรถ หักจากค่าเช่า ไม่ใช่เงินเพิ่ม</li>
          <li><b>ค่าเช่าส่วนที่เหลือ</b> — ชำระในวันรับรถ</li>
          <li><b>เงินประกันความเสียหาย</b> — วางในวันรับรถ (อีโคคาร์ 3,000 · เก๋ง/กระบะ 5,000 · SUV/ตู้ 10,000 บาท)
            คืนเต็มจำนวนภายใน 3–7 วันทำการหลังคืนรถ หากไม่มีความเสียหาย</li>
        </ul>
        <div class="bank mt-2">
          <div class="bank-t">บัญชีรับโอน</div>
          <dl class="dl">
            <dt>ธนาคาร</dt><dd><?= e(setting('bank_name')) ?></dd>
            <dt>เลขที่</dt><dd><?= e(setting('bank_account_no')) ?></dd>
            <dt>ชื่อบัญชี</dt><dd style="font-size:.84rem"><?= e(setting('bank_account_name')) ?></dd>
            <?php if (setting('promptpay_id')): ?><dt>PromptPay</dt><dd><?= e(setting('promptpay_id')) ?></dd><?php endif; ?>
          </dl>
        </div>
      </div>
    </div>

    <div class="g2 mt-2">
      <div class="panel">
        <h3><?= icon('clock') ?> การรับ–คืนรถ</h3>
        <ul class="small mb-0">
          <?php $late = late_policy_text(); ?>
          <li>รับ–คืนตามวันเวลาที่ระบุ <?= e($late['grace']) ?></li>
          <li><?= e($late['fee']) ?></li>
          <li>ส่งมอบน้ำมันระดับใด ต้องคืนระดับเดียวกัน<?= fuel_fee_per_level() > 0 ? ' ส่วนที่ขาดคิดขีดละ ' . money0(fuel_fee_per_level()) . ' บาท' : '' ?></li>
          <li>มีบริการส่ง–รับรถถึงที่ (มีค่าบริการตามระยะทาง)</li>
          <li>ระยะทาง<?= (int) setting('free_km_per_day', '0') > 0
                ? 'ฟรี ' . money0(setting('free_km_per_day')) . ' กม./วัน'
                : 'ไม่จำกัด' ?></li>
        </ul>
      </div>

      <div class="panel">
        <h3><?= icon('shield') ?> ประกันและความคุ้มครอง</h3>
        <ul class="small mb-0">
          <li>รถทุกคันมีประกันภัยชั้น 1</li>
          <li>กรณีเกิดอุบัติเหตุ แจ้งบริษัทและบริษัทประกันทันที ห้ามเคลื่อนย้ายรถก่อนเจ้าหน้าที่ถึงที่เกิดเหตุ</li>
          <li>เป็นฝ่ายผิดหรือไม่มีคู่กรณี ผู้เช่ารับผิดชอบค่าเสียหายส่วนแรกตามกรมธรรม์</li>
          <li>สามารถซื้อ<b>ประกันเสริม</b>เพื่อลดค่าเสียหายส่วนแรกได้ตอนจอง</li>
        </ul>
      </div>
    </div>

    <div class="center mt-4">
      <a class="btn btn-lg" href="<?= url('cars.php') ?>"><?= icon('search') ?> เริ่มค้นหารถว่าง</a>
      <p class="small muted mt-2 mb-0">มีคำถามเพิ่มเติม? ดู<a href="<?= url('faq.php') ?>">คำถามที่พบบ่อย</a>
        หรือโทร <a href="tel:<?= e(preg_replace('/\D/', '', setting('phone'))) ?>"><?= e(setting('phone')) ?></a></p>
    </div>
  </div>
</section>

<?php page_foot(); ?>
