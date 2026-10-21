<?php
/** จัดการรถ — ตัวแปร: cars, today, warnDays */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'cars']);
?>
<div class="toolbar">
  <a class="btn btn-sm ml-auto" href="<?= url('admin/cars/new') ?>">+ เพิ่มรถ</a>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>รถ</th><th class="num">รายวัน</th><th class="num">สัปดาห์</th><th class="num">เดือน</th>
          <th class="num">เลขไมล์</th><th>เอกสาร</th><th>สถานะ</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cars as $c): ?>
          <tr>
            <td>
              <b><?= e($c['brand'] . ' ' . $c['model']) ?></b> <?= e($c['year'] ?: '') ?>
              <div class="text-xs text-muted"><?= e($c['plate']) ?> · <?= e(car_type_label($c['car_type'])) ?> ·
                <?= (int) $c['seats'] ?> ที่นั่ง · <?= $c['gear'] === 'auto' ? 'ออโต้' : 'ธรรมดา' ?></div>
              <?php if ($c['active_booking']): ?>
                <div class="text-xs text-ok">กำลังเช่า <?= e($c['active_booking']['booking_no']) ?> คืน <?= e(thai_date($c['active_booking']['return_at'])) ?></div>
              <?php endif; ?>
            </td>
            <td class="num">฿<?= money0($c['price_day']) ?></td>
            <td class="num"><?= (float) $c['price_week'] > 0 ? '฿' . money0($c['price_week']) : '<span class="text-xs text-muted">auto</span>' ?></td>
            <td class="num"><?= (float) $c['price_month'] > 0 ? '฿' . money0($c['price_month']) : '<span class="text-xs text-muted">auto</span>' ?></td>
            <td class="num"><?= number_format((int) $c['mileage']) ?></td>
            <td class="text-sm">
              <?php foreach (['ประกัน' => $c['insurance_expire'], 'พ.ร.บ.' => $c['act_expire'], 'ภาษี' => $c['tax_expire']] as $lb => $dt):
                  if (!$dt) {
                      continue;
                  }
                  $left = (int) floor((strtotime($dt) - strtotime($today)) / 86400);
                  $cls  = $left < 0 ? 'danger' : ($left <= $warnDays ? 'warn' : 'muted'); ?>
                <span class="badge badge-<?= $cls ?>" title="ครบ <?= e(thai_date($dt)) ?>"><?= e($lb) ?>
                  <?= $left < 0 ? 'หมดแล้ว' : $left . 'ว.' ?></span>
              <?php endforeach; ?>
            </td>
            <td>
              <form method="post" action="<?= url('admin/cars/status') ?>" onchange="this.submit()">
                <?= csrf_field() ?>
                <input type="hidden" name="car_id" value="<?= (int) $c['id'] ?>">
                <select name="status" class="!min-h-0 !px-2 !py-[5px] !text-[0.84rem]">
                  <option value="available"   <?= selected($c['status'] === 'available') ?>>พร้อมให้เช่า</option>
                  <option value="maintenance" <?= selected($c['status'] === 'maintenance') ?>>เข้าศูนย์/ซ่อม</option>
                  <option value="inactive"    <?= selected($c['status'] === 'inactive') ?>>ปิดใช้งาน</option>
                </select>
              </form>
            </td>
            <td class="whitespace-nowrap text-right">
              <a class="btn btn-ghost btn-sm" href="<?= url('admin/cars/' . (int) $c['id'] . '/edit') ?>">แก้ไข</a>
              <a class="btn btn-ghost btn-sm" href="<?= url('admin/bookings?car_id=' . (int) $c['id']) ?>">ประวัติ</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
