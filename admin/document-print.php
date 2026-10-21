<?php
/**
 * พิมพ์เอกสาร (พิมพ์จากเบราว์เซอร์ → Save as PDF ได้เลย)
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();

$id  = (int) ($_GET['id'] ?? 0);
$doc = db_one('SELECT * FROM documents WHERE id = :i', [':i' => $id]);
if (!$doc) {
    die('ไม่พบเอกสาร');
}
$booking = $doc['booking_id'] ? db_one('SELECT b.*, c.brand, c.model, c.plate FROM bookings b
                                        JOIN cars c ON c.id = b.car_id WHERE b.id = :i',
                                       [':i' => (int) $doc['booking_id']]) : null;
$items = json_decode((string) $doc['items'], true) ?: [];

$typeLabel = ['quotation'=>'ใบเสนอราคา','invoice'=>'ใบแจ้งหนี้','receipt'=>'ใบเสร็จรับเงิน','contract'=>'สัญญาเช่า'];
$typeEn    = ['quotation'=>'QUOTATION','invoice'=>'INVOICE','receipt'=>'RECEIPT','contract'=>'CONTRACT'];

/** แปลงตัวเลขเป็นคำอ่านภาษาไทย */
function baht_text(float $amount): string
{
    $num  = ['ศูนย์','หนึ่ง','สอง','สาม','สี่','ห้า','หก','เจ็ด','แปด','เก้า'];
    $unit = ['','สิบ','ร้อย','พัน','หมื่น','แสน','ล้าน'];
    $read = function (string $s) use ($num, $unit): string {
        $s = ltrim($s, '0');
        if ($s === '') return '';
        $len = strlen($s);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $d = (int) $s[$i];
            $pos = $len - $i - 1;
            if ($d === 0) continue;
            if ($pos === 0 && $d === 1 && $len > 1)      $out .= 'เอ็ด';
            elseif ($pos === 1 && $d === 1)               $out .= 'สิบ';
            elseif ($pos === 1 && $d === 2)               $out .= 'ยี่สิบ';
            else                                          $out .= $num[$d] . $unit[$pos];
        }
        return $out;
    };
    $amount = round($amount, 2);
    $int    = (int) floor($amount);
    $satang = (int) round(($amount - $int) * 100);
    $text   = $int === 0 ? 'ศูนย์' : $read((string) $int);
    $text  .= 'บาท';
    $text  .= $satang > 0 ? $read((string) $satang) . 'สตางค์' : 'ถ้วน';
    return $text;
}
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($typeLabel[$doc['doc_type']]) ?> <?= e($doc['doc_no']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" sizes="32x32" href="<?= url('assets/img/favicon-32.png') ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
<style>
:root{--brand:<?= e(setting('brand_primary', '#0f3d68')) ?>;--accent:<?= e(setting('brand_accent', '#e8a33d')) ?>}
body{background:#eef1f5;padding:24px 0}
@media print{body{padding:0;background:#fff}}
</style>
</head>
<body>

<div class="no-print center" style="max-width:820px;margin:0 auto 16px">
  <button class="btn" onclick="window.print()">🖨️ พิมพ์ / บันทึก PDF</button>
  <a class="btn btn-ghost" href="<?= url('admin/document-edit.php?id=' . $id) ?>">แก้ไข</a>
  <a class="btn btn-ghost" href="<?= url('admin/documents.php') ?>">กลับรายการ</a>
</div>

<div class="doc">
  <div class="doc-head">
    <div class="co">
      <?= brand_logo('full', 'doc-logo') ?>
      <p>
        <?= e(setting('company_name')) ?><br>
        <?= nl2br(e(setting('company_address'))) ?><br>
        โทร <?= e(setting('phone')) ?><?= setting('phone2') ? ', ' . e(setting('phone2')) : '' ?><br>
        <?= setting('company_tax_id') ? 'เลขประจำตัวผู้เสียภาษี ' . e(setting('company_tax_id')) : '' ?>
      </p>
    </div>
    <div class="meta">
      <h3><?= e($typeLabel[$doc['doc_type']]) ?></h3>
      <div class="tiny muted" style="margin-bottom:8px"><?= e($typeEn[$doc['doc_type']]) ?></div>
      <dl>
        <dt>เลขที่</dt><dd><?= e($doc['doc_no']) ?></dd>
        <dt>วันที่</dt><dd><?= e(thai_date($doc['issue_date'], false, true)) ?></dd>
        <?php if ($doc['due_date']): ?>
          <dt><?= $doc['doc_type'] === 'quotation' ? 'ยืนราคาถึง' : 'ครบกำหนด' ?></dt>
          <dd><?= e(thai_date($doc['due_date'], false, true)) ?></dd>
        <?php endif; ?>
        <?php if ($booking): ?><dt>อ้างอิง</dt><dd><?= e($booking['booking_no']) ?></dd><?php endif; ?>
      </dl>
    </div>
  </div>

  <div class="doc-parties">
    <div class="bx">
      <b>ผู้ให้เช่า</b>
      <?= e(setting('company_name') ?: setting('site_name')) ?><br>
      โทร <?= e(setting('phone')) ?>
    </div>
    <div class="bx">
      <b>ผู้เช่า / ลูกค้า</b>
      <?= e($doc['bill_name']) ?><br>
      <?= $doc['bill_tax_id'] ? 'เลขผู้เสียภาษี ' . e($doc['bill_tax_id']) . '<br>' : '' ?>
      <?= nl2br(e($doc['bill_address'])) ?>
    </div>
  </div>

  <?php if ($booking): ?>
    <p class="small" style="background:var(--bg);padding:10px 14px;border-radius:8px">
      <b>รถ:</b> <?= e($booking['brand'] . ' ' . $booking['model']) ?> ทะเบียน <?= e($booking['plate']) ?> ·
      <b>ช่วงเช่า:</b> <?= e(thai_date($booking['pickup_at'], true)) ?> – <?= e(thai_date($booking['return_at'], true)) ?>
      (<?= (int) $booking['rental_days'] ?> วัน)
    </p>
  <?php endif; ?>

  <table>
    <thead>
      <tr><th style="width:44px">#</th><th>รายละเอียด</th><th class="num" style="width:80px">จำนวน</th>
          <th style="width:70px">หน่วย</th><th class="num" style="width:110px">ราคา/หน่วย</th>
          <th class="num" style="width:120px">จำนวนเงิน</th></tr>
    </thead>
    <tbody>
      <?php foreach ($items as $i => $it): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= e($it['desc']) ?></td>
          <td class="num"><?= rtrim(rtrim(number_format((float) $it['qty'], 2), '0'), '.') ?></td>
          <td><?= e($it['unit'] ?? '') ?></td>
          <td class="num"><?= money($it['price']) ?></td>
          <td class="num"><?= money($it['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="doc-total">
    <div class="r"><span>รวมเป็นเงิน</span><span><?= money($doc['subtotal']) ?></span></div>
    <?php if ((float) $doc['discount'] > 0): ?>
      <div class="r"><span>ส่วนลด</span><span>-<?= money($doc['discount']) ?></span></div>
    <?php endif; ?>
    <?php if ((float) $doc['vat_rate'] > 0): ?>
      <div class="r"><span>ยอดก่อนภาษี</span><span><?= money($doc['subtotal'] - $doc['discount']) ?></span></div>
      <div class="r"><span>ภาษีมูลค่าเพิ่ม <?= rtrim(rtrim(number_format((float) $doc['vat_rate'], 2), '0'), '.') ?>%</span>
        <span><?= money($doc['vat_amount']) ?></span></div>
    <?php endif; ?>
    <?php if ((float) $doc['wht_rate'] > 0): ?>
      <div class="r"><span>หัก ณ ที่จ่าย <?= rtrim(rtrim(number_format((float) $doc['wht_rate'], 2), '0'), '.') ?>%</span>
        <span>-<?= money($doc['wht_amount']) ?></span></div>
    <?php endif; ?>
    <div class="r grand"><span>ยอดสุทธิ</span><span>฿<?= money($doc['grand_total']) ?></span></div>
  </div>

  <div style="clear:both"></div>
  <p class="small center" style="border:1px dashed var(--line);padding:9px;border-radius:8px;margin-top:14px">
    (<?= e(baht_text((float) $doc['grand_total'])) ?>)
  </p>

  <?php if ($doc['doc_type'] !== 'receipt'): ?>
    <div class="doc-note">
      <b>การชำระเงิน:</b> โอนเข้าบัญชี <?= e(setting('bank_name')) ?> เลขที่ <?= e(setting('bank_account_no')) ?>
      ชื่อบัญชี <?= e(setting('bank_account_name')) ?><?= setting('promptpay_id') ? ' · PromptPay ' . e(setting('promptpay_id')) : '' ?>
      <?php if ($doc['note']): ?><br><b>หมายเหตุ:</b> <?= nl2br(e($doc['note'])) ?><?php endif; ?>
    </div>
  <?php elseif ($doc['note']): ?>
    <div class="doc-note"><b>หมายเหตุ:</b> <?= nl2br(e($doc['note'])) ?></div>
  <?php endif; ?>

  <div class="doc-sign">
    <div><div class="ln">ผู้เช่า / ผู้สั่งซื้อ<br>วันที่ ......../......../........</div></div>
    <div><div class="ln">ผู้มีอำนาจลงนาม<br><?= e(setting('company_name') ?: setting('site_name')) ?></div></div>
  </div>
</div>

</body>
</html>
