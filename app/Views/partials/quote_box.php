<?php /** สรุปราคา ($quote จาก QuoteService) */ ?>
<div class="mt-6 border border-slate-200 rounded-xl overflow-hidden">
  <table class="table">
    <tbody>
    <?php foreach ($quote['rental_lines'] as $line): ?>
      <tr><td><?= e($line['label']) ?> × <?= e($line['qty']) ?></td><td class="text-right">฿<?= e(money($line['amount'])) ?></td></tr>
    <?php endforeach; ?>
    <?php foreach ($quote['addon_lines'] as $line): ?>
      <tr><td><?= e($line['label']) ?></td><td class="text-right">฿<?= e(money($line['amount'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($quote['discount'] > 0): ?>
      <tr><td>ส่วนลด</td><td class="text-right">−฿<?= e(money($quote['discount'])) ?></td></tr>
    <?php endif; ?>
    <?php if ($quote['vat_rate'] > 0): ?>
      <tr><td>VAT <?= e(money($quote['vat_rate'])) ?>%</td><td class="text-right">฿<?= e(money($quote['vat_amount'])) ?></td></tr>
    <?php endif; ?>
    <tr class="font-semibold"><td>รวมค่าเช่า (<?= e($quote['days']) ?> วัน)</td><td class="text-right text-brand-700">฿<?= e(money($quote['grand_total'])) ?></td></tr>
    <tr><td>มัดจำเพื่อยืนยันการจอง (หักจากค่าเช่า)</td><td class="text-right">฿<?= e(money($quote['deposit_amount'])) ?></td></tr>
    <tr><td>เงินประกัน (คืนเมื่อคืนรถ)</td><td class="text-right">฿<?= e(money($quote['security_deposit'])) ?></td></tr>
    <tr class="font-medium"><td>ชำระวันรับรถ (ค่าเช่าที่เหลือ + เงินประกัน)</td><td class="text-right">฿<?= e(money($quote['due_on_pickup'])) ?></td></tr>
    </tbody>
  </table>
</div>
