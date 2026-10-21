<?php
/**
 * งานประจำรถ — ประกัน / พ.ร.บ. / ภาษี / เช็กระยะ / ซ่อม
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $act = $_POST['act'] ?? '';
        if ($act === 'add') {
            $carId = (int) $_POST['car_id'];
            $mid = db_insert('maintenances', [
                'car_id'      => $carId,
                'task_type'   => in_array($_POST['task_type'] ?? '', ['service','insurance','act','tax','tyre','repair','other'], true) ? $_POST['task_type'] : 'other',
                'title'       => trim($_POST['title'] ?? '') ?: 'งานประจำรถ',
                'due_date'    => trim($_POST['due_date'] ?? '') ?: null,
                'due_mileage' => (int) ($_POST['due_mileage'] ?? 0) ?: null,
                'vendor'      => trim($_POST['vendor'] ?? '') ?: null,
                'note'        => trim($_POST['note'] ?? '') ?: null,
                'status'      => 'pending',
            ]);
            log_activity('maintenance_added', 'maintenances', $mid);
            flash('ok', 'เพิ่มงานแล้ว');

        } elseif ($act === 'done') {
            $mid  = (int) $_POST['id'];
            $cost = (float) ($_POST['cost'] ?? 0);
            $m    = db_one('SELECT * FROM maintenances WHERE id = :i', [':i' => $mid]);
            db_update('maintenances', [
                'status'    => 'done',
                'done_date' => trim($_POST['done_date'] ?? '') ?: date('Y-m-d'),
                'cost'      => $cost,
                'vendor'    => trim($_POST['vendor'] ?? '') ?: ($m['vendor'] ?? null),
            ], 'id', $mid);

            // อัปเดตวันหมดอายุในตารางรถ + สร้างงานรอบถัดไปอัตโนมัติ
            if ($m) {
                $nextDue = trim($_POST['next_due'] ?? '');
                $colMap  = ['insurance' => 'insurance_expire', 'act' => 'act_expire',
                            'tax' => 'tax_expire', 'service' => 'next_service_at'];
                if ($nextDue && isset($colMap[$m['task_type']])) {
                    db_update('cars', [$colMap[$m['task_type']] => $nextDue], 'id', (int) $m['car_id']);
                    db_insert('maintenances', [
                        'car_id' => (int) $m['car_id'], 'task_type' => $m['task_type'],
                        'title' => $m['title'], 'due_date' => $nextDue, 'status' => 'pending',
                    ]);
                }
            }
            log_activity('maintenance_done', 'maintenances', $mid, $cost);
            flash('ok', 'บันทึกว่าทำงานเสร็จแล้ว');

        } elseif ($act === 'skip') {
            db_update('maintenances', ['status' => 'skipped'], 'id', (int) $_POST['id']);
            flash('warn', 'ข้ามงานนี้แล้ว');
        }
    } catch (Throwable $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('admin/maintenance.php');
}

$filter = $_GET['status'] ?? 'pending';
$filter = in_array($filter, ['pending','done','skipped','all'], true) ? $filter : 'pending';
$w   = $filter === 'all' ? '1=1' : 'm.status = :st';
$prm = $filter === 'all' ? [] : [':st' => $filter];

$rows = db_all(
    "SELECT m.*, c.plate, c.brand, c.model, c.mileage
     FROM maintenances m JOIN cars c ON c.id = m.car_id
     WHERE $w ORDER BY m.status = 'pending' DESC, m.due_date IS NULL, m.due_date LIMIT 200",
    $prm
);
$cars = db_all("SELECT * FROM cars WHERE status <> 'inactive' ORDER BY sort_order");

$today   = date('Y-m-d');
$warn    = (int) setting('reminder_days_before', '30');
$overdue = (int) db_val("SELECT COUNT(*) FROM maintenances WHERE status='pending' AND due_date < :d", [':d' => $today]);
$soon    = (int) db_val("SELECT COUNT(*) FROM maintenances WHERE status='pending' AND due_date BETWEEN :a AND :b",
                        [':a' => $today, ':b' => date('Y-m-d', strtotime("+$warn days"))]);
$costYear = (float) db_val("SELECT COALESCE(SUM(cost),0) FROM maintenances WHERE status='done' AND YEAR(done_date) = :y", [':y' => date('Y')]);

$typeLabel = ['service'=>'เช็กระยะ/น้ำมันเครื่อง','insurance'=>'ประกันภัย','act'=>'พ.ร.บ.',
              'tax'=>'ภาษีประจำปี','tyre'=>'ยาง','repair'=>'ซ่อม','other'=>'อื่นๆ'];

admin_head('งานประจำรถ', 'maintenance.php');
?>
<div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
  <div class="stat <?= $overdue ? 'danger' : 'ok' ?>"><div class="lb">เลยกำหนดแล้ว</div><div class="vl"><?= $overdue ?></div></div>
  <div class="stat accent"><div class="lb">ครบใน <?= $warn ?> วัน</div><div class="vl"><?= $soon ?></div></div>
  <div class="stat brand"><div class="lb">ค่าใช้จ่ายปีนี้</div><div class="vl">฿<?= money0($costYear) ?></div></div>
</div>

<div class="toolbar">
  <?php foreach (['pending'=>'ค้างอยู่','done'=>'ทำแล้ว','skipped'=>'ข้าม','all'=>'ทั้งหมด'] as $k => $l): ?>
    <a class="btn btn-sm <?= $filter === $k ? '' : 'btn-ghost' ?>" href="?status=<?= $k ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>

<div class="split">
  <div class="panel">
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>กำหนด</th><th>งาน</th><th>รถ</th><th class="num">ค่าใช้จ่าย</th><th></th></tr></thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="center muted" style="padding:30px">ไม่มีรายการ</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r):
              $left = $r['due_date'] ? (int) floor((strtotime($r['due_date']) - strtotime($today)) / 86400) : null;
              $cls  = $left === null ? 'muted' : ($left < 0 ? 'danger' : ($left <= $warn ? 'warn' : 'info'));
          ?>
            <tr>
              <td style="white-space:nowrap">
                <?php if ($r['due_date']): ?>
                  <?= e(thai_date($r['due_date'])) ?>
                  <?php if ($r['status'] === 'pending'): ?>
                    <div><span class="badge badge-<?= $cls ?>"><?= $left < 0 ? 'เกิน ' . abs($left) . ' วัน' : 'อีก ' . $left . ' วัน' ?></span></div>
                  <?php endif; ?>
                <?php else: ?><span class="muted tiny">ไม่กำหนดวัน</span><?php endif; ?>
                <?php if ($r['due_mileage']): ?>
                  <div class="tiny muted">ที่ <?= number_format((int) $r['due_mileage']) ?> กม.
                    (ตอนนี้ <?= number_format((int) $r['mileage']) ?>)</div>
                <?php endif; ?>
              </td>
              <td>
                <b class="small"><?= e($r['title']) ?></b>
                <div class="tiny muted"><?= e($typeLabel[$r['task_type']] ?? $r['task_type']) ?>
                  <?= $r['vendor'] ? ' · ' . e($r['vendor']) : '' ?></div>
                <?php if ($r['status'] === 'done'): ?>
                  <div class="tiny" style="color:var(--ok)">เสร็จ <?= e(thai_date($r['done_date'])) ?></div>
                <?php elseif ($r['status'] === 'skipped'): ?>
                  <div class="tiny muted">ข้ามแล้ว</div>
                <?php endif; ?>
              </td>
              <td class="small"><?= e($r['brand'] . ' ' . $r['model']) ?><div class="tiny muted"><?= e($r['plate']) ?></div></td>
              <td class="num"><?= (float) $r['cost'] > 0 ? money0($r['cost']) : '-' ?></td>
              <td class="right">
                <?php if ($r['status'] === 'pending'): ?>
                  <details>
                    <summary class="btn btn-sm" style="list-style:none;cursor:pointer">ทำแล้ว</summary>
                    <form method="post" style="position:absolute;z-index:20;background:#fff;border:1px solid var(--line);
                          border-radius:8px;padding:14px;box-shadow:var(--shadow);width:250px;margin-top:6px;text-align:left">
                      <?= csrf_field() ?><input type="hidden" name="act" value="done">
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <div class="field"><label>วันที่ทำ</label><input type="date" name="done_date" value="<?= $today ?>"></div>
                      <div class="field"><label>ค่าใช้จ่าย (บาท)</label><input type="number" name="cost" step="0.01" min="0" value="0"></div>
                      <div class="field"><label>ร้าน/ศูนย์</label><input type="text" name="vendor" value="<?= e($r['vendor'] ?? '') ?>"></div>
                      <div class="field"><label>กำหนดครั้งถัดไป</label><input type="date" name="next_due">
                        <div class="hint">ใส่แล้วระบบจะสร้างงานเตือนรอบหน้าให้</div></div>
                      <button class="btn btn-sm btn-block">บันทึก</button>
                    </form>
                  </details>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?><input type="hidden" name="act" value="skip">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-ghost btn-sm">ข้าม</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <h3>เพิ่มงานใหม่</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="add">
      <div class="field"><label>รถ *</label>
        <select name="car_id" required>
          <?php foreach ($cars as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= e($c['brand'] . ' ' . $c['model'] . ' — ' . $c['plate']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>ประเภทงาน</label>
        <select name="task_type">
          <?php foreach ($typeLabel as $k => $l): ?>
            <option value="<?= $k ?>"><?= e($l) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>ชื่องาน *</label><input type="text" name="title" required placeholder="เช่น เปลี่ยนยาง 4 เส้น"></div>
      <div class="field"><label>กำหนดวันที่</label><input type="date" name="due_date"></div>
      <div class="field"><label>หรือกำหนดที่เลขไมล์</label><input type="number" name="due_mileage" min="0"></div>
      <div class="field"><label>ร้าน/ศูนย์</label><input type="text" name="vendor"></div>
      <div class="field"><label>หมายเหตุ</label><textarea name="note"></textarea></div>
      <button class="btn btn-block">เพิ่มงาน</button>
    </form>
  </div>
</div>
<?php admin_foot(); ?>
