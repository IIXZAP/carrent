<?php
/** เพิ่ม/แก้ไขลูกค้า + ประวัติการเช่า + เอกสารแนบ: $c $id $v $errors $history $cdocs $docKinds */
use App\Services\BookingStatus;
$err = static fn(string $k) => isset($errors[$k]) ? '<p class="mt-1 text-sm text-danger">' . e($errors[$k]) . '</p>' : '';
$bad = static fn(string $k) => isset($errors[$k]) ? ' class="field-error"' : '';
?>
<p class="mb-3 text-sm"><a href="<?= e(url('/admin/customers')) ?>">← ลูกค้าทั้งหมด</a></p>
<div class="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
  <div class="min-w-0 space-y-4">
    <form id="customer-form" method="post" action="<?= e(url($id ? '/admin/customers/' . $id : '/admin/customers')) ?>" class="panel">
      <?= csrf_field() ?>
      <h3>ข้อมูลลูกค้า<?= $id ? ' <span class="text-sm font-normal text-muted">' . e($c['code']) . '</span>' : '' ?></h3>
      <div class="grid gap-x-3.5 sm:grid-cols-2">
        <div class="field"><label for="type">ประเภท</label>
          <select id="type" name="type">
            <?php foreach (\App\Services\CustomerService::TYPES as $k => $label): ?>
              <option value="<?= e($k) ?>"<?= selected(($v['type'] ?? 'individual') === $k) ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select><?= $err('type') ?></div>
        <div class="field"><label for="tax_id">เลขผู้เสียภาษี (นิติบุคคล)</label>
          <input type="text" id="tax_id" name="tax_id" maxlength="20"<?= $bad('tax_id') ?> value="<?= e($v['tax_id'] ?? '') ?>"><?= $err('tax_id') ?></div>
        <div class="field"><label for="full_name">ชื่อ–สกุล / ชื่อบริษัท *</label>
          <input type="text" id="full_name" name="full_name" required<?= $bad('full_name') ?> value="<?= e($v['full_name'] ?? '') ?>"><?= $err('full_name') ?></div>
        <div class="field"><label for="phone">เบอร์โทร *</label>
          <input type="tel" id="phone" name="phone" required<?= $bad('phone') ?> value="<?= e($v['phone'] ?? '') ?>"><?= $err('phone') ?></div>
        <div class="field"><label for="email">อีเมล</label>
          <input type="email" id="email" name="email"<?= $bad('email') ?> value="<?= e($v['email'] ?? '') ?>"><?= $err('email') ?></div>
        <div class="field"><label for="line_id">LINE ID</label>
          <input type="text" id="line_id" name="line_id"<?= $bad('line_id') ?> value="<?= e($v['line_id'] ?? '') ?>"><?= $err('line_id') ?></div>
        <div class="field"><label for="license_no">เลขใบขับขี่</label>
          <input type="text" id="license_no" name="license_no"<?= $bad('license_no') ?> value="<?= e($v['license_no'] ?? '') ?>"><?= $err('license_no') ?></div>
        <div class="field"><label for="license_expire">ใบขับขี่หมดอายุ</label>
          <input type="date" id="license_expire" name="license_expire"<?= $bad('license_expire') ?> value="<?= e($v['license_expire'] ?? '') ?>"><?= $err('license_expire') ?></div>
      </div>
      <div class="field"><label for="address">ที่อยู่ (สำหรับออกใบกำกับภาษี)</label>
        <textarea id="address" name="address"><?= e($v['address'] ?? '') ?></textarea><?= $err('address') ?></div>
      <div class="field"><label for="note">หมายเหตุภายใน</label>
        <textarea id="note" name="note"><?= e($v['note'] ?? '') ?></textarea><?= $err('note') ?></div>
    </form>

    <?php if ($history): ?>
      <div class="panel">
        <h3>ประวัติการเช่า (<?= count($history) ?> ใบ)</h3>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>เลขที่</th><th>รถ</th><th>ช่วงเช่า</th><th class="num">ยอด</th><th>สถานะ</th></tr></thead>
            <tbody>
              <?php foreach ($history as $h): ?>
                <tr>
                  <td><a href="<?= e(url('/admin/bookings/' . (int) $h['id'])) ?>"><?= e($h['booking_no']) ?></a></td>
                  <td class="text-sm"><?= e($h['brand'] . ' ' . $h['model']) ?><div class="text-xs text-muted"><?= e($h['plate']) ?></div></td>
                  <td class="text-sm"><?= e(thai_date($h['pickup_at'])) ?> – <?= e(thai_date($h['return_at'])) ?><div class="text-xs text-muted"><?= (int) $h['rental_days'] ?> วัน</div></td>
                  <td class="num"><?= e(money0($h['grand_total'])) ?></td>
                  <td><span class="badge badge-<?= e(status_tone('booking', $h['status'])) ?>"><?= e(BookingStatus::STATUS[$h['status']]) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($id): ?>
    <div class="panel" id="docs">
      <h3>เอกสารแนบ</h3>
      <?php if ($cdocs): ?>
        <ul class="mb-3 list-none p-0">
          <?php foreach ($cdocs as $d): ?>
            <li class="list-row">
              <span class="min-w-0"><b><?= e($docKinds[$d['doc_kind']] ?? $d['doc_kind']) ?></b>
                <span class="block text-xs text-muted"><?= e(thai_date($d['created_at'], true)) ?><?= $d['uploader'] ? ' · ' . e($d['uploader']) : '' ?><?= $d['note'] ? ' · ' . e($d['note']) : '' ?></span></span>
              <span class="flex flex-none gap-1.5">
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/customer-docs/' . (int) $d['id'])) ?>" target="_blank" rel="noopener">เปิดดู</a>
                <?php if (\App\Services\Auth::isAdmin()): ?>
                  <form method="post" action="<?= e(url('/admin/customer-docs/' . (int) $d['id'] . '/delete')) ?>" onsubmit="return confirm('ลบเอกสารนี้ถาวร? (ใช้เมื่อลูกค้าขอลบข้อมูลตาม PDPA)')">
                    <?= csrf_field() ?><button class="btn btn-ghost btn-sm text-danger">ลบ</button>
                  </form>
                <?php endif; ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?><p class="text-sm text-muted">ยังไม่มีเอกสารแนบ</p><?php endif; ?>

      <form method="post" enctype="multipart/form-data" action="<?= e(url('/admin/customers/' . $id . '/documents')) ?>">
        <?= csrf_field() ?>
        <div class="grid gap-x-3.5 sm:grid-cols-2">
          <div class="field"><label for="doc_kind">ชนิดเอกสาร</label>
            <select id="doc_kind" name="doc_kind"><?php foreach ($docKinds as $k => $lb): ?><option value="<?= e($k) ?>"><?= e($lb) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="doc_note">หมายเหตุ</label><input type="text" id="doc_note" name="doc_note" maxlength="200" placeholder="ไม่บังคับ"></div>
        </div>
        <div class="field"><label for="doc_file">ไฟล์ (JPG PNG WEBP PDF ไม่เกิน 8 MB)</label>
          <input type="file" id="doc_file" name="doc_file" accept="image/jpeg,image/png,image/webp,application/pdf" data-shrink required>
          <div class="hint">ถ่ายรูปจากมือถือได้เลย — ระบบย่อรูปให้ก่อนส่ง</div></div>
        <button class="btn btn-sm">แนบเอกสาร</button>
      </form>
      <p class="mb-0 mt-3 text-xs text-muted">เอกสารเก็บในที่เก็บแบบปิด เปิดดูได้เฉพาะผู้ใช้หลังบ้านและบันทึกประวัติการเปิดทุกครั้ง เก็บเท่าที่จำเป็นต่อการทำสัญญาเช่า ตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล</p>
    </div>
    <?php endif; ?>
  </div>

  <div class="min-w-0 space-y-4 lg:sticky lg:top-[78px]">
    <div class="panel">
      <button class="btn btn-accent btn-block" form="customer-form"><?= $id ? 'บันทึก' : 'เพิ่มลูกค้า' ?></button>
      <a class="btn btn-ghost btn-block mt-2" href="<?= e(url('/admin/customers')) ?>">กลับรายการ</a>
      <?php if ($id): ?>
        <a class="btn btn-ghost btn-block mt-2" href="<?= e(url('/admin/bookings/create?customer=' . $id)) ?>">+ สร้างการจองให้ลูกค้านี้</a>
      <?php endif; ?>
    </div>

    <div class="panel border-danger">
      <h3 class="text-sm text-danger">Blacklist</h3>
      <label class="checkline"><input type="checkbox" name="is_blacklist" value="1" form="customer-form"<?= checked((int) ($v['is_blacklist'] ?? 0) === 1) ?>>
        <span class="text-sm">ห้ามจองออนไลน์ / ต้องให้เจ้าหน้าที่อนุมัติก่อน</span></label>
      <div class="field"><label for="blacklist_note">เหตุผล</label>
        <textarea id="blacklist_note" name="blacklist_note" form="customer-form"><?= e($v['blacklist_note'] ?? '') ?></textarea><?= $err('blacklist_note') ?></div>
    </div>

    <?php if ($id && \App\Services\Auth::isAdmin()): ?>
      <form method="post" action="<?= e(url('/admin/customers/' . $id . '/delete')) ?>" class="panel" onsubmit="return confirm('ลบลูกค้ารายนี้? (ประวัติการจองเดิมยังอยู่)')">
        <?= csrf_field() ?>
        <button class="btn btn-ghost btn-sm btn-block text-danger">ลบลูกค้า</button>
      </form>
    <?php endif; ?>
  </div>
</div>
