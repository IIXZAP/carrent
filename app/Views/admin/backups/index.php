<?php
/** สำรองข้อมูล — ตัวแปร: list, total, keep, last, ageH, upBytes, upFiles */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'backups']);
?>
<div class="stat-grid-lg">
  <div class="stat <?= $ageH === null || $ageH > 36 ? 'stat-danger' : 'stat-ok' ?>">
    <div class="lb">สำรองล่าสุด</div>
    <div class="vl !text-[1.25rem]"><?= $last !== '' ? e(thai_date($last, true)) : 'ยังไม่เคย' ?></div>
    <div class="sub"><?= $ageH === null ? 'กดสำรองตอนนี้ได้เลย' : ($ageH > 36 ? 'เกิน 36 ชม. — ตรวจว่างานอัตโนมัติทำงาน' : 'งานอัตโนมัติทำงานปกติ') ?></div>
  </div>
  <div class="stat">
    <div class="lb">ไฟล์สำรองที่เก็บอยู่</div>
    <div class="vl"><?= count($list) ?></div>
    <div class="sub">รวม <?= e(human_bytes($total)) ?> · เก็บย้อนหลัง <?= (int) $keep ?> วัน</div>
  </div>
  <div class="stat">
    <div class="lb">รูปและเอกสาร (ไม่รวมในไฟล์สำรอง)</div>
    <div class="vl !text-[1.25rem]"><?= e(human_bytes($upBytes)) ?></div>
    <div class="sub"><?= number_format($upFiles) ?> ไฟล์ · สำรองผ่าน cPanel → Backup</div>
  </div>
</div>

<div class="grid items-start gap-4 lg:grid-cols-2">
  <div class="panel min-w-0">
    <h3>ไฟล์สำรองฐานข้อมูล</h3>
    <p class="text-sm text-muted">ระบบสำรองให้อัตโนมัติวันละครั้งพร้อมงานรายวัน ไฟล์ที่เก่าเกิน <?= (int) $keep ?> วันจะถูกลบเอง (เก็บ 7 ไฟล์ล่าสุดไว้เสมอ) ·
      แนะนำให้ดาวน์โหลดไปเก็บนอกเซิร์ฟเวอร์อย่างน้อยเดือนละครั้ง</p>
    <?php if (!$list): ?>
      <p class="mb-0 text-sm text-muted">ยังไม่มีไฟล์สำรอง</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>วันที่สำรอง</th><th class="num">ขนาด</th><th></th></tr></thead>
          <tbody>
            <?php foreach (array_slice($list, 0, 100) as $f): ?>
              <tr>
                <td><?= e(thai_date(date('Y-m-d H:i:s', $f['time']), true)) ?>
                  <span class="block break-all text-xs text-muted"><?= e($f['name']) ?></span></td>
                <td class="num"><?= e(human_bytes($f['size'])) ?></td>
                <td><a class="btn btn-ghost btn-sm" href="<?= url('admin/backups/download?name=' . urlencode($f['name'])) ?>">ดาวน์โหลด</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="min-w-0">
    <div class="panel">
      <h3>สำรองทันที</h3>
      <p class="text-sm text-muted">ใช้ก่อนแก้ไขข้อมูลจำนวนมาก หรือก่อนอัปเดตระบบ</p>
      <form method="post" action="<?= url('admin/backups') ?>">
        <?= csrf_field() ?>
        <button class="btn btn-accent btn-block" name="act" value="create">สำรองตอนนี้</button>
      </form>
    </div>

    <form method="post" action="<?= url('admin/backups') ?>" class="panel mt-4">
      <?= csrf_field() ?><input type="hidden" name="act" value="keep">
      <h3>ระยะเวลาเก็บไฟล์</h3>
      <div class="field">
        <label for="backup_keep_days">เก็บย้อนหลัง (วัน)</label>
        <input type="number" id="backup_keep_days" name="backup_keep_days" min="7" max="3650" value="<?= (int) $keep ?>">
        <div class="hint">ขั้นต่ำ 7 วัน · ฐานข้อมูลขนาดปกติใช้พื้นที่ไม่ถึง 1 MB ต่อไฟล์</div>
      </div>
      <button class="btn btn-ghost btn-sm">บันทึก</button>
    </form>

    <div class="panel mt-4">
      <h3 class="text-sm">วิธีกู้ข้อมูลจากไฟล์สำรอง</h3>
      <ol class="m-0 pl-[18px] text-sm text-muted">
        <li>ดาวน์โหลดไฟล์ .sql.gz ที่ต้องการ</li>
        <li>cPanel → phpMyAdmin → เลือกฐานข้อมูล → Import</li>
        <li>เลือกไฟล์ (ไม่ต้องแตกไฟล์) แล้วกด Go</li>
      </ol>
      <p class="mb-0 mt-2 text-xs text-muted">การกู้จะแทนที่ข้อมูลปัจจุบันทั้งหมดด้วยข้อมูล ณ เวลาที่สำรอง — สำรองข้อมูลปัจจุบันก่อนทุกครั้ง</p>
    </div>
  </div>
</div>
