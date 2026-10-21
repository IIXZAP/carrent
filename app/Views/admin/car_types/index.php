<?php
/** ประเภทรถ — ตัวแปร: types, edit */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'car-types']);
?>
<div class="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
  <div class="panel min-w-0">
    <h3>ประเภทรถ</h3>
    <p class="text-sm text-muted">ประเภทที่เปิดใช้งานจะแสดงในช่องค้นหารถของลูกค้าและในฟอร์มเพิ่มรถ</p>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>ลำดับ</th><th>รหัส</th><th>ชื่อที่แสดง</th><th>จำนวนรถ</th><th>สถานะ</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($types as $t): ?>
            <tr>
              <td class="num"><?= (int) $t['sort_order'] ?></td>
              <td class="text-sm"><code><?= e($t['code']) ?></code></td>
              <td><b class="text-sm"><?= e($t['label_th']) ?></b></td>
              <td class="num"><?= (int) $t['car_count'] ?></td>
              <td><span class="badge badge-<?= (int) $t['is_active'] ? 'ok' : 'muted' ?>"><?= (int) $t['is_active'] ? 'ใช้งาน' : 'ปิด' ?></span></td>
              <td class="whitespace-nowrap text-right">
                <a class="btn btn-ghost btn-sm" href="?edit=<?= e(urlencode($t['code'])) ?>">แก้ไข</a>
                <?php if ((int) $t['car_count'] === 0): ?>
                  <form method="post" action="<?= url('admin/car-types/delete') ?>" class="inline" onsubmit="return confirm('ลบประเภทนี้?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="code" value="<?= e($t['code']) ?>">
                    <button class="btn btn-ghost btn-sm text-danger">ลบ</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel min-w-0">
    <h3><?= $edit ? 'แก้ไขประเภท' : 'เพิ่มประเภทใหม่' ?></h3>
    <form method="post" action="<?= url('admin/car-types/save') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="original" value="<?= e($edit['code'] ?? '') ?>">
      <?php if ($edit): ?>
        <div class="field"><label>รหัส</label><input type="text" value="<?= e($edit['code']) ?>" disabled>
          <div class="hint">รหัสแก้ไม่ได้ เพราะรถอ้างถึงอยู่</div></div>
      <?php else: ?>
        <div class="field"><label>รหัส *</label><input type="text" name="code" required maxlength="20" placeholder="เช่น hatchback">
          <div class="hint">ตัวอักษรอังกฤษพิมพ์เล็ก ตัวเลข - และ _</div></div>
      <?php endif; ?>
      <div class="field"><label>ชื่อที่แสดง *</label><input type="text" name="label_th" value="<?= e($edit['label_th'] ?? '') ?>" required maxlength="60"></div>
      <div class="field"><label>ลำดับ</label><input type="number" name="sort_order" value="<?= e($edit['sort_order'] ?? 0) ?>">
        <div class="hint">เลขน้อยอยู่ก่อน</div></div>
      <?php if ($edit): ?>
        <label class="checkline">
          <input type="checkbox" name="is_active" value="1" <?= checked((int) $edit['is_active']) ?>>
          <span>เปิดใช้งาน (ปิด = ซ่อนจากตัวเลือก รถเดิมยังใช้ได้)</span>
        </label>
      <?php endif; ?>
      <button class="btn btn-block mt-3"><?= $edit ? 'บันทึก' : 'เพิ่มประเภท' ?></button>
      <?php if ($edit): ?>
        <a class="btn btn-ghost btn-block mt-2" href="<?= url('admin/car-types') ?>">ยกเลิก</a>
      <?php endif; ?>
    </form>
  </div>
</div>
