<?php
/**
 * หน้าถ่ายรูปตรวจสภาพรถ (มือถือเป็นหลัก)
 * ตัวแปร: b, bookingId, kind, ins, phaseBlock, locked, map, prog, other, defaultKm, defaultFuel, positions
 */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'bookings']);
use App\Services\InspectionService;
$base = url('admin/bookings/' . $bookingId . '/inspection/' . $kind);
?>
<div class="mb-4">
  <a class="inline-flex items-center gap-1 text-sm" href="<?= url('admin/bookings/' . $bookingId) ?>"><?= icon('back') ?> กลับหน้าการจอง</a>
  <h2 class="mt-1.5">ตรวจสภาพรถ — <?= e(InspectionService::kindLabel($kind)) ?></h2>
  <p class="mb-0 text-muted">
    <?= e($b['booking_no']) ?> · <?= e($b['brand'] . ' ' . $b['model']) ?>
    · ทะเบียน <?= e($b['plate']) ?> · <?= e($b['contact_name']) ?>
  </p>
</div>

<?php if ($locked): ?>
  <div class="alert alert-info">
    <span>
      <?php if ($phaseBlock !== null && !InspectionService::isLocked($ins)): ?>
        <b>🔒 <?= e($phaseBlock) ?></b>
      <?php else: ?>
        <b>🔒 ผลตรวจรอบนี้ถูกล็อกแล้ว</b> เมื่อ <?= e(thai_date($ins['locked_at'], true)) ?>
        <?= $ins['locker'] ? 'โดย ' . e($ins['locker']) : '(ล็อกอัตโนมัติ)' ?>
        — เพิ่มรูป เปลี่ยนรูป หรือแก้ตัวเลขไม่ได้อีก ใช้เป็นหลักฐานแนบท้ายสัญญาได้
      <?php endif; ?>
    </span>
  </div>
<?php endif; ?>

<div class="insp-bar" id="progBar">
  <div class="insp-bar-fill" style="width:<?= (int) ($prog['done'] / max(1, $prog['required']) * 100) ?>%"></div>
  <span class="insp-bar-txt">ถ่ายแล้ว <b id="progDone"><?= (int) $prog['done'] ?></b>/<?= (int) $prog['required'] ?> มุม</span>
</div>

<div class="panel">
  <div class="insp-grid">
    <?php foreach ($positions as $key => [$label, $hint]):
        $have = $map[$key] ?? null; ?>
      <div class="insp-card<?= $have ? ' has' : '' ?>" data-pos="<?= e($key) ?>">
        <div class="insp-thumb">
          <?php if ($have): ?>
            <img src="<?= e(upload_url($have['file_path'])) ?>" alt="<?= e($label) ?>" loading="lazy">
          <?php else: ?>
            <span class="insp-ph"><?= icon('camera') ?></span>
          <?php endif; ?>
          <span class="insp-badge"><?= icon('check') ?></span>
          <span class="insp-spin"></span>
        </div>
        <div class="insp-meta">
          <b><?= e($label) ?><?= $key === 'damage' ? ' <span class="opt">(ไม่บังคับ)</span>' : '' ?></b>
          <?php if ($have): ?>
            <span class="insp-by block text-xs text-muted">ถ่าย <?= e(thai_date($have['created_at'], true)) ?><?= !empty($have['uploader']) ? ' · ' . e($have['uploader']) : '' ?></span>
          <?php else: ?>
            <span class="text-xs text-muted"><?= e($hint) ?></span>
          <?php endif; ?>
          <span class="insp-err text-xs"></span>
        </div>
        <?php if (!$locked): ?>
          <label class="btn btn-ghost btn-sm insp-btn">
            <?= icon('camera') ?> <span><?= $have ? 'ถ่ายใหม่' : 'ถ่ายรูป' ?></span>
            <input type="file" accept="image/*" capture="environment" hidden>
          </label>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<form method="post" action="<?= e($base) ?>" class="panel mt-4">
  <?= csrf_field() ?><input type="hidden" name="act" value="save">
  <h3>เลขไมล์ · น้ำมัน · หมายเหตุ</h3>
  <div class="grid gap-x-3.5 sm:grid-cols-2">
    <div class="field">
      <label for="mileage">เลขไมล์ (กม.)</label>
      <input type="number" id="mileage" name="mileage" inputmode="numeric" min="0"
             value="<?= (int) $defaultKm ?>"<?= $locked ? ' disabled' : '' ?>>
      <?php if ($kind === 'in' && !empty($b['mileage_out'])): ?>
        <span class="text-xs text-muted">ตอนส่งมอบ <?= number_format((float) $b['mileage_out']) ?> กม.</span>
      <?php endif; ?>
    </div>
    <div class="field">
      <label for="fuel_level">ระดับน้ำมัน</label>
      <select id="fuel_level" name="fuel_level"<?= $locked ? ' disabled' : '' ?>>
        <?php foreach ([8 => 'เต็มถัง', 7 => '7/8', 6 => '3/4 ถัง', 5 => '5/8', 4 => 'ครึ่งถัง',
                        3 => '3/8', 2 => '1/4 ถัง', 1 => '1/8', 0 => 'ถังว่าง'] as $v => $t): ?>
          <option value="<?= $v ?>"<?= selected((int) $defaultFuel === $v) ?>><?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="field">
    <label for="note">หมายเหตุ / ตำหนิที่พบ</label>
    <textarea id="note" name="note" rows="3"<?= $locked ? ' disabled' : '' ?>
              placeholder="เช่น รอยขีดข่วนประตูหลังขวา ยางหน้าซ้ายดอกเหลือน้อย"><?= e($ins['note'] ?? '') ?></textarea>
  </div>
  <?php if (!$locked): ?>
    <button class="btn btn-block">บันทึกผลตรวจสภาพรถ</button>
    <div class="mt-3.5 border-t border-dashed border-line pt-3">
      <label class="checkline">
        <input type="checkbox" name="lock_ack" value="1">
        <span>ตรวจครบแล้ว ต้องการล็อกผลตรวจรอบนี้
          <span class="block text-xs text-muted">ล็อกแล้วจะเพิ่มรูป เปลี่ยนรูป หรือแก้ตัวเลขไม่ได้อีก — ระบบล็อกให้เองเมื่อบันทึก<?= $kind === 'out' ? 'ส่งมอบรถ' : 'รับคืนรถ' ?></span></span>
      </label>
      <button class="btn btn-ghost btn-block" name="act" value="lock">🔒 บันทึกและล็อกผลตรวจ</button>
    </div>
  <?php endif; ?>
  <?php if ($ins && $ins['inspected_at']): ?>
    <p class="mb-0 mt-2 text-center text-xs text-muted">
      บันทึกล่าสุด <?= e(thai_date($ins['inspected_at'], true)) ?>
      <?= $ins['inspector'] ? ' โดย ' . e($ins['inspector']) : '' ?>
    </p>
  <?php endif; ?>
</form>

<?php if ($ins && $ins['history']): ?>
  <div class="panel mt-4">
    <h3>รูปที่ถูกถ่ายแทน (<?= count($ins['history']) ?>)</h3>
    <p class="text-xs text-muted">รูปเดิมของมุมที่ถ่ายซ้ำ ระบบเก็บไว้ทั้งหมด ไม่มีการลบ</p>
    <div class="insp-mini">
      <?php foreach ($ins['history'] as $p): ?>
        <a href="<?= e(upload_url($p['file_path'])) ?>" target="_blank" rel="noopener">
          <img src="<?= e(upload_url($p['file_path'])) ?>" alt="" loading="lazy">
          <span><?= e($positions[$p['position']][0] ?? $p['position']) ?>
            · <?= e(thai_date($p['created_at'], true)) ?><?= !empty($p['uploader']) ? ' · ' . e($p['uploader']) : '' ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($other && $other['photos']): ?>
  <div class="panel mt-4">
    <h3>เทียบกับ<?= e(InspectionService::kindLabel($kind === 'out' ? 'in' : 'out')) ?></h3>
    <p class="text-xs text-muted">บันทึกเมื่อ <?= e(thai_date($other['inspected_at'], true)) ?></p>
    <div class="insp-mini">
      <?php foreach ($other['photos'] as $p): ?>
        <a href="<?= e(upload_url($p['file_path'])) ?>" target="_blank" rel="noopener">
          <img src="<?= e(upload_url($p['file_path'])) ?>" alt="" loading="lazy">
          <span><?= e($positions[$p['position']][0] ?? $p['position']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php $this->start('after_main'); ?>
<script>
/* ---------- ถ่ายรูป → ย่อในเครื่อง → อัปทีละใบ ---------- */
(function () {
  var TOKEN    = <?= json_encode(csrf_token()) ?>;
  var ENDPOINT = <?= json_encode($base . '/photo') ?>;
  var MAX_SIDE = 1600;   // ด้านยาวสุดหลังย่อ
  var QUALITY  = 0.82;

  /* ย่อรูปด้วย canvas — ลดจาก 3–5 MB เหลือ ~250–400 KB */
  function shrink(file) {
    return new Promise(function (resolve) {
      var img = new Image();
      var url = URL.createObjectURL(file);
      img.onload = function () {
        URL.revokeObjectURL(url);
        var w = img.naturalWidth, h = img.naturalHeight;
        var scale = Math.min(1, MAX_SIDE / Math.max(w, h));
        w = Math.round(w * scale); h = Math.round(h * scale);
        var cv = document.createElement('canvas');
        cv.width = w; cv.height = h;
        cv.getContext('2d').drawImage(img, 0, 0, w, h);
        cv.toBlob(function (blob) {
          /* ถ้าย่อไม่สำเร็จ ให้ส่งไฟล์เดิมไปแทน ดีกว่าถ่ายแล้วหาย */
          resolve(blob || file);
        }, 'image/jpeg', QUALITY);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  function setProgress() {
    var total = <?= (int) $prog['required'] ?>;
    var n = 0;
    document.querySelectorAll('.insp-card.has').forEach(function (c) {
      if (c.dataset.pos !== 'damage') n++;
    });
    document.getElementById('progDone').textContent = n;
    document.querySelector('.insp-bar-fill').style.width = Math.min(100, n / total * 100) + '%';
  }

  document.querySelectorAll('.insp-card').forEach(function (card) {
    var input = card.querySelector('input[type=file]');
    var errEl = card.querySelector('.insp-err');
    if (!input) return;   /* ผลตรวจถูกล็อกแล้ว — ไม่มีปุ่มถ่ายรูป */

    input.addEventListener('change', async function () {
      var file = input.files && input.files[0];
      if (!file) return;
      errEl.textContent = '';
      card.classList.add('busy');

      try {
        var blob = await shrink(file);
        var fd = new FormData();
        fd.append('_csrf', TOKEN);
        fd.append('position', card.dataset.pos);
        fd.append('photo', blob, 'photo.jpg');

        var res  = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin',
                                           headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
        var data = await res.json();
        if (!data.ok) throw new Error(data.error || 'อัปโหลดไม่สำเร็จ');

        var thumb = card.querySelector('.insp-thumb');
        var im = thumb.querySelector('img');
        if (!im) {
          thumb.innerHTML = '<img alt=""><span class="insp-badge">'
            + document.querySelector('.insp-badge').innerHTML + '</span><span class="insp-spin"></span>';
          im = thumb.querySelector('img');
        }
        im.src = data.url + '?t=' + Date.now();
        card.classList.add('has');
        card.querySelector('.insp-btn span').textContent = 'ถ่ายใหม่';
        var meta = card.querySelector('.insp-meta .text-muted');
        if (meta) meta.textContent = 'ถ่ายเมื่อสักครู่';
        setProgress();
      } catch (e) {
        errEl.textContent = e.message || 'อัปโหลดไม่สำเร็จ ลองใหม่อีกครั้ง';
      } finally {
        card.classList.remove('busy');
        input.value = '';   /* เคลียร์เพื่อให้ถ่ายมุมเดิมซ้ำได้ */
      }
    });
  });
})();
</script>
<?php $this->end(); ?>
