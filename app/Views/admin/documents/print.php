<?php
/**
 * พิมพ์เอกสาร (พิมพ์จากเบราว์เซอร์ → Save as PDF ได้เลย)
 * ตัวแปร: doc, id, booking, items, typeLabel, typeEn
 */
$this->layout('layouts/admin_print', ['title' => $title]);
$trim = static fn($n): string => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
$th   = 'px-[11px] py-[9px] text-left text-[0.82rem] font-semibold bg-brand text-white print:[print-color-adjust:exact]';
?>
<?php $this->start('toolbar'); ?>
  <button class="btn" onclick="window.print()">🖨️ พิมพ์ / บันทึก PDF</button>
  <a class="btn btn-ghost" href="<?= url('admin/documents/' . $id . '/edit') ?>">แก้ไข</a>
  <a class="btn btn-ghost" href="<?= url('admin/documents') ?>">กลับรายการ</a>
<?php $this->end(); ?>

<div class="mx-auto my-4 max-w-[820px] bg-white px-[44px] py-10 shadow-card max-sm:px-5 max-sm:py-6 print:my-0 print:max-w-full print:p-0 print:shadow-none">
  <div class="mb-[22px] flex justify-between gap-6 border-b-2 border-brand pb-[18px] max-sm:flex-col">
    <div>
      <?= brand_logo('full', 'mb-2 h-[52px] w-auto') ?>
      <p class="mb-0 text-[0.86rem] leading-relaxed text-ink-2">
        <?= e(setting('company_name')) ?><br>
        <?= nl2br(e(setting('company_address'))) ?><br>
        โทร <?= e(setting('phone')) ?><?= setting('phone2') ? ', ' . e(setting('phone2')) : '' ?><br>
        <?= setting('company_tax_id') ? 'เลขประจำตัวผู้เสียภาษี ' . e(setting('company_tax_id')) : '' ?>
      </p>
    </div>
    <div class="min-w-[190px] text-right text-[0.88rem] max-sm:text-left">
      <h3 class="mb-2 text-[1.3rem] text-dark"><?= e($typeLabel[$doc['doc_type']]) ?></h3>
      <div class="mb-2 text-xs text-muted"><?= e($typeEn[$doc['doc_type']]) ?></div>
      <dl class="m-0 grid grid-cols-[auto_auto] justify-end gap-x-2.5 gap-y-[3px] max-sm:justify-start">
        <dt class="text-muted">เลขที่</dt><dd class="m-0 font-semibold"><?= e($doc['doc_no']) ?></dd>
        <dt class="text-muted">วันที่</dt><dd class="m-0 font-semibold"><?= e(thai_date($doc['issue_date'], false, true)) ?></dd>
        <?php if ($doc['due_date']): ?>
          <dt class="text-muted"><?= $doc['doc_type'] === 'quotation' ? 'ยืนราคาถึง' : 'ครบกำหนด' ?></dt>
          <dd class="m-0 font-semibold"><?= e(thai_date($doc['due_date'], false, true)) ?></dd>
        <?php endif; ?>
        <?php if ($booking): ?><dt class="text-muted">อ้างอิง</dt><dd class="m-0 font-semibold"><?= e($booking['booking_no']) ?></dd><?php endif; ?>
      </dl>
    </div>
  </div>

  <div class="mb-[22px] grid gap-6 text-[0.9rem] sm:grid-cols-2">
    <div class="note-box">
      <b class="mb-[5px] block text-[0.76rem] uppercase tracking-wide text-muted">ผู้ให้เช่า</b>
      <?= e(setting('company_name') ?: setting('site_name')) ?><br>
      โทร <?= e(setting('phone')) ?>
    </div>
    <div class="note-box">
      <b class="mb-[5px] block text-[0.76rem] uppercase tracking-wide text-muted">ผู้เช่า / ลูกค้า</b>
      <?= e($doc['bill_name']) ?><br>
      <?= $doc['bill_tax_id'] ? 'เลขผู้เสียภาษี ' . e($doc['bill_tax_id']) . '<br>' : '' ?>
      <?= nl2br(e($doc['bill_address'])) ?>
    </div>
  </div>

  <?php if ($booking): ?>
    <p class="rounded-lg bg-surface px-3.5 py-2.5 text-sm print:[print-color-adjust:exact]">
      <b>รถ:</b> <?= e($booking['brand'] . ' ' . $booking['model']) ?> ทะเบียน <?= e($booking['plate']) ?> ·
      <b>ช่วงเช่า:</b> <?= e(thai_date($booking['pickup_at'], true)) ?> – <?= e(thai_date($booking['return_at'], true)) ?>
      (<?= (int) $booking['rental_days'] ?> วัน)
    </p>
  <?php endif; ?>

  <table class="mb-[18px] w-full border-collapse text-[0.9rem]">
    <thead>
      <tr><th class="<?= $th ?> w-11">#</th><th class="<?= $th ?>">รายละเอียด</th><th class="<?= $th ?> w-20 !text-right">จำนวน</th>
          <th class="<?= $th ?> w-[70px]">หน่วย</th><th class="<?= $th ?> w-[110px] !text-right">ราคา/หน่วย</th>
          <th class="<?= $th ?> w-[120px] !text-right">จำนวนเงิน</th></tr>
    </thead>
    <tbody>
      <?php foreach ($items as $i => $it): ?>
        <tr class="border-b border-line">
          <td class="px-[11px] py-[9px]"><?= $i + 1 ?></td>
          <td class="px-[11px] py-[9px]"><?= e($it['desc']) ?></td>
          <td class="px-[11px] py-[9px] text-right tabular-nums"><?= $trim($it['qty']) ?></td>
          <td class="px-[11px] py-[9px]"><?= e($it['unit'] ?? '') ?></td>
          <td class="px-[11px] py-[9px] text-right tabular-nums"><?= money($it['price']) ?></td>
          <td class="px-[11px] py-[9px] text-right tabular-nums"><?= money($it['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="ml-auto w-[320px] max-w-full text-[0.92rem]">
    <?php $r = 'flex justify-between border-b border-line py-1.5'; ?>
    <div class="<?= $r ?>"><span>รวมเป็นเงิน</span><span><?= money($doc['subtotal']) ?></span></div>
    <?php if ((float) $doc['discount'] > 0): ?>
      <div class="<?= $r ?>"><span>ส่วนลด</span><span>-<?= money($doc['discount']) ?></span></div>
    <?php endif; ?>
    <?php if ((float) $doc['vat_rate'] > 0): ?>
      <div class="<?= $r ?>"><span>ยอดก่อนภาษี</span><span><?= money($doc['subtotal'] - $doc['discount']) ?></span></div>
      <div class="<?= $r ?>"><span>ภาษีมูลค่าเพิ่ม <?= $trim($doc['vat_rate']) ?>%</span><span><?= money($doc['vat_amount']) ?></span></div>
    <?php endif; ?>
    <?php if ((float) $doc['wht_rate'] > 0): ?>
      <div class="<?= $r ?>"><span>หัก ณ ที่จ่าย <?= $trim($doc['wht_rate']) ?>%</span><span>-<?= money($doc['wht_amount']) ?></span></div>
    <?php endif; ?>
    <div class="mt-[3px] flex justify-between border-t-2 border-brand pt-[9px] text-[1.08rem] font-extrabold text-brand"><span>ยอดสุทธิ</span><span>฿<?= money($doc['grand_total']) ?></span></div>
  </div>

  <p class="mt-3.5 rounded-lg border border-dashed border-line p-[9px] text-center text-sm">
    (<?= e(baht_text((float) $doc['grand_total'])) ?>)
  </p>

  <?php if ($doc['doc_type'] !== 'receipt'): ?>
    <div class="note-box mt-5 text-[0.84rem] text-ink-2">
      <b>การชำระเงิน:</b> โอนเข้าบัญชี <?= e(setting('bank_name')) ?> เลขที่ <?= e(setting('bank_account_no')) ?>
      ชื่อบัญชี <?= e(setting('bank_account_name')) ?><?= setting('promptpay_id') ? ' · PromptPay ' . e(setting('promptpay_id')) : '' ?>
      <?php if ($doc['note']): ?><br><b>หมายเหตุ:</b> <?= nl2br(e($doc['note'])) ?><?php endif; ?>
    </div>
  <?php elseif ($doc['note']): ?>
    <div class="note-box mt-5 text-[0.84rem] text-ink-2"><b>หมายเหตุ:</b> <?= nl2br(e($doc['note'])) ?></div>
  <?php endif; ?>

  <div class="mt-[52px] grid grid-cols-2 gap-10 text-center text-[0.86rem] text-ink-2">
    <div><div class="mt-[46px] border-t border-dotted border-ink-2 pt-[7px]">ผู้เช่า / ผู้สั่งซื้อ<br>วันที่ ......../......../........</div></div>
    <div><div class="mt-[46px] border-t border-dotted border-ink-2 pt-[7px]">ผู้มีอำนาจลงนาม<br><?= e(setting('company_name') ?: setting('site_name')) ?></div></div>
  </div>
</div>
