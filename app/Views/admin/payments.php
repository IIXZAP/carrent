<?php
/**
 * ตรวจสลิป / อนุมัติการชำระเงิน
 * ตัวแปร: filter, rows, cntPending, sumPending, sumToday
 */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'payments']);
$kindLabel   = ['deposit' => 'มัดจำ', 'balance' => 'ค่าเช่า', 'security' => 'เงินประกัน', 'extra' => 'ค่าใช้จ่ายเพิ่ม',
                'refund' => 'คืนเงินค่าเช่า', 'security_refund' => 'คืนเงินประกัน'];
$methodLabel = ['cash' => 'เงินสด', 'transfer' => 'โอนธนาคาร', 'promptpay' => 'PromptPay', 'card' => 'บัตรเครดิต', 'deduct' => 'หักจากเงินประกัน'];
?>
<div class="stat-grid-md">
  <div class="stat <?= $cntPending ? 'stat-danger' : 'stat-ok' ?>">
    <div class="lb">สลิปรอตรวจสอบ</div><div class="vl"><?= (int) $cntPending ?></div>
    <div class="sub">มูลค่า ฿<?= money0($sumPending) ?></div>
  </div>
  <div class="stat stat-ok"><div class="lb">อนุมัติวันนี้</div><div class="vl">฿<?= money0($sumToday) ?></div></div>
</div>

<div class="toolbar">
  <?php foreach (['pending' => 'รอตรวจสอบ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่ผ่าน', 'all' => 'ทั้งหมด'] as $k => $l): ?>
    <a class="btn btn-sm <?= $filter === $k ? '' : 'btn-ghost' ?>" href="?status=<?= e($k) ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>การจอง</th><th>ลูกค้า</th><th>ประเภท</th><th class="num">จำนวน</th><th>วันที่โอน</th><th>สลิป</th><th>สถานะ</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="py-8 text-center text-muted">ไม่มีรายการ</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $due = (float) $r['grand_total'] + (float) $r['extra_charge']; ?>
          <tr>
            <td>
              <a href="<?= url('admin/bookings/' . (int) $r['booking_id']) ?>"><b><?= e($r['booking_no']) ?></b></a>
              <div class="text-xs text-muted"><?= e($r['brand'] . ' ' . $r['model']) ?> · <?= e($r['plate']) ?></div>
            </td>
            <td class="text-sm"><?= e($r['contact_name']) ?><div class="text-xs text-muted"><?= e($r['contact_phone']) ?></div></td>
            <td class="text-sm"><?= e($kindLabel[$r['kind']] ?? $r['kind']) ?><div class="text-xs text-muted"><?= e($methodLabel[$r['method']] ?? $r['method']) ?></div></td>
            <td class="num">
              <b><?= money($r['amount']) ?></b>
              <div class="text-xs text-muted">ยอดรวม <?= money0($due) ?> · จ่ายแล้ว <?= money0($r['paid_amount']) ?></div>
            </td>
            <td class="text-sm"><?= e(thai_date($r['paid_at'] ?: $r['created_at'], true)) ?></td>
            <td>
              <?php if ($r['slip_file']): ?>
                <a href="<?= e(upload_url($r['slip_file'])) ?>" target="_blank" rel="noopener">
                  <?php if (preg_match('/\.(jpe?g|png|webp)$/i', $r['slip_file'])): ?>
                    <img class="h-14 w-14 rounded-md border border-line object-cover" src="<?= e(upload_url($r['slip_file'])) ?>" alt="สลิป">
                  <?php else: ?>ดูไฟล์ PDF<?php endif; ?>
                </a>
              <?php else: ?><span class="text-muted">-</span><?php endif; ?>
            </td>
            <td>
              <?php if ($r['status'] === 'approved'): ?>
                <span class="badge badge-ok">อนุมัติแล้ว</span>
                <div class="text-xs text-muted"><?= e($r['approver'] ?: '') ?><br><?= e(thai_date($r['approved_at'])) ?></div>
              <?php elseif ($r['status'] === 'rejected'): ?>
                <span class="badge badge-danger">ไม่ผ่าน</span>
                <div class="text-xs text-muted"><?= e($r['reject_reason']) ?></div>
              <?php else: ?>
                <span class="badge badge-warn">รอตรวจ</span>
              <?php endif; ?>
            </td>
            <td class="whitespace-nowrap text-right">
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post" action="<?= url('admin/payments/approve') ?>" class="inline-block"
                      onsubmit="return confirm('ยืนยันว่าได้รับเงิน <?= e(money($r['amount'])) ?> บาทแล้ว?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="payment_id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm">อนุมัติ</button>
                </form>
                <details class="relative inline-block text-left">
                  <summary class="btn btn-ghost btn-sm list-none summary-plain">ไม่ผ่าน</summary>
                  <form method="post" action="<?= url('admin/payments/reject') ?>"
                        class="absolute right-0 z-20 mt-1.5 w-[250px] rounded-lg border border-line bg-white p-3 shadow-card">
                    <?= csrf_field() ?>
                    <input type="hidden" name="payment_id" value="<?= (int) $r['id'] ?>">
                    <div class="field"><label>เหตุผล</label><input type="text" name="reason" required></div>
                    <button class="btn btn-danger btn-sm btn-block">ยืนยันปฏิเสธ</button>
                  </form>
                </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
