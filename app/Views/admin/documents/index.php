<?php
/** รายการเอกสาร — ตัวแปร: type, rows, typeLabel, statusLabel, sumQuote */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'documents']);
?>
<div class="mb-[22px] grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
  <div class="stat stat-accent"><div class="lb">ใบเสนอราคารอลูกค้าตอบ</div><div class="vl">฿<?= money0($sumQuote) ?></div></div>
</div>

<div class="toolbar">
  <a class="btn btn-sm <?= $type === '' ? '' : 'btn-ghost' ?>" href="?">ทั้งหมด</a>
  <?php foreach ($typeLabel as $k => $l): ?>
    <a class="btn btn-sm <?= $type === $k ? '' : 'btn-ghost' ?>" href="?type=<?= e($k) ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
  <a class="btn btn-sm ml-auto" href="<?= url('admin/documents/new?type=quotation') ?>">+ ใบเสนอราคา</a>
  <a class="btn btn-ghost btn-sm" href="<?= url('admin/documents/new?type=receipt') ?>">+ ใบเสร็จ</a>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>เลขที่</th><th>ประเภท</th><th>ลูกค้า</th><th>การจอง</th><th>วันที่</th>
                 <th class="num">ยอดสุทธิ</th><th>สถานะ</th><th></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="py-8 text-center text-muted">ยังไม่มีเอกสาร</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><b><?= e($r['doc_no']) ?></b></td>
            <td class="text-sm"><?= e($typeLabel[$r['doc_type']] ?? $r['doc_type']) ?></td>
            <td class="text-sm"><?= e($r['bill_name'] ?: ($r['full_name'] ?: '-')) ?></td>
            <td class="text-sm">
              <?= $r['booking_no'] ? '<a href="' . e(url('admin/bookings/' . (int) $r['booking_id'])) . '">' . e($r['booking_no']) . '</a>' : '-' ?>
            </td>
            <td class="text-sm"><?= e(thai_date($r['issue_date'])) ?></td>
            <td class="num">฿<?= money($r['grand_total']) ?></td>
            <td>
              <form method="post" action="<?= url('admin/documents/status') ?>" onchange="this.submit()">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <select name="status" class="!min-h-0 !px-2 !py-1 !text-[0.8rem]">
                  <?php foreach ($statusLabel as $k => $v2): ?>
                    <option value="<?= e($k) ?>" <?= selected($r['status'] === $k) ?>><?= e($v2[0]) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td class="whitespace-nowrap text-right">
              <a class="btn btn-sm" href="<?= url('admin/documents/' . (int) $r['id'] . '/print') ?>" target="_blank" rel="noopener">พิมพ์</a>
              <a class="btn btn-ghost btn-sm" href="<?= url('admin/documents/' . (int) $r['id'] . '/edit') ?>">แก้ไข</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
