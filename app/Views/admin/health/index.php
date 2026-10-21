<?php
/** สุขภาพระบบ — ตัวแปร: checks, count, overall, legacyDocs, pingUrl, key */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'health']);
$label = ['ok' => 'ปกติ', 'warn' => 'ควรดู', 'fail' => 'ต้องแก้'];
$badge = ['ok' => 'ok', 'warn' => 'warn', 'fail' => 'danger'];
$tone  = ['ok' => 'stat-ok', 'warn' => 'stat-accent', 'fail' => 'stat-danger'];
?>
<div class="mb-[22px] grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(190px,1fr))]">
  <div class="stat <?= $tone[$overall] ?>">
    <div class="lb">ผลรวม</div>
    <div class="vl !text-[1.4rem]"><?= $overall === 'ok' ? 'ระบบปกติ' : ($overall === 'warn' ? 'มีข้อควรดู' : 'มีข้อต้องแก้') ?></div>
    <div class="sub">ตรวจเมื่อ <?= e(thai_date(date('Y-m-d H:i:s'), true)) ?></div>
  </div>
  <div class="stat stat-ok"><div class="lb">ปกติ</div><div class="vl"><?= (int) $count['ok'] ?></div><div class="sub">รายการ</div></div>
  <div class="stat"><div class="lb">ควรดู</div><div class="vl"><?= (int) $count['warn'] ?></div><div class="sub">ยังใช้งานได้ แต่ควรจัดการ</div></div>
  <div class="stat <?= $count['fail'] ? 'stat-danger' : '' ?>"><div class="lb">ต้องแก้</div><div class="vl"><?= (int) $count['fail'] ?></div><div class="sub">กระทบการทำงานของระบบ</div></div>
</div>

<div class="panel">
  <div class="flex flex-wrap items-center justify-between gap-2.5">
    <h3 class="mb-0">รายการตรวจ</h3>
    <button class="btn btn-ghost btn-sm print:hidden" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
  </div>
  <div class="table-wrap mt-2">
    <table class="table">
      <thead><tr><th class="w-[30%]">หัวข้อ</th><th class="w-[12%]">ผล</th><th>ที่พบ</th><th>ต้องทำอะไร</th></tr></thead>
      <tbody>
        <?php foreach ($checks as [$ctitle, $level, $found, $advice]): ?>
          <tr>
            <td><b class="text-sm"><?= e($ctitle) ?></b></td>
            <td><span class="badge badge-<?= $badge[$level] ?>"><?= $label[$level] ?></span></td>
            <td class="text-sm"><?= e($found) ?></td>
            <td class="text-sm text-muted"><?= e($advice ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($legacyDocs > 0): ?>
  <div class="panel mt-4 print:hidden">
    <h3>ย้ายเอกสารลูกค้าเข้าที่เก็บแบบปิด</h3>
    <p class="text-sm text-muted">มีสำเนาบัตรประชาชน/ใบขับขี่ <b><?= (int) $legacyDocs ?> ไฟล์</b> ที่อัปโหลดก่อนเวอร์ชัน 5 ยังอยู่ในโฟลเดอร์ <code>uploads/docs</code>
      ซึ่งเป็นโฟลเดอร์ที่เปิดผ่านเว็บได้ ปุ่มนี้จะย้ายไฟล์ทั้งหมดไปไว้ใน <code>storage/private</code> — เป็นการย้าย ไม่มีการลบไฟล์
      และหลังย้ายยังเปิดดูได้จากหน้าลูกค้าตามเดิม</p>
    <form method="post" action="<?= url('admin/health') ?>" onsubmit="return confirm('ย้ายเอกสารลูกค้า <?= (int) $legacyDocs ?> ไฟล์เข้าที่เก็บแบบปิด?')">
      <?= csrf_field() ?>
      <button class="btn">ย้ายเข้าที่เก็บแบบปิด</button>
    </form>
  </div>
<?php endif; ?>

<div class="panel mt-4 print:hidden">
  <h3>เฝ้าระวังระบบล่มอัตโนมัติ</h3>
  <p class="text-sm text-muted">นำที่อยู่นี้ไปตั้งในบริการเฝ้าระวัง (เช่น UptimeRobot แบบ Keyword ให้ตรวจคำว่า <b>OK</b> ทุก 5 นาที) — ระบบจะตอบ OK เมื่อเว็บและฐานข้อมูลทำงานปกติ</p>
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
