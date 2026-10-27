<?php /** $booking $kind $active $view $error $form $photos */
$kindLabel = \App\Services\SignatureService::kinds()[$kind][0];
$self = ['no' => $booking['booking_no'], 'k' => $kind, 't' => $booking['sign_token']];
?>
<h1 class="text-xl font-bold"><?= e($kindLabel) ?> <span class="text-muted"><?= e($booking['booking_no']) ?></span></h1>

<?php if ($active): ?>
  <div class="panel mt-4 border-green-300 bg-green-50">
    <p class="font-semibold text-ok">ลงนามเรียบร้อยแล้ว</p>
    <p class="text-sm mt-1">ผู้ลงนาม <?= e($active['signer_name']) ?> · <?= e(thai_date($active['signed_at'], true)) ?></p>
    <p class="text-sm text-ink-2">รหัสอ้างอิง <b><?= e(\App\Services\SignatureService::ref($active)) ?></b> — เก็บรหัสนี้ไว้อ้างอิงกับร้าน</p>
  </div>
<?php elseif ($view['blocker']): ?>
  <div class="alert alert-danger mt-4"><?= e($view['blocker']) ?></div>
<?php else: ?>
  <?php if ($error): ?><div class="alert alert-danger mt-4"><?= e($error) ?></div><?php endif; ?>

  <div class="panel mt-4">
    <h2 class="font-semibold">ตรวจสอบข้อมูลก่อนลงนาม</h2>
    <table class="table mt-2">
      <?php foreach ($view['fields'] as [$label, $value]): ?>
        <tr><td class="text-muted w-2/5"><?= e($label) ?></td><td><?= e($value) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if ($kind === 'return' && $view['extras'] !== []): ?>
      <h3 class="font-medium mt-4 text-sm">รายการค่าใช้จ่ายเพิ่ม</h3>
      <ul class="text-sm mt-1 space-y-1">
        <?php foreach ($view['extras'] as $l): ?><li><?= e($l['label'] ?? '') ?> — ฿<?= e(money($l['amount'] ?? 0)) ?><?= !empty($l['waived']) ? ' (ยกเว้น)' : '' ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <?php if ($photos !== []): ?>
  <div class="panel mt-4">
    <h2 class="font-semibold">รูปสภาพรถ (<?= count($photos) ?> รูป)</h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 mt-2">
      <?php foreach ($photos as $p): ?>
        <a href="<?= e(url('/sign/photo/' . $p['id'] . '?' . http_build_query($self))) ?>" target="_blank" rel="noopener noreferrer">
          <img class="rounded border border-line w-full h-28 object-cover" loading="lazy" alt="<?= e($p['position']) ?>" src="<?= e(url('/sign/photo/' . $p['id'] . '?' . http_build_query($self))) ?>">
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <details class="panel mt-4" open>
    <summary class="font-semibold cursor-pointer">ข้อสัญญา</summary>
    <div class="text-sm mt-2"><?= $view['terms_html'] /* สร้างจากเทมเพลต partials/contract_terms ซึ่ง escape ทุกค่าแล้ว */ ?></div>
  </details>

  <form method="post" action="<?= e(url('/sign')) ?>" id="sign-form" class="panel mt-4 space-y-4">
    <?= csrf_field() ?>
    <?php foreach ($self as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <input type="hidden" name="seen" value="<?= e($view['hash']) ?>">
    <input type="hidden" name="signature" id="signature-data">

    <div>
      <h2 class="font-semibold">ความยินยอมข้อมูลส่วนบุคคล (PDPA)</h2>
      <p class="text-sm text-ink-2 mt-1 whitespace-pre-line"><?= e($view['pdpa']) ?></p>
    </div>
    <label class="flex items-start gap-2 text-sm"><input class="mt-1" type="checkbox" name="accept_terms" value="1" <?= !empty($form['accept_terms']) ? 'checked' : '' ?> required> ข้าพเจ้าได้อ่านและยอมรับข้อมูลและข้อสัญญาข้างต้นทุกข้อ</label>
    <label class="flex items-start gap-2 text-sm"><input class="mt-1" type="checkbox" name="consent" value="1" <?= !empty($form['consent']) ? 'checked' : '' ?> required> ข้าพเจ้ายินยอมให้เก็บและใช้ข้อมูลส่วนบุคคลตามข้อความข้างต้น</label>

    <div><label class="label" for="name">ชื่อ–นามสกุลผู้ลงนาม</label>
      <input id="name" name="name" maxlength="150" autocomplete="name" value="<?= e($form['name'] ?? $booking['contact_name']) ?>" required></div>

    <div>
      <label class="label">เซ็นชื่อในกรอบ (ใช้นิ้วหรือเมาส์)</label>
      <canvas id="pad" width="900" height="300" class="w-full rounded-lg border border-line bg-white touch-none" style="aspect-ratio: 3 / 1"></canvas>
      <div class="flex gap-2 mt-2"><button type="button" id="pad-clear" class="btn btn-ghost">ล้างลายเซ็น</button></div>
      <p id="pad-error" class="text-sm text-danger mt-1 hidden">กรุณาเซ็นชื่อในกรอบก่อนกดยืนยัน</p>
    </div>
    <button class="btn btn-lg btn-block" id="sign-submit">ยืนยันและลงนาม</button>
  </form>
  <script>
  (function () {
    var c = document.getElementById('pad'), ctx = c.getContext('2d'), drawing = false, inked = false;
    ctx.lineWidth = 5; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
    function pos(e) { var r = c.getBoundingClientRect(); return [(e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height]; }
    c.addEventListener('pointerdown', function (e) { drawing = true; c.setPointerCapture(e.pointerId); var p = pos(e); ctx.beginPath(); ctx.moveTo(p[0], p[1]); ctx.lineTo(p[0] + 0.1, p[1] + 0.1); ctx.stroke(); inked = true; e.preventDefault(); });
    c.addEventListener('pointermove', function (e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p[0], p[1]); ctx.stroke(); e.preventDefault(); });
    ['pointerup', 'pointercancel'].forEach(function (n) { c.addEventListener(n, function () { drawing = false; }); });
    document.getElementById('pad-clear').addEventListener('click', function () { ctx.clearRect(0, 0, c.width, c.height); inked = false; });
    document.getElementById('sign-form').addEventListener('submit', function (e) {
      if (!inked) { e.preventDefault(); document.getElementById('pad-error').classList.remove('hidden'); return; }
      document.getElementById('signature-data').value = c.toDataURL('image/png');
      document.getElementById('sign-submit').disabled = true;
    });
  })();
  </script>
<?php endif; ?>
