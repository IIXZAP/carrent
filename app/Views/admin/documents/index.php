<?php /** $docs $type */
$types = \App\Services\DocumentService::TYPES; $statuses = \App\Services\DocumentService::STATUSES;
$badge = ['draft' => 'badge-inactive', 'sent' => 'badge-confirmed', 'accepted' => 'badge-picked_up', 'paid' => 'badge-completed', 'void' => 'badge-rejected'];
?>
<div class="flex items-center justify-between">
  <h1 class="text-2xl font-bold">เอกสาร</h1>
  <a class="btn btn-primary" href="<?= e(url('/admin/documents/create')) ?>">+ สร้างเอกสาร</a>
</div>
<div class="mt-4 flex flex-wrap gap-2 text-sm">
  <a class="btn <?= $type === '' ? 'btn-primary' : '' ?>" href="<?= e(url('/admin/documents')) ?>">ทั้งหมด</a>
  <?php foreach ($types as $k => $label): ?>
    <a class="btn <?= $type === $k ? 'btn-primary' : '' ?>" href="<?= e(url('/admin/documents?type=' . $k)) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<div class="panel mt-4 overflow-x-auto">
  <table class="table">
    <thead><tr><th>เลขที่</th><th>ชนิด</th><th>วันที่</th><th>ผู้รับเอกสาร</th><th>การจอง</th><th class="text-right">ยอดสุทธิ</th><th>สถานะ</th></tr></thead>
    <tbody>
    <?php foreach ($docs as $d): ?>
      <tr>
        <td><a class="text-brand-700 hover:underline" href="<?= e(url('/admin/documents/' . $d['id'])) ?>"><?= e($d['doc_no']) ?></a></td>
        <td><?= e($types[$d['doc_type']]) ?></td>
        <td><?= e(thai_date($d['issue_date'])) ?></td>
        <td><?= e($d['bill_name']) ?></td>
        <td><?php if ($d['booking_id']): ?><a class="text-brand-700 hover:underline" href="<?= e(url('/admin/bookings/' . $d['booking_id'])) ?>"><?= e($d['booking_no']) ?></a><?php endif; ?></td>
        <td class="text-right">฿<?= e(money2($d['grand_total'])) ?></td>
        <td><span class="badge <?= e($badge[$d['status']]) ?>"><?= e($statuses[$d['status']]) ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($docs === []): ?><tr><td colspan="7" class="text-center text-slate-500">ยังไม่มีเอกสาร</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
