<?php
/**
 * วิธีการเช่า — ขั้นตอน เอกสารที่ต้องใช้ เงื่อนไขย่อ
 * ตัวแปร: late (ข้อความเงื่อนไขคืนรถช้า)
 */
$this->layout('layouts/public', [
    'title'  => 'วิธีการเช่า',
    'desc'   => 'ขั้นตอนการเช่ารถ เอกสารที่ต้องใช้ และเงื่อนไขการรับ–คืนรถ',
    'active' => 'how-to',
]);

$steps = [
    ['calendar',  'เลือกวันเวลา',     'ระบุวันรับ–คืนรถ และเลือกประเภทรถที่ต้องการ'],
    ['search',    'ค้นหารถว่าง',      'ระบบจะแสดงรถที่พร้อมให้บริการ พร้อมราคาทันที'],
    ['clipboard', 'ยืนยันการจอง',     'กรอกข้อมูลและโอนมัดจำ พร้อมอัปโหลดสลิปในระบบ'],
    ['car',       'รับและออกเดินทาง', 'รับรถตามวันเวลาที่เลือก พร้อมเอกสารการเช่า'],
];
$fuelFee = \App\Services\PricingService::fuelFeePerLevel();
?>
<section class="bg-gradient-to-b from-[#eaf2fc] to-surface py-7">
  <div class="wrap">
    <div class="mb-[5px] text-[0.72rem] font-extrabold uppercase tracking-[0.14em] text-accent-600">How it works</div>
    <h1 class="mb-1">วิธีการเช่ารถ</h1>
    <p class="mb-0 text-muted">จองออนไลน์ได้ภายในไม่กี่นาที พร้อมออกเดินทาง</p>
  </div>
</section>

<section class="py-9 sm:py-11 lg:py-14">
  <div class="wrap">
    <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
      <?php foreach ($steps as $i => [$ic, $t, $d]): ?>
        <div class="flex items-start gap-3 rounded-xl border border-line-2 bg-surface p-3.5">
          <span class="grid h-[30px] w-[30px] flex-none place-items-center rounded-[9px] bg-accent text-[0.86rem] font-extrabold text-brand-700"><?= $i + 1 ?></span>
          <div><h4 class="mb-[3px] text-[0.93rem]"><?= e($t) ?></h4><p class="m-0 text-[0.82rem] leading-relaxed text-ink-2"><?= e($d) ?></p></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="mt-6 grid gap-3.5 sm:grid-cols-2 sm:gap-4">
      <div class="panel">
        <h3><?= icon('doc') ?> เอกสารที่ต้องเตรียม</h3>
        <h4 class="mt-4 text-sm text-brand">บุคคลธรรมดา</h4>
        <ul class="text-sm">
          <li>บัตรประชาชนตัวจริง</li>
          <li>ใบขับขี่ตัวจริง (ยังไม่หมดอายุ)</li>
          <li>อายุ 21 ปีขึ้นไป มีใบขับขี่ไม่น้อยกว่า 1 ปี</li>
        </ul>
        <h4 class="mt-4 text-sm text-brand">นิติบุคคล</h4>
        <ul class="mb-0 text-sm">
          <li>หนังสือรับรองบริษัท อายุไม่เกิน 6 เดือน</li>
          <li>บัตรประชาชนผู้มีอำนาจลงนาม</li>
          <li>บัตรประชาชน + ใบขับขี่ของผู้ขับขี่</li>
        </ul>
      </div>

      <div class="panel">
        <h3><?= icon('money') ?> การชำระเงิน</h3>
        <ul class="text-sm">
          <li><b>มัดจำการจอง <?= money0(setting('booking_deposit', '1000')) ?> บาท</b> — โอนเพื่อล็อกรถ หักจากค่าเช่า ไม่ใช่เงินเพิ่ม</li>
          <li><b>ค่าเช่าส่วนที่เหลือ</b> — ชำระในวันรับรถ</li>
          <li><b>เงินประกันความเสียหาย</b> — วางในวันรับรถ (อีโคคาร์ 3,000 · เก๋ง/กระบะ 5,000 · SUV/ตู้ 10,000 บาท)
            คืนเต็มจำนวนภายใน 3–7 วันทำการหลังคืนรถ หากไม่มีความเสียหาย</li>
        </ul>
        <div class="bank mt-4">
          <div class="mb-[9px] text-[0.88rem] font-bold text-brand">บัญชีรับโอน</div>
          <dl class="dl">
            <dt>ธนาคาร</dt><dd><?= e(setting('bank_name')) ?></dd>
            <dt>เลขที่</dt><dd><?= e(setting('bank_account_no')) ?></dd>
            <dt>ชื่อบัญชี</dt><dd class="text-[0.84rem]"><?= e(setting('bank_account_name')) ?></dd>
            <?php if (setting('promptpay_id')): ?><dt>PromptPay</dt><dd><?= e(setting('promptpay_id')) ?></dd><?php endif; ?>
          </dl>
        </div>
      </div>
    </div>

    <div class="mt-4 grid gap-3.5 sm:grid-cols-2 sm:gap-4">
      <div class="panel">
        <h3><?= icon('clock') ?> การรับ–คืนรถ</h3>
        <ul class="mb-0 text-sm">
          <li>รับ–คืนตามวันเวลาที่ระบุ <?= e($late['grace']) ?></li>
          <li><?= e($late['fee']) ?></li>
          <li>ส่งมอบน้ำมันระดับใด ต้องคืนระดับเดียวกัน<?= $fuelFee > 0 ? ' ส่วนที่ขาดคิดขีดละ ' . money0($fuelFee) . ' บาท' : '' ?></li>
          <li>มีบริการส่ง–รับรถถึงที่ (มีค่าบริการตามระยะทาง)</li>
          <li>ระยะทาง<?= (int) setting('free_km_per_day', '0') > 0
                ? 'ฟรี ' . money0(setting('free_km_per_day')) . ' กม./วัน'
                : 'ไม่จำกัด' ?></li>
        </ul>
      </div>

      <div class="panel">
        <h3><?= icon('shield') ?> ประกันและความคุ้มครอง</h3>
        <ul class="mb-0 text-sm">
          <li>รถทุกคันมีประกันภัยชั้น 1</li>
          <li>กรณีเกิดอุบัติเหตุ แจ้งบริษัทและบริษัทประกันทันที ห้ามเคลื่อนย้ายรถก่อนเจ้าหน้าที่ถึงที่เกิดเหตุ</li>
          <li>เป็นฝ่ายผิดหรือไม่มีคู่กรณี ผู้เช่ารับผิดชอบค่าเสียหายส่วนแรกตามกรมธรรม์</li>
          <li>สามารถซื้อ<b>ประกันเสริม</b>เพื่อลดค่าเสียหายส่วนแรกได้ตอนจอง</li>
        </ul>
      </div>
    </div>

    <div class="mt-9 text-center">
      <a class="btn btn-lg" href="<?= url('cars') ?>"><?= icon('search') ?> เริ่มค้นหารถว่าง</a>
      <p class="mb-0 mt-4 text-sm text-muted">มีคำถามเพิ่มเติม? ดู<a href="<?= url('faq') ?>">คำถามที่พบบ่อย</a>
        หรือโทร <a href="tel:<?= e(preg_replace('/\D/', '', setting('phone'))) ?>"><?= e(setting('phone')) ?></a></p>
    </div>
  </div>
</section>
