<?php
/**
 * รายงานผลประกอบการรายเดือน — ฉบับเดียวจบ พิมพ์เป็น PDF ส่งเจ้าของร้านได้เลย
 *
 * ต่างจากหน้า "รายงาน" ตรงที่: เทียบกับเดือนก่อนให้ทุกตัวเลข ชี้รถที่ทำเงินดี–ด้อยที่สุด
 * สรุปข้อสังเกตจากข้อมูลให้อัตโนมัติ และมีช่องข้อเสนอแนะประจำเดือนที่บันทึกแยกรายเดือน
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_admin();

// ---------- เดือนที่ดู (ค่าเริ่มต้น = เดือนที่แล้ว เพราะรายงานทำหลังจบเดือน) ----------
$m = (string) ($_GET['m'] ?? date('Y-m', strtotime('first day of last month')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
    $m = date('Y-m', strtotime('first day of last month'));
}
$from  = $m . '-01';
$to    = date('Y-m-t', strtotime($from));
$dim   = (int) date('t', strtotime($from));
$pm    = date('Y-m', strtotime($from . ' -1 month'));
$pFrom = $pm . '-01';
$pTo   = date('Y-m-t', strtotime($pFrom));
$pDim  = (int) date('t', strtotime($pFrom));
$nm    = date('Y-m', strtotime($from . ' +1 month'));
$title = TH_MONTHS_FULL[(int) date('n', strtotime($from))] . ' ' . ((int) date('Y', strtotime($from)) + 543);
$noteKey = 'report_note_' . $m;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    set_setting($noteKey, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 3000));
    log_activity('monthly_report_note', null, null, $m);
    flash('ok', 'บันทึกข้อเสนอแนะประจำเดือนแล้ว');
    redirect('admin/monthly-report.php?m=' . $m);
}

/** ตัวเลขหลักของช่วงเวลาหนึ่ง — ใช้คำนวณทั้งเดือนนี้และเดือนก่อนด้วยสูตรเดียวกัน */
function month_metrics(string $from, string $to, int $dim): array
{
    $cars = (int) db_val("SELECT COUNT(*) FROM cars WHERE status <> 'inactive'");
    $days = car_days_sold($from, $to);
    $sold = array_sum($days);
    $all  = (int) db_val('SELECT COUNT(*) FROM bookings WHERE DATE(created_at) BETWEEN :a AND :b', [':a' => $from, ':b' => $to]);
    $canc = (int) db_val("SELECT COUNT(*) FROM bookings WHERE status = 'cancelled' AND DATE(created_at) BETWEEN :a AND :b", [':a' => $from, ':b' => $to]);
    $jobs = db_one(
        "SELECT COUNT(*) n, COALESCE(SUM(grand_total + extra_charge),0) v, COALESCE(SUM(extra_charge),0) x
         FROM bookings WHERE status <> 'cancelled' AND DATE(pickup_at) BETWEEN :a AND :b",
        [':a' => $from, ':b' => $to]
    );
    return [
        'cars'      => $cars,
        'days_map'  => $days,
        'sold'      => $sold,
        'util'      => $cars > 0 ? $sold / ($cars * $dim) * 100 : 0,
        'collected' => revenue_collected($from, $to),
        'new'       => $all,
        'cancelled' => $canc,
        'cancel_rt' => $all > 0 ? $canc / $all * 100 : 0,
        'jobs'      => (int) $jobs['n'],
        'booked'    => (float) $jobs['v'],
        'extra'     => (float) $jobs['x'],
        'ticket'    => (int) $jobs['n'] > 0 ? (float) $jobs['v'] / (int) $jobs['n'] : 0,
    ];
}

$cur  = month_metrics($from, $to, $dim);
$prev = month_metrics($pFrom, $pTo, $pDim);

/** ป้ายเทียบเดือนก่อน: ▲ 12% / ▼ 5% / เท่าเดิม — $goodUp บอกว่าขึ้นแล้วดีหรือแย่ */
function delta(float $now, float $before, bool $goodUp = true, bool $points = false): string
{
    if ($before == 0 && $now == 0) {
        return '<span class="dl muted">เท่าเดือนก่อน</span>';
    }
    if ($before == 0) {
        return '<span class="dl muted">เดือนก่อนไม่มีข้อมูล</span>';
    }
    $d = $points ? $now - $before : ($now - $before) / abs($before) * 100;
    if (abs($d) < 0.5) {
        return '<span class="dl muted">เท่าเดือนก่อน</span>';
    }
    $good = ($d > 0) === $goodUp;
    return '<span class="dl ' . ($good ? 'up' : 'down') . '">' . ($d > 0 ? '▲' : '▼') . ' '
         . number_format(abs($d), $points ? 1 : 0) . ($points ? ' จุด' : '%') . ' จากเดือนก่อน</span>';
}

// ---------- รายคัน ----------
$carRows = db_all(
    "SELECT c.id, c.plate, c.brand, c.model, c.car_type, c.price_day,
            COUNT(b.id) jobs, COALESCE(SUM(b.grand_total + b.extra_charge),0) revenue
     FROM cars c
     LEFT JOIN bookings b ON b.car_id = c.id AND b.status <> 'cancelled' AND DATE(b.pickup_at) BETWEEN :a AND :b
     WHERE c.status <> 'inactive'
     GROUP BY c.id",
    [':a' => $from, ':b' => $to]
);
$cost = [];
foreach (db_all("SELECT car_id, COALESCE(SUM(cost),0) c FROM maintenances WHERE status = 'done' AND done_date BETWEEN :a AND :b GROUP BY car_id",
    [':a' => $from, ':b' => $to]) as $r) {
    $cost[(int) $r['car_id']] = (float) $r['c'];
}
foreach ($carRows as &$r) {
    $r['days'] = $cur['days_map'][(int) $r['id']] ?? 0;
    $r['util'] = $r['days'] / $dim * 100;
    $r['cost'] = $cost[(int) $r['id']] ?? 0;
}
unset($r);
usort($carRows, fn($a, $b) => $b['util'] <=> $a['util']);
$best  = $carRows[0] ?? null;
$worst = $carRows ? $carRows[count($carRows) - 1] : null;

// ---------- ที่มาของการจอง / ลูกค้าใหม่–เก่า ----------
$srcLabel = ['web' => 'จองผ่านเว็บ', 'admin' => 'หน้าร้าน', 'line' => 'LINE', 'phone' => 'โทรศัพท์'];
$bySource = db_all(
    "SELECT source, COUNT(*) n FROM bookings WHERE status <> 'cancelled' AND DATE(created_at) BETWEEN :a AND :b
     GROUP BY source ORDER BY n DESC", [':a' => $from, ':b' => $to]);
$srcTotal = array_sum(array_column($bySource, 'n'));
$webShare = 0;
foreach ($bySource as $r) {
    if ($r['source'] === 'web') {
        $webShare = $srcTotal ? $r['n'] / $srcTotal * 100 : 0;
    }
}
$custNew = (int) db_val(
    "SELECT COUNT(DISTINCT b.customer_id) FROM bookings b
     WHERE b.status <> 'cancelled' AND b.customer_id IS NOT NULL AND DATE(b.created_at) BETWEEN :a AND :b
       AND NOT EXISTS (SELECT 1 FROM bookings o WHERE o.customer_id = b.customer_id AND o.status <> 'cancelled' AND o.created_at < :s)",
    [':a' => $from, ':b' => $to, ':s' => $from . ' 00:00:00']);
$custAll = (int) db_val(
    "SELECT COUNT(DISTINCT customer_id) FROM bookings
     WHERE status <> 'cancelled' AND customer_id IS NOT NULL AND DATE(created_at) BETWEEN :a AND :b",
    [':a' => $from, ':b' => $to]);
$custBack = max(0, $custAll - $custNew);

// ---------- การดำเนินงาน ----------
$late = (int) db_val(
    "SELECT COUNT(*) FROM bookings WHERE returned_at IS NOT NULL AND DATE(returned_at) BETWEEN :a AND :b
       AND returned_at > DATE_ADD(return_at, INTERVAL 1 HOUR)", [':a' => $from, ':b' => $to]);
$returned = (int) db_val("SELECT COUNT(*) FROM bookings WHERE returned_at IS NOT NULL AND DATE(returned_at) BETWEEN :a AND :b",
    [':a' => $from, ':b' => $to]);
$slipHrs = db_val(
    "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, approved_at)) / 60 FROM payments
     WHERE status = 'approved' AND slip_file IS NOT NULL AND DATE(approved_at) BETWEEN :a AND :b", [':a' => $from, ':b' => $to]);
$maintCost = array_sum($cost);
$busyDow = db_one(
    "SELECT DAYOFWEEK(pickup_at) d, COUNT(*) n FROM bookings WHERE status <> 'cancelled' AND DATE(pickup_at) BETWEEN :a AND :b
     GROUP BY DAYOFWEEK(pickup_at) ORDER BY n DESC LIMIT 1", [':a' => $from, ':b' => $to]);
$dowName = [1 => 'อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];

$lineLinked = 0; $lineSent = 0; $inspLocked = 0; $inspTotal = 0;
try {
    $lineLinked = (int) db_val("SELECT COUNT(DISTINCT line_user_id) FROM bookings WHERE line_user_id IS NOT NULL AND line_user_id <> ''");
    $lineSent   = (int) db_val("SELECT COUNT(*) FROM notifications WHERE channel = 'line' AND status = 'sent' AND DATE(sent_at) BETWEEN :a AND :b",
        [':a' => $from, ':b' => $to]);
    $inspTotal  = (int) db_val('SELECT COUNT(*) FROM inspections WHERE DATE(inspected_at) BETWEEN :a AND :b', [':a' => $from, ':b' => $to]);
    $inspLocked = (int) db_val('SELECT COUNT(*) FROM inspections WHERE locked_at IS NOT NULL AND DATE(inspected_at) BETWEEN :a AND :b', [':a' => $from, ':b' => $to]);
} catch (Throwable $e) {
    // ฐานข้อมูลยังไม่ถึงเวอร์ชัน 4 — ข้ามตัวเลขส่วนนี้
}

// ---------- ข้อสังเกตอัตโนมัติ: สรุปจากตัวเลขเท่านั้น ไม่เดา ----------
$notes = [];
if ($cur['jobs'] === 0 && $cur['new'] === 0) {
    $notes[] = ['muted', 'เดือนนี้ยังไม่มีการจองในระบบ จึงยังสรุปข้อสังเกตไม่ได้'];
} else {
    foreach ($carRows as $r) {
        if ($r['util'] >= 75) {
            $notes[] = ['ok', sprintf('%s %s (%s) ถูกใช้ %d%% ของเดือน — ความต้องการสูงกว่ารถที่มี ลองปรับราคาขึ้นช่วงวันที่เต็มเร็ว หรือพิจารณาเพิ่มรถประเภทเดียวกัน',
                $r['brand'], $r['model'], $r['plate'], round($r['util']))];
        }
    }
    // รถที่ใช้น้อย: 1–2 คันบอกรายคัน ถ้ามากกว่านั้นรวมเป็นข้อเดียว ไม่งั้นรายงานจะยาวเป็นข้อความซ้ำๆ
    $low = array_values(array_filter($carRows, fn($r) => $r['util'] < 20));
    if (count($low) > 0 && count($low) <= 2) {
        foreach ($low as $r) {
            $notes[] = ['warn', sprintf('%s %s (%s) ถูกใช้เพียง %d%% ของเดือน (%s วัน) — ลองทำราคาเช่ายาวหรือโปรโมชันเฉพาะคันนี้',
                $r['brand'], $r['model'], $r['plate'], round($r['util']), rtrim(rtrim(number_format($r['days'], 1), '0'), '.'))];
        }
    } elseif (count($low) > 2) {
        $names = array_map(fn($r) => $r['brand'] . ' ' . $r['model'] . ' ' . round($r['util']) . '%', $low);
        $notes[] = ['warn', sprintf('รถ %d จาก %d คันถูกใช้น้อยกว่า 20%% ของเดือน: %s — ภาพรวมยังมีรถว่างมาก ควรเน้นหาลูกค้าเพิ่มก่อนปรับราคารายคัน',
            count($low), count($carRows), implode(' · ', $names))];
    }
    if ($cur['new'] >= 5 && $cur['cancel_rt'] >= 20) {
        $notes[] = ['warn', sprintf('ยกเลิก %d จาก %d การจอง (%d%%) — ตรวจเหตุผลการยกเลิกในหน้าการจอง ส่วนใหญ่เกิดจากไม่โอนมัดจำภายในเวลา',
            $cur['cancelled'], $cur['new'], round($cur['cancel_rt']))];
    }
    if ($srcTotal >= 5) {
        $notes[] = [$webShare >= 50 ? 'ok' : 'muted', sprintf('จองผ่านเว็บ %d%% ของการจองทั้งหมด%s',
            round($webShare), $webShare < 30 ? ' — ลูกค้าส่วนใหญ่ยังโทรหรือทักมา ลองแปะลิงก์จองไว้ในโปรไฟล์ LINE และ Facebook' : '')];
    }
    if ($returned > 0 && $late > 0) {
        $notes[] = ['warn', sprintf('คืนรถช้าเกิน 1 ชั่วโมง %d จาก %d ครั้ง — เก็บค่าใช้จ่ายเพิ่มรวม %s บาท', $late, $returned, money0($cur['extra']))];
    }
    if ($slipHrs !== null && (float) $slipHrs > 6) {
        $notes[] = ['warn', sprintf('ใช้เวลาตรวจสลิปเฉลี่ย %.1f ชั่วโมง — ลูกค้ารอนานเกิน 6 ชั่วโมงมีโอกาสเปลี่ยนใจ', (float) $slipHrs)];
    }
    if ($busyDow && (int) $busyDow['n'] >= 3) {
        $notes[] = ['muted', 'วันที่ลูกค้ารับรถมากที่สุดคือวัน' . $dowName[(int) $busyDow['d']] . ' (' . (int) $busyDow['n'] . ' ครั้ง)'];
    }
    if ($inspTotal > 0 && $inspLocked < $inspTotal) {
        $notes[] = ['warn', sprintf('ผลตรวจสภาพรถ %d จาก %d รอบยังไม่ถูกล็อก — รอบที่ไม่ล็อกใช้เป็นหลักฐานได้อ่อนกว่า', $inspTotal - $inspLocked, $inspTotal)];
    }
    if (!$notes) {
        $notes[] = ['ok', 'ไม่พบจุดที่ต้องจับตาเป็นพิเศษในเดือนนี้'];
    }
}

$fmtDays = fn(float $d) => rtrim(rtrim(number_format($d, 1), '0'), '.');
$note    = (string) setting($noteKey);

admin_head('รายงานรายเดือน', 'monthly-report.php');
?>
<div class="toolbar no-print" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
  <a class="btn btn-ghost btn-sm" href="?m=<?= e($pm) ?>">← เดือนก่อน</a>
  <form method="get" style="display:flex;gap:8px;align-items:center">
    <label for="mm" class="small muted">เดือน</label>
    <input type="month" id="mm" name="m" value="<?= e($m) ?>" max="<?= date('Y-m') ?>" style="min-height:40px;max-width:190px">
    <button class="btn btn-ghost btn-sm">ดู</button>
  </form>
  <?php if ($nm <= date('Y-m')): ?><a class="btn btn-ghost btn-sm" href="?m=<?= e($nm) ?>">เดือนถัดไป →</a><?php endif; ?>
  <span style="margin-left:auto"></span>
  <button class="btn btn-sm" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
</div>

<div class="mrep">
  <header class="mrep-head">
    <div>
      <div class="mrep-shop"><?= e(setting('site_name', 'thonglorcarrent')) ?></div>
      <h2>รายงานผลประกอบการ <?= e($title) ?></h2>
    </div>
    <div class="mrep-meta">ช่วงข้อมูล <?= e(thai_date($from)) ?> – <?= e(thai_date($to)) ?><br>จัดทำเมื่อ <?= e(thai_date(date('Y-m-d'))) ?> · รถในระบบ <?= (int) $cur['cars'] ?> คัน</div>
  </header>

  <?php if ($m === date('Y-m')): ?>
    <div class="alert alert-warn no-print">เดือนนี้ยังไม่จบ ตัวเลขจะเปลี่ยนได้อีก — อัตราการใช้รถคิดเทียบกับทั้งเดือน</div>
  <?php endif; ?>

  <!-- ---------- ตัวเลขหลัก ---------- -->
  <div class="mrep-kpi">
    <div><span class="k">รายได้รับจริง</span><b>฿<?= money0($cur['collected']) ?></b><?= delta($cur['collected'], $prev['collected']) ?><span class="s">ไม่รวมเงินประกัน</span></div>
    <div><span class="k">อัตราการใช้รถ</span><b><?= number_format($cur['util'], 0) ?>%</b><?= delta($cur['util'], $prev['util'], true, true) ?><span class="s"><?= e($fmtDays($cur['sold'])) ?> จาก <?= $cur['cars'] * $dim ?> วัน-คัน</span></div>
    <div><span class="k">งานที่รับรถในเดือน</span><b><?= (int) $cur['jobs'] ?></b><?= delta($cur['jobs'], $prev['jobs']) ?><span class="s">มูลค่า ฿<?= money0($cur['booked']) ?></span></div>
    <div><span class="k">ค่าเช่าเฉลี่ยต่องาน</span><b>฿<?= money0($cur['ticket']) ?></b><?= delta($cur['ticket'], $prev['ticket']) ?><span class="s">รวมค่าใช้จ่ายเพิ่ม</span></div>
    <div><span class="k">การจองใหม่</span><b><?= (int) $cur['new'] ?></b><?= delta($cur['new'], $prev['new']) ?><span class="s">ยกเลิก <?= (int) $cur['cancelled'] ?> (<?= number_format($cur['cancel_rt'], 0) ?>%)</span></div>
    <div><span class="k">ค่าบำรุงรักษา</span><b>฿<?= money0($maintCost) ?></b><span class="dl muted">งานที่ปิดในเดือนนี้</span><span class="s">คงเหลือหลังหัก ฿<?= money0($cur['collected'] - $maintCost) ?></span></div>
  </div>

  <!-- ---------- รายคัน ---------- -->
  <section>
    <h3>ผลงานรายคัน</h3>
    <?php if ($best && $worst && $best['id'] !== $worst['id'] && $cur['sold'] > 0): ?>
      <p class="mrep-lead">
        ใช้งานมากที่สุด: <b><?= e($best['brand'] . ' ' . $best['model']) ?></b> (<?= round($best['util']) ?>%)
        · ใช้งานน้อยที่สุด: <b><?= e($worst['brand'] . ' ' . $worst['model']) ?></b> (<?= round($worst['util']) ?>%)
      </p>
    <?php endif; ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>รถ</th><th class="num">งาน</th><th class="num">วันเช่า</th><th style="width:22%">อัตราการใช้</th>
          <th class="num">ยอดจอง</th><th class="num">ค่าบำรุง</th><th class="num">คงเหลือ</th></tr></thead>
        <tbody>
          <?php foreach ($carRows as $r): ?>
            <tr>
              <td><b class="small"><?= e($r['brand'] . ' ' . $r['model']) ?></b><span class="tiny muted" style="display:block"><?= e($r['plate']) ?></span></td>
              <td class="num"><?= (int) $r['jobs'] ?></td>
              <td class="num"><?= e($fmtDays($r['days'])) ?></td>
              <td><div class="meter <?= $r['util'] < 20 ? 'danger' : ($r['util'] < 45 ? 'warn' : '') ?>"><i style="width:<?= min(100, round($r['util'])) ?>%"></i></div>
                <span class="tiny muted"><?= round($r['util']) ?>%</span></td>
              <td class="num">฿<?= money0($r['revenue']) ?></td>
              <td class="num"><?= $r['cost'] > 0 ? '฿' . money0($r['cost']) : '–' ?></td>
              <td class="num"><b>฿<?= money0($r['revenue'] - $r['cost']) ?></b></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="tiny muted">วันเช่านับจากเวลาเช่าจริงที่ตกอยู่ในเดือนนี้ · ยอดจองนับงานที่รับรถในเดือนนี้ ไม่ใช่เงินที่รับจริง</p>
  </section>

  <div class="mrep-two">
    <section>
      <h3>ลูกค้าและช่องทาง</h3>
      <table class="table">
        <tbody>
          <?php foreach ($bySource as $r): ?>
            <tr><td><?= e($srcLabel[$r['source']] ?? $r['source']) ?></td>
              <td class="num"><?= (int) $r['n'] ?> การจอง · <?= $srcTotal ? round($r['n'] / $srcTotal * 100) : 0 ?>%</td></tr>
          <?php endforeach; ?>
          <?php if (!$bySource): ?><tr><td colspan="2" class="muted">ไม่มีการจองในเดือนนี้</td></tr><?php endif; ?>
          <tr><td>ลูกค้าใหม่</td><td class="num"><?= $custNew ?> ราย</td></tr>
          <tr><td>ลูกค้าเดิมกลับมาเช่า</td><td class="num"><?= $custBack ?> ราย</td></tr>
        </tbody>
      </table>
    </section>
    <section>
      <h3>การดำเนินงาน</h3>
      <table class="table">
        <tbody>
          <tr><td>รับคืนรถ</td><td class="num"><?= $returned ?> ครั้ง · คืนช้า <?= $late ?></td></tr>
          <tr><td>ค่าใช้จ่ายเพิ่มที่เก็บได้</td><td class="num">฿<?= money0($cur['extra']) ?></td></tr>
          <tr><td>เวลาตรวจสลิปเฉลี่ย</td><td class="num"><?= $slipHrs !== null ? number_format((float) $slipHrs, 1) . ' ชม.' : '–' ?></td></tr>
          <tr><td>ตรวจสภาพรถ</td><td class="num"><?= $inspTotal ?> รอบ · ล็อกแล้ว <?= $inspLocked ?></td></tr>
          <tr><td>ข้อความ LINE ที่ส่ง</td><td class="num"><?= number_format($lineSent) ?> · ลูกค้าผูกบัญชี <?= $lineLinked ?></td></tr>
        </tbody>
      </table>
    </section>
  </div>

  <section>
    <h3>ข้อสังเกตจากข้อมูล</h3>
    <ul class="mrep-notes">
      <?php foreach ($notes as [$tone, $text]): ?>
        <li class="n-<?= e($tone) ?>"><?= e($text) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section>
    <h3>ข้อเสนอแนะประจำเดือน</h3>
    <?php if ($note !== ''): ?>
      <div class="mrep-advice"><?= nl2br(e($note)) ?></div>
    <?php else: ?>
      <p class="small muted print-only-hide">ยังไม่ได้เขียนข้อเสนอแนะของเดือนนี้</p>
    <?php endif; ?>
    <form method="post" class="no-print mt-2">
      <?= csrf_field() ?>
      <div class="field">
        <label for="note">เขียน/แก้ไขข้อเสนอแนะ (แสดงในรายงานที่พิมพ์)</label>
        <textarea id="note" name="note" rows="4" maxlength="3000" placeholder="เช่น แนะนำทำโปรเช่า 3 วันขึ้นไปสำหรับรถตู้ช่วงต้นเดือน"><?= e($note) ?></textarea>
      </div>
      <button class="btn btn-ghost btn-sm">บันทึกข้อเสนอแนะ</button>
    </form>
  </section>

  <footer class="mrep-foot">จัดทำโดย KTn Develop · ข้อมูลจากระบบ <?= e(setting('site_name', 'thonglorcarrent')) ?> ณ <?= e(thai_date(date('Y-m-d H:i:s'), true)) ?></footer>
</div>
<?php admin_foot(); ?>
