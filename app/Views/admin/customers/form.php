<?php
/** เพิ่ม/แก้ไขลูกค้า + เอกสารแนบ + ประวัติการเช่า — ตัวแปร: c, id, v, history, cdocs, spend, docKinds, limit */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'customers']);
?>
<form method="post" enctype="multipart/form-data" action="<?= url($id ? 'admin/customers/' . $id . '/edit' : 'admin/customers/new') ?>">
  <?= csrf_field() ?>
  <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
    <div class="min-w-0">
      <div class="panel">
        <h3>ข้อมูลลูกค้า</h3>
        <div class="grid gap-x-3.5 sm:grid-cols-2">
          <div class="field"><label>ประเภท</label>
            <select name="type">
              <option value="individual" <?= selected(($v['type'] ?? 'individual') === 'individual') ?>>บุคคลธรรมดา</option>
              <option value="company"    <?= selected(($v['type'] ?? '') === 'company') ?>>นิติบุคคล</option>
            </select></div>
          <div class="field"><label>เลขผู้เสียภาษี (นิติบุคคล)</label>
            <input type="text" name="tax_id" value="<?= e($v['tax_id'] ?? '') ?>" maxlength="20"></div>
          <div class="field"><label>ชื่อ–สกุล / ชื่อบริษัท *</label>
            <input type="text" name="full_name" value="<?= e($v['full_name'] ?? '') ?>" required></div>
          <div class="field"><label>เบอร์โทร *</label>
            <input type="tel" name="phone" value="<?= e($v['phone'] ?? '') ?>" required></div>
          <div class="field"><label>อีเมล</label>
            <input type="email" name="email" value="<?= e($v['email'] ?? '') ?>"></div>
          <div class="field"><label>LINE ID</label>
            <input type="text" name="line_id" value="<?= e($v['line_id'] ?? '') ?>"></div>
          <div class="field"><label>เลขใบขับขี่</label>
            <input type="text" name="license_no" value="<?= e($v['license_no'] ?? '') ?>"></div>
          <div class="field"><label>ใบขับขี่หมดอายุ</label>
            <input type="date" name="license_expire" value="<?= e($v['license_expire'] ?? '') ?>"></div>
        </div>
        <div class="field"><label>ที่อยู่ (สำหรับออกใบกำกับภาษี)</label>
          <textarea name="address"><?= e($v['address'] ?? '') ?></textarea></div>
        <div class="field"><label>หมายเหตุภายใน</label>
          <textarea name="note"><?= e($v['note'] ?? '') ?></textarea></div>
      </div>

      <?php if ($history): ?>
        <div class="panel mt-4">
          <h3>ประวัติการเช่า (<?= count($history) ?> ครั้ง · รวม ฿<?= money0($spend) ?>)</h3>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>เลขที่</th><th>รถ</th><th>ช่วงเช่า</th><th class="num">ยอด</th><th>สถานะ</th></tr></thead>
              <tbody>
                <?php foreach ($history as $h): [$l, $cl] = booking_status_label($h['status']); ?>
                  <tr>
                    <td><a href="<?= url('admin/bookings/' . (int) $h['id']) ?>"><?= e($h['booking_no']) ?></a></td>
                    <td class="text-sm"><?= e($h['brand'] . ' ' . $h['model']) ?><div class="text-xs text-muted"><?= e($h['plate']) ?></div></td>
                    <td class="text-sm"><?= e(thai_date($h['pickup_at'])) ?> – <?= e(thai_date($h['return_at'])) ?>
                      <div class="text-xs text-muted"><?= (int) $h['rental_days'] ?> วัน</div></td>
                    <td class="num"><?= money0($h['grand_total']) ?></td>
                    <td><span class="badge badge-<?= e($cl) ?>"><?= e($l) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="min-w-0">
      <div class="panel">
        <button class="btn btn-accent btn-block"><?= $id ? 'บันทึก' : 'เพิ่มลูกค้า' ?></button>
        <a class="btn btn-ghost btn-block mt-2" href="<?= url('admin/customers') ?>">กลับรายการ</a>
        <?php if ($id): ?>
          <a class="btn btn-ghost btn-block mt-2" href="<?= url('admin/bookings/new') ?>">+ สร้างการจองให้ลูกค้านี้</a>
        <?php endif; ?>
      </div>

      <div class="panel mt-4">
        <h3>เอกสารแนบ</h3>
        <?php if ($cdocs || !empty($v['id_card_file']) || !empty($v['license_file'])): ?>
          <ul class="mb-3 list-none p-0">
            <?php foreach ($cdocs as $d): ?>
              <li class="list-row">
                <span class="min-w-0"><b><?= e($docKinds[$d['doc_kind']] ?? $d['doc_kind']) ?></b>
                  <span class="block text-xs text-muted"><?= e(thai_date($d['created_at'], true)) ?><?= $d['uploader'] ? ' · ' . e($d['uploader']) : '' ?><?= $d['note'] ? ' · ' . e($d['note']) : '' ?></span></span>
                <a class="btn btn-ghost btn-sm flex-none" href="<?= url('admin/customer-files/' . (int) $d['id']) ?>" target="_blank" rel="noopener">เปิดดู</a></li>
            <?php endforeach; ?>
            <?php foreach (['id_card' => 'id_card_file', 'license' => 'license_file'] as $lk => $lc):
                if (empty($v[$lc])) {
                    continue;
                } ?>
              <li class="list-row">
                <span class="min-w-0"><b><?= $lk === 'id_card' ? 'บัตรประชาชน' : 'ใบขับขี่' ?></b>
                  <span class="block text-xs text-muted">ไฟล์เดิม (ก่อนเวอร์ชัน 5)</span></span>
                <a class="btn btn-ghost btn-sm flex-none" href="<?= url('admin/customers/' . $id . '/legacy/' . $lk) ?>" target="_blank" rel="noopener">เปิดดู</a></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="text-sm text-muted">ยังไม่มีเอกสารแนบ</p>
        <?php endif; ?>
        <div class="field"><label>แนบเอกสารใหม่</label>
          <select name="doc_kind">
            <?php foreach ($docKinds as $k => $lb): ?><option value="<?= e($k) ?>"><?= e($lb) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><input type="file" name="doc_file" accept="image/*,application/pdf" aria-label="ไฟล์เอกสาร" data-shrink data-limit="<?= (int) $limit ?>">
          <div class="hint">ถ่ายรูปจากมือถือได้เลย · JPG PNG PDF · กด "บันทึก" เพื่อแนบ</div></div>
        <div class="field"><label>หมายเหตุเอกสาร</label><input type="text" name="doc_note" maxlength="200" placeholder="ไม่บังคับ"></div>
        <p class="mb-0 text-xs text-muted">เอกสารเก็บในที่เก็บแบบปิด เปิดดูได้เฉพาะผู้ใช้หลังบ้าน และระบบบันทึกทุกครั้งว่าใครเปิดดู
          เก็บเท่าที่จำเป็นต่อการทำสัญญาเช่า ตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล</p>
      </div>

      <div class="panel mt-4 border-danger">
        <h3 class="text-sm text-danger">Blacklist</h3>
        <label class="checkline">
          <input type="checkbox" name="is_blacklist" value="1" <?= checked((int) ($v['is_blacklist'] ?? 0)) ?>>
          <span class="text-sm">ห้ามจองออนไลน์ / ต้องให้เจ้าหน้าที่อนุมัติก่อน</span>
        </label>
        <div class="field"><label>เหตุผล</label>
          <textarea name="blacklist_note"><?= e($v['blacklist_note'] ?? '') ?></textarea></div>
      </div>
    </div>
  </div>
</form>
