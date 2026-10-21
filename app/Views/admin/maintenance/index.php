<?php
/** งานประจำรถ — ตัวแปร: filter, rows, cars, today, warn, overdue, soon, costYear, typeLabel */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'maintenance']);
?>
<div class="mb-[22px] grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(170px,1fr))]">
  <div class="stat <?= $overdue ? 'stat-danger' : 'stat-ok' ?>"><div class="lb">เลยกำหนดแล้ว</div><div class="vl"><?= (int) $overdue ?></div></div>
  <div class="stat stat-accent"><div class="lb">ครบใน <?= (int) $warn ?> วัน</div><div class="vl"><?= (int) $soon ?></div></div>
  <div class="stat stat-brand"><div class="lb">ค่าใช้จ่ายปีนี้</div><div class="vl">฿<?= money0($costYear) ?></div></div>
</div>

<div class="toolbar">
  <?php foreach (['pending' => 'ค้างอยู่', 'done' => 'ทำแล้ว', 'skipped' => 'ข้าม', 'all' => 'ทั้งหมด'] as $k => $l): ?>
    <a class="btn btn-sm <?= $filter === $k ? '' : 'btn-ghost' ?>" href="?status=<?= e($k) ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>

<div class="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
  <div class="panel min-w-0">
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>กำหนด</th><th>งาน</th><th>รถ</th><th class="num">ค่าใช้จ่าย</th><th></th></tr></thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="py-8 text-center text-muted">ไม่มีรายการ</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r):
              $left = $r['due_date'] ? (int) floor((strtotime($r['due_date']) - strtotime($today)) / 86400) : null;
              $cls  = $left === null ? 'muted' : ($left < 0 ? 'danger' : ($left <= $warn ? 'warn' : 'info')); ?>
            <tr>
              <td class="whitespace-nowrap">
                <?php if ($r['due_date']): ?>
                  <?= e(thai_date($r['due_date'])) ?>
                  <?php if ($r['status'] === 'pending'): ?>
                    <div><span class="badge badge-<?= $cls ?>"><?= $left < 0 ? 'เกิน ' . abs($left) . ' วัน' : 'อีก ' . $left . ' วัน' ?></span></div>
                  <?php endif; ?>
                <?php else: ?><span class="text-xs text-muted">ไม่กำหนดวัน</span><?php endif; ?>
                <?php if ($r['due_mileage']): ?>
                  <div class="text-xs text-muted">ที่ <?= number_format((int) $r['due_mileage']) ?> กม.
                    (ตอนนี้ <?= number_format((int) $r['mileage']) ?>)</div>
                <?php endif; ?>
              </td>
              <td>
                <b class="text-sm"><?= e($r['title']) ?></b>
                <div class="text-xs text-muted"><?= e($typeLabel[$r['task_type']] ?? $r['task_type']) ?>
                  <?= $r['vendor'] ? ' · ' . e($r['vendor']) : '' ?></div>
                <?php if ($r['status'] === 'done'): ?>
                  <div class="text-xs text-ok">เสร็จ <?= e(thai_date($r['done_date'])) ?></div>
                <?php elseif ($r['status'] === 'skipped'): ?>
                  <div class="text-xs text-muted">ข้ามแล้ว</div>
                <?php endif; ?>
              </td>
              <td class="text-sm"><?= e($r['brand'] . ' ' . $r['model']) ?><div class="text-xs text-muted"><?= e($r['plate']) ?></div></td>
              <td class="num"><?= (float) $r['cost'] > 0 ? money0($r['cost']) : '-' ?></td>
              <td class="whitespace-nowrap text-right">
                <?php if ($r['status'] === 'pending'): ?>
                  <details class="relative inline-block text-left">
                    <summary class="btn btn-sm list-none summary-plain">ทำแล้ว</summary>
                    <form method="post" action="<?= url('admin/maintenance/done') ?>"
                          class="absolute right-0 z-20 mt-1.5 w-[250px] rounded-lg border border-line bg-white p-3.5 text-left shadow-card">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <div class="field"><label>วันที่ทำ</label><input type="date" name="done_date" value="<?= e($today) ?>"></div>
                      <div class="field"><label>ค่าใช้จ่าย (บาท)</label><input type="number" name="cost" step="0.01" min="0" value="0"></div>
                      <div class="field"><label>ร้าน/ศูนย์</label><input type="text" name="vendor" value="<?= e($r['vendor'] ?? '') ?>"></div>
                      <div class="field"><label>กำหนดครั้งถัดไป</label><input type="date" name="next_due">
                        <div class="hint">ใส่แล้วระบบจะสร้างงานเตือนรอบหน้าให้</div></div>
                      <button class="btn btn-sm btn-block">บันทึก</button>
                    </form>
                  </details>
                  <form method="post" action="<?= url('admin/maintenance/skip') ?>" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-ghost btn-sm">ข้าม</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel min-w-0">
    <h3>เพิ่มงานใหม่</h3>
    <form method="post" action="<?= url('admin/maintenance/add') ?>">
      <?= csrf_field() ?>
      <div class="field"><label>รถ *</label>
        <select name="car_id" required>
          <?php foreach ($cars as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= e($c['brand'] . ' ' . $c['model'] . ' — ' . $c['plate']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>ประเภทงาน</label>
        <select name="task_type">
          <?php foreach ($typeLabel as $k => $l): ?>
            <option value="<?= e($k) ?>"><?= e($l) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>ชื่องาน *</label><input type="text" name="title" required placeholder="เช่น เปลี่ยนยาง 4 เส้น"></div>
      <div class="field"><label>กำหนดวันที่</label><input type="date" name="due_date"></div>
      <div class="field"><label>หรือกำหนดที่เลขไมล์</label><input type="number" name="due_mileage" min="0"></div>
      <div class="field"><label>ร้าน/ศูนย์</label><input type="text" name="vendor"></div>
      <div class="field"><label>หมายเหตุ</label><textarea name="note"></textarea></div>
      <button class="btn btn-block">เพิ่มงาน</button>
    </form>
  </div>
</div>
