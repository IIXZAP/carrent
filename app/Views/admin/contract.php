<?php
/**
 * พิมพ์สัญญาเช่ารถ
 * ตัวแปร: b, id, cus, carRow, sigC, sigR, inspOut, inspIn, posOut, posIn, extraLines
 */
$this->layout('layouts/admin_print', ['title' => $title]);

use App\Services\InspectionService;
use App\Services\PrivateFileService;
use App\Services\SignatureService;

$blankHtml = '<span class="inline-block min-w-[130px] border-b border-dotted border-slate-500 px-1"></span>';

/** ช่องลงนามผู้เช่า: มีลายเซ็นอิเล็กทรอนิกส์ → แสดงรูป + เวลา + รหัสอ้างอิง, ไม่มี → เส้นประให้เซ็นมือ */
$renterSign = static function (?array $sig, string $role) use ($b): string {
    $name = '(' . e($sig['signer_name'] ?? $b['contact_name']) . ')<br>' . e($role);
    $img  = $sig ? PrivateFileService::dataUri($sig['file_path']) : '';
    if (!$sig || $img === '') {
        return '<div class="flex flex-col"><div class="mt-[46px] border-t border-dotted border-ink-2 pt-[7px]">' . $name . '</div></div>';
    }
    return '<div class="flex flex-col"><img class="mx-auto -mb-2 block h-[54px] max-w-full object-contain" src="' . e($img) . '" alt="ลายเซ็นผู้เช่า">'
         . '<div class="border-t border-dotted border-ink-2 pt-[7px]">' . $name
         . '<span class="mt-0.5 block text-[0.68rem] text-muted">ลงนามอิเล็กทรอนิกส์ ' . e(thai_date($sig['signed_at'], true))
         . ' · รหัสอ้างอิง ' . e(SignatureService::ref($sig)) . '</span></div></div>';
};
$ownerSign = static fn(string $role): string =>
    '<div class="flex flex-col"><div class="mt-[46px] border-t border-dotted border-ink-2 pt-[7px]">(...........................................)<br>' . e($role) . '</div></div>';
$sigNote = static function (?array $sig) use ($b, $carRow): string {
    if (!$sig) {
        return '';
    }
    $chg = SignatureService::changes($sig, $b, $carRow);
    return $chg ? '<p class="mt-3.5 rounded-md border border-[#d9a441] px-[9px] py-[5px] text-xs"><b>หมายเหตุ:</b> ข้อมูลต่อไปนี้ถูกแก้ไขหลังผู้เช่าลงนาม — '
        . e(implode(' · ', array_map(fn($x) => $x['label'] . ' (ขณะลงนาม: ' . $x['signed'] . ')', $chg))) . '</p>' : '';
};
$signGrid = 'mt-[52px] grid grid-cols-2 gap-10 text-center text-[0.86rem] text-ink-2';
?>
<?php $this->start('toolbar'); ?>
  <button class="btn" onclick="window.print()">🖨️ พิมพ์สัญญา</button>
  <a class="btn btn-ghost" href="<?= url('admin/bookings/' . $id) ?>">กลับหน้างาน</a>
<?php $this->end(); ?>

<div class="mx-auto my-4 max-w-[820px] bg-white px-[44px] py-10 text-[15px] leading-[1.75] shadow-card max-sm:px-5 max-sm:py-6 print:my-0 print:max-w-full print:p-0 print:shadow-none">
  <h1 class="mb-[.2em] text-center text-[1.35rem]">สัญญาเช่ารถยนต์</h1>
  <div class="mb-[22px] text-center text-[0.9rem] text-ink-2">เลขที่สัญญา <?= e($b['booking_no']) ?> · ทำที่ <?= e(setting('company_name') ?: setting('site_name')) ?></div>

  <p>สัญญาฉบับนี้ทำขึ้นเมื่อวันที่ <b><?= e(thai_date(date('Y-m-d'), false, true)) ?></b> ระหว่าง</p>

  <p><b>ผู้ให้เช่า:</b> <?= e(setting('company_name') ?: setting('site_name')) ?>
    <?= setting('company_address') ? ' ที่อยู่ ' . e(setting('company_address')) : '' ?>
    โทร <?= e(setting('phone')) ?> ซึ่งต่อไปในสัญญานี้เรียกว่า “ผู้ให้เช่า” ฝ่ายหนึ่ง กับ</p>

  <p><b>ผู้เช่า:</b> <?= e($b['contact_name']) ?>
    <?= $cus && $cus['address'] ? ' ที่อยู่ ' . e($cus['address']) : ' ที่อยู่ ' . $blankHtml ?>
    โทร <?= e($b['contact_phone']) ?>
    <?= $cus && $cus['license_no'] ? ' ใบขับขี่เลขที่ ' . e($cus['license_no']) : ' ใบขับขี่เลขที่ ' . $blankHtml ?>
    ซึ่งต่อไปในสัญญานี้เรียกว่า “ผู้เช่า” อีกฝ่ายหนึ่ง</p>

  <p>ทั้งสองฝ่ายตกลงทำสัญญากันโดยมีข้อความดังต่อไปนี้</p>

  <?= $this->partial('partials/contract_terms', ['b' => $b, 'blank' => $blankHtml]) ?>

  <p>ทั้งสองฝ่ายได้อ่านและเข้าใจข้อความในสัญญาโดยตลอดแล้ว จึงลงลายมือชื่อไว้เป็นสำคัญต่อหน้าพยาน
    และต่างเก็บไว้ฝ่ายละหนึ่งฉบับ</p>

  <?= $sigNote($sigC) ?>
  <div class="<?= $signGrid ?>">
    <?= $renterSign($sigC, 'ผู้เช่า') ?>
    <?= $ownerSign('ผู้ให้เช่า') ?>
  </div>
  <div class="<?= $signGrid ?>">
    <?= $ownerSign('พยาน') ?>
    <?= $ownerSign('พยาน') ?>
  </div>

  <?php // ---------- ใบแนบ ก: บันทึกสภาพรถตอนส่งมอบ ----------
  if ($inspOut && $inspOut['photos']):
      $inspOut['mileage']    = $inspOut['mileage'] ?: ($b['mileage_out'] ?? null);
      $inspOut['fuel_level'] = $inspOut['fuel_level'] ?? ($b['fuel_out'] ?? null); ?>
    <div class="mt-[34px] border-t-2 border-line pt-[22px] print:mt-0 print:break-before-page print:border-t-0">
      <h3 class="mb-1.5 text-[1.02rem]">ใบแนบ ก — บันทึกสภาพรถ ณ วันส่งมอบ</h3>
      <p class="text-xs">
        <?= e($b['brand'] . ' ' . $b['model']) ?> ทะเบียน <?= e($b['plate']) ?>
        · บันทึกเมื่อ <?= e(thai_date($inspOut['inspected_at'], true)) ?>
        · เลขไมล์ <?= $inspOut['mileage'] ? number_format((float) $inspOut['mileage']) . ' กม.' : '-' ?>
        · น้ำมัน <?= e(fuel_label($inspOut['fuel_level'])) ?>
        <?php if (InspectionService::isLocked($inspOut)): ?>
          <br>ผลตรวจถูกล็อกเมื่อ <?= e(thai_date($inspOut['locked_at'], true)) ?><?= $inspOut['locker'] ? ' โดย ' . e($inspOut['locker']) : '' ?> — หลังจากเวลานี้แก้ไขไม่ได้
        <?php endif; ?>
      </p>

      <div class="mt-2 grid grid-cols-4 gap-1.5">
        <?php foreach (InspectionService::positions() as $pk => $pdef):
            if (empty($posOut[$pk])) {
                continue;
            } ?>
          <figure class="m-0">
            <img class="block aspect-[4/3] w-full rounded border border-[#d8dee7] object-cover print:[print-color-adjust:exact]" src="<?= e(upload_url($posOut[$pk]['file_path'])) ?>" alt="">
            <figcaption class="mt-0.5 text-center text-[0.62rem] text-[#6b7684]"><?= e($pdef[0]) ?> · <?= e(thai_date($posOut[$pk]['created_at'], true)) ?></figcaption>
          </figure>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($inspOut['note'])): ?>
        <p class="mt-2 text-xs"><b>ตำหนิที่พบ ณ วันส่งมอบ:</b> <?= e($inspOut['note']) ?></p>
      <?php endif; ?>

      <p class="mt-2 text-xs">ผู้เช่าได้ตรวจสอบสภาพรถตามภาพถ่ายข้างต้นแล้ว และยอมรับว่าเป็นสภาพรถ ณ วันรับมอบจริง</p>

      <?php // ลายเซ็นสัญญาครอบคลุมใบแนบ ก เฉพาะเมื่อตอนเซ็นมีรูปสภาพรถให้ลูกค้าดูแล้ว
            $snapC  = $sigC ? (json_decode((string) $sigC['snapshot'], true) ?: []) : [];
            $coverA = $sigC && (int) ($snapC['inspection']['photos'] ?? 0) > 0; ?>
      <div class="<?= $signGrid ?>">
        <?= $renterSign($coverA ? $sigC : null, 'ผู้เช่า — ตรวจรับสภาพรถ') ?>
        <?= $ownerSign('ผู้ส่งมอบรถ') ?>
      </div>
    </div>
  <?php endif; ?>

  <?php // ---------- ใบแนบ ข: สภาพรถตอนรับคืน ----------
  if ($inspIn && $inspIn['photos']):
      // เลขไมล์อาจถูกกรอกในฟอร์มส่งมอบ/รับคืน แทนที่จะกรอกในหน้าถ่ายรูป — ใช้ค่าจากใบจองเป็นตัวสำรอง
      $inKm  = $inspIn['mileage'] ?: ($b['mileage_in'] ?? null);
      $outKm = ($inspOut['mileage'] ?? null) ?: ($b['mileage_out'] ?? null);
      $inspIn['mileage']    = $inKm;
      $inspIn['fuel_level'] = $inspIn['fuel_level'] ?? ($b['fuel_in'] ?? null);
      $kmUsed = ($outKm && $inKm) ? (float) $inKm - (float) $outKm : null; ?>
    <div class="mt-[34px] border-t-2 border-line pt-[22px] print:mt-0 print:break-before-page print:border-t-0">
      <h3 class="mb-1.5 text-[1.02rem]">ใบแนบ ข — บันทึกสภาพรถ ณ วันรับคืน</h3>
      <p class="text-xs">
        <?= e($b['brand'] . ' ' . $b['model']) ?> ทะเบียน <?= e($b['plate']) ?>
        · บันทึกเมื่อ <?= e(thai_date($inspIn['inspected_at'], true)) ?>
        · เลขไมล์ <?= $inspIn['mileage'] ? number_format((float) $inspIn['mileage']) . ' กม.' : '-' ?>
        <?= $kmUsed !== null ? '(ใช้ไป ' . number_format($kmUsed) . ' กม.)' : '' ?>
        · น้ำมัน <?= e(fuel_label($inspIn['fuel_level'])) ?>
        <?php if (InspectionService::isLocked($inspIn)): ?>
          <br>ผลตรวจถูกล็อกเมื่อ <?= e(thai_date($inspIn['locked_at'], true)) ?><?= $inspIn['locker'] ? ' โดย ' . e($inspIn['locker']) : '' ?> — หลังจากเวลานี้แก้ไขไม่ได้
        <?php endif; ?>
      </p>

      <div class="mt-2 grid grid-cols-4 gap-1.5">
        <?php foreach (InspectionService::positions() as $pk => $pdef):
            if (empty($posIn[$pk])) {
                continue;
            } ?>
          <figure class="m-0">
            <img class="block aspect-[4/3] w-full rounded border border-[#d8dee7] object-cover print:[print-color-adjust:exact]" src="<?= e(upload_url($posIn[$pk]['file_path'])) ?>" alt="">
            <figcaption class="mt-0.5 text-center text-[0.62rem] text-[#6b7684]"><?= e($pdef[0]) ?> · <?= e(thai_date($posIn[$pk]['created_at'], true)) ?></figcaption>
          </figure>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($inspIn['note'])): ?>
        <p class="mt-2 text-xs"><b>ตำหนิที่พบ ณ วันรับคืน:</b> <?= e($inspIn['note']) ?></p>
      <?php endif; ?>
      <?php if (!empty($b['damage_note'])): ?>
        <p class="mt-2 text-xs"><b>ความเสียหายที่บันทึก:</b> <?= e($b['damage_note']) ?></p>
      <?php endif; ?>
      <?php if ($extraLines): ?>
        <table class="mt-2 w-full border-collapse text-[0.78rem]">
          <thead><tr><th class="border border-[#d8dee7] px-[7px] py-[3px] text-left">ค่าใช้จ่ายเพิ่มตอนคืนรถ</th>
            <th class="w-[90px] whitespace-nowrap border border-[#d8dee7] px-[7px] py-[3px] text-right">บาท</th></tr></thead>
          <tbody>
            <?php foreach ($extraLines as $l): ?>
              <tr><td class="border border-[#d8dee7] px-[7px] py-[3px]"><?= e($l['label']) ?><?= $l['detail'] !== '' ? ' <span class="text-muted">— ' . e($l['detail']) . '</span>' : '' ?></td>
                  <td class="w-[90px] whitespace-nowrap border border-[#d8dee7] px-[7px] py-[3px] text-right"><?= $l['waived'] ? 'ยกเว้น' : money($l['amount']) ?></td></tr>
            <?php endforeach; ?>
            <tr><th class="border border-[#d8dee7] px-[7px] py-[3px] text-left">รวม</th>
              <th class="border border-[#d8dee7] px-[7px] py-[3px] text-right"><?= money($b['extra_charge'] ?? 0) ?></th></tr>
          </tbody>
        </table>
      <?php endif; ?>

      <p class="mt-2 text-xs">ผู้เช่าได้ตรวจสอบสภาพรถ ณ วันคืนรถตามภาพถ่ายข้างต้นร่วมกับผู้รับคืนรถแล้ว และรับทราบรายการข้างต้น</p>

      <?= $sigNote($sigR) ?>
      <div class="<?= $signGrid ?>">
        <?= $renterSign($sigR, 'ผู้เช่า — คืนรถ') ?>
        <?= $ownerSign('ผู้รับคืนรถ') ?>
      </div>
    </div>
  <?php endif; ?>
</div>
