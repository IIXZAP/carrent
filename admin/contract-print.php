<?php
/**
 * พิมพ์สัญญาเช่ารถ (ดึงข้อมูลจากการจองอัตโนมัติ)
 * แก้ข้อความสัญญาให้ตรงกับที่ทนายของร้านตรวจแล้วก่อนใช้งานจริง
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/inspection.php';
require_once __DIR__ . '/../includes/signature.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$b  = db_one('SELECT b.*, c.brand, c.model, c.plate, c.province, c.color, c.year, c.insurance_type
              FROM bookings b JOIN cars c ON c.id = b.car_id WHERE b.id = :i', [':i' => $id]);
if (!$b) {
    die('ไม่พบการจอง');
}
$cus = $b['customer_id'] ? db_one('SELECT * FROM customers WHERE id = :i', [':i' => (int) $b['customer_id']]) : null;

// ลายเซ็นอิเล็กทรอนิกส์ของผู้เช่า (ถ้าเซ็นผ่านมือถือแล้ว) — ฝังลงในช่องลงนามของฉบับพิมพ์
$carRow = ['brand' => $b['brand'], 'model' => $b['model'], 'plate' => $b['plate']];
$sigC   = signature_get($id, 'contract');
$sigR   = signature_get($id, 'return');
/** ช่องลงนามผู้เช่า: มีลายเซ็นอิเล็กทรอนิกส์ → แสดงรูป + เวลา + รหัสอ้างอิง, ไม่มี → เส้นประให้เซ็นมือ */
$renterSign = function (?array $sig, string $role) use ($b): string {
    $name = '(' . e($sig['signer_name'] ?? $b['contact_name']) . ')<br>' . e($role);
    if (!$sig || !($img = private_data_uri($sig['file_path']))) {
        return '<div><div class="ln">' . $name . '</div></div>';
    }
    return '<div><img class="doc-sig" src="' . e($img) . '" alt="ลายเซ็นผู้เช่า"><div class="ln signed">' . $name
         . '<span class="doc-sig-meta">ลงนามอิเล็กทรอนิกส์ ' . e(thai_date($sig['signed_at'], true))
         . ' · รหัสอ้างอิง ' . e(signature_ref($sig)) . '</span></div></div>';
};
$sigNote = function (?array $sig) use ($b, $carRow): string {
    if (!$sig) {
        return '';
    }
    $chg = signature_changes($sig, $b, $carRow);
    return $chg ? '<p class="tiny doc-sig-warn"><b>หมายเหตุ:</b> ข้อมูลต่อไปนี้ถูกแก้ไขหลังผู้เช่าลงนาม — '
        . e(implode(' · ', array_map(fn($x) => $x['label'] . ' (ขณะลงนาม: ' . $x['signed'] . ')', $chg))) . '</p>' : '';
};
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>สัญญาเช่ารถ <?= e($b['booking_no']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" sizes="32x32" href="<?= url('assets/img/favicon-32.png') ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
<style>
:root{--brand:<?= e(setting('brand_primary', '#0f3d68')) ?>}
body{background:#eef1f5;padding:24px 0}
.doc{font-size:15px;line-height:1.75}
.doc h1{text-align:center;font-size:1.35rem;margin-bottom:.2em}
.doc .sub{text-align:center;color:var(--ink-2);font-size:.9rem;margin-bottom:22px}
.doc ol{padding-left:30px}   /* 30px: เลขข้อ 2 หลัก (10. 11. 12.) ไม่ถูกตัดตอนพิมพ์ */
.doc ol > li{margin-bottom:8px}
.blank{display:inline-block;min-width:130px;border-bottom:1px dotted #888;padding:0 5px}
@media print{body{padding:0;background:#fff}@page{margin:16mm}}
</style>
</head>
<body>

<div class="no-print center" style="max-width:820px;margin:0 auto 16px">
  <button class="btn" onclick="window.print()">🖨️ พิมพ์สัญญา</button>
  <a class="btn btn-ghost" href="<?= url('admin/booking-view.php?id=' . $id) ?>">กลับหน้างาน</a>
</div>

<div class="doc">
  <h1>สัญญาเช่ารถยนต์</h1>
  <div class="sub">เลขที่สัญญา <?= e($b['booking_no']) ?> · ทำที่ <?= e(setting('company_name') ?: setting('site_name')) ?></div>

  <p>สัญญาฉบับนี้ทำขึ้นเมื่อวันที่ <b><?= e(thai_date(date('Y-m-d'), false, true)) ?></b> ระหว่าง</p>

  <p><b>ผู้ให้เช่า:</b> <?= e(setting('company_name') ?: setting('site_name')) ?>
    <?= setting('company_address') ? ' ที่อยู่ ' . e(setting('company_address')) : '' ?>
    โทร <?= e(setting('phone')) ?> ซึ่งต่อไปในสัญญานี้เรียกว่า “ผู้ให้เช่า” ฝ่ายหนึ่ง กับ</p>

  <p><b>ผู้เช่า:</b> <?= e($b['contact_name']) ?>
    <?= $cus && $cus['address'] ? ' ที่อยู่ ' . e($cus['address']) : ' ที่อยู่ <span class="blank"></span>' ?>
    โทร <?= e($b['contact_phone']) ?>
    <?= $cus && $cus['license_no'] ? ' ใบขับขี่เลขที่ ' . e($cus['license_no']) : ' ใบขับขี่เลขที่ <span class="blank"></span>' ?>
    ซึ่งต่อไปในสัญญานี้เรียกว่า “ผู้เช่า” อีกฝ่ายหนึ่ง</p>

  <p>ทั้งสองฝ่ายตกลงทำสัญญากันโดยมีข้อความดังต่อไปนี้</p>

  <?php require __DIR__ . '/../includes/_contract_terms.php'; ?>

  <p>ทั้งสองฝ่ายได้อ่านและเข้าใจข้อความในสัญญาโดยตลอดแล้ว จึงลงลายมือชื่อไว้เป็นสำคัญต่อหน้าพยาน
    และต่างเก็บไว้ฝ่ายละหนึ่งฉบับ</p>

  <?= $sigNote($sigC) ?>
  <div class="doc-sign">
    <?= $renterSign($sigC, 'ผู้เช่า') ?>
    <div><div class="ln">(...........................................)<br>ผู้ให้เช่า</div></div>
  </div>
  <div class="doc-sign">
    <div><div class="ln">(...........................................)<br>พยาน</div></div>
    <div><div class="ln">(...........................................)<br>พยาน</div></div>
  </div>

  <?php
  // ---------- ใบแนบ: บันทึกสภาพรถตอนส่งมอบ ----------
  // แนบท้ายสัญญาเพื่อให้ลูกค้าเซ็นรับทราบสภาพรถ ณ วันรับรถ
  $inspOut = inspection_get((int) $b['id'], 'out');
  if ($inspOut && $inspOut['photos']):
      $posOut = inspection_photo_map($inspOut);
      $inspOut['mileage']    = $inspOut['mileage'] ?: ($b['mileage_out'] ?? null);
      $inspOut['fuel_level'] = $inspOut['fuel_level'] ?? ($b['fuel_out'] ?? null);
  ?>
    <div class="doc-attach">
      <h3>ใบแนบ ก — บันทึกสภาพรถ ณ วันส่งมอบ</h3>
      <p class="tiny">
        <?= e($b['brand'] . ' ' . $b['model']) ?> ทะเบียน <?= e($b['plate']) ?>
        · บันทึกเมื่อ <?= e(thai_date($inspOut['inspected_at'], true)) ?>
        · เลขไมล์ <?= $inspOut['mileage'] ? number_format((float) $inspOut['mileage']) . ' กม.' : '-' ?>
        · น้ำมัน <?= e(fuel_label($inspOut['fuel_level'])) ?>
        <?php if (inspection_is_locked($inspOut)): ?>
          <br>ผลตรวจถูกล็อกเมื่อ <?= e(thai_date($inspOut['locked_at'], true)) ?><?= $inspOut['locker'] ? ' โดย ' . e($inspOut['locker']) : '' ?> — หลังจากเวลานี้แก้ไขไม่ได้
        <?php endif; ?>
      </p>

      <div class="doc-insp">
        <?php foreach (inspection_positions() as $pk => [$plabel, ]):
            if (empty($posOut[$pk])) continue; ?>
          <figure>
            <img src="<?= e(upload_url($posOut[$pk]['file_path'])) ?>" alt="">
            <figcaption><?= e($plabel) ?> · <?= e(thai_date($posOut[$pk]['created_at'], true)) ?></figcaption>
          </figure>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($inspOut['note'])): ?>
        <p class="tiny mt-1"><b>ตำหนิที่พบ ณ วันส่งมอบ:</b> <?= e($inspOut['note']) ?></p>
      <?php endif; ?>

      <p class="tiny mt-1">ผู้เช่าได้ตรวจสอบสภาพรถตามภาพถ่ายข้างต้นแล้ว
        และยอมรับว่าเป็นสภาพรถ ณ วันรับมอบจริง</p>

      <?php // ลายเซ็นสัญญาครอบคลุมใบแนบ ก เฉพาะเมื่อตอนเซ็นมีรูปสภาพรถให้ลูกค้าดูแล้ว
            $snapC = $sigC ? (json_decode((string) $sigC['snapshot'], true) ?: []) : [];
            $coverA = $sigC && (int) ($snapC['inspection']['photos'] ?? 0) > 0; ?>
      <div class="doc-sign">
        <?= $renterSign($coverA ? $sigC : null, 'ผู้เช่า — ตรวจรับสภาพรถ') ?>
        <div><div class="ln">(...........................................)<br>ผู้ส่งมอบรถ</div></div>
      </div>
    </div>
  <?php endif; ?>

  <?php
  // ---------- ใบแนบ ข: สภาพรถตอนรับคืน ----------
  // พิมพ์เมื่อมีผลตรวจรอบรับคืนแล้ว — ใช้ให้ผู้เช่าเซ็นรับทราบสภาพรถและค่าใช้จ่ายเพิ่มก่อนคืนเงินประกัน
  $inspIn = inspection_get((int) $b['id'], 'in');
  if ($inspIn && $inspIn['photos']):
      $posIn = inspection_photo_map($inspIn);
      // เลขไมล์อาจถูกกรอกในฟอร์มส่งมอบ/รับคืน แทนที่จะกรอกในหน้าถ่ายรูป — ใช้ค่าจากใบจองเป็นตัวสำรอง
      $inKm   = $inspIn['mileage'] ?: ($b['mileage_in'] ?? null);
      $outKm  = ($inspOut['mileage'] ?? null) ?: ($b['mileage_out'] ?? null);
      $inspIn['mileage']    = $inKm;
      $inspIn['fuel_level'] = $inspIn['fuel_level'] ?? ($b['fuel_in'] ?? null);
      $kmUsed = ($outKm && $inKm) ? (float) $inKm - (float) $outKm : null;
  ?>
    <div class="doc-attach">
      <h3>ใบแนบ ข — บันทึกสภาพรถ ณ วันรับคืน</h3>
      <p class="tiny">
        <?= e($b['brand'] . ' ' . $b['model']) ?> ทะเบียน <?= e($b['plate']) ?>
        · บันทึกเมื่อ <?= e(thai_date($inspIn['inspected_at'], true)) ?>
        · เลขไมล์ <?= $inspIn['mileage'] ? number_format((float) $inspIn['mileage']) . ' กม.' : '-' ?>
        <?= $kmUsed !== null ? '(ใช้ไป ' . number_format($kmUsed) . ' กม.)' : '' ?>
        · น้ำมัน <?= e(fuel_label($inspIn['fuel_level'])) ?>
        <?php if (inspection_is_locked($inspIn)): ?>
          <br>ผลตรวจถูกล็อกเมื่อ <?= e(thai_date($inspIn['locked_at'], true)) ?><?= $inspIn['locker'] ? ' โดย ' . e($inspIn['locker']) : '' ?> — หลังจากเวลานี้แก้ไขไม่ได้
        <?php endif; ?>
      </p>

      <div class="doc-insp">
        <?php foreach (inspection_positions() as $pk => [$plabel, ]):
            if (empty($posIn[$pk])) continue; ?>
          <figure>
            <img src="<?= e(upload_url($posIn[$pk]['file_path'])) ?>" alt="">
            <figcaption><?= e($plabel) ?> · <?= e(thai_date($posIn[$pk]['created_at'], true)) ?></figcaption>
          </figure>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($inspIn['note'])): ?>
        <p class="tiny mt-1"><b>ตำหนิที่พบ ณ วันรับคืน:</b> <?= e($inspIn['note']) ?></p>
      <?php endif; ?>
      <?php if (!empty($b['damage_note'])): ?>
        <p class="tiny mt-1"><b>ความเสียหายที่บันทึก:</b> <?= e($b['damage_note']) ?></p>
      <?php endif; ?>
      <?php if ($xl = booking_extra_lines($b)): ?>
        <table class="doc-xl">
          <thead><tr><th>ค่าใช้จ่ายเพิ่มตอนคืนรถ</th><th class="num">บาท</th></tr></thead>
          <tbody>
            <?php foreach ($xl as $l): ?>
              <tr><td><?= e($l['label']) ?><?= $l['detail'] !== '' ? ' <span class="muted">— ' . e($l['detail']) . '</span>' : '' ?></td>
                  <td class="num"><?= $l['waived'] ? 'ยกเว้น' : money($l['amount']) ?></td></tr>
            <?php endforeach; ?>
            <tr><th>รวม</th><th class="num"><?= money($b['extra_charge'] ?? 0) ?></th></tr>
          </tbody>
        </table>
      <?php endif; ?>

      <p class="tiny mt-1">ผู้เช่าได้ตรวจสอบสภาพรถ ณ วันคืนรถตามภาพถ่ายข้างต้นร่วมกับผู้รับคืนรถแล้ว
        และรับทราบรายการข้างต้น</p>

      <?= $sigNote($sigR) ?>
      <div class="doc-sign">
        <?= $renterSign($sigR, 'ผู้เช่า — คืนรถ') ?>
        <div><div class="ln">(...........................................)<br>ผู้รับคืนรถ</div></div>
      </div>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
