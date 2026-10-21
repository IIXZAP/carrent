<?php
/**
 * รายงานผลประกอบการรายเดือน
 * ตัวแปร: ดูที่ MonthlyReportService::build() + note, srcLabel
 */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'monthly-report']);

$fmtDays = static fn(float $d): string => rtrim(rtrim(number_format($d, 1), '0'), '.');

/** ป้ายเทียบเดือนก่อน: ▲ 12% / ▼ 5% / เท่าเดิม — $goodUp บอกว่าขึ้นแล้วดีหรือแย่ */
$delta = static function (float $now, float $before, bool $goodUp = true, bool $points = false): string {
    $muted = static fn(string $t): string => '<span class="text-[0.78rem] text-muted">' . $t . '</span>';
    if ($before == 0 && $now == 0) {
        return $muted('เท่าเดือนก่อน');
    }
    if ($before == 0) {
        return $muted('เดือนก่อนไม่มีข้อมูล');
    }
    $d = $points ? $now - $before : ($now - $before) / abs($before) * 100;
    if (abs($d) < 0.5) {
        return $muted('เท่าเดือนก่อน');
    }
    $good = ($d > 0) === $goodUp;
    return '<span class="text-[0.78rem] font-semibold ' . ($good ? 'text-ok' : 'text-danger') . '">' . ($d > 0 ? '▲' : '▼') . ' '
         . number_format(abs($d), $points ? 1 : 0) . ($points ? ' จุด' : '%') . ' จากเดือนก่อน</span>';
};
$kpi   = 'flex min-w-0 flex-col gap-px bg-white px-[15px] py-[13px] print:[print-color-adjust:exact]';
$kpiK  = 'text-[0.78rem] font-semibold text-muted';
$kpiB  = 'text-[1.5rem] leading-tight text-dark tabular-nums';
$kpiS  = 'text-[0.76rem] text-muted';
$h3    = 'mb-2 border-b border-line pb-1.5 text-base';
$noteTone = ['ok' => 'border-l-ok', 'warn' => 'border-l-accent', 'muted' => 'border-l-line'];
?>
<div class="mb-3.5 flex flex-wrap items-center gap-2 print:hidden">
  <a class="btn btn-ghost btn-sm" href="?m=<?= e($pm) ?>">← เดือนก่อน</a>
  <form method="get" class="flex items-center gap-2">
    <label for="mm" class="text-sm text-muted">เดือน</label>
    <input type="month" id="mm" name="m" value="<?= e($m) ?>" max="<?= date('Y-m') ?>" class="!min-h-[40px] !max-w-[190px]">
    <button class="btn btn-ghost btn-sm">ดู</button>
  </form>
  <?php if ($nm <= date('Y-m')): ?><a class="btn btn-ghost btn-sm" href="?m=<?= e($nm) ?>">เดือนถัดไป →</a><?php endif; ?>
  <span class="ml-auto"></span>
  <button class="btn btn-sm" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
</div>

<div class="max-w-[960px] rounded-xl border border-line bg-white px-7 py-[26px] max-sm:px-3.5 max-sm:py-[18px] print:max-w-none print:rounded-none print:border-0 print:p-0">
  <header class="mb-[18px] flex flex-wrap items-end justify-between gap-4 border-b-[3px] border-brand pb-3.5">
    <div>
      <div class="text-[0.8rem] font-bold uppercase tracking-wider text-muted"><?= e(setting('site_name', 'thonglorcarrent')) ?></div>
      <h2 class="mb-0 mt-0.5 text-[1.45rem]">รายงานผลประกอบการ <?= e($monthTitle) ?></h2>
    </div>
    <div class="text-right text-[0.8rem] leading-relaxed text-muted max-sm:text-left">ช่วงข้อมูล <?= e(thai_date($from)) ?> – <?= e(thai_date($to)) ?><br>จัดทำเมื่อ <?= e(thai_date(date('Y-m-d'))) ?> · รถในระบบ <?= (int) $cur['cars'] ?> คัน</div>
  </header>

  <?php if ($m === date('Y-m')): ?>
    <div class="alert alert-warn print:hidden">เดือนนี้ยังไม่จบ ตัวเลขจะเปลี่ยนได้อีก — อัตราการใช้รถคิดเทียบกับทั้งเดือน</div>
  <?php endif; ?>

  <!-- ---------- ตัวเลขหลัก ---------- -->
  <div class="grid grid-cols-1 gap-px overflow-hidden rounded-[10px] border border-line bg-line min-[420px]:grid-cols-2 min-[900px]:grid-cols-3 print:grid-cols-3">
    <div class="<?= $kpi ?>"><span class="<?= $kpiK ?>">รายได้รับจริง</span><b class="<?= $kpiB ?>">฿<?= money0($cur['collected']) ?></b><?= $delta($cur['collected'], $prev['collected']) ?><span class="<?= $kpiS ?>">ไม่รวมเงินประกัน</span></div>
    <div class="<?= $kpi ?>"><span class="<?= $kpiK ?>">อัตราการใช้รถ</span><b class="<?= $kpiB ?>"><?= number_format($cur['util'], 0) ?>%</b><?= $delta($cur['util'], $prev['util'], true, true) ?><span class="<?= $kpiS ?>"><?= e($fmtDays($cur['sold'])) ?> จาก <?= (int) ($cur['cars'] * $dim) ?> วัน-คัน</span></div>
    <div class="<?= $kpi ?>"><span class="<?= $kpiK ?>">งานที่รับรถในเดือน</span><b class="<?= $kpiB ?>"><?= (int) $cur['jobs'] ?></b><?= $delta($cur['jobs'], $prev['jobs']) ?><span class="<?= $kpiS ?>">มูลค่า ฿<?= money0($cur['booked']) ?></span></div>
    <div class="<?= $kpi ?>"><span class="<?= $kpiK ?>">ค่าเช่าเฉลี่ยต่องาน</span><b class="<?= $kpiB ?>">฿<?= money0($cur['ticket']) ?></b><?= $delta($cur['ticket'], $prev['ticket']) ?><span class="<?= $kpiS ?>">รวมค่าใช้จ่ายเพิ่ม</span></div>
    <div class="<?= $kpi ?>"><span class="<?= $kpiK ?>">การจองใหม่</span><b class="<?= $kpiB ?>"><?= (int) $cur['new'] ?></b><?= $delta($cur['new'], $prev['new']) ?><span class="<?= $kpiS ?>">ยกเลิก <?= (int) $cur['cancelled'] ?> (<?= number_format($cur['cancel_rt'], 0) ?>%)</span></div>
    <div class="<?= $kpi ?>"><span class="<?= $kpiK ?>">ค่าบำรุงรักษา</span><b class="<?= $kpiB ?>">฿<?= money0($maintCost) ?></b><span class="text-[0.78rem] text-muted">งานที่ปิดในเดือนนี้</span><span class="<?= $kpiS ?>">คงเหลือหลังหัก ฿<?= money0($cur['collected'] - $maintCost) ?></span></div>
  </div>

  <!-- ---------- รายคัน ---------- -->
  <section class="mt-[22px] print:break-inside-avoid">
    <h3 class="<?= $h3 ?>">ผลงานรายคัน</h3>
    <?php if ($best && $worst && $best['id'] !== $worst['id'] && $cur['sold'] > 0): ?>
      <p class="mb-2 text-[0.9rem] text-ink-2">
        ใช้งานมากที่สุด: <b><?= e($best['brand'] . ' ' . $best['model']) ?></b> (<?= round($best['util']) ?>%)
        · ใช้งานน้อยที่สุด: <b><?= e($worst['brand'] . ' ' . $worst['model']) ?></b> (<?= round($worst['util']) ?>%)
      </p>
    <?php endif; ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>รถ</th><th class="num">งาน</th><th class="num">วันเช่า</th><th class="w-[22%]">อัตราการใช้</th>
          <th class="num">ยอดจอง</th><th class="num">ค่าบำรุง</th><th class="num">คงเหลือ</th></tr></thead>
        <tbody>
          <?php foreach ($carRows as $r): ?>
            <tr>
              <td><b class="text-sm"><?= e($r['brand'] . ' ' . $r['model']) ?></b><span class="block text-xs text-muted"><?= e($r['plate']) ?></span></td>
              <td class="num"><?= (int) $r['jobs'] ?></td>
              <td class="num"><?= e($fmtDays($r['days'])) ?></td>
              <td><div class="meter <?= $r['util'] < 20 ? 'danger' : ($r['util'] < 45 ? 'warn' : '') ?>"><i style="width:<?= (int) min(100, round($r['util'])) ?>%"></i></div>
                <span class="text-xs text-muted"><?= round($r['util']) ?>%</span></td>
              <td class="num">฿<?= money0($r['revenue']) ?></td>
              <td class="num"><?= $r['cost'] > 0 ? '฿' . money0($r['cost']) : '–' ?></td>
              <td class="num"><b>฿<?= money0($r['revenue'] - $r['cost']) ?></b></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="mb-0 mt-2 text-xs text-muted">วันเช่านับจากเวลาเช่าจริงที่ตกอยู่ในเดือนนี้ · ยอดจองนับงานที่รับรถในเดือนนี้ ไม่ใช่เงินที่รับจริง</p>
  </section>

  <div class="grid gap-x-[26px] [grid-template-columns:repeat(auto-fit,minmax(280px,1fr))]">
    <section class="mt-[22px] print:break-inside-avoid">
      <h3 class="<?= $h3 ?>">ลูกค้าและช่องทาง</h3>
      <table class="table">
        <tbody>
          <?php foreach ($bySource as $r): ?>
            <tr><td><?= e($srcLabel[$r['source']] ?? $r['source']) ?></td>
              <td class="num"><?= (int) $r['n'] ?> การจอง · <?= $srcTotal ? (int) round($r['n'] / $srcTotal * 100) : 0 ?>%</td></tr>
          <?php endforeach; ?>
          <?php if (!$bySource): ?><tr><td colspan="2" class="text-muted">ไม่มีการจองในเดือนนี้</td></tr><?php endif; ?>
          <tr><td>ลูกค้าใหม่</td><td class="num"><?= (int) $custNew ?> ราย</td></tr>
          <tr><td>ลูกค้าเดิมกลับมาเช่า</td><td class="num"><?= (int) $custBack ?> ราย</td></tr>
        </tbody>
      </table>
    </section>
    <section class="mt-[22px] print:break-inside-avoid">
      <h3 class="<?= $h3 ?>">การดำเนินงาน</h3>
      <table class="table">
        <tbody>
          <tr><td>รับคืนรถ</td><td class="num"><?= (int) $returned ?> ครั้ง · คืนช้า <?= (int) $late ?></td></tr>
          <tr><td>ค่าใช้จ่ายเพิ่มที่เก็บได้</td><td class="num">฿<?= money0($cur['extra']) ?></td></tr>
          <tr><td>เวลาตรวจสลิปเฉลี่ย</td><td class="num"><?= $slipHrs !== null ? number_format((float) $slipHrs, 1) . ' ชม.' : '–' ?></td></tr>
          <tr><td>ตรวจสภาพรถ</td><td class="num"><?= (int) $inspTotal ?> รอบ · ล็อกแล้ว <?= (int) $inspLocked ?></td></tr>
          <tr><td>ข้อความ LINE ที่ส่ง</td><td class="num"><?= number_format($lineSent) ?> · ลูกค้าผูกบัญชี <?= (int) $lineLinked ?></td></tr>
        </tbody>
      </table>
    </section>
  </div>

  <section class="mt-[22px]">
    <h3 class="<?= $h3 ?>">ข้อสังเกตจากข้อมูล</h3>
    <ul class="m-0 list-none p-0">
      <?php foreach ($notes as [$tone, $text]): ?>
        <li class="mb-1.5 rounded-r-md border-l-[3px] bg-surface py-[9px] pl-3.5 pr-3 text-[0.9rem] print:[print-color-adjust:exact] <?= $noteTone[$tone] ?? 'border-l-line' ?>"><?= e($text) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="mt-[22px]">
    <h3 class="<?= $h3 ?>">ข้อเสนอแนะประจำเดือน</h3>
    <?php if ($note !== ''): ?>
      <div class="rounded-lg border border-line px-3.5 py-3 text-[0.92rem] leading-[1.7]"><?= nl2br(e($note)) ?></div>
    <?php else: ?>
      <p class="text-sm text-muted print:hidden">ยังไม่ได้เขียนข้อเสนอแนะของเดือนนี้</p>
    <?php endif; ?>
    <form method="post" action="<?= url('admin/monthly-report?m=' . $m) ?>" class="mt-3 print:hidden">
      <?= csrf_field() ?>
      <div class="field">
        <label for="note">เขียน/แก้ไขข้อเสนอแนะ (แสดงในรายงานที่พิมพ์)</label>
        <textarea id="note" name="note" rows="4" maxlength="3000" placeholder="เช่น แนะนำทำโปรเช่า 3 วันขึ้นไปสำหรับรถตู้ช่วงต้นเดือน"><?= e($note) ?></textarea>
      </div>
      <button class="btn btn-ghost btn-sm">บันทึกข้อเสนอแนะ</button>
    </form>
  </section>

  <footer class="mt-6 border-t border-line pt-2.5 text-center text-xs text-muted">จัดทำโดย KTn Develop · ข้อมูลจากระบบ <?= e(setting('site_name', 'thonglorcarrent')) ?> ณ <?= e(thai_date(date('Y-m-d H:i:s'), true)) ?></footer>
</div>
