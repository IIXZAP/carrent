<?php
/**
 * เทียบผล UX สองเวอร์ชัน
 *
 * หน้านี้ต่อฐานข้อมูลของ "อีกเวอร์ชัน" เพิ่มอีกหนึ่งตัว แล้วดึงสถิติมาวางเทียบกัน
 * เปิดจากเวอร์ชันไหนก็ได้ ผลเหมือนกัน
 *
 * ตั้งค่าชื่อฐานข้อมูลอีกฝั่งได้ที่ หลังบ้าน → ตั้งค่า → ab_other_db
 */
require_once __DIR__ . '/../includes/abtrack.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_admin();   // ตัวเลขรายได้ — เฉพาะเจ้าของร้าน

$from   = $_GET['from'] ?? date('Y-m-d', strtotime('-29 days'));
$to     = $_GET['to']   ?? date('Y-m-d');
$device = (string) ($_GET['device'] ?? 'all');
$device = in_array($device, ['all', 'mobile', 'desktop'], true) ? $device : 'all';

$mine = ab_funnel($from, $to, $device);

// ---------- ต่อฐานข้อมูลอีกฝั่ง ----------
$otherDb    = trim((string) setting('ab_other_db', DB_NAME === 'carrent' ? 'carrent2' : 'carrent'));
$otherUser  = trim((string) setting('ab_other_user', '')) ?: DB_USER;
$otherPass  = (string) setting('ab_other_pass', '') !== '' ? (string) setting('ab_other_pass') : DB_PASS;
$other      = null;
$otherError = null;

if ($otherDb !== '' && $otherDb !== DB_NAME) {
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, $otherDb, DB_CHARSET);
        $pdo = new PDO($dsn, $otherUser, $otherPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $sql = "SELECT step, COUNT(DISTINCT visitor_id) n FROM ab_events
                WHERE DATE(created_at) BETWEEN :a AND :b"
             . ($device !== 'all' ? ' AND device = :d' : '')
             . " GROUP BY step";
        $st = $pdo->prepare($sql);
        $p  = [':a' => $from, ':b' => $to];
        if ($device !== 'all') { $p[':d'] = $device; }
        $st->execute($p);

        $other = ['view_home' => 0, 'view_car' => 0, 'start_form' => 0, 'submit' => 0, 'booked' => 0];
        foreach ($st->fetchAll() as $r) {
            if (isset($other[$r['step']])) { $other[$r['step']] = (int) $r['n']; }
        }
    } catch (Throwable $e) {
        $otherError = $e->getMessage();
    }
}

// เวอร์ชันนี้คือ A หรือ B
$meIsA = !defined('SITE_VARIANT') || SITE_VARIANT === 'A';
$A     = $meIsA ? $mine  : ($other ?: null);
$B     = $meIsA ? ($other ?: null) : $mine;
$labelA = 'A · ดีไซน์เดิม';
$labelB = 'B · ดีไซน์ใหม่';

$steps = ['view_home', 'view_car', 'start_form', 'submit', 'booked'];

/** อัตราผ่านจากขั้นแรกถึงขั้นนี้ */
function rate(?array $f, string $step): ?float
{
    if (!$f || empty($f['view_home'])) return null;
    return $f[$step] / $f['view_home'] * 100;
}

admin_head('เทียบผล UX สองเวอร์ชัน', 'reports.php');
?>
<div class="page-head">
  <div>
    <h2>เทียบผล UX สองเวอร์ชัน</h2>
    <p class="muted">นับเป็นจำนวนคน (ไม่ใช่จำนวนครั้ง) · ไม่เก็บข้อมูลส่วนบุคคลใดๆ</p>
  </div>
</div>

<form method="get" class="panel toolbar">
  <div class="field"><label for="from">ตั้งแต่</label>
    <input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
  <div class="field"><label for="to">ถึง</label>
    <input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
  <div class="field"><label for="device">อุปกรณ์</label>
    <select id="device" name="device">
      <?php foreach (['all' => 'ทั้งหมด', 'mobile' => 'มือถือ', 'desktop' => 'เดสก์ท็อป'] as $k => $v): ?>
        <option value="<?= $k ?>"<?= $device === $k ? ' selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select></div>
  <button class="btn">ดูผล</button>
</form>

<?php if ($otherError): ?>
  <div class="alert alert-warn">
    <div>
    <b>ต่อฐานข้อมูลอีกเวอร์ชันไม่ได้</b> — แสดงเฉพาะเวอร์ชันนี้<br>
    <span class="tiny">ฐานข้อมูลที่พยายามต่อ: <code><?= e($otherDb) ?></code> · <?= e($otherError) ?></span><br>
    <span class="tiny">ถ้าผู้ใช้ฐานข้อมูลคนละชุด ให้ตั้งค่า <code>ab_other_user</code> และ <code>ab_other_pass</code>
      ที่ หลังบ้าน → ตั้งค่า</span>
    </div>
  </div>
<?php endif; ?>

<div class="panel">
  <h3>กรวยการจอง</h3>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>ขั้นตอน</th>
          <th class="num"><?= e($labelA) ?></th>
          <th class="num"><?= e($labelB) ?></th>
          <th class="num">ผ่าน A</th>
          <th class="num">ผ่าน B</th>
          <th class="num">ต่าง</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($steps as $s):
            $ra = rate($A, $s); $rb = rate($B, $s);
            $diff = ($ra !== null && $rb !== null) ? $rb - $ra : null; ?>
          <tr>
            <td><?= e(ab_step_label($s)) ?></td>
            <td class="num"><?= $A ? number_format($A[$s]) : '—' ?></td>
            <td class="num"><?= $B ? number_format($B[$s]) : '—' ?></td>
            <td class="num"><?= $ra !== null ? number_format($ra, 1) . '%' : '—' ?></td>
            <td class="num"><?= $rb !== null ? number_format($rb, 1) . '%' : '—' ?></td>
            <td class="num">
              <?php if ($diff === null): ?>—
              <?php elseif (abs($diff) < 0.05): ?><span class="muted">เท่ากัน</span>
              <?php else: ?>
                <b style="color:var(--<?= $diff > 0 ? 'ok' : 'danger' ?>)">
                  <?= ($diff > 0 ? '+' : '') . number_format($diff, 1) ?>%
                </b>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="tiny muted mt-1">
    "ผ่าน" = สัดส่วนคนที่มาถึงขั้นนั้น เทียบกับคนที่เข้าหน้าแรก ·
    ช่อง "ต่าง" เป็นบวก = ดีไซน์ใหม่ทำได้ดีกว่า
  </p>
</div>

<?php
$aBook = ($A && $A['view_home']) ? $A['booked'] / $A['view_home'] * 100 : null;
$bBook = ($B && $B['view_home']) ? $B['booked'] / $B['view_home'] * 100 : null;
$minSample = 100;   // ต่ำกว่านี้ตัวเลขแกว่งเกินกว่าจะสรุปอะไรได้
$enough = $A && $B && $A['view_home'] >= $minSample && $B['view_home'] >= $minSample;
?>
<div class="panel mt-2">
  <h3>สรุป</h3>
  <?php if (!$A || !$B): ?>
    <p class="muted">ยังมีข้อมูลไม่ครบทั้งสองเวอร์ชัน — เทียบไม่ได้</p>
  <?php elseif (!$enough): ?>
    <div class="alert alert-warn">
      <div>
        <b>ข้อมูลยังน้อยเกินกว่าจะสรุป</b><br>
        ตอนนี้ A มี <?= number_format($A['view_home']) ?> คน · B มี <?= number_format($B['view_home']) ?> คน<br>
        <span class="tiny">ควรมีอย่างน้อยฝั่งละ <?= $minSample ?> คนก่อน ไม่งั้นตัวเลขแกว่งจากความบังเอิญ
          มากกว่าจากดีไซน์ — ปล่อยเก็บข้อมูลต่ออีกสักระยะ</span>
      </div>
    </div>
  <?php else:
      $gap = $bBook - $aBook; ?>
    <table class="table">
      <tr><th>อัตราจองสำเร็จ — <?= e($labelA) ?></th>
          <td class="num"><b><?= number_format($aBook, 2) ?>%</b>
            <span class="tiny muted">(<?= number_format($A['booked']) ?> จาก <?= number_format($A['view_home']) ?>)</span></td></tr>
      <tr><th>อัตราจองสำเร็จ — <?= e($labelB) ?></th>
          <td class="num"><b><?= number_format($bBook, 2) ?>%</b>
            <span class="tiny muted">(<?= number_format($B['booked']) ?> จาก <?= number_format($B['view_home']) ?>)</span></td></tr>
      <tr><th>ส่วนต่าง</th>
          <td class="num">
            <b style="color:var(--<?= $gap > 0 ? 'ok' : ($gap < 0 ? 'danger' : 'ink') ?>)">
              <?= ($gap > 0 ? '+' : '') . number_format($gap, 2) ?>%
            </b>
          </td></tr>
    </table>
    <p class="tiny muted mt-1">
      ตัวเลขนี้บอกแค่ว่าช่วงที่ผ่านมาเกิดอะไรขึ้น ไม่ได้พิสูจน์ว่าดีไซน์เป็นสาเหตุ —
      ถ้าสองเวอร์ชันได้ทราฟฟิกคนละแหล่งหรือคนละช่วงเวลา ผลจะเอียงได้
      วิธีที่เทียบได้จริงคือแบ่งคนเข้าสองเวอร์ชันแบบสุ่มในช่วงเวลาเดียวกัน
    </p>
  <?php endif; ?>
</div>

<div class="panel mt-2">
  <h3 class="small">จุดที่คนหลุดมากที่สุด</h3>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>ช่วง</th><th class="num">หลุดใน A</th><th class="num">หลุดใน B</th></tr></thead>
      <tbody>
        <?php for ($i = 0; $i < count($steps) - 1; $i++):
            $s1 = $steps[$i]; $s2 = $steps[$i + 1];
            $da = ($A && $A[$s1]) ? (1 - $A[$s2] / $A[$s1]) * 100 : null;
            $db = ($B && $B[$s1]) ? (1 - $B[$s2] / $B[$s1]) * 100 : null; ?>
          <tr>
            <td><?= e(ab_step_label($s1)) ?> → <?= e(ab_step_label($s2)) ?></td>
            <td class="num"><?= $da !== null ? number_format($da, 1) . '%' : '—' ?></td>
            <td class="num"><?= $db !== null ? number_format($db, 1) . '%' : '—' ?></td>
          </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>
  <p class="tiny muted mt-1">ช่วงที่ตัวเลขสูงสุดคือจุดที่ควรแก้ก่อน</p>
</div>

<?php admin_foot(); ?>
