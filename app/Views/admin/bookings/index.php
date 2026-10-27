<?php
/** รายการการจอง: $bookings $counts $status $q */
use App\Services\BookingStatus;
use App\Services\Phone;

$active = array_filter($bookings, static fn($b) => $b['status'] !== 'cancelled');
$sumTotal = array_sum(array_map(static fn($b) => (float) $b['grand_total'], $active));
$sumPaid = array_sum(array_map(static fn($b) => (float) $b['paid_total'], $active));
?>
<form class="toolbar" method="get">
  <div class="field">
    <label for="q">ค้นหา</label>
    <input type="text" id="q" name="q" value="<?= e($q) ?>" placeholder="เลขที่จอง / ชื่อ / เบอร์ / ทะเบียน">
  </div>
  <div class="field">
    <label for="status">สถานะ</label>
    <select id="status" name="status">
      <option value="">ทั้งหมด</option>
      <?php foreach (BookingStatus::STATUS as $code => $label): ?>
        <option value="<?= e($code) ?>"<?= selected($status === $code) ?>><?= e($label) ?> (<?= (int) ($counts[$code] ?? 0) ?>)</option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-sm">กรอง</button>
  <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/bookings')) ?>">ล้าง</a>
  <a class="btn btn-sm ml-auto" href="<?= e(url('/admin/bookings/create')) ?>">+ จองใหม่</a>
</form>

<div class="stat-grid-sm">
  <div class="stat"><div class="lb">จำนวนรายการ</div><div class="vl"><?= count($bookings) ?></div></div>
  <?php if (\App\Services\Auth::isAdmin()): ?>
    <div class="stat stat-brand"><div class="lb">มูลค่ารวม</div><div class="vl">฿<?= e(money0($sumTotal)) ?></div><div class="sub">ไม่รวมรายการที่ยกเลิก</div></div>
    <div class="stat stat-ok"><div class="lb">เก็บเงินแล้ว</div><div class="vl">฿<?= e(money0($sumPaid)) ?></div></div>
    <div class="stat stat-accent"><div class="lb">ค้างรับ</div><div class="vl">฿<?= e(money0(max(0, $sumTotal - $sumPaid))) ?></div></div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>เลขที่</th><th>ลูกค้า</th><th>รถ</th><th>ช่วงเช่า</th><th class="num">ยอดรวม</th><th class="num">ค้าง</th><th>สถานะ</th><th></th></tr></thead>
      <tbody>
        <?php if (!$bookings): ?><tr><td colspan="8" class="py-8 text-center text-muted">ไม่พบรายการ</td></tr><?php endif; ?>
        <?php foreach ($bookings as $r):
            $bal = (float) $r['balance_due'];
            $late = $r['status'] === 'picked_up' && strtotime($r['return_at']) < time(); ?>
          <tr>
            <td>
              <a href="<?= e(url('/admin/bookings/' . (int) $r['id'])) ?>"><b><?= e($r['booking_no']) ?></b></a>
              <div class="text-xs text-muted"><?= e(thai_date($r['created_at'])) ?> · <?= e($r['source']) ?></div>
            </td>
            <td><?= e($r['contact_name']) ?><div class="text-xs text-muted"><?= e(Phone::display($r['contact_phone'])) ?></div></td>
            <td class="text-sm"><?= e($r['brand'] . ' ' . $r['model']) ?><div class="text-xs text-muted"><?= e($r['plate']) ?></div></td>
            <td class="text-sm">
              <?= e(thai_date($r['pickup_at'], true)) ?><br>
              <span class="text-muted">→ <?= e(thai_date($r['return_at'], true)) ?></span>
              <div class="text-xs text-muted"><?= (int) $r['rental_days'] ?> วัน<?= $late ? ' · <b class="text-danger">เลยกำหนด</b>' : '' ?></div>
            </td>
            <td class="num"><?= e(money0($r['grand_total'])) ?></td>
            <td class="num"><?= $bal > 0.004 && $r['status'] !== 'cancelled' ? '<b class="text-warn">' . e(money0($bal)) . '</b>' : '<span class="text-muted">-</span>' ?></td>
            <td>
              <span class="badge badge-<?= e(status_tone('booking', $r['status'])) ?>"><?= e(BookingStatus::STATUS[$r['status']]) ?></span>
              <div class="mt-1 text-xs"><span class="badge badge-<?= e(status_tone('payment', $r['payment_status'])) ?>"><?= e(BookingStatus::PAYMENT[$r['payment_status']]) ?></span></div>
            </td>
            <td class="text-right"><a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/bookings/' . (int) $r['id'])) ?>">เปิด</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
