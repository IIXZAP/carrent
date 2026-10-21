<?php
/**
 * สำรองข้อมูล — ดูรายการไฟล์สำรอง สำรองทันที และดาวน์โหลดไปเก็บเอง
 */
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_admin();

// ---------- ดาวน์โหลด ----------
if (isset($_GET['download'])) {
    $f = backup_find((string) $_GET['download']);
    if (!$f) {
        flash('danger', 'ไม่พบไฟล์สำรองนี้');
        redirect('admin/backups.php');
    }
    log_activity('backup_downloaded', null, null, $f['name']);
    header('Content-Type: ' . (str_ends_with($f['name'], '.gz') ? 'application/gzip' : 'application/sql'));
    header('Content-Disposition: attachment; filename="' . $f['name'] . '"');
    header('Content-Length: ' . $f['size']);
    header('Cache-Control: no-store');
    readfile($f['path']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'create') {
        try {
            $r = backup_create();
            log_activity('backup_created', null, null, $r['name']);
            flash('ok', 'สำรองข้อมูลเรียบร้อย — ' . $r['tables'] . ' ตาราง ' . number_format($r['rows']) . ' แถว ขนาด ' . human_bytes($r['size']));
        } catch (Throwable $e) {
            flash('danger', 'สำรองไม่สำเร็จ: ' . $e->getMessage());
        }
    } elseif ($act === 'keep') {
        $d = max(7, min(3650, (int) ($_POST['backup_keep_days'] ?? 90)));
        set_setting('backup_keep_days', (string) $d);
        flash('ok', 'ตั้งให้เก็บไฟล์สำรองย้อนหลัง ' . $d . ' วัน');
    }
    redirect('admin/backups.php');
}

$list   = backup_list();
$total  = array_sum(array_column($list, 'size'));
$keep   = max(7, (int) setting('backup_keep_days', '90'));
$last   = (string) setting('backup_last_at');
$ageH   = $last !== '' ? (time() - strtotime($last)) / 3600 : null;
[$upBytes, $upFiles] = dir_usage(rtrim(UPLOAD_DIR, '/'));

admin_head('สำรองข้อมูล', 'backups.php');
?>
<div class="stats">
  <div class="stat <?= $ageH === null || $ageH > 36 ? 'danger' : 'ok' ?>">
    <div class="lb">สำรองล่าสุด</div>
    <div class="vl" style="font-size:1.25rem"><?= $last !== '' ? e(thai_date($last, true)) : 'ยังไม่เคย' ?></div>
    <div class="sub"><?= $ageH === null ? 'กดสำรองตอนนี้ได้เลย' : ($ageH > 36 ? 'เกิน 36 ชม. — ตรวจว่างานอัตโนมัติทำงาน' : 'งานอัตโนมัติทำงานปกติ') ?></div>
  </div>
  <div class="stat">
    <div class="lb">ไฟล์สำรองที่เก็บอยู่</div>
    <div class="vl"><?= count($list) ?></div>
    <div class="sub">รวม <?= e(human_bytes($total)) ?> · เก็บย้อนหลัง <?= $keep ?> วัน</div>
  </div>
  <div class="stat">
    <div class="lb">รูปและเอกสาร (ไม่รวมในไฟล์สำรอง)</div>
    <div class="vl" style="font-size:1.25rem"><?= e(human_bytes($upBytes)) ?></div>
    <div class="sub"><?= number_format($upFiles) ?> ไฟล์ · สำรองผ่าน cPanel → Backup</div>
  </div>
</div>

<div class="g2">
  <div class="panel">
    <h3>ไฟล์สำรองฐานข้อมูล</h3>
    <p class="small muted">ระบบสำรองให้อัตโนมัติวันละครั้งพร้อมงานรายวัน ไฟล์ที่เก่าเกิน <?= $keep ?> วันจะถูกลบเอง (เก็บ 7 ไฟล์ล่าสุดไว้เสมอ) ·
      แนะนำให้ดาวน์โหลดไปเก็บนอกเซิร์ฟเวอร์อย่างน้อยเดือนละครั้ง</p>
    <?php if (!$list): ?>
      <p class="small muted mb-0">ยังไม่มีไฟล์สำรอง</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>วันที่สำรอง</th><th class="num">ขนาด</th><th></th></tr></thead>
          <tbody>
            <?php foreach (array_slice($list, 0, 100) as $f): ?>
              <tr>
                <td><?= e(thai_date(date('Y-m-d H:i:s', $f['time']), true)) ?>
                  <span class="tiny muted" style="display:block;word-break:break-all"><?= e($f['name']) ?></span></td>
                <td class="num"><?= e(human_bytes($f['size'])) ?></td>
                <td><a class="btn btn-ghost btn-sm" href="<?= url('admin/backups.php?download=' . urlencode($f['name'])) ?>">ดาวน์โหลด</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="panel">
      <h3>สำรองทันที</h3>
      <p class="small muted">ใช้ก่อนแก้ไขข้อมูลจำนวนมาก หรือก่อนอัปเดตระบบ</p>
      <form method="post">
        <?= csrf_field() ?>
        <button class="btn btn-accent btn-block" name="act" value="create">สำรองตอนนี้</button>
      </form>
    </div>

    <form method="post" class="panel mt-2">
      <?= csrf_field() ?><input type="hidden" name="act" value="keep">
      <h3>ระยะเวลาเก็บไฟล์</h3>
      <div class="field">
        <label for="backup_keep_days">เก็บย้อนหลัง (วัน)</label>
        <input type="number" id="backup_keep_days" name="backup_keep_days" min="7" max="3650" value="<?= $keep ?>">
        <div class="hint">ขั้นต่ำ 7 วัน · ฐานข้อมูลขนาดปกติใช้พื้นที่ไม่ถึง 1 MB ต่อไฟล์</div>
      </div>
      <button class="btn btn-ghost btn-sm">บันทึก</button>
    </form>

    <div class="panel mt-2">
      <h3 class="small">วิธีกู้ข้อมูลจากไฟล์สำรอง</h3>
      <ol class="small muted" style="padding-left:18px;margin:0">
        <li>ดาวน์โหลดไฟล์ .sql.gz ที่ต้องการ</li>
        <li>cPanel → phpMyAdmin → เลือกฐานข้อมูล → Import</li>
        <li>เลือกไฟล์ (ไม่ต้องแตกไฟล์) แล้วกด Go</li>
      </ol>
      <p class="tiny muted mt-1 mb-0">การกู้จะแทนที่ข้อมูลปัจจุบันทั้งหมดด้วยข้อมูล ณ เวลาที่สำรอง — สำรองข้อมูลปัจจุบันก่อนทุกครั้ง</p>
    </div>
  </div>
</div>
<?php admin_foot(); ?>
