<?php /** $booking $kind $customer $view $active $changes $shop */
$title = \App\Services\SignatureService::kinds()[$kind][0];
$blankLine = '<span class="inline-block min-w-[130px] border-b border-dotted border-slate-500 px-1">&nbsp;</span>';
?>
<div class="text-center">
  <div class="text-lg font-bold"><?= e($shop['shop_name'] ?? '') ?></div>
  <div class="text-slate-600 whitespace-pre-line"><?= e($shop['shop_address'] ?? '') ?><?= !empty($shop['phone']) ? ' โทร ' . e($shop['phone']) : '' ?></div>
  <div class="text-xl font-bold mt-3"><?= e($title) ?></div>
  <div>เลขที่การจอง <b><?= e($booking['booking_no']) ?></b></div>
</div>

<div class="mt-4 border border-slate-300 rounded p-3">
  <div class="font-semibold">ผู้เช่า</div>
  <div><?= e($customer['full_name'] ?? $booking['contact_name']) ?> · โทร <?= e($booking['contact_phone']) ?></div>
  <?php if (!empty($customer['address'])): ?><div class="whitespace-pre-line"><?= e($customer['address']) ?></div><?php endif; ?>
  <?php if (!empty($customer['license_no'])): ?><div>ใบขับขี่เลขที่ <?= e($customer['license_no']) ?></div><?php endif; ?>
</div>

<?php if ($kind === 'return'): ?>
<table class="w-full mt-4 border-collapse">
  <?php foreach ($view['fields'] as [$label, $value]): ?>
    <tr class="border-b border-slate-200"><td class="py-1 w-2/5 text-slate-600"><?= e($label) ?></td><td><?= e($value) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php if ($view['extras']): ?>
  <div class="mt-3 font-semibold">รายการค่าใช้จ่ายเพิ่ม</div>
  <ul class="list-disc pl-6"><?php foreach ($view['extras'] as $l): ?><li><?= e($l['label'] ?? '') ?> — <?= e(money($l['amount'] ?? 0)) ?> บาท<?= !empty($l['waived']) ? ' (ยกเว้น)' : '' ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php endif; ?>

<?php if ($kind === 'contract'): ?>
  <div class="mt-3"><?= $view['terms_html'] ?></div>
<?php endif; ?>

<div class="mt-4 text-slate-600 whitespace-pre-line text-xs"><b>ความยินยอมข้อมูลส่วนบุคคล:</b> <?= e($view['pdpa']) ?></div>

<?php if ($changes): ?>
  <div class="mt-4 border-2 border-red-600 text-red-700 p-2 text-xs">
    <b>คำเตือน: ข้อมูลต่อไปนี้ถูกแก้ไขหลังลงนาม</b>
    <?php foreach ($changes as $c): ?><div><?= e($c['label']) ?>: ตอนเซ็น <?= e($c['signed']) ?> → ปัจจุบัน <?= e($c['now']) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="mt-10 grid grid-cols-2 gap-8 text-center">
  <div>
    <?php if ($active): ?>
      <img class="mx-auto h-20 object-contain" alt="ลายเซ็นผู้เช่า" src="<?= e(url('/admin/signatures/' . $active['id'] . '/image')) ?>">
    <?php else: ?><div class="h-20"></div><?php endif; ?>
    <div class="border-t border-slate-400 pt-1">ผู้เช่า<?= $active ? ' (' . e($active['signer_name']) . ')' : '' ?></div>
  </div>
  <div><div class="h-20"></div><div class="border-t border-slate-400 pt-1">ผู้ให้เช่า</div></div>
</div>
<?php if ($active): ?>
  <p class="mt-4 text-[11px] text-slate-500 break-all">ลงนามอิเล็กทรอนิกส์เมื่อ <?= e(thai_date($active['signed_at'], true)) ?> · IP <?= e($active['ip']) ?> · รหัสอ้างอิง <?= e(\App\Services\SignatureService::ref($active)) ?> · SHA-256 <?= e($active['snapshot_hash']) ?></p>
<?php endif; ?>
