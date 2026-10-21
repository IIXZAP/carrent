<?php
/** ผู้ใช้งานหลังบ้าน — ตัวแปร: me, edit, users, logs */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'users']);
?>
<div class="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
  <div class="panel min-w-0">
    <h3>ผู้ใช้งานระบบ</h3>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>ชื่อ</th><th>อีเมล</th><th>สิทธิ์</th><th>เข้าล่าสุด</th><th>สถานะ</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><b class="text-sm"><?= e($u['name']) ?></b>
                <?= (int) $u['id'] === (int) $me['id'] ? ' <span class="badge badge-primary">คุณ</span>' : '' ?>
                <div class="text-xs text-muted"><?= e($u['phone'] ?: '') ?></div></td>
              <td class="text-sm"><?= e($u['email']) ?></td>
              <td><span class="badge badge-<?= $u['role'] === 'admin' ? 'primary' : 'muted' ?>">
                <?= $u['role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'เจ้าหน้าที่' ?></span></td>
              <td class="text-sm"><?= e(thai_date($u['last_login_at'], true)) ?></td>
              <td><span class="badge badge-<?= (int) $u['is_active'] ? 'ok' : 'danger' ?>">
                <?= (int) $u['is_active'] ? 'ใช้งาน' : 'ปิด' ?></span></td>
              <td class="whitespace-nowrap text-right">
                <a class="btn btn-ghost btn-sm" href="?edit=<?= (int) $u['id'] ?>">แก้ไข</a>
                <?php if ((int) $u['id'] !== (int) $me['id']): ?>
                  <form method="post" action="<?= url('admin/users/delete') ?>" class="inline" onsubmit="return confirm('ลบผู้ใช้นี้?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button class="btn btn-ghost btn-sm text-danger">ลบ</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h3 class="mt-5">ประวัติการใช้งานล่าสุด</h3>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>เวลา</th><th>ผู้ใช้</th><th>การกระทำ</th><th>อ้างอิง</th></tr></thead>
        <tbody>
          <?php foreach ($logs as $l): ?>
            <tr>
              <td class="text-xs"><?= e(thai_date($l['created_at'], true)) ?></td>
              <td class="text-xs"><?= e($l['name'] ?: 'ระบบ') ?></td>
              <td class="text-xs"><?= e($l['action']) ?></td>
              <td class="text-xs text-muted"><?= e(($l['ref_table'] ?: '') . ($l['ref_id'] ? '#' . $l['ref_id'] : '')) ?>
                <?= $l['detail'] ? ' · ' . e(mb_substr((string) $l['detail'], 0, 40)) : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel min-w-0">
    <h3><?= $edit ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้ใหม่' ?></h3>
    <form method="post" action="<?= url('admin/users/save') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <div class="field"><label>ชื่อ *</label><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
      <div class="field"><label>อีเมล (ใช้ล็อกอิน) *</label><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>" required></div>
      <div class="field"><label>เบอร์โทร</label><input type="text" name="phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
      <div class="field"><label>รหัสผ่าน <?= $edit ? '(เว้นว่าง = ไม่เปลี่ยน)' : '*' ?></label>
        <input type="password" name="password" autocomplete="new-password" <?= $edit ? '' : 'required' ?>>
        <div class="hint">อย่างน้อย 8 ตัวอักษร</div></div>
      <div class="field"><label>สิทธิ์</label>
        <select name="role">
          <option value="staff" <?= selected(($edit['role'] ?? 'staff') === 'staff') ?>>เจ้าหน้าที่ (ใช้งานทั่วไป)</option>
          <option value="admin" <?= selected(($edit['role'] ?? '') === 'admin') ?>>ผู้ดูแลระบบ (ตั้งค่า + จัดการผู้ใช้)</option>
        </select></div>
      <label class="checkline">
        <input type="checkbox" name="is_active" value="1" <?= checked(!$edit || (int) $edit['is_active']) ?>>
        <span>เปิดใช้งานบัญชีนี้</span>
      </label>
      <button class="btn btn-block mt-3"><?= $edit ? 'บันทึก' : 'เพิ่มผู้ใช้' ?></button>
      <?php if ($edit): ?>
        <a class="btn btn-ghost btn-block mt-2" href="<?= url('admin/users') ?>">ยกเลิก</a>
      <?php endif; ?>
    </form>
  </div>
</div>
