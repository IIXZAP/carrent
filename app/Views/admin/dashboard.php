<?php
/**
 * ภาพรวม
 * ตัวแปร: today, isOwner, revenueMonth, bookedMonth, activeNow, totalCars, pending, slipWait, bookedDays, daysInM,
 *         utilization, pickupToday, returnToday, overdue, reminderDays, dueTasks, trend, maxTrend, perCar, maxCarRev, carStatus
 */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'dashboard']);
$meterTone = $utilization < 40 ? 'danger' : ($utilization < 65 ? 'warn' : '');
?>
<div class="stat-grid-lg">
  <?php if ($isOwner): ?>
    <div class="stat stat-brand">
      <div class="lb">รายได้รับจริงเดือนนี้</div>
      <div class="vl">฿<?= money0($revenueMonth) ?></div>
      <div class="sub">เงินที่ยืนยันแล้ว ไม่รวมเงินประกัน</div>
    </div>
    <div class="stat stat-accent">
      <div class="lb">ยอดจองเดือนนี้</div>
      <div class="vl">฿<?= money0($bookedMonth) ?></div>
      <div class="sub">มูลค่างานที่รับไว้ (รวมยังไม่เก็บ)</div>
    </div>
  <?php endif; ?>
  <div class="stat stat-ok">
    <div class="lb">อัตราการใช้รถ</div>
    <div class="vl"><?= (int) $utilization ?>%</div>
    <div class="meter mt-1 <?= $meterTone ?>"><i style="width:<?= (int) $utilization ?>%"></i></div>
    <div class="sub"><?= money0($bookedDays) ?> วัน จากทั้งหมด <?= (int) ($totalCars * $daysInM) ?> วัน-คัน</div>
  </div>
  <div class="stat <?= $pending + $slipWait > 0 ? 'stat-danger' : '' ?>">
    <div class="lb">งานรอดำเนินการ</div>
    <div class="vl"><?= (int) ($pending + $slipWait) ?></div>
    <div class="sub">จองรอยืนยัน <?= (int) $pending ?> · สลิปรอตรวจ <?= (int) $slipWait ?></div>
  </div>
  <div class="stat">
    <div class="lb">รถที่ออกงานอยู่</div>
    <div class="vl"><?= (int) $activeNow ?> / <?= (int) $totalCars ?></div>
    <div class="sub">คันที่ลูกค้ากำลังใช้</div>
  </div>
</div>

<?php if ($overdue): ?>
  <!-- รถเลยกำหนดคืน: ระบบกันคันเหล่านี้ไม่ให้จองต่อจนกว่าจะบันทึกรับคืน จึงต้องเห็นและกดได้ทันที -->
  <div class="panel mb-4 border-danger/40 bg-danger-bg">
    <h3 class="text-danger">⚠️ รถเลยกำหนดคืน <?= count($overdue) ?> คัน</h3>
    <p class="text-sm">คันเหล่านี้ <b>ลูกค้ารายอื่นจองไม่ได้</b> จนกว่าจะบันทึกรับคืน — ถ้ารถกลับมาแล้วให้กดบันทึกทันที</p>
    <?php foreach ($overdue as $o):
        $lateH   = (int) floor((time() - strtotime($o['return_at'])) / 3600);
        $lateTxt = $lateH >= 24 ? floor($lateH / 24) . ' วัน ' . ($lateH % 24) . ' ชม.' : max(1, $lateH) . ' ชม.'; ?>
      <div class="flex items-center justify-between gap-3 border-t border-danger/20 py-2.5">
        <div class="min-w-0">
          <b><?= e($o['brand'] . ' ' . $o['model']) ?></b> <span class="text-muted"><?= e($o['plate']) ?></span>
          <span class="block text-sm"><?= e($o['contact_name']) ?> · <?= e($o['contact_phone']) ?></span>
          <span class="block text-xs text-danger">ครบกำหนด <?= e(thai_date($o['return_at'], true)) ?> · เลยมา <?= e($lateTxt) ?></span>
        </div>
        <a class="btn btn-sm flex-none" href="<?= url('admin/bookings/' . (int) $o['id']) ?>#actions">บันทึกรับคืน</a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
  <div class="min-w-0">
    <!-- งานวันนี้ -->
    <div class="panel">
      <h3>งานวันนี้ — <?= e(thai_date($today, false, true)) ?></h3>
      <div class="mt-2 grid gap-4 sm:grid-cols-2">
        <div>
          <h4 class="text-sm text-brand">🚗 ส่งมอบรถ (<?= count($pickupToday) ?>)</h4>
          <?php if (!$pickupToday): ?><p class="text-sm text-muted">ไม่มีงานส่งมอบวันนี้</p><?php endif; ?>
          <?php foreach ($pickupToday as $p): [$l, $cl] = booking_status_label($p['status']); ?>
            <div class="mb-2 rounded-lg border border-line px-3 py-2.5">
              <div class="flex justify-between gap-2">
                <b class="text-sm"><?= e(date('H:i', strtotime($p['pickup_at']))) ?> น.</b>
                <span class="badge badge-<?= e($cl) ?>"><?= e($l) ?></span>
              </div>
              <div class="text-sm"><?= e($p['brand'] . ' ' . $p['model']) ?> · <?= e($p['plate']) ?></div>
              <div class="text-xs text-muted"><?= e($p['contact_name']) ?> · <?= e($p['contact_phone']) ?></div>
              <a class="text-xs" href="<?= url('admin/bookings/' . (int) $p['id']) ?>">เปิดงาน <?= e($p['booking_no']) ?> →</a>
            </div>
          <?php endforeach; ?>
        </div>
        <div>
          <h4 class="text-sm text-ok">🔙 รับคืนรถ (<?= count($returnToday) ?>)</h4>
          <?php if (!$returnToday): ?><p class="text-sm text-muted">ไม่มีงานรับคืนวันนี้</p><?php endif; ?>
          <?php foreach ($returnToday as $p): ?>
            <div class="mb-2 rounded-lg border border-line px-3 py-2.5">
              <div class="flex justify-between gap-2">
                <b class="text-sm"><?= e(date('H:i', strtotime($p['return_at']))) ?> น.</b>
                <span class="badge badge-primary">รับรถแล้ว</span>
              </div>
              <div class="text-sm"><?= e($p['brand'] . ' ' . $p['model']) ?> · <?= e($p['plate']) ?></div>
              <div class="text-xs text-muted"><?= e($p['contact_name']) ?> · <?= e($p['contact_phone']) ?></div>
              <a class="text-xs" href="<?= url('admin/bookings/' . (int) $p['id']) ?>">เปิดงาน <?= e($p['booking_no']) ?> →</a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php if ($isOwner): ?>
      <!-- รายได้ย้อนหลัง -->
      <div class="panel mt-4">
        <h3>รายได้รับจริง 6 เดือนย้อนหลัง</h3>
        <div class="mt-2 flex h-[180px] items-end gap-2.5 pt-2.5">
          <?php foreach ($trend as $t): ?>
            <div class="flex h-full flex-1 flex-col items-center justify-end gap-1.5">
              <div class="text-xs font-bold tabular-nums text-ink-2">฿<?= money0($t['value'] / 1000) ?>k</div>
              <div class="min-h-[3px] w-full rounded-t-[5px] bg-brand hover:bg-accent" style="height:<?= max(2, (int) round($t['value'] / $maxTrend * 100)) ?>%" title="<?= e(money($t['value'])) ?> บาท"></div>
              <div class="whitespace-nowrap text-xs text-muted"><?= e($t['label']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- รายได้ต่อคัน -->
      <div class="panel mt-4">
        <h3>รายได้ต่อคัน เดือนนี้</h3>
        <div class="table-wrap mt-2">
          <table class="table">
            <thead><tr><th>รถ</th><th class="num">งาน</th><th class="num">วันเช่า</th><th class="num">ยอดจอง</th><th class="w-1/4">สัดส่วน</th></tr></thead>
            <tbody>
              <?php foreach ($perCar as $r): ?>
                <tr>
                  <td><b class="text-sm"><?= e($r['brand'] . ' ' . $r['model']) ?></b><br><span class="text-xs text-muted"><?= e($r['plate']) ?></span></td>
                  <td class="num"><?= (int) $r['jobs'] ?></td>
                  <td class="num"><?= (int) $r['days'] ?></td>
                  <td class="num">฿<?= money0($r['revenue']) ?></td>
                  <td><div class="meter"><i style="width:<?= (int) round((float) $r['revenue'] / $maxCarRev * 100) ?>%"></i></div></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- คอลัมน์ขวา -->
  <div class="min-w-0">
    <div class="panel">
      <h3>ทางลัด</h3>
      <a class="btn btn-block" href="<?= url('admin/bookings/new') ?>">+ สร้างการจองใหม่</a>
      <a class="btn btn-ghost btn-block mt-2" href="<?= url('admin/payments') ?>">ตรวจสลิป (<?= (int) $slipWait ?>)</a>
      <a class="btn btn-ghost btn-block mt-2" href="<?= url('admin/calendar') ?>">ดูปฏิทินรถ</a>
      <a class="btn btn-ghost btn-block mt-2" href="<?= url('admin/documents/new?type=quotation') ?>">ออกใบเสนอราคา</a>
    </div>

    <div class="panel mt-4">
      <h3>งานประจำรถใกล้ครบกำหนด</h3>
      <?php if (!$dueTasks): ?>
        <p class="mb-0 text-sm text-muted">ไม่มีงานที่ใกล้ครบกำหนดใน <?= (int) $reminderDays ?> วันข้างหน้า</p>
      <?php endif; ?>
      <?php foreach ($dueTasks as $t):
          $left = (int) floor((strtotime($t['due_date']) - strtotime($today)) / 86400);
          $cls  = $left < 0 ? 'danger' : ($left <= 7 ? 'warn' : 'info'); ?>
        <div class="flex items-start gap-2.5 border-b border-line py-[9px]">
          <span class="badge badge-<?= $cls ?> flex-none"><?= $left < 0 ? 'เกิน ' . abs($left) . ' วัน' : $left . ' วัน' ?></span>
          <div class="min-w-0">
            <div class="text-sm"><?= e($t['title']) ?></div>
            <div class="text-xs text-muted"><?= e($t['plate']) ?> · ครบ <?= e(thai_date($t['due_date'])) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <a class="btn btn-ghost btn-sm btn-block mt-3" href="<?= url('admin/maintenance') ?>">ดูทั้งหมด</a>
    </div>

    <div class="panel mt-4">
      <h3>สถานะรถ</h3>
      <?php
      $labels = ['available' => ['พร้อมให้เช่า', 'ok'], 'maintenance' => ['เข้าศูนย์/ซ่อม', 'warn'], 'inactive' => ['ปิดใช้งาน', 'muted']];
      foreach ($carStatus as $cs):
          [$l, $c] = $labels[$cs['status']] ?? [$cs['status'], 'muted']; ?>
        <div class="sum-row border-b border-line">
          <span><span class="badge badge-<?= $c ?>"><?= e($l) ?></span></span>
          <span><b><?= (int) $cs['n'] ?></b> คัน</span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
