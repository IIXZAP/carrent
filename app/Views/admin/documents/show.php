<?php /** $doc */
$types = \App\Services\DocumentService::TYPES; $statuses = \App\Services\DocumentService::STATUSES;
$closed = in_array($doc['status'], ['paid', 'void'], true);
$net = round((float) $doc['subtotal'] - (float) $doc['discount'], 2);
?>
<p class="mb-3 text-sm"><a href="<?= e(url('/admin/documents')) ?>">← เอกสาร</a></p>
<div class="flex flex-wrap items-center gap-3 mt-2">
  <h1 class="text-2xl font-bold"><?= e($types[$doc['doc_type']]) ?> <span class="text-muted"><?= e($doc['doc_no']) ?></span></h1>
  <span class="badge badge-<?= e(status_tone('document', $doc['status'])) ?>"><?= e($statuses[$doc['status']]) ?></span>
  <div class="ml-auto flex gap-2">
    <a class="btn btn-ghost" target="_blank" href="<?= e(url('/admin/documents/' . $doc['id'] . '/print')) ?>">พิมพ์</a>
    <?php if (!$closed): ?><a class="btn btn-ghost" href="<?= e(url('/admin/documents/' . $doc['id'] . '/edit')) ?>">แก้ไข</a><?php endif; ?>
  </div>
</div>
<div class="panel mt-6">
  <p>ผู้รับเอกสาร: <b><?= e($doc['bill_name']) ?></b> <?= $doc['bill_tax_id'] ? '· เลขผู้เสียภาษี ' . e($doc['bill_tax_id']) : '' ?></p>
  <?php if ($doc['booking_id']): ?><p class="text-sm mt-1"><a class="text-brand-700 hover:underline" href="<?= e(url('/admin/bookings/' . $doc['booking_id'])) ?>">ดูการจองที่เกี่ยวข้อง</a></p><?php endif; ?>
  <table class="table mt-4">
    <thead><tr><th>#</th><th>รายการ</th><th class="text-right">จำนวน</th><th class="text-right">ราคา</th><th class="text-right">รวม</th></tr></thead>
    <?php foreach ($doc['items'] as $it): ?>
      <tr><td><?= e($it['line_no']) ?></td><td><?= e($it['description']) ?></td><td class="text-right"><?= e(rtrim(rtrim($it['qty'], '0'), '.')) ?> <?= e($it['unit']) ?></td><td class="text-right"><?= e(money2($it['price'])) ?></td><td class="text-right"><?= e(money2($it['amount'])) ?></td></tr>
    <?php endforeach; ?>
    <tr><td colspan="4" class="text-right">รวมเป็นเงิน</td><td class="text-right"><?= e(money2($doc['subtotal'])) ?></td></tr>
    <?php if ((float) $doc['discount'] > 0): ?><tr><td colspan="4" class="text-right">ส่วนลด</td><td class="text-right">-<?= e(money2($doc['discount'])) ?></td></tr><?php endif; ?>
    <?php if ((float) $doc['vat_rate'] > 0): ?><tr><td colspan="4" class="text-right">VAT <?= e(rtrim(rtrim($doc['vat_rate'], '0'), '.')) ?>%</td><td class="text-right"><?= e(money2($doc['vat_amount'])) ?></td></tr><?php endif; ?>
    <?php if ((float) $doc['wht_rate'] > 0): ?><tr><td colspan="4" class="text-right">หัก ณ ที่จ่าย <?= e(rtrim(rtrim($doc['wht_rate'], '0'), '.')) ?>%</td><td class="text-right">-<?= e(money2($doc['wht_amount'])) ?></td></tr><?php endif; ?>
    <tr class="font-semibold"><td colspan="4" class="text-right">ยอดสุทธิ</td><td class="text-right">฿<?= e(money2($doc['grand_total'])) ?></td></tr>
  </table>
  <?php if ($doc['note']): ?><p class="text-sm text-ink-2 mt-3"><?= nl2br(e($doc['note'])) ?></p><?php endif; ?>
</div>
<?php if (!$closed): ?>
<form method="post" action="<?= e(url('/admin/documents/' . $doc['id'] . '/status')) ?>" class="panel mt-4 flex flex-wrap items-center gap-2">
  <?= csrf_field() ?>
  <span class="text-sm text-muted">เปลี่ยนสถานะเป็น</span>
  <?php foreach ($statuses as $k => $label): if ($k === $doc['status']) { continue; } ?>
    <button name="status" value="<?= e($k) ?>" class="btn btn-ghost" <?= in_array($k, ['paid', 'void'], true) ? 'onclick="return confirm(\'ยืนยัน? เปลี่ยนแล้วแก้ไขเอกสารไม่ได้\')"' : '' ?>><?= e($label) ?></button>
  <?php endforeach; ?>
</form>
<?php endif; ?>
