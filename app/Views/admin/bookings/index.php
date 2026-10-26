<?php use App\Services\BookingStatus; use App\Services\Phone; ?>
<div class="flex items-center justify-between">
  <h1 class="text-2xl font-bold">การจอง</h1>
  <a class="btn btn-primary" href="<?= e(url('/admin/bookings/create')) ?>">+ สร้างการจอง</a>
</div>

<form method="get" class="mt-4 flex flex-wrap gap-3">
  <select class="field w-auto" name="status" onchange="this.form.submit()">
    <option value="">ทุกสถานะ</option>
    <?php foreach (BookingStatus::STATUS as $code => $label): ?>
      <option value="<?= e($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= e($label) ?> (<?= e($counts[$code] ?? 0) ?>)</option>
    <?php endforeach; ?>
  </select>
  <input class="field w-64" type="search" name="q" value="<?= e($q) ?>" placeholder="เลขที่จอง / ชื่อ / เบอร์ / ทะเบียน">
  <button class="btn">ค้นหา</button>
</form>

<div class="panel mt-4 overflow-x-auto p-0">
  <table class="table">
    <thead><tr><th>เลขที่จอง</th><th>ผู้เช่า</th><th>รถ</th><th>รับ → คืน</th><th class="text-right">ยอดรวม</th><th>สถานะ</th><th>ชำระ</th></tr></thead>
    <tbody>
    <?php foreach ($bookings as $b): ?>
      <tr>
        <td><a class="text-brand-700 hover:underline font-medium" href="<?= e(url('/admin/bookings/' . $b['id'])) ?>"><?= e($b['booking_no']) ?></a></td>
        <td><?= e($b['contact_name']) ?><br><span class="text-slate-500"><?= e(Phone::display($b['contact_phone'])) ?></span></td>
        <td><?= e($b['brand'] . ' ' . $b['model']) ?><br><span class="text-slate-500"><?= e($b['plate']) ?></span></td>
        <td class="whitespace-nowrap"><?= e(date('d/m/y H:i', strtotime($b['pickup_at']))) ?><br>→ <?= e(date('d/m/y H:i', strtotime($b['return_at']))) ?></td>
        <td class="text-right">฿<?= e(money($b['grand_total'])) ?></td>
        <td><span class="badge badge-<?= e($b['status']) ?>"><?= e(BookingStatus::STATUS[$b['status']]) ?></span></td>
        <td><?= e(BookingStatus::PAYMENT[$b['payment_status']]) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$bookings): ?><tr><td colspan="7" class="text-center text-slate-500 py-8">ไม่พบการจอง</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
