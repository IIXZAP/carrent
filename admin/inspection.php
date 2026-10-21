<?php
/**
 * หน้าถ่ายรูปตรวจสภาพรถ — ออกแบบให้ใช้บนมือถือหน้างานเป็นหลัก
 *
 * กดปุ่ม → เปิดกล้องหลังทันที → ย่อรูปในเครื่องก่อนส่ง → อัปทีละใบ
 * การย่อรูปฝั่งเครื่องสำคัญมาก เพราะรูปจากมือถือใบละ 3–5 MB
 * ถ้าส่งดิบๆ บน shared hosting จะช้าและเต็มพื้นที่เร็ว (ย่อแล้วเหลือ ~250–400 KB)
 */
require_once __DIR__ . '/../includes/inspection.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

// ไฟล์ใหญ่เกิน post_max_size → PHP ทิ้ง $_POST ทั้งก้อน รวมทั้ง CSRF token
// ต้องดักก่อน ไม่งั้นหน้าจะเด้งออกแบบไม่มีข้อความบอกว่าเกิดอะไรขึ้น
if (post_too_large()) {
    // หน้านี้ส่งรูปด้วย fetch จึงต้องตอบเป็น JSON ให้สคริปต์อ่านออก
    // ถ้า redirect ไป สคริปต์จะได้ HTML แล้วขึ้น error กว้างๆ ที่ไม่บอกสาเหตุ
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(413);
    echo json_encode([
        'ok'    => false,
        'error' => 'รูปใหญ่เกินไป (เซิร์ฟเวอร์รับได้ไม่เกิน '
                   . round(upload_limit_bytes() / 1048576, 1) . ' MB) กรุณาลองถ่ายใหม่',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$bookingId = (int) ($_GET['booking'] ?? 0);
$kind      = ($_GET['kind'] ?? 'out') === 'in' ? 'in' : 'out';

$b = db_one('SELECT b.*, c.brand, c.model, c.plate, c.color
             FROM bookings b JOIN cars c ON c.id = b.car_id WHERE b.id = :id', [':id' => $bookingId]);
if (!$b) {
    admin_head('ไม่พบการจอง', 'bookings.php');
    echo '<div class="panel center"><h3>ไม่พบการจองนี้</h3>'
       . '<a class="btn" href="' . url('admin/bookings.php') . '">กลับรายการ</a></div>';
    admin_foot();
    exit;
}

// ------------------------------------------------------------
// รับรูป 1 ใบ (เรียกด้วย fetch จากหน้าเว็บ) — ตอบกลับเป็น JSON
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'photo') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        csrf_check_ajax();
        if ($why = inspection_phase_block($b, $kind)) {
            throw new RuntimeException($why);
        }
        $pos = (string) ($_POST['position'] ?? '');
        if (!isset(inspection_positions()[$pos])) {
            throw new RuntimeException('ไม่รู้จักมุมถ่ายนี้');
        }
        $insId = inspection_ensure($bookingId, $kind);
        $path  = inspection_save_photo($insId, $pos, $_FILES['photo'] ?? [], $_POST['caption'] ?? null);
        if (!$path) {
            throw new RuntimeException('ไม่ได้รับไฟล์รูป');
        }
        log_activity('inspection_photo', 'bookings', $bookingId, $kind . ':' . $pos);
        echo json_encode(['ok' => true, 'url' => upload_url($path), 'position' => $pos], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ------------------------------------------------------------
// บันทึกเลขไมล์ / น้ำมัน / หมายเหตุ
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['act'] ?? '', ['save', 'lock'], true)) {
    csrf_check();
    if ($why = inspection_phase_block($b, $kind)) {
        flash('warn', $why);
        redirect('admin/inspection.php?booking=' . $bookingId . '&kind=' . $kind);
    }
    if (inspection_is_locked(inspection_get($bookingId, $kind))) {
        flash('warn', 'ผลตรวจรอบนี้ถูกล็อกแล้ว แก้ไขไม่ได้');
        redirect('admin/inspection.php?booking=' . $bookingId . '&kind=' . $kind);
    }
    $insId = inspection_ensure($bookingId, $kind);
    db_update('inspections', [
        'mileage'      => max(0, min(9999999, (int) ($_POST['mileage'] ?? 0))) ?: null,
        'fuel_level'   => max(0, min(8, (int) ($_POST['fuel_level'] ?? 8))),
        'note'         => mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 1000) ?: null,
        'inspected_by' => auth_user()['id'] ?? null,
        'inspected_at' => date('Y-m-d H:i:s'),
    ], 'id', $insId);
    log_activity('inspection_saved', 'bookings', $bookingId, $kind);
    if ($_POST['act'] === 'lock') {
        // ต้องติ๊กรับทราบก่อน — ล็อกแล้วย้อนไม่ได้ จึงไม่ให้กดพลาดด้วยปุ่มเดียว
        if (empty($_POST['lock_ack'])) {
            flash('warn', 'บันทึกแล้ว แต่ยังไม่ได้ล็อก — กรุณาติ๊กรับทราบก่อนกดล็อก');
            redirect('admin/inspection.php?booking=' . $bookingId . '&kind=' . $kind);
        }
        inspection_lock($bookingId, $kind);
        flash('ok', 'บันทึกและล็อกผลตรวจสภาพรถแล้ว');
    } else {
        flash('ok', 'บันทึกผลตรวจสภาพรถแล้ว');
    }
    redirect('admin/booking-view.php?id=' . $bookingId);
}

$ins    = inspection_get($bookingId, $kind);
$phaseBlock = inspection_phase_block($b, $kind);
$locked = inspection_is_locked($ins) || $phaseBlock !== null;
$map    = inspection_photo_map($ins);
$prog  = inspection_progress($ins);
$other = inspection_get($bookingId, $kind === 'out' ? 'in' : 'out');

// ค่าตั้งต้นของเลขไมล์: ใช้ค่าที่เคยบันทึกไว้ → ค่าจากใบจอง → เลขไมล์ล่าสุดของรถ
$defaultKm = $ins['mileage']
    ?? ($kind === 'out' ? $b['mileage_out'] : $b['mileage_in'])
    ?? db_val('SELECT mileage FROM cars WHERE id = :c', [':c' => (int) $b['car_id']]);
$defaultFuel = $ins['fuel_level'] ?? ($kind === 'out' ? ($b['fuel_out'] ?? 8) : ($b['fuel_in'] ?? 8));

admin_head('ตรวจสภาพรถ ' . inspection_kind_label($kind), 'bookings.php');
?>
<div class="page-head">
  <div>
    <a class="back-link" href="<?= url('admin/booking-view.php?id=' . $bookingId) ?>"><?= icon('back') ?> กลับหน้าการจอง</a>
    <h2>ตรวจสภาพรถ — <?= e(inspection_kind_label($kind)) ?></h2>
    <p class="muted">
      <?= e($b['booking_no']) ?> · <?= e($b['brand'] . ' ' . $b['model']) ?>
      · ทะเบียน <?= e($b['plate']) ?> · <?= e($b['contact_name']) ?>
    </p>
  </div>
</div>

<?php if ($locked): ?>
  <div class="alert alert-info">
    <b>🔒 ผลตรวจรอบนี้ถูกล็อกแล้ว</b> เมื่อ <?= e(thai_date($ins['locked_at'], true)) ?>
    <?= $ins['locker'] ? 'โดย ' . e($ins['locker']) : '(ล็อกอัตโนมัติ)' ?>
    — เพิ่มรูป เปลี่ยนรูป หรือแก้ตัวเลขไม่ได้อีก ใช้เป็นหลักฐานแนบท้ายสัญญาได้
  </div>
<?php endif; ?>

<div class="insp-bar" id="progBar">
  <div class="insp-bar-fill" style="width:<?= (int) ($prog['done'] / max(1, $prog['required']) * 100) ?>%"></div>
  <span class="insp-bar-txt">ถ่ายแล้ว <b id="progDone"><?= (int) $prog['done'] ?></b>/<?= (int) $prog['required'] ?> มุม</span>
</div>

<div class="panel">
  <div class="insp-grid">
    <?php foreach (inspection_positions() as $key => [$label, $hint]):
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
            <span class="tiny muted insp-by">ถ่าย <?= e(thai_date($have['created_at'], true)) ?><?= !empty($have['uploader']) ? ' · ' . e($have['uploader']) : '' ?></span>
          <?php else: ?>
            <span class="tiny muted"><?= e($hint) ?></span>
          <?php endif; ?>
          <span class="tiny insp-err"></span>
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

<form method="post" class="panel">
  <?= csrf_field() ?><input type="hidden" name="act" value="save">
  <h3>เลขไมล์ · น้ำมัน · หมายเหตุ</h3>
  <div class="g2">
    <div class="field">
      <label for="mileage">เลขไมล์ (กม.)</label>
      <input type="number" id="mileage" name="mileage" inputmode="numeric" min="0"
             value="<?= (int) $defaultKm ?>"<?= $locked ? ' disabled' : '' ?>>
      <?php if ($kind === 'in' && !empty($b['mileage_out'])): ?>
        <span class="tiny muted">ตอนส่งมอบ <?= number_format((float) $b['mileage_out']) ?> กม.</span>
      <?php endif; ?>
    </div>
    <div class="field">
      <label for="fuel_level">ระดับน้ำมัน</label>
      <select id="fuel_level" name="fuel_level"<?= $locked ? ' disabled' : '' ?>>
        <?php foreach ([8 => 'เต็มถัง', 7 => '7/8', 6 => '3/4 ถัง', 5 => '5/8', 4 => 'ครึ่งถัง',
                        3 => '3/8', 2 => '1/4 ถัง', 1 => '1/8', 0 => 'ถังว่าง'] as $v => $t): ?>
          <option value="<?= $v ?>"<?= (int) $defaultFuel === $v ? ' selected' : '' ?>><?= e($t) ?></option>
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
    <div class="insp-lock">
      <label class="checkline">
        <input type="checkbox" name="lock_ack" value="1">
        <span>ตรวจครบแล้ว ต้องการล็อกผลตรวจรอบนี้
          <span class="tiny muted" style="display:block">ล็อกแล้วจะเพิ่มรูป เปลี่ยนรูป หรือแก้ตัวเลขไม่ได้อีก — ระบบล็อกให้เองเมื่อบันทึก<?= $kind === 'out' ? 'ส่งมอบรถ' : 'รับคืนรถ' ?></span></span>
      </label>
      <button class="btn btn-ghost btn-block" name="act" value="lock">🔒 บันทึกและล็อกผลตรวจ</button>
    </div>
  <?php endif; ?>
  <?php if ($ins && $ins['inspected_at']): ?>
    <p class="tiny muted center mt-1">
      บันทึกล่าสุด <?= e(thai_date($ins['inspected_at'], true)) ?>
      <?= $ins['inspector'] ? ' โดย ' . e($ins['inspector']) : '' ?>
    </p>
  <?php endif; ?>
</form>

<?php if ($ins && $ins['history']): ?>
  <div class="panel">
    <h3>รูปที่ถูกถ่ายแทน (<?= count($ins['history']) ?>)</h3>
    <p class="tiny muted">รูปเดิมของมุมที่ถ่ายซ้ำ ระบบเก็บไว้ทั้งหมด ไม่มีการลบ</p>
    <div class="insp-mini">
      <?php foreach ($ins['history'] as $p): ?>
        <a href="<?= e(upload_url($p['file_path'])) ?>" target="_blank">
          <img src="<?= e(upload_url($p['file_path'])) ?>" alt="" loading="lazy">
          <span><?= e(inspection_positions()[$p['position']][0] ?? $p['position']) ?>
            · <?= e(thai_date($p['created_at'], true)) ?><?= !empty($p['uploader']) ? ' · ' . e($p['uploader']) : '' ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($other && $other['photos']): ?>
  <div class="panel">
    <h3>เทียบกับ<?= e(inspection_kind_label($kind === 'out' ? 'in' : 'out')) ?></h3>
    <p class="tiny muted">บันทึกเมื่อ <?= e(thai_date($other['inspected_at'], true)) ?></p>
    <div class="insp-mini">
      <?php foreach ($other['photos'] as $p): ?>
        <a href="<?= e(upload_url($p['file_path'])) ?>" target="_blank">
          <img src="<?= e(upload_url($p['file_path'])) ?>" alt="" loading="lazy">
          <span><?= e(inspection_positions()[$p['position']][0] ?? $p['position']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<script>
/* ---------- ถ่ายรูป → ย่อในเครื่อง → อัปทีละใบ ---------- */
(function () {
  const TOKEN    = <?= json_encode(csrf_token()) ?>;
  const ENDPOINT = <?= json_encode(url('admin/inspection.php?booking=' . $bookingId . '&kind=' . $kind)) ?>;
  const MAX_SIDE = 1600;   // ด้านยาวสุดหลังย่อ
  const QUALITY  = 0.82;

  /* ย่อรูปด้วย canvas — ลดจาก 3–5 MB เหลือ ~250–400 KB */
  function shrink(file) {
    return new Promise(function (resolve, reject) {
      const img = new Image();
      const url = URL.createObjectURL(file);
      img.onload = function () {
        URL.revokeObjectURL(url);
        let w = img.naturalWidth, h = img.naturalHeight;
        const scale = Math.min(1, MAX_SIDE / Math.max(w, h));
        w = Math.round(w * scale); h = Math.round(h * scale);
        const cv = document.createElement('canvas');
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
    const total = <?= (int) $prog['required'] ?>;
    let n = 0;
    document.querySelectorAll('.insp-card.has').forEach(function (c) {
      if (c.dataset.pos !== 'damage') n++;
    });
    document.getElementById('progDone').textContent = n;
    document.querySelector('.insp-bar-fill').style.width = Math.min(100, n / total * 100) + '%';
  }

  document.querySelectorAll('.insp-card').forEach(function (card) {
    const input = card.querySelector('input[type=file]');
    const errEl = card.querySelector('.insp-err');
    if (!input) return;   /* ผลตรวจถูกล็อกแล้ว — ไม่มีปุ่มถ่ายรูป */

    input.addEventListener('change', async function () {
      const file = input.files && input.files[0];
      if (!file) return;
      errEl.textContent = '';
      card.classList.add('busy');

      try {
        const blob = await shrink(file);
        const fd = new FormData();
        fd.append('act', 'photo');
        fd.append('_csrf', TOKEN);
        fd.append('position', card.dataset.pos);
        fd.append('photo', blob, 'photo.jpg');

        const res  = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'อัปโหลดไม่สำเร็จ');

        const thumb = card.querySelector('.insp-thumb');
        let im = thumb.querySelector('img');
        if (!im) {
          thumb.innerHTML = '<img alt=""><span class="insp-badge">'
            + document.querySelector('.insp-badge').innerHTML + '</span><span class="insp-spin"></span>';
          im = thumb.querySelector('img');
        }
        im.src = data.url + '?t=' + Date.now();
        card.classList.add('has');
        card.querySelector('.insp-btn span').textContent = 'ถ่ายใหม่';
        var meta = card.querySelector('.insp-meta .muted');
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
<?php admin_foot(); ?>
