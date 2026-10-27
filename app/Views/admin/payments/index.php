<?php use App\Models\Payment; use App\Services\Phone; ?>
<h1 class="text-2xl font-bold">ชำระเงิน / ตรวจสลิป</h1>

<div class="mt-4 flex flex-wrap gap-2 text-sm">
  <?php foreach (['pending' => 'รอตรวจสอบ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ปฏิเสธ', 'all' => 'ทั้งหมด'] as $code => $label): ?>
    <a href="<?= e(url('/admin/payments?status=' . $code)) ?>"
       class="rounded-full border px-3 py-1 <?= $status === $code ? 'bg-slate-900 text-white border-slate-900' : 'bg-white border-slate-300 hover:bg-slate-50' ?>">
      <?= e($label) ?><?= $code !== 'all' ? ' (' . e($counts[$code]) . ')' : '' ?></a>
  <?php endforeach; ?>
</div>

<div class="panel mt-4 overflow-x-auto p-0">
  <table class="table">
    <thead><tr><th>วันที่โอน</th><th>การจอง</th><th>รายการ</th><th class="text-right">จำนวน</th><th>สถานะ</th><th>สลิป</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td class="whitespace-nowrap"><?= e(date('d/m/y H:i', strtotime($p['paid_at'] ?? $p['created_at']))) ?></td>
        <td><a class="text-brand-700 hover:underline font-medium" href="<?= e(url('/admin/bookings/' . $p['booking_id'])) ?>"><?= e($p['booking_no']) ?></a>
          <br><span class="text-slate-500"><?= e($p['contact_name']) ?> · <?= e(Phone::display($p['contact_phone'])) ?></span>
          <br><span class="text-xs text-slate-500">ยอดรวม ฿<?= e(money($p['grand_total'])) ?> · มัดจำ ฿<?= e(money($p['deposit_amount'])) ?></span></td>
        <td><?= e(Payment::KINDS[$p['kind']]) ?><br><span class="text-xs text-slate-500"><?= e(Payment::METHODS[$p['method']]) ?></span></td>
        <td class="text-right font-medium">฿<?= e(money($p['amount'])) ?></td>
        <td><span class="badge badge-<?= e($p['status']) ?>"><?= e(Payment::STATUS[$p['status']]) ?></span>
          <?php if ($p['approver']): ?><br><span class="text-xs text-slate-500">โดย <?= e($p['approver']) ?></span><?php endif; ?>
          <?php if ($p['reject_reason']): ?><br><span class="text-xs text-red-700"><?= e($p['reject_reason']) ?></span><?php endif; ?></td>
        <td><?php if ($p['slip_file']): ?><a class="text-brand-700 hover:underline" target="_blank" rel="noopener" href="<?= e(url('/admin/payments/' . $p['id'] . '/slip')) ?>">ดูสลิป</a><?php endif; ?></td>
        <td class="whitespace-nowrap">
          <?php if ($p['status'] === 'pending'): ?>
            <form class="inline" method="post" action="<?= e(url('/admin/payments/' . $p['id'] . '/approve')) ?>"><?= csrf_field() ?>
              <button class="btn !px-3 !py-1 text-green-700 border-green-300 hover:bg-green-50">อนุมัติ</button></form>
            <form class="inline" method="post" action="<?= e(url('/admin/payments/' . $p['id'] . '/reject')) ?>"
                  onsubmit="var r = prompt('เหตุผลที่ปฏิเสธ (ลูกค้าจะเห็น)', 'ยอดไม่ตรง'); if (r === null) { return false; } this.reason.value = r; return true;"><?= csrf_field() ?>
              <input type="hidden" name="reason"><button class="btn !px-3 !py-1 text-red-700 border-red-300 hover:bg-red-50">ปฏิเสธ</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$payments): ?><tr><td colspan="7" class="text-center text-slate-500 py-8">ไม่มีรายการ</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
