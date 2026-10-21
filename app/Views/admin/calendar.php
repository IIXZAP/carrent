<?php
/**
 * ปฏิทินรถแบบ timeline
 * ตัวแปร: month, first, daysIn, prev, next, today, cars, grid, util, allBlocks
 */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'calendar']);

// สีแถบตามสถานะ (เขียนเต็มเพื่อให้ Tailwind สแกนเจอ)
$barClass = [
    'confirmed' => 'bg-brand text-white',
    'pending'   => 'bg-[#c98a17] text-white',
    'picked_up' => 'bg-ok text-white',
    'returned'  => 'bg-[#aab4c2] text-[#2b3542]',
    'completed' => 'bg-[#aab4c2] text-[#2b3542]',
    'block'     => 'bg-[#6b7789] text-white [background-image:repeating-linear-gradient(45deg,transparent,transparent_5px,rgba(255,255,255,.18)_5px,rgba(255,255,255,.18)_10px)]',
];
$dows = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
?>
<div class="toolbar print:hidden">
  <a class="btn btn-ghost btn-sm" href="?m=<?= e($prev) ?>">← <?= e(TH_MONTHS[(int) date('n', strtotime($prev . '-01'))]) ?></a>
  <h2 class="mx-2 mb-0"><?= e(TH_MONTHS_FULL[(int) date('n', strtotime($first))]) ?> <?= (int) date('Y', strtotime($first)) + 543 ?></h2>
  <a class="btn btn-ghost btn-sm" href="?m=<?= e($next) ?>"><?= e(TH_MONTHS[(int) date('n', strtotime($next . '-01'))]) ?> →</a>
  <a class="btn btn-ghost btn-sm" href="?m=<?= date('Y-m') ?>">เดือนนี้</a>
  <span class="badge badge-primary ml-auto">อัตราการใช้รถเดือนนี้ <?= (int) $util ?>%</span>
  <a class="btn btn-sm" href="<?= url('admin/bookings/new') ?>">+ จองใหม่</a>
</div>

<div class="panel">
  <div class="overflow-x-auto rounded-xl border border-line bg-white">
    <table class="w-full min-w-[900px] border-collapse text-[0.82rem]">
      <thead>
        <tr>
          <th class="sticky left-0 z-[3] min-w-[170px] border border-line bg-surface px-2.5 py-1.5 text-left text-[0.86rem] font-semibold">รถ</th>
          <?php for ($d = 1; $d <= $daysIn; $d++):
              $date = sprintf('%s-%02d', $month, $d);
              $dow  = (int) date('w', strtotime($date));
              $we   = ($dow === 0 || $dow === 6); ?>
            <th class="border border-line px-1 py-1.5 text-center text-xs font-bold <?= $we ? 'bg-[#eef1f6] text-danger' : 'bg-surface text-ink-2' ?>"><?= $d ?><br>
              <span class="text-[0.68rem] font-normal"><?= $dows[$dow] ?></span></th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cars as $c): ?>
          <tr>
            <th class="sticky left-0 z-[2] min-w-[170px] border border-line bg-white px-2.5 py-2 text-left text-[0.86rem] font-semibold">
              <?= e($c['brand'] . ' ' . $c['model']) ?>
              <div class="text-xs font-normal text-muted"><?= e($c['plate']) ?> · ฿<?= money0($c['price_day']) ?>/วัน</div>
            </th>
            <?php for ($d = 1; $d <= $daysIn; $d++):
                $date = sprintf('%s-%02d', $month, $d);
                $dow  = (int) date('w', strtotime($date));
                $cell = $grid[(int) $c['id']][$d] ?? null; ?>
              <td class="relative h-9 border border-line p-0 <?= ($dow === 0 || $dow === 6) ? 'bg-[#fafbfd]' : '' ?> <?= $date === $today ? 'shadow-[inset_0_0_0_2px_rgb(var(--c-accent))]' : '' ?>">
                <?php if ($cell): ?>
                  <a class="absolute inset-x-px inset-y-[3px] flex items-center overflow-hidden whitespace-nowrap rounded-[5px] px-[5px] text-[0.7rem] no-underline hover:text-inherit <?= $barClass[$cell['kind']] ?? $barClass['completed'] ?>"
                     href="<?= e($cell['link']) ?>" title="<?= e($cell['title']) ?>"><?= e($cell['label']) ?></a>
                <?php endif; ?>
              </td>
            <?php endfor; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="mt-3 flex flex-wrap gap-4 text-[0.82rem] text-ink-2">
    <span><i class="mr-[5px] inline-block h-[13px] w-[13px] rounded-[3px] bg-[#c98a17] align-[-2px]"></i>รอยืนยัน</span>
    <span><i class="mr-[5px] inline-block h-[13px] w-[13px] rounded-[3px] bg-brand align-[-2px]"></i>ยืนยันแล้ว</span>
    <span><i class="mr-[5px] inline-block h-[13px] w-[13px] rounded-[3px] bg-ok align-[-2px]"></i>รถออกงาน</span>
    <span><i class="mr-[5px] inline-block h-[13px] w-[13px] rounded-[3px] bg-[#aab4c2] align-[-2px]"></i>คืนรถแล้ว</span>
    <span><i class="mr-[5px] inline-block h-[13px] w-[13px] rounded-[3px] bg-[#6b7789] align-[-2px]"></i>ปิดรถ/ซ่อม</span>
    <span><i class="mr-[5px] inline-block h-[13px] w-[13px] rounded-[3px] border-2 border-accent align-[-2px]"></i>วันนี้</span>
  </div>
</div>

<div class="mt-4 grid items-start gap-4 lg:grid-cols-2 print:hidden">
  <div class="panel">
    <h3>ปิดรถชั่วคราว (ซ่อม / เจ้าของใช้เอง)</h3>
    <form method="post" action="<?= url('admin/calendar/block') ?>">
      <?= csrf_field() ?><input type="hidden" name="m" value="<?= e($month) ?>">
      <div class="field"><label>รถ</label>
        <select name="car_id" required>
          <?php foreach ($cars as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= e($c['brand'] . ' ' . $c['model'] . ' — ' . $c['plate']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="grid gap-x-3.5 sm:grid-cols-2">
        <div class="field"><label>ตั้งแต่</label><input type="datetime-local" name="start_at" required></div>
        <div class="field"><label>ถึง</label><input type="datetime-local" name="end_at" required></div>
      </div>
      <div class="field"><label>เหตุผล</label><input type="text" name="reason" placeholder="เช่น เข้าศูนย์เช็กระยะ, เจ้าของใช้เอง"></div>
      <button class="btn btn-block">บันทึกช่วงปิดรถ</button>
    </form>
  </div>

  <div class="panel">
    <h3>ช่วงปิดรถที่ตั้งไว้</h3>
    <?php if (!$allBlocks): ?>
      <p class="mb-0 text-sm text-muted">ยังไม่มีช่วงปิดรถในอนาคต</p>
    <?php endif; ?>
    <div class="table-wrap">
      <table class="table">
        <tbody>
          <?php foreach ($allBlocks as $bl): ?>
            <tr>
              <td class="text-sm"><b><?= e($bl['plate']) ?></b><br><span class="text-xs text-muted"><?= e($bl['reason']) ?></span></td>
              <td class="text-sm"><?= e(thai_date($bl['start_at'], true)) ?><br><span class="text-muted">→ <?= e(thai_date($bl['end_at'], true)) ?></span></td>
              <td class="text-right">
                <form method="post" action="<?= url('admin/calendar/unblock') ?>" onsubmit="return confirm('ยกเลิกช่วงปิดรถนี้?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="block_id" value="<?= (int) $bl['id'] ?>">
                  <input type="hidden" name="m" value="<?= e($month) ?>">
                  <button class="btn btn-ghost btn-sm">ลบ</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
