<?php
/**
 * สุขภาพระบบ — รายการตรวจที่ใช้ทำรายงานตรวจสุขภาพระบบรายไตรมาส
 * พิมพ์หน้านี้เป็น PDF แนบรายงานให้ลูกค้าได้เลย
 */
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/line.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['act'] ?? '') === 'migrate_docs') {
        try {
            $r = legacy_docs_migrate((int) (auth_user()['id'] ?? 0) ?: null);
            log_activity('documents_migrated', 'customers', null, $r);
            flash($r['failed'] > 0 ? 'warn' : 'ok', 'ย้ายเอกสารลูกค้าเข้าที่เก็บแบบปิดแล้ว ' . $r['moved'] . ' ไฟล์'
                . ($r['missing'] ? ' · ไม่พบไฟล์จริง ' . $r['missing'] . ' รายการ' : '')
                . ($r['failed'] ? ' · ย้ายไม่สำเร็จ ' . $r['failed'] . ' ไฟล์ (ไฟล์ยังอยู่ที่เดิม ตรวจสิทธิ์โฟลเดอร์ storage แล้วกดอีกครั้ง)' : ''));
        } catch (Throwable $ex) {
            flash('danger', 'ย้ายเอกสารไม่สำเร็จ: ' . $ex->getMessage());
        }
    }
    redirect('admin/health.php');
}
legacy_docs_protect();
$legacyDocs = 0;
try { $legacyDocs = legacy_docs_count(); } catch (Throwable $e) {}

$checks = health_checks();
$count  = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach ($checks as $c) {
    $count[$c[1]]++;
}
$overall = $count['fail'] > 0 ? 'fail' : ($count['warn'] > 0 ? 'warn' : 'ok');
$label   = ['ok' => 'ปกติ', 'warn' => 'ควรดู', 'fail' => 'ต้องแก้'];
$badge   = ['ok' => 'ok', 'warn' => 'warn', 'fail' => 'danger'];
$key     = (string) setting('health_key');
$pingUrl = site_url('health.php');

admin_head('สุขภาพระบบ', 'health.php');
?>
<div class="stats">
  <div class="stat <?= $overall === 'ok' ? 'ok' : ($overall === 'warn' ? 'accent' : 'danger') ?>">
    <div class="lb">ผลรวม</div>
    <div class="vl" style="font-size:1.4rem"><?= $overall === 'ok' ? 'ระบบปกติ' : ($overall === 'warn' ? 'มีข้อควรดู' : 'มีข้อต้องแก้') ?></div>
    <div class="sub">ตรวจเมื่อ <?= e(thai_date(date('Y-m-d H:i:s'), true)) ?></div>
  </div>
  <div class="stat ok"><div class="lb">ปกติ</div><div class="vl"><?= $count['ok'] ?></div><div class="sub">รายการ</div></div>
  <div class="stat"><div class="lb">ควรดู</div><div class="vl"><?= $count['warn'] ?></div><div class="sub">ยังใช้งานได้ แต่ควรจัดการ</div></div>
  <div class="stat <?= $count['fail'] ? 'danger' : '' ?>"><div class="lb">ต้องแก้</div><div class="vl"><?= $count['fail'] ?></div><div class="sub">กระทบการทำงานของระบบ</div></div>
</div>

<div class="panel">
  <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
    <h3 class="mb-0">รายการตรวจ</h3>
    <button class="btn btn-ghost btn-sm no-print" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
  </div>
  <div class="table-wrap mt-2">
    <table class="table">
      <thead><tr><th style="width:30%">หัวข้อ</th><th style="width:12%">ผล</th><th>ที่พบ</th><th>ต้องทำอะไร</th></tr></thead>
      <tbody>
        <?php
        // เรียงให้ข้อที่ต้องแก้ขึ้นก่อน
        usort($checks, fn($a, $b) => array_search($a[1], ['fail', 'warn', 'ok']) <=> array_search($b[1], ['fail', 'warn', 'ok']));
        foreach ($checks as [$title, $level, $found, $advice]): ?>
          <tr>
            <td><b class="small"><?= e($title) ?></b></td>
            <td><span class="badge badge-<?= $badge[$level] ?>"><?= $label[$level] ?></span></td>
            <td class="small"><?= e($found) ?></td>
            <td class="small muted"><?= e($advice ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($legacyDocs > 0): ?>
<div class="panel mt-2 no-print">
  <h3>ย้ายเอกสารลูกค้าเข้าที่เก็บแบบปิด</h3>
  <p class="small muted">มีสำเนาบัตรประชาชน/ใบขับขี่ <b><?= (int) $legacyDocs ?> ไฟล์</b> ที่อัปโหลดก่อนเวอร์ชัน 5 ยังอยู่ในโฟลเดอร์ <code>uploads/docs</code>
    ซึ่งเป็นโฟลเดอร์ที่เปิดผ่านเว็บได้ ปุ่มนี้จะย้ายไฟล์ทั้งหมดไปไว้ใน <code>storage/private</code> — เป็นการย้าย ไม่มีการลบไฟล์
    และหลังย้ายยังเปิดดูได้จากหน้าลูกค้าตามเดิม</p>
  <form method="post" onsubmit="return confirm('ย้ายเอกสารลูกค้า <?= (int) $legacyDocs ?> ไฟล์เข้าที่เก็บแบบปิด?')">
    <?= csrf_field() ?><input type="hidden" name="act" value="migrate_docs">
    <button class="btn">ย้ายเข้าที่เก็บแบบปิด</button>
  </form>
</div>
<?php endif; ?>

<div class="panel mt-2 no-print">
  <h3>เฝ้าระวังระบบล่มอัตโนมัติ</h3>
  <p class="small muted">นำที่อยู่นี้ไปตั้งในบริการเฝ้าระวัง (เช่น UptimeRobot แบบ Keyword ให้ตรวจคำว่า <b>OK</b> ทุก 5 นาที) — ระบบจะตอบ OK เมื่อเว็บและฐานข้อมูลทำงานปกติ</p>
  <div class="field">
    <label>ที่อยู่สำหรับเฝ้าระวัง (ไม่เปิดเผยข้อมูลใดๆ)</label>
    <input type="text" readonly value="<?= e($pingUrl) ?>" onclick="this.select()">
  </div>
  <div class="field">
    <label>ที่อยู่แบบละเอียด (มีรหัสลับ — ใช้เฉพาะผู้ดูแลระบบ)</label>
    <input type="text" readonly value="<?= e($pingUrl . '?key=' . $key) ?>" onclick="this.select()">
    <div class="hint">ตอบผลตรวจทุกข้อเป็น JSON ใช้ทำรายงานอัตโนมัติ</div>
  </div>
</div>
<?php admin_foot(); ?>
