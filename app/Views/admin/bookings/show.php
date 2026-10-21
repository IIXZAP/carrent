<?php
/**
 * หน้างานการจอง 1 รายการ — ยืนยัน / ส่งมอบรถ / รับคืนรถ / เช่าต่อ / ปิดเงินประกัน / ยกเลิก / บันทึกเงิน
 * ตัวแปร: b, car, id, payments, customer, docs, breakdown, sl sc pl pc, due, balance, held, xLines, sigs, sigVoided,
 *         hasLine, inspOut, inspIn, progOut, progIn, mapOut, mapIn, nowLocal
 */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'bookings']);

$kindLabel   = ['deposit' => 'มัดจำ', 'balance' => 'ค่าเช่า', 'security' => 'เงินประกัน', 'extra' => 'ค่าใช้จ่ายเพิ่ม',
                'refund' => 'คืนเงินค่าเช่า', 'security_refund' => 'คืนเงินประกัน'];
$methodLabel = ['cash' => 'เงินสด', 'transfer' => 'โอนธนาคาร', 'promptpay' => 'PromptPay', 'card' => 'บัตรเครดิต',
                'deduct' => 'หักจากเงินประกัน'];
$docTypes    = ['quotation' => 'ใบเสนอราคา', 'invoice' => 'ใบแจ้งหนี้', 'receipt' => 'ใบเสร็จรับเงิน', 'contract' => 'สัญญาเช่า'];

/** ปุ่มถ่ายรูปสภาพรถ + สถานะความครบ — ใช้ในกล่องส่งมอบและกล่องรับคืน */
$inspectBlock = static function (int $bookingId, string $kind, array $prog): string {
    $link  = url('admin/bookings/' . $bookingId . '/inspection/' . $kind);
    $cls   = $prog['complete'] ? 'ok' : 'warn';
    $label = $prog['done'] > 0 ? 'ถ่ายแล้ว ' . $prog['done'] . '/' . $prog['required'] . ' มุม' : 'ยังไม่ได้ถ่ายรูปสภาพรถ';
    return '<a class="btn btn-ghost btn-block mb-2" href="' . e($link) . '">'
         . icon('camera') . ' ถ่ายรูปสภาพรถ'
         . ' <span class="badge badge-' . $cls . ' ml-1.5">' . e($label) . '</span></a>';
};

$summaryBtn = 'list-none cursor-pointer summary-plain';
?>

<div class="mb-4 flex flex-wrap items-center gap-2.5 print:hidden">
  <a class="btn btn-ghost btn-sm" href="<?= url('admin/bookings') ?>">← รายการจอง</a>
  <div class="ml-auto flex flex-wrap gap-2">
    <a class="btn btn-ghost btn-sm" href="<?= url('admin/bookings/' . $id . '/edit') ?>">แก้ไขข้อมูล</a>
    <a class="btn btn-ghost btn-sm" href="<?= url('admin/documents/new?booking_id=' . $id . '&type=quotation') ?>">ออกใบเสนอราคา</a>
    <a class="btn btn-ghost btn-sm" href="<?= url('admin/documents/new?booking_id=' . $id . '&type=receipt') ?>">ออกใบเสร็จ</a>
    <a class="btn btn-ghost btn-sm" href="<?= url('admin/bookings/' . $id . '/contract') ?>" target="_blank" rel="noopener">พิมพ์สัญญาเช่า</a>
  </div>
</div>

<div class="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
  <div class="min-w-0">
    <!-- ---------- สรุปงาน ---------- -->
    <div class="panel">
      <div class="flex flex-wrap items-start justify-between gap-3.5">
        <div>
          <h2 class="mb-0"><?= e($b['booking_no']) ?></h2>
          <p class="mb-0 text-sm text-muted">สร้างเมื่อ <?= e(thai_date($b['created_at'], true)) ?> · ช่องทาง <?= e($b['source']) ?></p>
        </div>
        <div class="text-right">
          <span class="badge badge-<?= e($sc) ?>"><?= e($sl) ?></span>
          <span class="badge badge-<?= e($pc) ?>"><?= e($pl) ?></span>
        </div>
      </div>

      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div>
          <h4 class="text-sm">ข้อมูลรถ</h4>
          <table class="table">
            <tbody>
              <tr><th>รถ</th><td><?= e($b['brand'] . ' ' . $b['model']) ?></td></tr>
              <tr><th>ทะเบียน</th><td><?= e($b['plate']) ?> · สี<?= e($b['color']) ?></td></tr>
              <tr><th>รับรถ</th><td><?= e(thai_date($b['pickup_at'], true, true)) ?></td></tr>
              <tr><th>คืนรถ</th><td><?= e(thai_date($b['return_at'], true, true)) ?></td></tr>
              <tr><th>ระยะเวลา</th><td><?= (int) $b['rental_days'] ?> วัน</td></tr>
              <tr><th>จุดรับ</th><td><?= e($b['pickup_place'] ?: '-') ?></td></tr>
              <tr><th>จุดคืน</th><td><?= e($b['return_place'] ?: '-') ?></td></tr>
            </tbody>
          </table>
        </div>
        <div>
          <h4 class="text-sm">ข้อมูลผู้เช่า</h4>
          <table class="table">
            <tbody>
              <tr><th>ชื่อ</th><td><?= e($b['contact_name']) ?></td></tr>
              <tr><th>โทร</th><td><a href="tel:<?= e($b['contact_phone']) ?>"><?= e($b['contact_phone']) ?></a></td></tr>
              <tr><th>อีเมล</th><td><?= e($b['contact_email'] ?: '-') ?></td></tr>
              <tr><th>LINE</th><td><?= e($b['contact_line'] ?: '-') ?></td></tr>
              <?php if ($customer): ?>
                <tr><th>รหัสลูกค้า</th><td><a href="<?= url('admin/customers/' . (int) $customer['id'] . '/edit') ?>"><?= e($customer['code']) ?></a>
                  <?= (int) $customer['is_blacklist'] ? ' <span class="badge badge-danger">Blacklist</span>' : '' ?></td></tr>
                <tr><th>ใบขับขี่</th><td><?= e($customer['license_no'] ?: '-') ?></td></tr>
              <?php endif; ?>
              <tr><th>หมายเหตุ</th><td><?= nl2br(e($b['note'] ?: '-')) ?></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ---------- การเงิน ---------- -->
    <div class="panel mt-4">
      <h3>การเงิน</h3>
      <div class="mt-2 grid gap-4 sm:grid-cols-2">
        <div>
          <div class="sum">
            <?php foreach (($breakdown['rental'] ?? []) as $l): ?>
              <div class="sum-row"><span><?= e($l['label']) ?> × <?= (int) $l['qty'] ?></span><span><?= money($l['amount']) ?></span></div>
            <?php endforeach; ?>
            <?php foreach (($breakdown['addons'] ?? []) as $l): ?>
              <div class="sum-row"><span class="text-muted"><?= e($l['label']) ?></span><span><?= money($l['amount']) ?></span></div>
            <?php endforeach; ?>
            <?php if ((float) $b['discount'] > 0): ?>
              <div class="sum-row"><span>ส่วนลด</span><span>-<?= money($b['discount']) ?></span></div>
            <?php endif; ?>
            <?php if ((float) $b['vat_rate'] > 0): ?>
              <div class="sum-row"><span>VAT <?= (float) $b['vat_rate'] ?>%</span><span><?= money($b['vat_amount']) ?></span></div>
            <?php endif; ?>
            <?php foreach ($xLines as $l): ?>
              <div class="sum-row"><span><?= e($l['label']) ?><?php if (!empty($l['detail'])): ?>
                  <span class="block text-xs text-muted"><?= e($l['detail']) ?></span><?php endif; ?></span>
                <span><?php if (!empty($l['waived'])): ?><span class="badge badge-info">ยกเว้น</span>
                  <s class="text-xs text-muted"><?= money($l['suggested'] ?? 0) ?></s><?php else: ?><?= money($l['amount']) ?><?php endif; ?></span></div>
            <?php endforeach; ?>
            <div class="sum-row total"><span>ยอดที่ต้องเก็บ</span><span>฿<?= money($due) ?></span></div>
            <div class="sum-row"><span>เก็บแล้ว</span><span><?= money($b['paid_amount']) ?></span></div>
            <div class="sum-row <?= $balance > 0 ? 'hl' : '' ?>"><span>คงเหลือ</span><span>฿<?= money(max(0, $balance)) ?></span></div>
            <div class="sum-row"><span class="text-muted">เงินประกันตามเงื่อนไข</span><span><?= money($b['security_deposit']) ?></span></div>
            <div class="sum-row"><span class="text-muted">เงินประกันที่ร้านถืออยู่</span>
              <span><?= $held > 0 ? '<b>' . money($held) . '</b>' : money(0) ?></span></div>
          </div>
        </div>
        <div>
          <form method="post" action="<?= url('admin/bookings/' . $id . '/action') ?>" class="panel panel-flat">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="payment">
            <h4 class="mt-0 text-sm">บันทึกรับเงิน</h4>
            <div class="field">
              <label>ประเภท</label>
              <select name="kind">
                <option value="deposit">มัดจำ</option>
                <option value="balance" selected>ค่าเช่าส่วนที่เหลือ</option>
                <option value="security">เงินประกัน</option>
                <option value="extra">ค่าใช้จ่ายเพิ่มเติม</option>
                <option value="refund">คืนเงินค่าเช่า</option>
              </select>
              <div class="hint">คืนเงินประกันให้ใช้ปุ่ม "ปิดเงินประกัน" ทางขวา ระบบจะไม่นับเป็นรายได้ติดลบ</div>
            </div>
            <div class="field">
              <label>ช่องทาง</label>
              <select name="method">
                <option value="cash">เงินสด</option>
                <option value="transfer">โอนธนาคาร</option>
                <option value="promptpay">PromptPay</option>
                <option value="card">บัตรเครดิต</option>
              </select>
            </div>
            <div class="field">
              <label>จำนวนเงิน (บาท)</label>
              <input type="number" name="amount" step="0.01" min="0" value="<?= e(max(0, $balance)) ?>" required>
            </div>
            <div class="field">
              <label>หมายเหตุ</label>
              <input type="text" name="pnote" maxlength="200">
            </div>
            <button class="btn btn-block">บันทึกรับเงิน</button>
          </form>
        </div>
      </div>

      <?php if ($payments): ?>
        <h4 class="mt-5 text-sm">ประวัติการเงิน</h4>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>วันที่</th><th>ประเภท</th><th>ช่องทาง</th><th class="num">จำนวน</th><th>สถานะ</th><th>สลิป</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($payments as $p): ?>
                <tr>
                  <td class="text-sm"><?= e(thai_date($p['paid_at'] ?: $p['created_at'], true)) ?></td>
                  <td class="text-sm"><?= e($kindLabel[$p['kind']] ?? $p['kind']) ?></td>
                  <td class="text-sm"><?= e($methodLabel[$p['method']] ?? $p['method']) ?><?php if ($p['note']): ?>
                    <span class="block text-xs text-muted"><?= e($p['note']) ?></span><?php endif; ?></td>
                  <td class="num"><?= money($p['amount']) ?></td>
                  <td>
                    <?php if ($p['status'] === 'approved'): ?><span class="badge badge-ok">ยืนยันแล้ว</span>
                    <?php elseif ($p['status'] === 'rejected'): ?><span class="badge badge-danger">ไม่ผ่าน</span>
                    <?php else: ?><span class="badge badge-warn">รอตรวจ</span><?php endif; ?>
                  </td>
                  <td><?= $p['slip_file'] ? '<a href="' . e(upload_url($p['slip_file'])) . '" target="_blank" rel="noopener">ดู</a>' : '-' ?></td>
                  <td class="text-right">
                    <?php if ($p['status'] === 'pending'): ?>
                      <form method="post" action="<?= url('admin/payments/approve') ?>" class="inline-block"
                            onsubmit="return confirm('ยืนยันว่าได้รับเงิน <?= e(money($p['amount'])) ?> บาทแล้ว?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="back" value="admin/bookings/<?= $id ?>">
                        <button class="btn btn-sm">อนุมัติ</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($inspOut || $inspIn): ?>
      <div class="panel mt-4">
        <h3><?= icon('compare') ?> สภาพรถ ก่อน–หลัง</h3>
        <p class="text-xs text-muted">
          <?php foreach ([['ส่งมอบ', $inspOut], ['รับคืน', $inspIn]] as [$lb, $iv]):
              if (!$iv) {
                  continue;
              } ?>
            <span class="block">
              <?= e($lb) ?> <?= e(thai_date($iv['inspected_at'], true)) ?><?= $iv['inspector'] ? ' โดย ' . e($iv['inspector']) : '' ?>
              <?php if (\App\Services\InspectionService::isLocked($iv)): ?>
                · <span class="badge badge-ok">🔒 ล็อกแล้ว <?= e(thai_date($iv['locked_at'], true)) ?></span>
              <?php else: ?>
                · <span class="badge badge-warn">ยังไม่ล็อก แก้ไขได้</span>
              <?php endif; ?>
              <?php if (!empty($iv['history'])): ?> · ถ่ายซ้ำ <?= count($iv['history']) ?> รูป (เก็บรูปเดิมไว้)<?php endif; ?>
            </span>
          <?php endforeach; ?>
        </p>

        <div class="mt-1 grid gap-4 sm:grid-cols-2">
          <table class="table tiny">
            <tr><th>เลขไมล์ตอนส่งมอบ</th><td class="num"><?= $inspOut && $inspOut['mileage'] ? number_format((float) $inspOut['mileage']) . ' กม.' : '-' ?></td></tr>
            <tr><th>เลขไมล์ตอนรับคืน</th><td class="num"><?= $inspIn && $inspIn['mileage'] ? number_format((float) $inspIn['mileage']) . ' กม.' : '-' ?></td></tr>
            <?php if ($inspOut && $inspIn && $inspOut['mileage'] && $inspIn['mileage']): ?>
              <tr><th>ระยะที่ใช้ไป</th><td class="num"><b><?= number_format((float) $inspIn['mileage'] - (float) $inspOut['mileage']) ?> กม.</b></td></tr>
            <?php endif; ?>
          </table>
          <table class="table tiny">
            <tr><th>น้ำมันตอนส่งมอบ</th><td class="num"><?= e(fuel_label($inspOut['fuel_level'] ?? null)) ?></td></tr>
            <tr><th>น้ำมันตอนรับคืน</th><td class="num"><?= e(fuel_label($inspIn['fuel_level'] ?? null)) ?></td></tr>
            <?php if ($inspOut && $inspIn && $inspOut['fuel_level'] !== null && $inspIn['fuel_level'] !== null
                      && (int) $inspIn['fuel_level'] < (int) $inspOut['fuel_level']): ?>
              <tr><th>น้ำมันขาด</th><td class="num"><b class="text-danger"><?= (int) $inspOut['fuel_level'] - (int) $inspIn['fuel_level'] ?> ขีด</b></td></tr>
            <?php endif; ?>
          </table>
        </div>

        <div class="mt-3 grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
          <?php foreach (\App\Services\InspectionService::positions() as $pk => $pdef):
              if (empty($mapOut[$pk]) && empty($mapIn[$pk])) {
                  continue;
              } ?>
            <div class="min-w-0 rounded-xl border border-line p-2.5">
              <h4 class="mb-2 text-sm"><?= e($pdef[0]) ?></h4>
              <div class="grid grid-cols-2 gap-2">
                <?php foreach ([['ส่งมอบ', $mapOut], ['รับคืน', $mapIn]] as [$cap, $map]): ?>
                  <figure class="m-0 min-w-0">
                    <?php if (!empty($map[$pk])): ?>
                      <a href="<?= e(upload_url($map[$pk]['file_path'])) ?>" target="_blank" rel="noopener">
                        <img class="block aspect-[4/3] w-full rounded-lg border border-line object-cover" src="<?= e(upload_url($map[$pk]['file_path'])) ?>" alt="" loading="lazy"></a>
                    <?php else: ?>
                      <div class="rounded-xl border border-dashed border-line-2 p-[18px] text-center text-[0.85rem] text-muted">ไม่มีรูป</div>
                    <?php endif; ?>
                    <figcaption class="mt-[3px] text-center text-[0.74rem] text-muted"><?= e($cap) ?>
                      <?php if (!empty($map[$pk])): ?><span class="block text-xs"><?= e(thai_date($map[$pk]['created_at'], true)) ?><?= !empty($map[$pk]['uploader']) ? ' · ' . e($map[$pk]['uploader']) : '' ?></span><?php endif; ?>
                    </figcaption>
                  </figure>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php foreach ([['out', $inspOut], ['in', $inspIn]] as [$k, $iv]):
            if ($iv && !empty($iv['note'])): ?>
          <div class="alert alert-warn mt-3">
            <span><b>หมายเหตุ<?= e(\App\Services\InspectionService::kindLabel($k)) ?>:</b> <?= e($iv['note']) ?></span>
          </div>
        <?php endif; endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($docs): ?>
      <div class="panel mt-4">
        <h3>เอกสารที่ออกแล้ว</h3>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>เลขที่</th><th>ประเภท</th><th>วันที่</th><th class="num">ยอด</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($docs as $d): ?>
                <tr>
                  <td><?= e($d['doc_no']) ?></td>
                  <td><?= e($docTypes[$d['doc_type']] ?? $d['doc_type']) ?></td>
                  <td><?= e(thai_date($d['issue_date'])) ?></td>
                  <td class="num"><?= money($d['grand_total']) ?></td>
                  <td class="text-right"><a class="btn btn-ghost btn-sm" href="<?= url('admin/documents/' . (int) $d['id'] . '/print') ?>" target="_blank" rel="noopener">พิมพ์</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- ---------- คอลัมน์ขวา: การกระทำ ---------- -->
  <div class="min-w-0 print:hidden">
    <div id="actions" class="panel">
      <h3>ดำเนินการ</h3>
      <?php $act = url('admin/bookings/' . $id . '/action'); ?>

      <?php if ($b['status'] === 'pending'): ?>
        <form method="post" action="<?= $act ?>" class="mb-3">
          <?= csrf_field() ?><input type="hidden" name="act" value="confirm">
          <button class="btn btn-block">✅ ยืนยันการจอง</button>
          <p class="mb-0 mt-1.5 text-xs text-muted">ระบบจะส่งอีเมล/LINE ยืนยันให้ลูกค้าอัตโนมัติ</p>
        </form>
      <?php endif; ?>

      <?php if (in_array($b['status'], ['pending', 'confirmed'], true)): ?>
        <details class="mb-3">
          <summary class="btn btn-accent btn-block <?= $summaryBtn ?>">🚗 ส่งมอบรถ</summary>
          <form method="post" action="<?= $act ?>" class="mt-3">
            <?= csrf_field() ?><input type="hidden" name="act" value="pickup">
            <?= $inspectBlock($id, 'out', $progOut) ?>
            <div class="field"><label>เลขไมล์ตอนส่งมอบ</label>
              <input type="number" name="mileage_out" value="<?= (int) ($inspOut['mileage'] ?? $b['car_mileage']) ?>" min="0"></div>
            <div class="field"><label>ระดับน้ำมัน (0–8 ขีด)</label>
              <input type="number" name="fuel_out" value="<?= (int) ($inspOut['fuel_level'] ?? 8) ?>" min="0" max="8"></div>
            <button class="btn btn-accent btn-block"
              <?= $progOut['complete'] ? '' : 'onclick="return confirm(\'ยังถ่ายรูปสภาพรถไม่ครบ (' . (int) $progOut['done'] . '/' . (int) $progOut['required'] . ' มุม)\\nถ้าเกิดข้อพิพาทเรื่องรอยขีดข่วนจะไม่มีหลักฐาน\\n\\nต้องการส่งมอบรถเลยหรือไม่?\')"' ?>>
              บันทึกส่งมอบรถ
            </button>
          </form>
        </details>
      <?php endif; ?>

      <?php if ($b['status'] === 'picked_up'):
          $overdueMin = (int) floor((time() - strtotime($b['return_at'])) / 60); ?>
        <details class="mb-3" open>
          <summary class="btn btn-block <?= $summaryBtn ?>">🔙 รับคืนรถ</summary>
          <form method="post" action="<?= $act ?>" class="mt-3" id="returnForm"
                data-url="<?= e(url('admin/bookings/' . $id . '/preview/return')) ?>"
                data-balance="<?= e(round($balance, 2)) ?>" data-held="<?= e($held) ?>">
            <?= csrf_field() ?><input type="hidden" name="act" value="return">
            <?= $inspectBlock($id, 'in', $progIn) ?>
            <div class="field"><label>เวลาที่รับคืนรถจริง</label>
              <input type="datetime-local" name="returned_at" value="<?= e($nowLocal) ?>">
              <input type="hidden" name="rt_manual" value="">
              <div class="hint">กำหนดคืน <?= e(thai_date($b['return_at'], true)) ?><?= $overdueMin > 0 ? ' — <b class="text-danger">เลยกำหนดแล้ว</b>' : '' ?> · ไม่แก้ = ใช้เวลาที่กดบันทึก · ถ้าบันทึกย้อนหลังให้แก้เป็นเวลาที่รถมาถึงจริง</div></div>
            <div class="grid grid-cols-2 gap-x-2.5">
              <div class="field"><label>เลขไมล์ตอนรับคืน</label>
                <input type="number" name="mileage_in" inputmode="numeric"
                       value="<?= (int) ($inspIn['mileage'] ?? ($b['mileage_out'] ?: $b['car_mileage'])) ?>" min="<?= (int) $b['mileage_out'] ?>"></div>
              <div class="field"><label>น้ำมัน (0–8 ขีด)</label>
                <input type="number" name="fuel_in" inputmode="numeric"
                       value="<?= (int) ($inspIn['fuel_level'] ?? ($b['fuel_out'] ?? 8)) ?>" min="0" max="8">
                <div class="hint">ตอนรับรถ <?= $b['fuel_out'] !== null ? (int) $b['fuel_out'] . '/8' : '-' ?></div></div>
            </div>

            <div class="chg-box">
              <div class="chg-head"><b>ค่าใช้จ่ายเพิ่ม</b>
                <span class="text-xs text-muted">ระบบคิดให้ตามเงื่อนไข — แก้จำนวน หรือเอาติ๊กออกเพื่อยกเว้นได้</span></div>
              <div id="chgLines"></div>
              <p id="chgNone" class="mb-0 text-xs text-muted">ไม่มีค่าใช้จ่ายเพิ่มตามเงื่อนไข (คืนตรงเวลา น้ำมันครบ)</p>
              <div class="chg-row chg-other">
                <input type="text" name="other_label" maxlength="120" placeholder="ค่าใช้จ่ายอื่น เช่น ค่าทำความสะอาด" aria-label="รายการค่าใช้จ่ายอื่น">
                <input type="number" name="other_amount" class="chg-amt" step="0.01" min="0" placeholder="0" inputmode="numeric" aria-label="จำนวนเงินค่าใช้จ่ายอื่น">
              </div>
              <div class="chg-total"><span>รวมค่าใช้จ่ายเพิ่ม</span><b><span id="chgTotal">0</span> บาท</b></div>
              <div class="chg-total sub"><span>ยอดที่ต้องเก็บจากลูกค้าตอนนี้</span><b><span id="chgDue"><?= money0(max(0, $balance)) ?></span> บาท</b></div>
              <?php if ($held > 0): ?>
                <p class="mb-0 mt-1 text-xs text-muted">ร้านถือเงินประกันอยู่ <?= money0($held) ?> บาท — หักชำระยอดนี้ได้ในขั้น "ปิดเงินประกัน" หลังบันทึกรับคืน</p>
              <?php endif; ?>
            </div>

            <div class="field"><label>บันทึกความเสียหาย</label>
              <textarea name="damage_note" maxlength="1000" placeholder="ไม่มี ให้เว้นว่าง"></textarea></div>
            <button class="btn btn-block"
              <?= $progIn['complete'] ? '' : 'onclick="return confirm(\'ยังถ่ายรูปสภาพรถตอนรับคืนไม่ครบ (' . (int) $progIn['done'] . '/' . (int) $progIn['required'] . ' มุม)\\nถ้าพบรอยภายหลังจะไม่มีหลักฐานเทียบ\\n\\nต้องการบันทึกรับคืนเลยหรือไม่?\')"' ?>>
              บันทึกรับคืนรถ
            </button>
          </form>
        </details>
      <?php endif; ?>

      <?php if (in_array($b['status'], ['pending', 'confirmed', 'picked_up'], true)): ?>
        <details class="mb-3">
          <summary class="btn btn-ghost btn-block <?= $summaryBtn ?>">⏩ เช่าต่อ / เลื่อนวันคืน</summary>
          <form method="post" action="<?= $act ?>" class="mt-3" id="extendForm"
                data-url="<?= e(url('admin/bookings/' . $id . '/preview/extend')) ?>">
            <?= csrf_field() ?><input type="hidden" name="act" value="extend">
            <div class="field"><label>วัน–เวลาคืนรถใหม่</label>
              <input type="datetime-local" name="new_return" required
                     min="<?= e(date('Y-m-d\TH:i', strtotime($b['return_at']) + 60)) ?>"
                     value="<?= e(date('Y-m-d\TH:i', strtotime($b['return_at']) + 86400)) ?>">
              <div class="hint">กำหนดคืนเดิม <?= e(thai_date($b['return_at'], true)) ?> · ระบบตรวจให้ว่ารถไม่ชนคิวถัดไป</div></div>
            <div class="chg-box" id="extOut" aria-live="polite">
              <p class="mb-0 text-xs text-muted" id="extMsg">กำลังคำนวณ…</p>
              <div id="extRows" hidden>
                <div class="chg-total sub"><span>จำนวนวันเช่า</span><b><span id="extDays"></span></b></div>
                <div class="chg-total sub"><span>ค่าเช่าเพิ่ม</span><b><span id="extRental"></span> บาท</b></div>
                <div class="chg-total sub" id="extAddonRow"><span>บริการเสริมช่วงเช่าต่อ</span><b><span id="extAddon"></span> บาท</b></div>
                <div class="chg-total sub" id="extVatRow"><span>VAT</span><b><span id="extVat"></span> บาท</b></div>
                <div class="chg-total"><span>ค่าเช่าต่อที่ต้องเก็บ</span><b><span id="extCharge"></span> บาท</b></div>
                <p class="mb-0 mt-1 text-xs text-warn" id="extWarn" hidden></p>
              </div>
            </div>
            <div class="field" id="extChargeField" hidden><label>เก็บจริง (บาท)</label>
              <input type="number" name="charge" step="0.01" min="0" inputmode="decimal">
              <div class="hint">ลดให้ลูกค้าได้ แต่ไม่เกินยอดที่ระบบคิด ส่วนต่างบันทึกเป็นส่วนลด</div></div>
            <button class="btn btn-block" id="extBtn" disabled>บันทึกเช่าต่อ</button>
          </form>
        </details>
      <?php endif; ?>

      <?php if ($held > 0 && in_array($b['status'], ['returned', 'completed', 'cancelled'], true)):
          $paidNow   = (float) $b['paid_amount'];
          $owe       = $b['status'] === 'cancelled' ? max(0, round((float) $b['extra_charge'] - max(0, $paidNow), 2)) : max(0, round($balance, 2));
          $maxDeduct = min($held, $owe); ?>
        <form method="post" action="<?= $act ?>" class="chg-box mb-3" id="secForm" data-held="<?= e($held) ?>" data-owe="<?= e($owe) ?>"
              onsubmit="return confirm('ยืนยันปิดเงินประกัน?\nรายการนี้บันทึกเป็นประวัติการเงิน แก้ย้อนหลังไม่ได้')">
          <?= csrf_field() ?><input type="hidden" name="act" value="security_settle">
          <div class="chg-head"><b>ปิดเงินประกัน</b>
            <span class="text-xs text-muted">ร้านถืออยู่ <?= money0($held) ?> บาท · <?= $b['status'] === 'cancelled' ? 'ค่าใช้จ่ายค้างชำระ' : 'ลูกค้าค้างชำระ' ?> <?= money0($owe) ?> บาท</span></div>
          <div class="field"><label>หักชำระยอดค้าง (บาท)</label>
            <input type="number" name="deduct" step="0.01" min="0" max="<?= e($maxDeduct) ?>" value="<?= e($maxDeduct) ?>" inputmode="decimal" <?= $maxDeduct <= 0 ? 'readonly' : '' ?>>
            <div class="hint"><?= $maxDeduct > 0 ? 'หักได้ไม่เกิน ' . money0($maxDeduct) . ' บาท' : 'ไม่มียอดค้าง' ?></div></div>
          <details class="mb-2">
            <summary class="cursor-pointer text-xs text-brand">+ หักค่าใช้จ่ายที่พบหลังคืนรถ (ค่าปรับจราจร ค่าซ่อม)</summary>
            <div class="chg-row chg-other !border-t-0">
              <input type="text" name="add_label" maxlength="120" placeholder="ค่าอะไร เช่น ใบสั่งความเร็ว 12 ก.ย." aria-label="รายการค่าใช้จ่ายที่หักเพิ่ม">
              <input type="number" name="add_amount" class="chg-amt" step="0.01" min="0" max="<?= e($held) ?>" placeholder="0" inputmode="numeric" aria-label="จำนวนเงินที่หักเพิ่ม">
            </div>
            <p class="mb-0 text-xs text-muted">ระบบบันทึกเป็นค่าใช้จ่ายเพิ่มของการจองนี้ แล้วหักจากเงินประกัน</p>
          </details>
          <div class="chg-total"><span>คืนลูกค้า</span><b><span id="secRefund"><?= money0($held - $maxDeduct) ?></span> บาท</b></div>
          <div class="field mt-1"><label>คืนเงินทาง</label>
            <select name="refund_method">
              <option value="cash">เงินสด</option>
              <option value="transfer">โอนธนาคาร</option>
              <option value="promptpay">PromptPay</option>
            </select></div>
          <div class="field"><label>หมายเหตุ</label>
            <input type="text" name="refund_note" maxlength="200" placeholder="เช่น โอนเข้าบัญชีลงท้าย 1234"></div>
          <button class="btn btn-accent btn-block">ปิดเงินประกัน</button>
        </form>
      <?php endif; ?>

      <?php if ($b['status'] === 'returned'):
          $warn = [];
          if ($balance > 0.009) {
              $warn[] = 'ยังมียอดค้าง ' . money($balance) . ' บาท';
          }
          if ($held > 0) {
              $warn[] = 'ยังถือเงินประกัน ' . money($held) . ' บาท ที่ยังไม่ได้คืนลูกค้า';
          } ?>
        <form method="post" action="<?= $act ?>" class="mb-3">
          <?= csrf_field() ?><input type="hidden" name="act" value="complete">
          <button class="btn btn-block" <?= $warn ? 'onclick="return confirm(\'' . e(implode('\\n', $warn)) . '\\n\\nต้องการปิดงานเลยหรือไม่?\')"' : '' ?>>
            🏁 ปิดงาน
          </button>
        </form>
      <?php endif; ?>

      <?php if (in_array($b['status'], ['pending', 'confirmed'], true)): ?>
        <details>
          <summary class="btn btn-ghost btn-block text-danger <?= $summaryBtn ?>">ยกเลิกการจอง</summary>
          <form method="post" action="<?= $act ?>" class="mt-3" onsubmit="return confirm('ยืนยันยกเลิกการจองนี้?')">
            <?= csrf_field() ?><input type="hidden" name="act" value="cancel">
            <div class="field"><label>เหตุผล</label><input type="text" name="cancel_reason" maxlength="200" required></div>
            <button class="btn btn-danger btn-block">ยืนยันยกเลิก</button>
          </form>
        </details>
      <?php elseif (in_array($b['status'], ['completed', 'cancelled'], true) && $held <= 0): ?>
        <p class="mb-0 text-xs text-muted">งานนี้ปิดแล้ว ไม่มีรายการที่ต้องทำต่อ</p>
      <?php endif; ?>
    </div>

    <!-- ---------- ลายเซ็นลูกค้า ---------- -->
    <div class="panel mt-4" id="signatures">
      <h3 class="text-sm">ลายเซ็นลูกค้า</h3>
      <?php foreach (\App\Services\SignatureService::kinds() as $sk => $kdef):
          $sname = $kdef[0];
          $sg    = $sigs[$sk];
          $blk   = $sg ? null : \App\Services\SignatureService::blocker($b, $sk); ?>
        <div class="sig-item">
          <div class="sig-head"><b><?= e($sname) ?></b>
            <?php if ($sg): ?><span class="badge badge-ok">ลงนามแล้ว</span>
            <?php elseif ($blk === null): ?><span class="badge badge-warn">รอลงนาม</span>
            <?php else: ?><span class="badge badge-muted">ยังไม่ถึงขั้นตอน</span><?php endif; ?></div>

          <?php if ($sg):
              $chg = \App\Services\SignatureService::changes($sg, $b, $car); ?>
            <?php $img = \App\Services\PrivateFileService::dataUri($sg['file_path']); if ($img !== ''): ?>
              <img class="sig-img" src="<?= e($img) ?>" alt="ลายเซ็นของ <?= e($sg['signer_name']) ?>">
            <?php endif; ?>
            <p class="mb-0 text-xs text-muted"><?= e($sg['signer_name']) ?> · <?= e(thai_date($sg['signed_at'], true)) ?><br>
              รหัสอ้างอิง <?= e(\App\Services\SignatureService::ref($sg)) ?> · IP <?= e($sg['ip'] ?: '-') ?></p>
            <?php if (!\App\Services\SignatureService::intact($sg)): ?>
              <div class="alert alert-danger mt-1.5 text-xs">สำเนาข้อมูลที่เก็บไว้กับลายเซ็นไม่ตรงกับรหัสตรวจสอบ — ข้อมูลอาจถูกแก้ในฐานข้อมูลโดยตรง</div>
            <?php endif; ?>
            <?php if ($chg): ?>
              <div class="alert alert-warn mt-1.5 text-xs"><span><b>ข้อมูลถูกแก้หลังลูกค้าลงนาม</b>
                <?php foreach ($chg as $x): ?>
                  <span class="block">• <?= e($x['label']) ?>: <?= e($x['signed']) ?> → <?= e($x['now']) ?></span>
                <?php endforeach; ?>
                <span class="mt-1 block">ควรให้ลูกค้าเซ็นฉบับใหม่เพื่อรับรองข้อมูลล่าสุด</span></span></div>
            <?php endif; ?>
            <?php if (is_admin() && \App\Services\SignatureService::blocker($b, $sk) === null): ?>
              <details class="mt-1.5">
                <summary class="cursor-pointer text-xs text-brand">ขอให้ลูกค้าเซ็นใหม่</summary>
                <form method="post" action="<?= $act ?>" class="mt-1.5" onsubmit="return confirm('ยกเลิกลายเซ็นฉบับนี้เพื่อให้ลูกค้าเซ็นใหม่?\nฉบับเดิมยังเก็บไว้เป็นประวัติ')">
                  <?= csrf_field() ?><input type="hidden" name="act" value="sign_void"><input type="hidden" name="kind" value="<?= e($sk) ?>">
                  <div class="field"><label>เหตุผล</label><input type="text" name="void_reason" maxlength="200" required placeholder="เช่น เลื่อนวันคืนรถ"></div>
                  <button class="btn btn-ghost btn-sm btn-block">ยกเลิกฉบับนี้ ให้เซ็นใหม่</button>
                </form>
              </details>
            <?php endif; ?>

          <?php elseif ($blk === null): $link = \App\Services\SignatureService::url($b, $sk); ?>
            <a class="btn btn-accent btn-block btn-sm" href="<?= e($link) ?>" target="_blank" rel="noopener">✍️ ให้ลูกค้าเซ็นบนเครื่องนี้</a>
            <div class="sig-copy mt-1.5">
              <input type="text" readonly value="<?= e($link) ?>" id="signLink_<?= e($sk) ?>" aria-label="ลิงก์เซ็น<?= e($sname) ?>" onfocus="this.select()">
              <button type="button" class="btn btn-ghost btn-sm" data-copy="signLink_<?= e($sk) ?>">คัดลอกลิงก์</button>
            </div>
            <?php if ($hasLine): ?>
              <form method="post" action="<?= $act ?>" class="mt-1.5">
                <?= csrf_field() ?><input type="hidden" name="act" value="sign_send"><input type="hidden" name="kind" value="<?= e($sk) ?>">
                <button class="btn btn-ghost btn-sm btn-block">ส่งลิงก์ให้ลูกค้าทาง LINE</button>
              </form>
            <?php else: ?>
              <p class="mb-0 mt-1.5 text-xs text-muted">ลูกค้ายังไม่ผูก LINE — คัดลอกลิงก์ส่งทางแชตหรือ SMS ได้</p>
            <?php endif; ?>
          <?php else: ?>
            <p class="mb-0 text-xs text-muted"><?= $sk === 'return' && in_array($b['status'], ['pending', 'confirmed', 'picked_up'], true)
                ? 'เซ็นได้หลังบันทึกรับคืนรถ' : 'ไม่ได้ลงนามในระบบ' ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($sigVoided): ?>
        <details class="mt-1.5">
          <summary class="cursor-pointer text-xs text-muted">ประวัติ: ฉบับที่ถูกยกเลิก <?= count($sigVoided) ?> ฉบับ</summary>
          <?php foreach ($sigVoided as $vs): ?>
            <p class="mb-0 mt-1.5 text-xs text-muted"><?= e(\App\Services\SignatureService::kinds()[$vs['kind']][0] ?? $vs['kind']) ?> · เซ็น <?= e(thai_date($vs['signed_at'], true)) ?>
              · ยกเลิก <?= e(thai_date($vs['voided_at'], true)) ?><?= $vs['voider'] ? ' โดย ' . e($vs['voider']) : '' ?>
              <?= $vs['void_reason'] ? ' — ' . e($vs['void_reason']) : '' ?></p>
          <?php endforeach; ?>
        </details>
      <?php endif; ?>
    </div>

    <div class="panel mt-4">
      <h3 class="text-sm">หมายเหตุภายใน</h3>
      <form method="post" action="<?= $act ?>">
        <?= csrf_field() ?><input type="hidden" name="act" value="note">
        <div class="field"><textarea name="note" maxlength="1000"><?= e($b['note']) ?></textarea></div>
        <button class="btn btn-ghost btn-sm btn-block">บันทึก</button>
      </form>
    </div>

    <?php if ($b['mileage_out'] || $b['mileage_in']): ?>
      <div class="panel mt-4">
        <h3 class="text-sm">บันทึกรับ–คืนรถ</h3>
        <table class="table">
          <tbody>
            <tr><th>ไมล์ออก</th><td><?= $b['mileage_out'] ? number_format((int) $b['mileage_out']) . ' กม.' : '-' ?></td></tr>
            <tr><th>ไมล์เข้า</th><td><?= $b['mileage_in'] ? number_format((int) $b['mileage_in']) . ' กม.' : '-' ?></td></tr>
            <tr><th>ระยะทางที่ใช้</th><td><?= ($b['mileage_in'] && $b['mileage_out']) ? number_format((int) $b['mileage_in'] - (int) $b['mileage_out']) . ' กม.' : '-' ?></td></tr>
            <tr><th>น้ำมัน ออก/เข้า</th><td><?= $b['fuel_out'] !== null ? (int) $b['fuel_out'] . '/8' : '-' ?> → <?= $b['fuel_in'] !== null ? (int) $b['fuel_in'] . '/8' : '-' ?></td></tr>
            <?php if ($b['damage_note']): ?><tr><th>ความเสียหาย</th><td><?= nl2br(e($b['damage_note'])) ?></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php $this->start('after_main'); ?>
<script>
(function () {
  var fmt = function (n) { return Math.round(n).toLocaleString('en-US'); };
  var debounce = function (fn, ms) { var t; return function () { clearTimeout(t); t = setTimeout(fn, ms); }; };

  /* ---------- รับคืนรถ: ค่าใช้จ่ายเพิ่มที่ระบบคิดให้ ---------- */
  var rf = document.getElementById('returnForm');
  if (rf) {
    var box = document.getElementById('chgLines'), none = document.getElementById('chgNone');
    var state = {};   // key → {on, amount, suggested} จำสิ่งที่พนักงานแก้ไว้ระหว่างที่ระบบคำนวณใหม่
    var total = function () {
      var sum = 0;
      box.querySelectorAll('.chg-row').forEach(function (r) {
        var on = r.querySelector('input[type=checkbox]').checked, a = r.querySelector('.chg-amt');
        a.readOnly = !on; r.classList.toggle('off', !on);
        if (on) sum += parseFloat(a.value) || 0;
        state[r.dataset.key] = { on: on, amount: a.value, suggested: parseFloat(r.dataset.suggested) };
      });
      sum += parseFloat(rf.other_amount.value) || 0;
      document.getElementById('chgTotal').textContent = fmt(sum);
      document.getElementById('chgDue').textContent = fmt(Math.max(0, parseFloat(rf.dataset.balance) + sum));
    };
    var render = function (lines) {
      box.textContent = '';
      lines.forEach(function (l) {
        var st = state[l.key], keep = st && st.suggested === l.amount;   // ยอดแนะนำเปลี่ยน → ใช้ยอดใหม่ แต่คงการติ๊กไว้
        var row = document.createElement('div');
        row.className = 'chg-row'; row.dataset.key = l.key; row.dataset.suggested = l.amount;
        var seen = document.createElement('input'); seen.type = 'hidden'; seen.name = 'chg[' + l.key + '][seen]'; seen.value = '1';
        var lab = document.createElement('label'); lab.className = 'chg-main';
        var cb = document.createElement('input'); cb.type = 'checkbox'; cb.name = 'chg[' + l.key + '][on]'; cb.value = '1';
        cb.checked = st ? st.on : true;
        var tx = document.createElement('span'), b = document.createElement('b'), d = document.createElement('span');
        b.textContent = l.label; d.className = 'text-xs text-muted'; d.textContent = l.detail;
        tx.appendChild(b); tx.appendChild(d); lab.appendChild(cb); lab.appendChild(tx);
        var amt = document.createElement('input'); amt.type = 'number'; amt.className = 'chg-amt'; amt.min = '0'; amt.step = '0.01';
        amt.name = 'chg[' + l.key + '][amount]'; amt.inputMode = 'numeric'; amt.setAttribute('aria-label', 'จำนวนเงิน ' + l.label);
        amt.value = keep ? st.amount : l.amount;
        row.appendChild(seen); row.appendChild(lab); row.appendChild(amt); box.appendChild(row);
      });
      none.hidden = lines.length > 0;
      total();
    };
    var load = function () {
      var u = rf.dataset.url + '?km=' + encodeURIComponent(rf.mileage_in.value) + '&fuel=' + encodeURIComponent(rf.fuel_in.value)
            + '&at=' + encodeURIComponent(rf.rt_manual.value ? rf.returned_at.value : '');
      fetch(u, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) render(j.lines || []); })
        .catch(function () { /* คำนวณไม่ได้ก็ยังบันทึกได้ — ฝั่งเซิร์ฟเวอร์คิดซ้ำตอนบันทึก */ });
    };
    /* เวลารับคืน: พนักงานแก้เอง = ใช้ค่านั้น · ไม่แตะ = เดินตามนาฬิกา และตอนบันทึกเซิร์ฟเวอร์ใช้เวลาจริง */
    var pad2 = function (n) { return (n < 10 ? '0' : '') + n; };
    rf.returned_at.addEventListener('input', function () { rf.rt_manual.value = '1'; });
    setInterval(function () {
      if (rf.rt_manual.value) return;
      var d = new Date();
      rf.returned_at.value = d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
      load();
    }, 60000);
    var later = debounce(load, 350);
    [rf.mileage_in, rf.fuel_in, rf.returned_at].forEach(function (el) { el.addEventListener('input', later); el.addEventListener('change', later); });
    rf.addEventListener('input', function (e) { if (e.target.closest('.chg-box')) total(); });
    rf.addEventListener('change', function (e) { if (e.target.closest('.chg-box')) total(); });
    load();
  }

  /* ---------- เช่าต่อ ---------- */
  var ef = document.getElementById('extendForm');
  if (ef) {
    var msg = document.getElementById('extMsg'), rows = document.getElementById('extRows'),
        btn = document.getElementById('extBtn'), cf = document.getElementById('extChargeField');
    var quote = function () {
      btn.disabled = true;
      if (!ef.new_return.value) { msg.hidden = false; msg.textContent = 'เลือกวัน–เวลาคืนรถใหม่'; rows.hidden = true; cf.hidden = true; return; }
      fetch(ef.dataset.url + '?ret=' + encodeURIComponent(ef.new_return.value), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (q) {
          if (!q.ok) { msg.hidden = false; msg.textContent = q.error || 'คำนวณไม่ได้'; msg.className = 'mb-0 text-xs text-danger'; rows.hidden = true; cf.hidden = true; return; }
          msg.hidden = true; rows.hidden = false; cf.hidden = false; msg.className = 'mb-0 text-xs text-muted';
          document.getElementById('extDays').textContent = q.days_old + ' → ' + q.days_new + ' วัน';
          document.getElementById('extRental').textContent = fmt(q.rental_delta);
          document.getElementById('extAddon').textContent = fmt(q.addon_delta);
          document.getElementById('extAddonRow').hidden = !(q.addon_delta > 0);
          document.getElementById('extVat').textContent = fmt(q.vat);
          document.getElementById('extVatRow').hidden = !(q.vat > 0);
          document.getElementById('extCharge').textContent = fmt(q.charge);
          var w = document.getElementById('extWarn');
          w.hidden = !q.warning; w.textContent = q.warning ? 'นอกเวลาทำการ: ' + q.warning + ' — บันทึกได้ถ้าตกลงกับลูกค้าแล้ว' : '';
          ef.charge.max = q.charge; ef.charge.value = q.charge;
          btn.disabled = false;
        })
        .catch(function () { msg.hidden = false; msg.textContent = 'เชื่อมต่อไม่ได้ ลองใหม่อีกครั้ง'; rows.hidden = true; });
    };
    var once = false;
    ef.closest('details').addEventListener('toggle', function () { if (this.open && !once) { once = true; quote(); } });
    ef.new_return.addEventListener('change', quote);
    ef.addEventListener('submit', function (e) {
      if (!confirm('ยืนยันเลื่อนกำหนดคืนรถ?\nยอดค่าเช่าของการจองนี้จะเพิ่มขึ้น ' + fmt(parseFloat(ef.charge.value) || 0) + ' บาท')) e.preventDefault();
    });
  }

  /* ---------- คัดลอกลิงก์เซ็นเอกสาร ---------- */
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.getElementById(b.dataset.copy), t = b.textContent;
      var done = function () { b.textContent = 'คัดลอกแล้ว'; setTimeout(function () { b.textContent = t; }, 1600); };
      el.select();
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(el.value).then(done, function () {});
      else { try { document.execCommand('copy'); done(); } catch (e) {} }
    });
  });

  /* ---------- ปิดเงินประกัน ---------- */
  var sf = document.getElementById('secForm');
  if (sf) {
    var secCalc = function () {
      var held = parseFloat(sf.dataset.held), owe = parseFloat(sf.dataset.owe) || 0;
      var add = Math.max(0, Math.min(held, parseFloat(sf.add_amount.value) || 0));
      var d = Math.max(0, Math.min(held, owe + add, (parseFloat(sf.deduct.value) || 0) + add));
      document.getElementById('secRefund').textContent = fmt(held - d);
    };
    sf.deduct.addEventListener('input', secCalc);
    sf.add_amount.addEventListener('input', secCalc);
  }
})();
</script>
<?php $this->end(); ?>
