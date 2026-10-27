<?php /** $doc $shop */
$types = \App\Services\DocumentService::TYPES;
$net = round((float) $doc['subtotal'] - (float) $doc['discount'], 2);
$pct = static fn($v) => rtrim(rtrim((string) $v, '0'), '.');
?>
<div class="flex justify-between items-start">
  <div>
    <div class="text-lg font-bold"><?= e($shop['shop_name'] ?? '') ?></div>
    <div class="whitespace-pre-line text-slate-600"><?= e($shop['shop_address'] ?? '') ?></div>
    <?php if (!empty($shop['shop_tax_id'])): ?><div>เลขประจำตัวผู้เสียภาษี <?= e($shop['shop_tax_id']) ?></div><?php endif; ?>
    <?php if (!empty($shop['phone'])): ?><div>โทร <?= e($shop['phone']) ?></div><?php endif; ?>
  </div>
  <div class="text-right">
    <div class="text-xl font-bold"><?= e($types[$doc['doc_type']]) ?></div>
    <div>เลขที่ <b><?= e($doc['doc_no']) ?></b></div>
    <div>วันที่ <?= e(thai_date($doc['issue_date'])) ?></div>
    <?php if ($doc['due_date']): ?><div>ครบกำหนด <?= e(thai_date($doc['due_date'])) ?></div><?php endif; ?>
    <?php if ($doc['status'] === 'void'): ?><div class="text-red-700 font-bold">ยกเลิก</div><?php endif; ?>
  </div>
</div>
<div class="mt-5 border border-slate-300 rounded p-3">
  <div class="font-semibold"><?= e($doc['bill_name']) ?></div>
  <?php if ($doc['bill_tax_id']): ?><div>เลขประจำตัวผู้เสียภาษี <?= e($doc['bill_tax_id']) ?></div><?php endif; ?>
  <?php if ($doc['bill_address']): ?><div class="whitespace-pre-line"><?= e($doc['bill_address']) ?></div><?php endif; ?>
</div>
<table class="w-full mt-5 border-collapse">
  <thead><tr class="border-y border-slate-400 text-left"><th class="py-1 w-10">#</th><th>รายการ</th><th class="text-right">จำนวน</th><th class="text-right">ราคา/หน่วย</th><th class="text-right">จำนวนเงิน</th></tr></thead>
  <tbody>
  <?php foreach ($doc['items'] as $it): ?>
    <tr class="border-b border-slate-200"><td class="py-1"><?= e($it['line_no']) ?></td><td><?= e($it['description']) ?></td>
      <td class="text-right"><?= e($pct($it['qty'])) ?> <?= e($it['unit']) ?></td><td class="text-right"><?= e(money2($it['price'])) ?></td><td class="text-right"><?= e(money2($it['amount'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><td colspan="4" class="text-right pt-2">รวมเป็นเงิน</td><td class="text-right pt-2"><?= e(money2($doc['subtotal'])) ?></td></tr>
    <?php if ((float) $doc['discount'] > 0): ?><tr><td colspan="4" class="text-right">ส่วนลด</td><td class="text-right">-<?= e(money2($doc['discount'])) ?></td></tr>
      <tr><td colspan="4" class="text-right">ยอดหลังหักส่วนลด</td><td class="text-right"><?= e(money2($net)) ?></td></tr><?php endif; ?>
    <?php if ((float) $doc['vat_rate'] > 0): ?><tr><td colspan="4" class="text-right">ภาษีมูลค่าเพิ่ม <?= e($pct($doc['vat_rate'])) ?>%</td><td class="text-right"><?= e(money2($doc['vat_amount'])) ?></td></tr><?php endif; ?>
    <?php if ((float) $doc['wht_rate'] > 0): ?><tr><td colspan="4" class="text-right">หัก ณ ที่จ่าย <?= e($pct($doc['wht_rate'])) ?>%</td><td class="text-right">-<?= e(money2($doc['wht_amount'])) ?></td></tr><?php endif; ?>
    <tr class="font-bold border-t border-slate-400"><td colspan="4" class="text-right pt-1">ยอดสุทธิ</td><td class="text-right pt-1">฿<?= e(money2($doc['grand_total'])) ?></td></tr>
  </tfoot>
</table>
<?php if ($doc['note']): ?><p class="mt-4 text-slate-600 whitespace-pre-line">หมายเหตุ: <?= e($doc['note']) ?></p><?php endif; ?>
<div class="mt-16 grid grid-cols-2 gap-8 text-center">
  <div><div class="border-t border-slate-400 pt-1">ผู้รับเอกสาร</div></div>
  <div><div class="border-t border-slate-400 pt-1">ผู้ออกเอกสาร</div></div>
</div>
