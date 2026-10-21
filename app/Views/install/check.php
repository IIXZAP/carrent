<?php
/**
 * ผลตรวจระบบหลังติดตั้ง
 * ตัวแปร: rows (name, ok, detail, warn), fail, warn, dbOk
 */
$this->layout('layouts/focus', ['title' => $title, 'heading' => $heading, 'sub' => $sub, 'narrow' => $narrow]);
?>
<?php if ($fail === 0 && $warn === 0): ?>
  <div class="alert alert-ok text-[1.1rem] font-bold">✅ ผ่านทุกข้อ — ระบบพร้อมใช้งาน</div>
<?php elseif ($fail === 0): ?>
  <div class="alert alert-warn text-[1.1rem] font-bold">⚠️ ใช้งานได้ แต่มี <?= (int) $warn ?> ข้อที่ควรแก้</div>
<?php else: ?>
  <div class="alert alert-danger text-[1.1rem] font-bold">❌ มีปัญหา <?= (int) $fail ?> ข้อที่ต้องแก้ก่อนใช้งาน</div>
<?php endif; ?>

<!-- ทดสอบ CSS แบบเห็นผลทันที: ถ้า app.css โหลดได้ คลาส bg-ok จะมีสีพื้น -->
<div class="panel mb-3.5">
  <h2 class="mb-3 mt-0 text-[1.05rem]">ทดสอบการโหลดไฟล์ CSS</h2>
  <div id="cssTest" class="rounded-lg bg-red-100 px-3.5 py-2.5 text-[0.93rem] font-semibold text-red-700">
    ❌ CSS โหลดไม่สำเร็จ — หน้าเว็บจะไม่มีการจัดรูปแบบ
  </div>
  <span id="cssProbe" class="bg-ok" hidden></span>
  <p class="mb-0 mt-2.5 text-sm text-muted">URL ที่เรียก: <code><?= e(url('assets/css/app.css')) ?></code>
    — <a href="<?= e(url('assets/css/app.css')) ?>" target="_blank" rel="noopener">กดเปิดดูตรงๆ</a> (ถ้าขึ้น 404 แปลว่า path ผิด)</p>
</div>

<div class="panel">
  <table class="w-full border-collapse text-[0.93rem]">
    <?php foreach ($rows as $r): ?>
      <tr class="border-b border-line last:border-b-0">
        <td class="w-7 py-2 pr-2 text-center align-top text-[1.05rem]"><?= $r['ok'] ? '✅' : ($r['warn'] ? '⚠️' : '❌') ?></td>
        <td class="py-2 align-top">
          <div class="font-semibold <?= (!$r['ok'] && !$r['warn']) ? 'text-danger' : '' ?>"><?= e($r['name']) ?></div>
          <?php if ($r['detail'] !== ''): ?><div class="text-[0.86rem] text-muted"><?= e($r['detail']) ?></div><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <div class="mt-3.5 rounded-[10px] border border-dashed border-brand bg-brand-50 px-4 py-3.5 text-[0.9rem]">
    <b class="text-brand">ถ้า CSS ยังไม่ขึ้น</b> — เปิด <code>config/config.php</code> แล้วตั้งค่าเองที่บรรทัด
    <code>define('BASE_URL_MANUAL', null);</code><br>
    • เว็บอยู่ที่ <code>example.com</code> → ใส่ <code>''</code><br>
    • เว็บอยู่ที่ <code>example.com/carrent</code> → ใส่ <code>'/carrent'</code>
  </div>
</div>

<div class="panel mt-3.5 border-red-200 bg-red-50">
  <b class="text-danger">⚠️ ลบ route /check ออกหรือปล่อยให้ระบบล็อกอัตโนมัติ</b>
  <p class="mb-0 mt-1.5 text-[0.86rem] text-muted">หลังมีผู้ใช้เข้าระบบครั้งแรก หน้านี้จะเปิดได้เฉพาะเจ้าของร้านที่เข้าสู่ระบบอยู่เท่านั้น</p>
</div>

<p class="mt-4 text-center"><a href="<?= url('') ?>">→ ไปหน้าเว็บลูกค้า</a> · <a href="<?= url('admin/login') ?>">เข้าหลังบ้าน</a></p>

<?php $this->start('after_main'); ?>
<script>
(function () {
  var c = getComputedStyle(document.getElementById('cssProbe')).backgroundColor;
  var el = document.getElementById('cssTest');
  if (c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent') {
    el.className = 'rounded-lg bg-green-100 px-3.5 py-2.5 text-[0.93rem] font-semibold text-green-800';
    el.textContent = '✅ CSS โหลดสำเร็จ';
  }
})();
</script>
<?php $this->end(); ?>
