<?php
/** ฐานลูกค้า — ตัวแปร: q, only, rows, totals */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'customers']);
$tot = $totals['total'];
?>
<div class="mb-[22px] grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(160px,1fr))]">
  <div class="stat stat-brand"><div class="lb">ลูกค้าทั้งหมด</div><div class="vl"><?= (int) $tot ?></div></div>
  <div class="stat stat-ok"><div class="lb">ลูกค้าที่กลับมาเช่าซ้ำ</div><div class="vl"><?= (int) $totals['repeat'] ?></div>
    <div class="sub"><?= $tot ? round($totals['repeat'] / $tot * 100) : 0 ?>% ของฐานลูกค้า</div></div>
  <div class="stat <?= $totals['blacklist'] ? 'stat-danger' : '' ?>"><div class="lb">Blacklist</div><div class="vl"><?= (int) $totals['blacklist'] ?></div></div>
</div>

<form class="toolbar" method="get">
  <div class="field"><label>ค้นหา</label>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="ชื่อ / เบอร์ / รหัส / เลขผู้เสียภาษี" class="!min-w-[260px]"></div>
  <button class="btn btn-sm">ค้นหา</button>
  <a class="btn btn-ghost btn-sm" href="?only=blacklist">ดูเฉพาะ Blacklist</a>
  <a class="btn btn-ghost btn-sm" href="<?= url('admin/customers') ?>">ล้าง</a>
  <a class="btn btn-sm ml-auto" href="<?= url('admin/customers/new') ?>">+ เพิ่มลูกค้า</a>
</form>

<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>รหัส</th><th>ชื่อ</th><th>ติดต่อ</th><th>เอกสาร</th>
            <th class="num">เช่า</th><th class="num">ยอดสะสม</th><th>ล่าสุด</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="py-8 text-center text-muted">ไม่พบลูกค้า</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="text-sm"><?= e($r['code']) ?></td>
            <td>
              <b><?= e($r['full_name']) ?></b>
              <?= (int) $r['is_blacklist'] ? ' <span class="badge badge-danger">Blacklist</span>' : '' ?>
              <div class="text-xs text-muted"><?= $r['type'] === 'company' ? 'นิติบุคคล' . ($r['tax_id'] ? ' · ' . e($r['tax_id']) : '') : 'บุคคลธรรมดา' ?></div>
            </td>
            <td class="text-sm"><?= e($r['phone']) ?>
              <div class="text-xs text-muted"><?= e($r['email'] ?: ($r['line_id'] ?: '-')) ?></div></td>
            <td class="text-sm">
              <?php // เอกสารเปิดดูได้จากหน้าลูกค้าเท่านั้น (ผ่านหน้าที่ตรวจสิทธิ์และบันทึกประวัติ) ไม่วางลิงก์ไฟล์ตรงในตาราง
                    $nd = (int) $r['docs'] + ($r['id_card_file'] ? 1 : 0) + ($r['license_file'] ? 1 : 0); ?>
              <?php if ($nd > 0): ?><a href="<?= url('admin/customers/' . (int) $r['id'] . '/edit') ?>"><?= $nd ?> ไฟล์</a>
              <?php else: ?><span class="text-xs text-muted">ยังไม่มี</span><?php endif; ?>
            </td>
            <td class="num"><?= (int) $r['jobs'] ?></td>
            <td class="num">฿<?= money0($r['spend']) ?></td>
            <td class="text-sm"><?= e(thai_date($r['last_rent'])) ?></td>
            <td class="text-right"><a class="btn btn-ghost btn-sm" href="<?= url('admin/customers/' . (int) $r['id'] . '/edit') ?>">เปิด</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
