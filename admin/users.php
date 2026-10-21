<?php
/**
 * จัดการผู้ใช้งานหลังบ้าน (เฉพาะ admin)
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_admin();

$me = auth_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $act = $_POST['act'] ?? '';
        if ($act === 'save') {
            $uid   = (int) ($_POST['id'] ?? 0);
            $email = trim($_POST['email'] ?? '');
            $name  = trim($_POST['name'] ?? '');
            $pass  = (string) ($_POST['password'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('กรุณากรอกชื่อและอีเมลให้ถูกต้อง');
            }
            $dup = db_one('SELECT id FROM users WHERE email = :e AND id <> :i', [':e' => $email, ':i' => $uid]);
            if ($dup) throw new RuntimeException('อีเมลนี้ถูกใช้แล้ว');

            $data = [
                'name'      => $name,
                'email'     => $email,
                'role'      => ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff',
                'phone'     => trim($_POST['phone'] ?? '') ?: null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ];
            if ($pass !== '') {
                if (strlen($pass) < 8) throw new RuntimeException('รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร');
                $data['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
            }

            if ($uid) {
                // กันไม่ให้ปิดบัญชีตัวเอง หรือลดสิทธิ์ตัวเองจนไม่เหลือ admin
                if ($uid === (int) $me['id']) {
                    $data['is_active'] = 1;
                    $data['role']      = 'admin';
                }
                // ต้องเหลือเจ้าของร้านที่ใช้งานได้อย่างน้อย 1 คนเสมอ
                $cur = db_one('SELECT role, is_active FROM users WHERE id = :i', [':i' => $uid]);
                if ($cur && $cur['role'] === 'admin' && (int) $cur['is_active'] === 1
                    && ($data['role'] !== 'admin' || $data['is_active'] !== 1)
                    && (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> :i", [':i' => $uid]) < 1) {
                    throw new RuntimeException('ต้องมีเจ้าของร้านที่ใช้งานได้อย่างน้อย 1 คน');
                }
                db_update('users', $data, 'id', $uid);
                log_activity('user_updated', 'users', $uid);
                flash('ok', 'บันทึกข้อมูลผู้ใช้แล้ว');
            } else {
                if ($pass === '') throw new RuntimeException('กรุณากำหนดรหัสผ่านสำหรับผู้ใช้ใหม่');
                $uid = db_insert('users', $data);
                log_activity('user_created', 'users', $uid);
                flash('ok', 'เพิ่มผู้ใช้แล้ว');
            }
        } elseif ($act === 'delete') {
            $uid = (int) $_POST['id'];
            if ($uid === (int) $me['id']) throw new RuntimeException('ลบบัญชีตัวเองไม่ได้');
            $admins = (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
            $target = db_one('SELECT role FROM users WHERE id = :i', [':i' => $uid]);
            if ($target && $target['role'] === 'admin' && $admins <= 1) {
                throw new RuntimeException('ต้องมีผู้ดูแลระบบอย่างน้อย 1 คน');
            }
            db_exec('DELETE FROM users WHERE id = :i', [':i' => $uid]);
            log_activity('user_deleted', 'users', $uid);
            flash('warn', 'ลบผู้ใช้แล้ว');
        }
    } catch (Throwable $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('admin/users.php');
}

$editId = (int) ($_GET['edit'] ?? 0);
$edit   = $editId ? db_one('SELECT * FROM users WHERE id = :i', [':i' => $editId]) : null;
$users  = db_all('SELECT * FROM users ORDER BY role, name');
$logs   = db_all('SELECT l.*, u.name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                  ORDER BY l.id DESC LIMIT 40');

admin_head('ผู้ใช้งาน', 'users.php');
?>
<div class="split">
  <div class="panel">
    <h3>ผู้ใช้งานระบบ</h3>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>ชื่อ</th><th>อีเมล</th><th>สิทธิ์</th><th>เข้าล่าสุด</th><th>สถานะ</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><b class="small"><?= e($u['name']) ?></b>
                <?= (int) $u['id'] === (int) $me['id'] ? ' <span class="badge badge-primary">คุณ</span>' : '' ?>
                <div class="tiny muted"><?= e($u['phone'] ?: '') ?></div></td>
              <td class="small"><?= e($u['email']) ?></td>
              <td><span class="badge badge-<?= $u['role'] === 'admin' ? 'primary' : 'muted' ?>">
                <?= $u['role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'เจ้าหน้าที่' ?></span></td>
              <td class="small"><?= e(thai_date($u['last_login_at'], true)) ?></td>
              <td><span class="badge badge-<?= (int) $u['is_active'] ? 'ok' : 'danger' ?>">
                <?= (int) $u['is_active'] ? 'ใช้งาน' : 'ปิด' ?></span></td>
              <td class="right" style="white-space:nowrap">
                <a class="btn btn-ghost btn-sm" href="?edit=<?= (int) $u['id'] ?>">แก้ไข</a>
                <?php if ((int) $u['id'] !== (int) $me['id']): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('ลบผู้ใช้นี้?')">
                    <?= csrf_field() ?><input type="hidden" name="act" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button class="btn btn-ghost btn-sm" style="color:var(--danger)">ลบ</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h3 class="mt-3">ประวัติการใช้งานล่าสุด</h3>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>เวลา</th><th>ผู้ใช้</th><th>การกระทำ</th><th>อ้างอิง</th></tr></thead>
        <tbody>
          <?php foreach ($logs as $l): ?>
            <tr>
              <td class="tiny"><?= e(thai_date($l['created_at'], true)) ?></td>
              <td class="tiny"><?= e($l['name'] ?: 'ระบบ') ?></td>
              <td class="tiny"><?= e($l['action']) ?></td>
              <td class="tiny muted"><?= e(($l['ref_table'] ?: '') . ($l['ref_id'] ? '#' . $l['ref_id'] : '')) ?>
                <?= $l['detail'] ? ' · ' . e(mb_substr((string) $l['detail'], 0, 40)) : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <h3><?= $edit ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้ใหม่' ?></h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <div class="field"><label>ชื่อ *</label><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
      <div class="field"><label>อีเมล (ใช้ล็อกอิน) *</label><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>" required></div>
      <div class="field"><label>เบอร์โทร</label><input type="text" name="phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
      <div class="field"><label>รหัสผ่าน <?= $edit ? '(เว้นว่าง = ไม่เปลี่ยน)' : '*' ?></label>
        <input type="password" name="password" autocomplete="new-password" <?= $edit ? '' : 'required' ?>>
        <div class="hint">อย่างน้อย 8 ตัวอักษร</div></div>
      <div class="field"><label>สิทธิ์</label>
        <select name="role">
          <option value="staff" <?= ($edit['role'] ?? '') === 'staff' ? 'selected' : '' ?>>เจ้าหน้าที่ (ใช้งานทั่วไป)</option>
          <option value="admin" <?= ($edit['role'] ?? '') === 'admin' ? 'selected' : '' ?>>ผู้ดูแลระบบ (ตั้งค่า + จัดการผู้ใช้)</option>
        </select></div>
      <label class="checkline">
        <input type="checkbox" name="is_active" value="1" <?= !$edit || (int) $edit['is_active'] ? 'checked' : '' ?>>
        <span>เปิดใช้งานบัญชีนี้</span>
      </label>
      <button class="btn btn-block mt-2"><?= $edit ? 'บันทึก' : 'เพิ่มผู้ใช้' ?></button>
      <?php if ($edit): ?>
        <a class="btn btn-ghost btn-block mt-1" href="<?= url('admin/users.php') ?>">ยกเลิก</a>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php admin_foot(); ?>
