<?php /** $booking $kinds */ $id = (int) $booking['id']; ?>
<a href="<?= e(url('/admin/bookings/' . $id)) ?>" class="text-sm text-slate-500 hover:text-slate-800">← <?= e($booking['booking_no']) ?></a>
<h1 class="text-2xl font-bold mt-2">สัญญาและลายเซ็น <span class="text-slate-400"><?= e($booking['booking_no']) ?></span></h1>

<?php foreach ($kinds as $kind => $k): ?>
<div class="panel mt-6">
  <div class="flex flex-wrap items-center gap-2">
    <h2 class="font-semibold mr-auto"><?= e($k['label']) ?></h2>
    <a class="btn" target="_blank" href="<?= e(url('/admin/bookings/' . $id . '/contract/print?kind=' . $kind)) ?>">พิมพ์</a>
  </div>

  <?php if ($k['active']): $sig = $k['active']; ?>
    <div class="mt-3 grid gap-4 sm:grid-cols-[220px_1fr]">
      <img class="rounded border border-slate-200 bg-white w-full" alt="ลายเซ็น" src="<?= e(url('/admin/signatures/' . $sig['id'] . '/image')) ?>">
      <div class="text-sm space-y-1">
        <p><span class="badge badge-completed">ลงนามแล้ว</span> <b><?= e($sig['signer_name']) ?></b></p>
        <p>เมื่อ <?= e(thai_date($sig['signed_at'], true)) ?> · IP <?= e($sig['ip']) ?></p>
        <p class="text-slate-500 break-all">อุปกรณ์: <?= e($sig['user_agent']) ?></p>
        <p>รหัสอ้างอิง <b><?= e(\App\Services\SignatureService::ref($sig)) ?></b></p>
        <?php if (!$k['intact']): ?><p class="text-red-700 font-semibold">⚠ สำเนาที่เก็บไม่ตรงกับ hash — ข้อมูลถูกแก้ในฐานข้อมูลโดยตรง ห้ามใช้เป็นหลักฐาน</p><?php endif; ?>
      </div>
    </div>
    <?php if ($k['changes']): ?>
      <div class="alert alert-error mt-3 text-sm">
        <p class="font-semibold">ข้อมูลถูกแก้ไขหลังลูกค้าลงนาม</p>
        <ul class="list-disc pl-5 mt-1">
          <?php foreach ($k['changes'] as $c): ?><li><?= e($c['label']) ?>: ตอนเซ็น <b><?= e($c['signed']) ?></b> → ปัจจุบัน <b><?= e($c['now']) ?></b></li><?php endforeach; ?>
        </ul>
        <p class="mt-1">ถ้าต้องการให้ลูกค้ารับรองข้อมูลใหม่ ให้ยกเลิกลายเซ็นแล้วส่งลิงก์ให้เซ็นอีกครั้ง</p>
      </div>
    <?php endif; ?>
    <?php if (\App\Services\Auth::isAdmin()): ?>
    <form method="post" action="<?= e(url('/admin/signatures/' . $sig['id'] . '/void')) ?>" class="mt-4 flex flex-wrap items-end gap-2" onsubmit="return confirm('ยกเลิกลายเซ็นนี้เพื่อให้ลูกค้าเซ็นใหม่? ฉบับเดิมยังเก็บไว้เป็นประวัติ')">
      <?= csrf_field() ?>
      <div class="grow"><label class="label" for="reason-<?= e($sig['id']) ?>">เหตุผลที่ยกเลิก (เพื่อเซ็นใหม่)</label><input class="field" id="reason-<?= e($sig['id']) ?>" name="reason" maxlength="250" required></div>
      <button class="btn">ยกเลิกลายเซ็น</button>
    </form>
    <?php endif; ?>
  <?php elseif ($k['blocker']): ?>
    <p class="text-sm text-slate-500 mt-3"><?= e($k['blocker']) ?></p>
  <?php else: ?>
    <p class="text-sm text-slate-600 mt-3">ยังไม่ลงนาม — ส่งลิงก์นี้ให้ลูกค้า (เปิดบนมือถือได้ ไม่ต้องล็อกอิน)</p>
    <div class="flex gap-2 mt-2">
      <input class="field font-mono text-xs" readonly value="<?= e($k['link']) ?>" onclick="this.select()">
      <button type="button" class="btn" onclick="navigator.clipboard.writeText(this.previousElementSibling.value); this.textContent='คัดลอกแล้ว'">คัดลอก</button>
    </div>
    <p class="text-xs text-slate-500 mt-1">ลิงก์นี้ใช้เซ็นได้เฉพาะการจองนี้ — อย่าโพสต์ในที่สาธารณะ</p>
  <?php endif; ?>

  <?php $old = array_values(array_filter($k['history'], static fn($s) => $s['voided_at'] !== null)); if ($old): ?>
    <h3 class="font-medium text-sm mt-5">ฉบับที่ยกเลิกแล้ว</h3>
    <ul class="text-sm mt-1 space-y-1 text-slate-600">
      <?php foreach ($old as $s): ?>
        <li><a class="text-brand-700 hover:underline" target="_blank" href="<?= e(url('/admin/signatures/' . $s['id'] . '/image')) ?>"><?= e($s['signer_name']) ?></a>
          ลงนาม <?= e(thai_date($s['signed_at'], true)) ?> · ยกเลิก <?= e(thai_date($s['voided_at'], true)) ?> โดย <?= e($s['voider'] ?? '-') ?> — <?= e($s['void_reason']) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php endforeach; ?>
