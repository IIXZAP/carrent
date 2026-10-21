<?php
/**
 * หน้าเซ็นเอกสารบนมือถือ (ลูกค้า) — ใช้ layout focus
 * ตัวแปร: valid, b, kind, docName, car, insp, photos, extras, fields, termsHtml, sig, blocker, error, viewHash, no, token
 */
$this->layout('layouts/focus', ['title' => $title, 'heading' => $heading, 'sub' => $sub]);

$card  = 'mb-3 rounded-xl border border-line bg-white p-4';
$h2    = 'mb-2.5 mt-0 text-[1.02rem] leading-snug';
$row   = 'flex justify-between gap-3.5 border-t border-line py-[7px] text-[0.95rem] first:border-t-0 first:pt-0';
$step  = 'mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-brand align-[1px] text-[0.82rem] font-bold text-white';
$phone = (string) setting('phone');
$snap  = ($valid && $sig) ? (json_decode((string) $sig['snapshot'], true) ?: []) : [];
?>
<?php if (!$valid): ?>
  <div class="<?= $card ?>">
    <h2 class="<?= $h2 ?>">เปิดหน้านี้ไม่ได้</h2>
    <p class="m-0">ลิงก์ไม่ถูกต้องหรือไม่ครบ กรุณาขอลิงก์ใหม่จากร้าน<?php if ($phone !== ''): ?>
      โทร <a class="text-brand underline" href="tel:<?= e($phone) ?>"><?= e($phone) ?></a><?php endif; ?></p>
  </div>

<?php elseif ($sig): /* ============ ลงนามแล้ว ============ */
    $sf = \App\Services\SignatureService::fields($b, $car, $kind); ?>
  <div class="<?= $card ?> py-5 text-center">
    <div class="mx-auto mb-2.5 flex h-[60px] w-[60px] items-center justify-center rounded-full bg-ok text-[2rem] text-white" aria-hidden="true">✓</div>
    <h2 class="mb-1 mt-0 text-[1.2rem]">ลงนามเรียบร้อยแล้ว</h2>
    <p class="m-0 text-muted"><?= e($docName) ?> เลขที่ <?= e($b['booking_no']) ?></p>
    <?php $img = \App\Services\PrivateFileService::dataUri($sig['file_path']); if ($img !== ''): ?>
      <img class="mx-auto mb-1 mt-3 block w-full max-w-[300px] border-b border-slate-400 bg-white" src="<?= e($img) ?>" alt="ลายเซ็นของ <?= e($sig['signer_name']) ?>">
    <?php endif; ?>
    <div class="font-semibold"><?= e($sig['signer_name']) ?></div>
    <div class="mt-1.5 text-[0.82rem] text-muted">ลงนามเมื่อ <?= e(thai_date($sig['signed_at'], true)) ?></div>
    <div class="mt-1.5 text-[0.82rem] text-muted">รหัสอ้างอิง
      <span class="font-mono text-[0.84rem] tracking-wider"><?= e(\App\Services\SignatureService::ref($sig)) ?></span></div>
  </div>
  <div class="<?= $card ?>">
    <h2 class="<?= $h2 ?>">ข้อมูลที่ท่านลงนามรับรอง</h2>
    <dl class="m-0">
      <?php foreach (($snap['fields'] ?? []) as $k => $v): ?>
        <div class="<?= $row ?>"><dt class="max-w-[45%] flex-none text-muted"><?= e($sf[$k][0] ?? $k) ?></dt>
          <dd class="m-0 min-w-0 break-words text-right font-semibold"><?= e($v) ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <p class="mb-0 mt-1.5 text-[0.82rem] text-muted">เก็บลิงก์หน้านี้ไว้เปิดดูได้ภายหลัง หากข้อมูลไม่ถูกต้องกรุณาแจ้งร้าน<?= $phone !== '' ? ' โทร ' . e($phone) : '' ?></p>
  </div>

<?php elseif ($blocker): ?>
  <div class="<?= $card ?>">
    <h2 class="<?= $h2 ?>">ยังเซ็นเอกสารนี้ไม่ได้</h2>
    <p class="m-0"><?= e($blocker) ?></p>
  </div>

<?php else: /* ============ ฟอร์มลงนาม ============ */ ?>
  <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>

  <section class="<?= $card ?>">
    <h2 class="<?= $h2 ?>"><span class="<?= $step ?>">1</span>ตรวจข้อมูล<?= $kind === 'return' ? 'การคืนรถ' : 'การเช่า' ?></h2>
    <dl class="m-0">
      <?php foreach ($fields as $k => [$label, $val]):
          if ($kind === 'return' && in_array($k, ['total', 'deposit', 'extra'], true)) {
              continue;
          } ?>
        <div class="<?= $row ?>"><dt class="max-w-[45%] flex-none text-muted"><?= e($label) ?></dt>
          <dd class="m-0 min-w-0 break-words text-right font-semibold"><?= e($val) ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </section>

  <?php if ($kind === 'return'):
      $due  = (float) $b['grand_total'] + (float) $b['extra_charge'];
      $bal  = max(0, $due - (float) $b['paid_amount']);
      $held = \App\Models\Payment::securityHeld((int) $b['id']); ?>
    <section class="<?= $card ?>">
      <h2 class="<?= $h2 ?>">ค่าใช้จ่ายเพิ่มตอนคืนรถ</h2>
      <dl class="m-0">
        <?php if (!$extras): ?>
          <div class="<?= $row ?>"><dt class="text-muted">ค่าใช้จ่ายเพิ่ม</dt><dd class="m-0 text-right font-semibold">ไม่มี</dd></div>
        <?php endif; ?>
        <?php foreach ($extras as $l): ?>
          <div class="<?= $row ?>"><dt class="max-w-[45%] flex-none text-muted"><?= e($l['label']) ?></dt>
            <dd class="m-0 text-right font-semibold"><?= $l['waived']
                ? '<span class="font-normal text-muted">ยกเว้นให้</span>'
                : e(money0($l['amount'])) . ' บาท' ?></dd></div>
        <?php endforeach; ?>
        <?php if ($extras): ?>
          <div class="<?= $row ?>"><dt class="text-muted">รวมค่าใช้จ่ายเพิ่ม</dt>
            <dd class="m-0 text-right text-[1.05rem] font-semibold"><?= e(money0($b['extra_charge'])) ?> บาท</dd></div>
        <?php endif; ?>
        <div class="<?= $row ?>"><dt class="text-muted">ยอดค้างชำระทั้งหมด</dt>
          <dd class="m-0 text-right font-semibold"><?= e(money0($bal)) ?> บาท</dd></div>
        <?php if ($held > 0): ?>
          <div class="<?= $row ?>"><dt class="text-muted">เงินประกันที่ร้านถือไว้</dt>
            <dd class="m-0 text-right font-semibold"><?= e(money0($held)) ?> บาท</dd></div>
          <div class="<?= $row ?>"><dt class="text-muted">เงินประกันที่จะได้คืน</dt>
            <dd class="m-0 text-right text-[1.05rem] font-semibold"><?= e(money0(max(0, $held - $bal))) ?> บาท</dd></div>
        <?php endif; ?>
      </dl>
    </section>
  <?php endif; ?>

  <?php if ($photos): ?>
    <section class="<?= $card ?>">
      <h2 class="<?= $h2 ?>">สภาพรถ<?= $kind === 'return' ? 'ตอนคืนรถ' : 'ตอนรับรถ' ?> (<?= count($photos) ?> รูป)</h2>
      <div class="grid grid-cols-3 gap-1.5 min-[420px]:grid-cols-4">
        <?php foreach (\App\Services\InspectionService::positions() as $pk => $pdef):
            if (empty($photos[$pk])) {
                continue;
            }
            $plabel = $pdef[0]; ?>
          <a class="relative block aspect-[4/3] overflow-hidden rounded-lg border border-line bg-[#eef1f5]"
             href="<?= e(upload_url($photos[$pk]['file_path'])) ?>" target="_blank" rel="noopener">
            <img class="block h-full w-full object-cover" src="<?= e(upload_url($photos[$pk]['file_path'])) ?>" alt="<?= e($plabel) ?>" loading="lazy">
            <span class="absolute inset-x-0 bottom-0 truncate bg-[rgba(15,23,35,.72)] px-[5px] py-0.5 text-[0.66rem] text-white"><?= e($plabel) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php
          $mile = $insp['mileage'] ?: ($kind === 'return' ? $b['mileage_in'] : $b['mileage_out']);
          $fl   = $insp['fuel_level'] ?? ($kind === 'return' ? $b['fuel_in'] : $b['fuel_out']);
      ?>
      <p class="mb-0 mt-2 text-[0.86rem] text-slate-600">
        เลขไมล์ <?= $mile ? e(number_format((float) $mile)) . ' กม.' : '-' ?>
        · น้ำมัน <?= e(fuel_label($fl !== null && $fl !== '' ? (int) $fl : null)) ?>
        <?php if (!empty($insp['note'])): ?><br>ตำหนิที่บันทึก: <?= e($insp['note']) ?><?php endif; ?>
      </p>
      <p class="mb-0 mt-1.5 text-[0.82rem] text-muted">แตะรูปเพื่อดูขนาดเต็ม</p>
    </section>
  <?php endif; ?>

  <section class="<?= $card ?>">
    <h2 class="<?= $h2 ?>"><span class="<?= $step ?>">2</span><?= $kind === 'return' ? 'ข้อความรับรอง' : 'อ่านเงื่อนไขสัญญา' ?></h2>
    <div class="max-h-[300px] overflow-y-auto rounded-[10px] border border-line bg-[#fbfcfd] py-1 pl-1.5 pr-3.5 text-[0.92rem] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent [&_p]:mx-2 [&_p]:my-2.5 print:max-h-none print:overflow-visible"
         tabindex="0" role="region" aria-label="เงื่อนไข"><?= $termsHtml ?></div>
    <?php if ($kind === 'contract'): ?><p class="mb-0 mt-1.5 text-[0.82rem] text-muted">เลื่อนอ่านในกรอบได้ครบทุกข้อ</p><?php endif; ?>
  </section>

  <section class="<?= $card ?>">
    <h2 class="<?= $h2 ?>"><span class="<?= $step ?>">3</span>ลงนาม</h2>
    <form method="post" id="signForm" action="<?= e(url('sign') . '?no=' . rawurlencode($no) . '&k=' . $kind . '&t=' . $token) ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="sig" id="sig">
      <input type="hidden" name="seen" value="<?= e($viewHash) ?>">

      <label class="mb-1 block text-[0.88rem] font-semibold" for="name">ชื่อ–นามสกุลผู้ลงนาม</label>
      <input class="field min-h-[48px]" type="text" id="name" name="name" maxlength="150" autocomplete="name" required
             value="<?= e($_POST['name'] ?? $b['contact_name']) ?>">

      <?php $ck = 'mt-2.5 flex cursor-pointer items-start gap-[11px] rounded-[10px] border border-line p-3 text-[0.93rem] has-[:checked]:border-ok has-[:checked]:bg-[#e8f6ee]'; ?>
      <label class="<?= $ck ?>"><input class="mt-px h-6 w-6 flex-none accent-brand" type="checkbox" name="accept" value="1" <?= !empty($_POST['accept']) ? 'checked' : '' ?>>
        <span><?= $kind === 'return'
            ? 'ข้าพเจ้าได้ตรวจสภาพรถร่วมกับผู้รับคืนรถแล้ว และรับทราบรายการค่าใช้จ่ายข้างต้น'
            : 'ข้าพเจ้าได้อ่านและยอมรับเงื่อนไขสัญญาเช่ารถข้างต้นทุกข้อ' . ($photos ? ' และยืนยันว่าภาพถ่ายข้างต้นเป็นสภาพรถ ณ วันรับรถจริง' : '') ?></span></label>
      <label class="<?= $ck ?>"><input class="mt-px h-6 w-6 flex-none accent-brand" type="checkbox" name="pdpa" value="1" <?= !empty($_POST['pdpa']) ? 'checked' : '' ?>>
        <span><?= e(setting('pdpa_policy_text', 'ข้าพเจ้ายินยอมให้ผู้ให้เช่าเก็บรวบรวมและใช้ข้อมูลส่วนบุคคลของข้าพเจ้าเพื่อการทำสัญญาเช่ารถและการปฏิบัติตามกฎหมายที่เกี่ยวข้อง')) ?></span></label>

      <div class="relative mt-3.5">
        <canvas class="pad block aspect-[5/2] w-full cursor-crosshair touch-none rounded-xl border-2 border-dashed border-slate-400 bg-white"
                id="pad" width="900" height="360" role="img" aria-label="กรอบเซ็นชื่อ ใช้นิ้วหรือปากกาเขียนในกรอบนี้"></canvas>
        <div class="pointer-events-none absolute inset-x-[8%] bottom-[22%] border-b border-slate-300" aria-hidden="true"></div>
        <div class="pointer-events-none absolute inset-0 flex items-center justify-center px-3 text-center text-slate-400" id="padPh">ใช้นิ้วเซ็นชื่อในกรอบนี้</div>
      </div>
      <div class="mt-1.5 flex items-center justify-between text-[0.84rem] text-muted"><span>เซ็นให้เหมือนในบัตรประชาชน</span>
        <button type="button" class="min-h-[44px] flex-none cursor-pointer whitespace-nowrap border-0 bg-transparent px-1.5 font-semibold text-brand underline focus-visible:outline focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-accent" id="padClear">ล้าง เซ็นใหม่</button></div>

      <div class="alert alert-danger mt-3 mb-0" id="formErr" role="alert" hidden></div>
      <button class="btn btn-block mt-3.5 min-h-[52px] text-[1.05rem]" id="submitBtn">ยืนยันการลงนาม</button>
      <p class="mb-0 mt-1.5 text-center text-[0.82rem] text-muted">ระบบบันทึกวัน–เวลาและอุปกรณ์ที่ใช้ลงนามไว้เป็นหลักฐาน</p>
    </form>
  </section>
<?php endif; ?>

<p class="px-2.5 py-1.5 text-center text-[0.82rem] text-muted"><?= e(setting('company_name') ?: setting('site_name')) ?><?= $phone !== '' ? ' · โทร ' . e($phone) : '' ?></p>

<?php if ($valid && !$sig && !$blocker): ?>
<?php $this->start('after_main'); ?>
<script>
(function () {
  var pad = document.getElementById('pad'), ctx = pad.getContext('2d'), ph = document.getElementById('padPh');
  var form = document.getElementById('signForm'), err = document.getElementById('formErr'), btn = document.getElementById('submitBtn');
  var drawing = false, last = null, inkLen = 0;

  /* ขนาดภายในของกรอบคงที่ 900×360 — จอหมุนหรือเปลี่ยนขนาดแล้วลายเซ็นไม่หาย */
  var pos = function (e) {
    var r = pad.getBoundingClientRect();
    return { x: (e.clientX - r.left) * pad.width / r.width, y: (e.clientY - r.top) * pad.height / r.height, k: pad.width / r.width };
  };
  ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111827'; ctx.fillStyle = '#111827';

  pad.addEventListener('pointerdown', function (e) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    e.preventDefault();
    try { pad.setPointerCapture(e.pointerId); } catch (x) {}
    drawing = true; last = pos(e);
    ctx.beginPath(); ctx.arc(last.x, last.y, 1.3 * last.k, 0, Math.PI * 2); ctx.fill();
    ph.hidden = true; pad.classList.remove('border-dashed', 'border-slate-400'); pad.classList.add('border-solid', 'border-brand'); err.hidden = true;
  });
  pad.addEventListener('pointermove', function (e) {
    if (!drawing) return;
    e.preventDefault();
    var p = pos(e);
    ctx.lineWidth = 2.6 * p.k;
    ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
    /* นับความยาวเส้นเฉพาะส่วนที่อยู่ในกรอบ — ลากนิ้วออกนอกกรอบไม่นับเป็นลายเซ็น */
    if (p.x >= 0 && p.x <= pad.width && p.y >= 0 && p.y <= pad.height) inkLen += Math.hypot(p.x - last.x, p.y - last.y);
    last = p;
  });
  var stop = function () { drawing = false; last = null; };
  pad.addEventListener('pointerup', stop);
  pad.addEventListener('pointercancel', stop);
  pad.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') stop(); });

  document.getElementById('padClear').addEventListener('click', function () {
    ctx.clearRect(0, 0, pad.width, pad.height); inkLen = 0; ph.hidden = false;
    pad.classList.remove('border-solid', 'border-brand'); pad.classList.add('border-dashed', 'border-slate-400');
  });

  var fail = function (msg, el) {
    err.textContent = msg; err.hidden = false;
    (el || err).scrollIntoView({ block: 'center', behavior: 'smooth' });
    if (el && el.focus) el.focus({ preventScroll: true });
  };
  form.addEventListener('submit', function (e) {
    if (form.name.value.trim().length < 2) { e.preventDefault(); return fail('กรุณาพิมพ์ชื่อ–นามสกุลของผู้ลงนาม', form.name); }
    if (!form.accept.checked) { e.preventDefault(); return fail('กรุณาติ๊กยืนยันว่าได้อ่านและยอมรับแล้ว', form.accept); }
    if (!form.pdpa.checked) { e.preventDefault(); return fail('กรุณาติ๊กยินยอมการใช้ข้อมูลส่วนบุคคลก่อนลงนาม', form.pdpa); }
    if (inkLen < pad.width * 0.25) { e.preventDefault(); return fail('กรุณาเซ็นชื่อในกรอบให้ชัดเจนก่อนกดยืนยัน', pad); }
    document.getElementById('sig').value = pad.toDataURL('image/png');
    btn.disabled = true; btn.textContent = 'กำลังบันทึก…';
  });
  /* กดย้อนกลับมาหน้านี้ (bfcache) ปุ่มต้องกลับมากดได้ */
  window.addEventListener('pageshow', function () { btn.disabled = false; btn.textContent = 'ยืนยันการลงนาม'; });
})();
</script>
<?php $this->end(); ?>
<?php endif; ?>
