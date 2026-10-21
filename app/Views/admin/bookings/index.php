<?php
/**
 * รายการการจอง — ค้นหา/กรอง
 * ตัวแปร: f (ตัวกรอง), rows, total, pages, page, sumTotal, sumPaid, isOwner, cars, query
 */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'bookings']);
?>
<form class="toolbar" method="get">
  <div class="field">
    <label>ค้นหา</label>
    <input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="เลขที่จอง / ชื่อ / เบอร์ / ทะเบียน">
  </div>
  <div class="field">
    <label>สถานะ</label>
    <select name="status">
      <option value="">ทั้งหมด</option>
      <?php foreach (['pending', 'confirmed', 'picked_up', 'returned', 'completed', 'cancelled'] as $s):
          [$l] = booking_status_label($s); ?>
        <option value="<?= $s ?>" <?= selected($f['status'] === $s) ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label>รถ</label>
    <select name="car_id">
      <option value="">ทุกคัน</option>
      <?php foreach ($cars as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= selected($f['car_id'] === (int) $c['id']) ?>>
          <?= e($c['brand'] . ' ' . $c['model'] . ' (' . $c['plate'] . ')') ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>รับรถตั้งแต่</label><input type="date" name="from" value="<?= e($f['from']) ?>"></div>
  <div class="field"><label>ถึง</label><input type="date" name="to" value="<?= e($f['to']) ?>"></div>
  <button class="btn btn-sm">กรอง</button>
  <a class="btn btn-ghost btn-sm" href="<?= url('admin/bookings') ?>">ล้าง</a>
  <a class="btn btn-sm ml-auto" href="<?= url('admin/bookings/new') ?>">+ จองใหม่</a>
</form>

<div class="mb-[22px] grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(160px,1fr))]">
  <div class="stat"><div class="lb">จำนวนรายการ</div><div class="vl"><?= (int) $total ?></div></div>
  <?php if ($isOwner): ?>
    <div class="stat stat-brand"><div class="lb">มูลค่ารวม</div><div class="vl">฿<?= money0($sumTotal) ?></div><div class="sub">ไม่รวมรายการที่ยกเลิก</div></div>
    <div class="stat stat-ok"><div class="lb">เก็บเงินแล้ว</div><div class="vl">฿<?= money0($sumPaid) ?></div></div>
    <div class="stat stat-accent"><div class="lb">ค้างรับ</div><div class="vl">฿<?= money0(max(0, $sumTotal - $sumPaid)) ?></div></div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>เลขที่</th><th>ลูกค้า</th><th>รถ</th><th>ช่วงเช่า</th>
          <th class="num">ยอดรวม</th><th class="num">ค้าง</th><th>สถานะ</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="py-8 text-center text-muted">ไม่พบรายการ</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            [$sl, $sc] = booking_status_label($r['status']);
            [$pl, $pc] = payment_status_label($r['payment_status']);
            $bal  = (float) $r['grand_total'] + (float) $r['extra_charge'] - (float) $r['paid_amount'];
            $late = $r['status'] === 'picked_up' && strtotime($r['return_at']) < time(); ?>
          <tr>
            <td>
              <a href="<?= url('admin/bookings/' . (int) $r['id']) ?>"><b><?= e($r['booking_no']) ?></b></a>
              <div class="text-xs text-muted"><?= e(thai_date($r['created_at'])) ?> · <?= e($r['source']) ?></div>
            </td>
            <td>
              <?= e($r['contact_name']) ?>
              <div class="text-xs text-muted"><?= e($r['contact_phone']) ?></div>
            </td>
            <td class="text-sm">
              <?= e($r['brand'] . ' ' . $r['model']) ?>
              <div class="text-xs text-muted"><?= e($r['plate']) ?></div>
            </td>
            <td class="text-sm">
              <?= e(thai_date($r['pickup_at'], true)) ?><br>
              <span class="text-muted">→ <?= e(thai_date($r['return_at'], true)) ?></span>
              <div class="text-xs text-muted"><?= (int) $r['rental_days'] ?> วัน<?= $late ? ' · <b class="text-danger">เลยกำหนด</b>' : '' ?></div>
            </td>
            <td class="num"><?= money0($r['grand_total']) ?></td>
            <td class="num"><?= $bal > 0 ? '<b class="text-warn">' . money0($bal) . '</b>' : '<span class="text-muted">-</span>' ?></td>
            <td>
              <span class="badge badge-<?= e($sc) ?>"><?= e($sl) ?></span>
              <div class="mt-1 text-xs"><span class="badge badge-<?= e($pc) ?>"><?= e($pl) ?></span></div>
            </td>
            <td class="text-right">
              <a class="btn btn-ghost btn-sm" href="<?= url('admin/bookings/' . (int) $r['id']) ?>">เปิด</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?= $this->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'query' => $query]) ?>
</div>
