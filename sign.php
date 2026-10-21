<?php
/**
 * หน้าเซ็นเอกสารบนมือถือ — สำหรับลูกค้า ไม่ต้องล็อกอิน
 *
 *   sign.php?no=<เลขที่จอง>&k=contract&t=<รหัสลิงก์>   เซ็นสัญญาเช่ารถ (วันรับรถ)
 *   sign.php?no=<เลขที่จอง>&k=return&t=<รหัสลิงก์>     เซ็นใบรับคืนรถ (วันคืนรถ)
 *
 * ลิงก์ได้จากปุ่มในหน้าการจองของหลังบ้าน (เปิดบนเครื่องพนักงาน / คัดลอก / ส่งทาง LINE)
 * รหัสลิงก์เป็นรหัสสุ่ม 128 บิตของแต่ละการจอง คนที่ไม่มีลิงก์เปิดหน้านี้ไม่ได้
 *
 * หน้านี้มี CSS ในตัว ไม่ใช้ app.css — หน้าตาจึงเหมือนกันทุกเวอร์ชันของเว็บหลัก
 */
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/signature.php';
require_once __DIR__ . '/includes/notify.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$no    = trim((string) ($_REQUEST['no'] ?? ''));
$token = trim((string) ($_REQUEST['t'] ?? ''));
$kind  = (string) ($_REQUEST['k'] ?? 'contract');
$kinds = signature_kinds();

$b = ($no !== '' && preg_match('/^[0-9a-f]{32}$/', $token))
    ? db_one('SELECT b.*, c.brand, c.model, c.plate, c.province, c.color, c.year, c.insurance_type
              FROM bookings b JOIN cars c ON c.id = b.car_id WHERE b.booking_no = :n', [':n' => $no])
    : null;
$valid = $b && !empty($b['sign_token']) && hash_equals((string) $b['sign_token'], $token) && isset($kinds[$kind]);

$site   = (string) setting('site_name', 'thonglorcarrent');
$hex    = static fn(string $c, string $d): string => preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? $c : $d;
$brand  = $hex((string) setting('brand_primary'), '#1f2d3d');
$accent = $hex((string) setting('brand_accent'), '#e09a3c');

$error = '';
$sig = null; $fields = []; $termsHtml = ''; $termsText = ''; $blocker = null;
$insp = null; $photos = []; $extras = []; $docName = '';

if ($valid) {
    $docName = $kinds[$kind][0];
    $car     = ['brand' => $b['brand'], 'model' => $b['model'], 'plate' => $b['plate']];
    $insp    = inspection_get((int) $b['id'], $kind === 'return' ? 'in' : 'out');
    $photos  = $insp ? inspection_photo_map($insp) : [];
    $extras  = $kind === 'return' ? booking_extra_lines($b) : [];
    $fields  = signature_fields($b, $car, $kind);

    // ข้อความที่ลูกค้าลงนามรับรอง — เก็บทั้งก้อนไว้กับลายเซ็น
    if ($kind === 'contract') {
        $blank = '(ตามที่บันทึกในวันรับรถ)';
        $b['mileage_out'] = $b['mileage_out'] ?: ($insp['mileage'] ?? null);   // ถ่ายรูปหน้าปัดไว้แล้วแต่ยังไม่กดส่งมอบ
        ob_start();
        require __DIR__ . '/includes/_contract_terms.php';
        $termsHtml = (string) ob_get_clean();
    } else {
        $termsHtml = '<p>ผู้เช่าได้ตรวจสอบสภาพรถ ณ วันคืนรถร่วมกับผู้รับคืนรถแล้ว และรับทราบเลขไมล์ ระดับน้ำมัน '
                   . 'ความเสียหายที่บันทึก และรายการค่าใช้จ่ายเพิ่มตามที่แสดงในหน้านี้ '
                   . 'เงินประกันความเสียหายจะได้รับคืนหลังหักค่าใช้จ่ายค้างชำระ (ถ้ามี) ตามเงื่อนไขในสัญญาเช่า</p>';
    }
    $termsText = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</li>', '</p>'], "\n", $termsHtml)))));

    $sig     = signature_get((int) $b['id'], $kind);
    $blocker = $sig ? null : signature_blocker($b, $kind);

    $viewHash = signature_view_hash($b, $car, $kind, $termsText);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$sig) {
        try {
            // ข้อมูลเปลี่ยนระหว่างที่ลูกค้าเปิดหน้านี้ค้างไว้ → ยังไม่รับลายเซ็น ให้ตรวจข้อมูลล่าสุดก่อน
            if (!hash_equals($viewHash, (string) ($_POST['seen'] ?? ''))) {
                throw new RuntimeException('ร้านมีการปรับข้อมูลหลังจากที่เปิดหน้านี้ กรุณาตรวจข้อมูลด้านล่างอีกครั้งแล้วเซ็นใหม่');
            }
            $sid = signature_save($b, $car, $kind, (string) ($_POST['sig'] ?? ''), (string) ($_POST['name'] ?? ''),
                                  !empty($_POST['accept']), !empty($_POST['pdpa']), $termsText);
            try {
                notify_queue('line', setting('line_admin_user_id'),
                    "✍️ ลูกค้าเซ็น{$docName}แล้ว\nเลขที่จอง: {$b['booking_no']}\n"
                    . "รถ: {$b['brand']} {$b['model']} ({$b['plate']})\nผู้ลงนาม: " . trim((string) $_POST['name']),
                    null, 'bookings', (int) $b['id']);
                notify_flush();
            } catch (Throwable $e) {
                // แจ้งร้านไม่สำเร็จไม่ควรทำให้ลูกค้าเห็นว่าเซ็นไม่ผ่าน
            }
            header('Location: ' . url('sign.php') . '?no=' . rawurlencode($no) . '&k=' . $kind . '&t=' . $token . '&done=1');
            exit;
        } catch (Throwable $ex) {
            $error = public_error($ex, 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
            $sig   = signature_get((int) $b['id'], $kind);
        }
    }
} else {
    http_response_code(404);
}

$snap = $sig ? (json_decode((string) $sig['snapshot'], true) ?: []) : [];
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?= $valid ? e($docName . ' ' . $b['booking_no']) : 'ลิงก์ไม่ถูกต้อง' ?> — <?= e($site) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" sizes="32x32" href="<?= url('assets/img/favicon-32.png') ?>">
<style>
/* ---------- ตัวแปรสี: เปลี่ยนตามแบรนด์ที่ตั้งในหลังบ้าน ---------- */
:root{
  --brand:<?= e($brand) ?>; --accent:<?= e($accent) ?>;
  --bg:#f3f5f8; --card:#fff; --ink:#18212e; --ink-2:#4a5568; --muted:#6b7688; --line:#dfe5ec;
  --ok:#15803d; --ok-bg:#e8f6ee; --warn:#8a5200; --warn-bg:#fdf3dc; --danger:#b4232a; --danger-bg:#fdecec;
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;font-family:'Sarabun',system-ui,-apple-system,sans-serif;font-size:16px;line-height:1.65;
  color:var(--ink);background:var(--bg);padding-bottom:calc(28px + env(safe-area-inset-bottom,0px))}
button,input{font:inherit;color:inherit}
[hidden]{display:none!important}
a{color:var(--brand)}

/* ---------- หัวหน้า ---------- */
.top{background:var(--brand);color:#fff;padding:calc(14px + env(safe-area-inset-top,0px)) 16px 16px}
.top .shop{font-size:.8rem;opacity:.78;letter-spacing:.02em}
.top h1{margin:2px 0 0;font-size:1.3rem;font-weight:700;line-height:1.3}
.top .no{font-size:.9rem;opacity:.9;margin-top:2px}

.wrap{max-width:620px;margin:0 auto;padding:14px 14px 0}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:12px}
.card h2{margin:0 0 10px;font-size:1.02rem;line-height:1.35}
.step{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;
  background:var(--brand);color:#fff;font-size:.82rem;font-weight:700;margin-right:8px;vertical-align:1px}

/* ---------- ตารางสรุป ---------- */
.rows{margin:0}
.row{display:flex;justify-content:space-between;gap:14px;padding:7px 0;border-top:1px solid var(--line);font-size:.95rem}
.row:first-child{border-top:0;padding-top:0}
.row dt{color:var(--muted);flex:none;max-width:45%}
.row dd{margin:0;text-align:right;font-weight:600;min-width:0;overflow-wrap:anywhere}
.row.sum dd{font-size:1.05rem}
.row .wv{font-weight:400;color:var(--muted)}

/* ---------- รูปสภาพรถ ---------- */
.shots{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}
.shots a{display:block;position:relative;border-radius:8px;overflow:hidden;border:1px solid var(--line);aspect-ratio:4/3;background:#eef1f5}
.shots img{width:100%;height:100%;object-fit:cover;display:block}
.shots span{position:absolute;left:0;right:0;bottom:0;background:rgba(15,23,35,.72);color:#fff;font-size:.66rem;
  padding:2px 5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.meta{font-size:.86rem;color:var(--ink-2);margin:8px 0 0}

/* ---------- ข้อสัญญา ---------- */
.terms{max-height:300px;overflow-y:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--line);border-radius:10px;
  padding:4px 14px 4px 6px;font-size:.92rem;background:#fbfcfd}
.terms ol{padding-left:26px;margin:10px 0}
.terms li{margin-bottom:9px}
.terms p{margin:10px 0 10px 8px}
.terms:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.hint{font-size:.82rem;color:var(--muted);margin:6px 0 0}

/* ---------- ฟอร์มลงนาม ---------- */
label.f{display:block;font-size:.88rem;font-weight:600;margin:0 0 4px}
input[type=text]{width:100%;min-height:48px;padding:10px 12px;border:1px solid #c5ced9;border-radius:10px;background:#fff;font-size:1rem}
input[type=text]:focus-visible{outline:2px solid var(--accent);outline-offset:1px;border-color:var(--accent)}
.ck{display:flex;gap:11px;align-items:flex-start;padding:12px;border:1px solid var(--line);border-radius:10px;margin-top:10px;cursor:pointer;font-size:.93rem}
.ck input{flex:none;width:24px;height:24px;margin:1px 0 0;accent-color:var(--brand)}
.ck:has(input:checked){border-color:var(--ok);background:var(--ok-bg)}
.pad-wrap{position:relative;margin-top:14px}
.pad{display:block;width:100%;aspect-ratio:5/2;border:2px dashed #b7c1ce;border-radius:12px;background:#fff;touch-action:none;cursor:crosshair}
.pad.has{border-style:solid;border-color:var(--brand)}
.pad-ph{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#a3adba;font-size:1rem;pointer-events:none;text-align:center;padding:0 12px}
.pad-base{position:absolute;left:8%;right:8%;bottom:22%;border-bottom:1px solid #d5dbe3;pointer-events:none}
.pad-bar{display:flex;justify-content:space-between;align-items:center;margin-top:6px;font-size:.84rem;color:var(--muted)}
.btn{display:flex;align-items:center;justify-content:center;width:100%;min-height:52px;border:0;border-radius:12px;
  background:var(--brand);color:#fff;font-weight:700;font-size:1.05rem;cursor:pointer;margin-top:14px}
.btn:disabled{opacity:.55;cursor:default}
.btn:focus-visible,.link:focus-visible{outline:3px solid var(--accent);outline-offset:2px}
.link{white-space:nowrap;flex:none;background:none;border:0;color:var(--brand);font-weight:600;min-height:44px;padding:0 6px;cursor:pointer;text-decoration:underline}

.note{border-radius:10px;padding:11px 13px;font-size:.93rem;margin-bottom:12px}
.note.err{background:var(--danger-bg);color:var(--danger);border:1px solid #f3c5c7}
.note.ok{background:var(--ok-bg);color:#0f5d2c;border:1px solid #bfe3cd}
.note.warn{background:var(--warn-bg);color:var(--warn);border:1px solid #f1dba6}
.done{text-align:center;padding:6px 0 2px}
.done .tick{width:60px;height:60px;border-radius:50%;background:var(--ok);color:#fff;font-size:2rem;line-height:60px;margin:0 auto 10px}
.done h2{font-size:1.2rem;margin:0 0 4px}
.sigimg{display:block;max-width:300px;width:100%;margin:12px auto 4px;border-bottom:1px solid #9aa5b3;background:#fff}
.ref{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.84rem;letter-spacing:.04em}
.foot{text-align:center;font-size:.82rem;color:var(--muted);padding:6px 10px}
@media (max-width:420px){.shots{grid-template-columns:repeat(3,1fr)}}
@media print{.top{background:#fff;color:#000;border-bottom:2px solid #000}.terms{max-height:none;overflow:visible}body{background:#fff}}
</style>
</head>
<body>

<header class="top">
  <div class="shop"><?= e($site) ?></div>
  <h1><?= $valid ? e($docName) : 'ลิงก์ไม่ถูกต้อง' ?></h1>
  <?php if ($valid): ?><div class="no">เลขที่ <?= e($b['booking_no']) ?></div><?php endif; ?>
</header>

<main class="wrap">
<?php if (!$valid): ?>
  <div class="card">
    <h2>เปิดหน้านี้ไม่ได้</h2>
    <p style="margin:0">ลิงก์ไม่ถูกต้องหรือไม่ครบ กรุณาขอลิงก์ใหม่จากร้าน<?= setting('phone') ? ' โทร <a href="tel:' . e(setting('phone')) . '">' . e(setting('phone')) . '</a>' : '' ?></p>
  </div>

<?php elseif ($sig): /* ============ ลงนามแล้ว ============ */
    $sf = signature_fields($b, $car, $kind); ?>
  <div class="card done">
    <div class="tick" aria-hidden="true">✓</div>
    <h2>ลงนามเรียบร้อยแล้ว</h2>
    <p style="margin:0;color:var(--ink-2)"><?= e($docName) ?> เลขที่ <?= e($b['booking_no']) ?></p>
    <?php if ($img = private_data_uri($sig['file_path'])): ?>
      <img class="sigimg" src="<?= e($img) ?>" alt="ลายเซ็นของ <?= e($sig['signer_name']) ?>">
    <?php endif; ?>
    <div style="font-weight:600"><?= e($sig['signer_name']) ?></div>
    <div class="hint">ลงนามเมื่อ <?= e(thai_date($sig['signed_at'], true)) ?></div>
    <div class="hint">รหัสอ้างอิง <span class="ref"><?= e(signature_ref($sig)) ?></span></div>
  </div>
  <div class="card">
    <h2>ข้อมูลที่ท่านลงนามรับรอง</h2>
    <dl class="rows">
      <?php foreach (($snap['fields'] ?? []) as $k => $v): ?>
        <div class="row"><dt><?= e($sf[$k][0] ?? $k) ?></dt><dd><?= e($v) ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <p class="hint">เก็บลิงก์หน้านี้ไว้เปิดดูได้ภายหลัง หากข้อมูลไม่ถูกต้องกรุณาแจ้งร้าน<?= setting('phone') ? ' โทร ' . e(setting('phone')) : '' ?></p>
  </div>

<?php elseif ($blocker): ?>
  <div class="card">
    <h2>ยังเซ็นเอกสารนี้ไม่ได้</h2>
    <p style="margin:0"><?= e($blocker) ?></p>
  </div>

<?php else: /* ============ ฟอร์มลงนาม ============ */ ?>
  <?php if ($error): ?><div class="note err" role="alert"><?= e($error) ?></div><?php endif; ?>

  <section class="card">
    <h2><span class="step">1</span>ตรวจข้อมูล<?= $kind === 'return' ? 'การคืนรถ' : 'การเช่า' ?></h2>
    <dl class="rows">
      <?php foreach ($fields as $k => [$label, $val]):
          if ($kind === 'return' && in_array($k, ['total', 'deposit', 'extra'], true)) continue; ?>
        <div class="row"><dt><?= e($label) ?></dt><dd><?= e($val) ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </section>

  <?php if ($kind === 'return'):
      $due  = (float) $b['grand_total'] + (float) $b['extra_charge'];
      $bal  = max(0, $due - (float) $b['paid_amount']);
      $held = security_held((int) $b['id']); ?>
    <section class="card">
      <h2>ค่าใช้จ่ายเพิ่มตอนคืนรถ</h2>
      <dl class="rows">
        <?php if (!$extras): ?>
          <div class="row"><dt>ค่าใช้จ่ายเพิ่ม</dt><dd>ไม่มี</dd></div>
        <?php endif; ?>
        <?php foreach ($extras as $l): ?>
          <div class="row"><dt><?= e($l['label']) ?></dt>
            <dd><?= $l['waived'] ? '<span class="wv">ยกเว้นให้</span>' : money0($l['amount']) . ' บาท' ?></dd></div>
        <?php endforeach; ?>
        <?php if ($extras): ?>
          <div class="row sum"><dt>รวมค่าใช้จ่ายเพิ่ม</dt><dd><?= money0($b['extra_charge']) ?> บาท</dd></div>
        <?php endif; ?>
        <div class="row"><dt>ยอดค้างชำระทั้งหมด</dt><dd><?= money0($bal) ?> บาท</dd></div>
        <?php if ($held > 0): ?>
          <div class="row"><dt>เงินประกันที่ร้านถือไว้</dt><dd><?= money0($held) ?> บาท</dd></div>
          <div class="row sum"><dt>เงินประกันที่จะได้คืน</dt><dd><?= money0(max(0, $held - $bal)) ?> บาท</dd></div>
        <?php endif; ?>
      </dl>
    </section>
  <?php endif; ?>

  <?php if ($photos): ?>
    <section class="card">
      <h2>สภาพรถ<?= $kind === 'return' ? 'ตอนคืนรถ' : 'ตอนรับรถ' ?> (<?= count($photos) ?> รูป)</h2>
      <div class="shots">
        <?php foreach (inspection_positions() as $pk => [$plabel, ]):
            if (empty($photos[$pk])) continue; ?>
          <a href="<?= e(upload_url($photos[$pk]['file_path'])) ?>" target="_blank" rel="noopener">
            <img src="<?= e(upload_url($photos[$pk]['file_path'])) ?>" alt="<?= e($plabel) ?>" loading="lazy">
            <span><?= e($plabel) ?></span></a>
        <?php endforeach; ?>
      </div>
      <p class="meta">
        เลขไมล์ <?= ($insp['mileage'] ?: ($kind === 'return' ? $b['mileage_in'] : $b['mileage_out'])) ? number_format((float) ($insp['mileage'] ?: ($kind === 'return' ? $b['mileage_in'] : $b['mileage_out']))) . ' กม.' : '-' ?>
        <?php $fl = $insp['fuel_level'] ?? ($kind === 'return' ? $b['fuel_in'] : $b['fuel_out']); ?>
        · น้ำมัน <?= e(fuel_label($fl !== null && $fl !== '' ? (int) $fl : null)) ?>
        <?php if (!empty($insp['note'])): ?><br>ตำหนิที่บันทึก: <?= e($insp['note']) ?><?php endif; ?>
      </p>
      <p class="hint">แตะรูปเพื่อดูขนาดเต็ม</p>
    </section>
  <?php endif; ?>

  <section class="card">
    <h2><span class="step">2</span><?= $kind === 'return' ? 'ข้อความรับรอง' : 'อ่านเงื่อนไขสัญญา' ?></h2>
    <div class="terms" tabindex="0" role="region" aria-label="เงื่อนไข"><?= $termsHtml ?></div>
    <?php if ($kind === 'contract'): ?><p class="hint">เลื่อนอ่านในกรอบได้ครบทุกข้อ</p><?php endif; ?>
  </section>

  <section class="card">
    <h2><span class="step">3</span>ลงนาม</h2>
    <form method="post" id="signForm" action="<?= e(url('sign.php') . '?no=' . rawurlencode($no) . '&k=' . $kind . '&t=' . $token) ?>" novalidate>
      <input type="hidden" name="sig" id="sig">
      <input type="hidden" name="seen" value="<?= e($viewHash) ?>">
      <label class="f" for="name">ชื่อ–นามสกุลผู้ลงนาม</label>
      <input type="text" id="name" name="name" maxlength="150" autocomplete="name" required
             value="<?= e($_POST['name'] ?? $b['contact_name']) ?>">

      <label class="ck"><input type="checkbox" name="accept" value="1" <?= !empty($_POST['accept']) ? 'checked' : '' ?>>
        <span><?= $kind === 'return'
            ? 'ข้าพเจ้าได้ตรวจสภาพรถร่วมกับผู้รับคืนรถแล้ว และรับทราบรายการค่าใช้จ่ายข้างต้น'
            : 'ข้าพเจ้าได้อ่านและยอมรับเงื่อนไขสัญญาเช่ารถข้างต้นทุกข้อ' . ($photos ? ' และยืนยันว่าภาพถ่ายข้างต้นเป็นสภาพรถ ณ วันรับรถจริง' : '') ?></span></label>
      <label class="ck"><input type="checkbox" name="pdpa" value="1" <?= !empty($_POST['pdpa']) ? 'checked' : '' ?>>
        <span><?= e(setting('pdpa_policy_text', 'ข้าพเจ้ายินยอมให้ผู้ให้เช่าเก็บรวบรวมและใช้ข้อมูลส่วนบุคคลของข้าพเจ้าเพื่อการทำสัญญาเช่ารถและการปฏิบัติตามกฎหมายที่เกี่ยวข้อง')) ?></span></label>

      <div class="pad-wrap">
        <canvas class="pad" id="pad" width="900" height="360" role="img" aria-label="กรอบเซ็นชื่อ ใช้นิ้วหรือปากกาเขียนในกรอบนี้"></canvas>
        <div class="pad-base" aria-hidden="true"></div>
        <div class="pad-ph" id="padPh">ใช้นิ้วเซ็นชื่อในกรอบนี้</div>
      </div>
      <div class="pad-bar"><span>เซ็นให้เหมือนในบัตรประชาชน</span>
        <button type="button" class="link" id="padClear">ล้าง เซ็นใหม่</button></div>

      <div class="note err" id="formErr" role="alert" hidden style="margin:12px 0 0"></div>
      <button class="btn" id="submitBtn">ยืนยันการลงนาม</button>
      <p class="hint" style="text-align:center">ระบบบันทึกวัน–เวลาและอุปกรณ์ที่ใช้ลงนามไว้เป็นหลักฐาน</p>
    </form>
  </section>
<?php endif; ?>

  <p class="foot"><?= e(setting('company_name') ?: $site) ?><?= setting('phone') ? ' · โทร ' . e(setting('phone')) : '' ?></p>
</main>

<?php if ($valid && !$sig && !$blocker): ?>
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
    ph.hidden = true; pad.classList.add('has'); err.hidden = true;
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
    ctx.clearRect(0, 0, pad.width, pad.height); inkLen = 0; ph.hidden = false; pad.classList.remove('has');
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
<?php endif; ?>
</body>
</html>
