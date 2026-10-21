<?php
/**
 * เพิ่ม/แก้ไขลูกค้า + เอกสารแนบ (สำเนาบัตร/ใบขับขี่) + ประวัติการเช่า
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/private_files.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

// ไฟล์ใหญ่เกิน post_max_size → PHP ทิ้ง $_POST ทั้งก้อน รวมทั้ง CSRF token
// ต้องดักก่อน ไม่งั้นหน้าจะเด้งออกแบบไม่มีข้อความบอกว่าเกิดอะไรขึ้น
if (post_too_large()) {
    flash('danger', 'ไฟล์ที่อัปโหลดใหญ่เกินไป (เซิร์ฟเวอร์รับได้ไม่เกิน '
        . round(upload_limit_bytes() / 1048576, 1) . ' MB) กรุณาย่อรูปก่อนอัปโหลด');
    redirect(safe_referer_path('admin/dashboard.php'));
}

$id = (int) ($_GET['id'] ?? 0);
$c  = $id ? db_one('SELECT * FROM customers WHERE id = :i', [':i' => $id]) : null;
if ($id && !$c) {
    flash('danger', 'ไม่พบลูกค้า');
    redirect('admin/customers.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $data = [
            'type'           => ($_POST['type'] ?? '') === 'company' ? 'company' : 'individual',
            'full_name'      => trim($_POST['full_name'] ?? ''),
            'tax_id'         => trim($_POST['tax_id'] ?? '') ?: null,
            'phone'          => trim($_POST['phone'] ?? ''),
            'email'          => trim($_POST['email'] ?? '') ?: null,
            'line_id'        => trim($_POST['line_id'] ?? '') ?: null,
            'address'        => trim($_POST['address'] ?? '') ?: null,
            'license_no'     => trim($_POST['license_no'] ?? '') ?: null,
            'license_expire' => trim($_POST['license_expire'] ?? '') ?: null,
            'is_blacklist'   => isset($_POST['is_blacklist']) ? 1 : 0,
            'blacklist_note' => trim($_POST['blacklist_note'] ?? '') ?: null,
            'note'           => trim($_POST['note'] ?? '') ?: null,
        ];
        if ($data['full_name'] === '' || $data['phone'] === '') {
            throw new RuntimeException('กรุณากรอกชื่อและเบอร์โทร');
        }
        // เอกสารแนบเก็บในที่เก็บแบบปิด (storage/private) — เปิดผ่านลิงก์ตรงไม่ได้ ต้องเปิดผ่านหลังบ้านเท่านั้น
        // รับไฟล์ก่อนบันทึกข้อมูล: ถ้าไฟล์ไม่ผ่าน (ชนิดผิด/ใหญ่เกิน) จะได้ไม่บันทึกครึ่งๆ กลางๆ
        $doc = private_store_upload($_FILES['doc_file'] ?? [], 'docs', 8);
        $docKind = isset(customer_doc_kinds()[$_POST['doc_kind'] ?? '']) ? (string) $_POST['doc_kind'] : 'id_card';

        if ($id) {
            db_update('customers', $data, 'id', $id);
            log_activity('customer_updated', 'customers', $id);
            flash('ok', 'บันทึกข้อมูลลูกค้าแล้ว');
        } else {
            $data['code'] = next_customer_code();
            $id = db_insert('customers', $data);
            log_activity('customer_created', 'customers', $id);
            flash('ok', 'เพิ่มลูกค้า ' . $data['code'] . ' แล้ว');
        }
        if ($doc) {
            $did = db_insert('customer_documents', [
                'customer_id' => $id, 'doc_kind' => $docKind, 'file_path' => $doc['path'], 'mime' => $doc['mime'],
                'file_size' => $doc['size'], 'note' => trim((string) ($_POST['doc_note'] ?? '')) ?: null,
                'uploaded_by' => auth_user()['id'] ?? null,
            ]);
            log_activity('document_uploaded', 'customer_documents', $did, ['customer_id' => $id, 'kind' => $docKind]);
            flash('ok', 'แนบ' . customer_doc_kinds()[$docKind] . 'แล้ว (เก็บในที่เก็บแบบปิด)');
        }
        redirect('admin/customer-edit.php?id=' . $id);
    } catch (Throwable $ex) {
        flash('danger', $ex->getMessage());
    }
}

$v = $c ?: [];
$history = $id ? db_all(
    "SELECT b.*, ca.brand, ca.model, ca.plate FROM bookings b JOIN cars ca ON ca.id = b.car_id
     WHERE b.customer_id = :c ORDER BY b.pickup_at DESC LIMIT 50", [':c' => $id]) : [];
$cdocs = $id ? db_all('SELECT d.*, u.name AS uploader FROM customer_documents d LEFT JOIN users u ON u.id = d.uploaded_by
                       WHERE d.customer_id = :c ORDER BY d.id DESC', [':c' => $id]) : [];
legacy_docs_protect();
$spend = $id ? (float) db_val("SELECT COALESCE(SUM(grand_total + extra_charge),0) FROM bookings WHERE customer_id = :c AND status <> 'cancelled'", [':c' => $id]) : 0;

admin_head($id ? 'ลูกค้า ' . ($c['code'] ?: $c['full_name']) : 'เพิ่มลูกค้า', 'customers.php');
?>
<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="split">
    <div>
      <div class="panel">
        <h3>ข้อมูลลูกค้า</h3>
        <div class="g2">
          <div class="field"><label>ประเภท</label>
            <select name="type">
              <option value="individual" <?= ($v['type'] ?? '') === 'individual' ? 'selected' : '' ?>>บุคคลธรรมดา</option>
              <option value="company"    <?= ($v['type'] ?? '') === 'company' ? 'selected' : '' ?>>นิติบุคคล</option>
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
        <div class="panel mt-2">
          <h3>ประวัติการเช่า (<?= count($history) ?> ครั้ง · รวม ฿<?= money0($spend) ?>)</h3>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>เลขที่</th><th>รถ</th><th>ช่วงเช่า</th><th class="num">ยอด</th><th>สถานะ</th></tr></thead>
              <tbody>
                <?php foreach ($history as $h): [$l, $cl] = booking_status_label($h['status']); ?>
                  <tr>
                    <td><a href="<?= url('admin/booking-view.php?id=' . (int) $h['id']) ?>"><?= e($h['booking_no']) ?></a></td>
                    <td class="small"><?= e($h['brand'] . ' ' . $h['model']) ?><div class="tiny muted"><?= e($h['plate']) ?></div></td>
                    <td class="small"><?= e(thai_date($h['pickup_at'])) ?> – <?= e(thai_date($h['return_at'])) ?>
                      <div class="tiny muted"><?= (int) $h['rental_days'] ?> วัน</div></td>
                    <td class="num"><?= money0($h['grand_total']) ?></td>
                    <td><span class="badge badge-<?= $cl ?>"><?= e($l) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div>
      <div class="panel">
        <button class="btn btn-accent btn-block"><?= $id ? 'บันทึก' : 'เพิ่มลูกค้า' ?></button>
        <a class="btn btn-ghost btn-block mt-1" href="<?= url('admin/customers.php') ?>">กลับรายการ</a>
        <?php if ($id): ?>
          <a class="btn btn-ghost btn-block mt-1" href="<?= url('admin/booking-edit.php') ?>">+ สร้างการจองให้ลูกค้านี้</a>
        <?php endif; ?>
      </div>

      <div class="panel mt-2">
        <h3>เอกสารแนบ</h3>
        <?php $dk = customer_doc_kinds(); ?>
        <?php if ($cdocs || !empty($v['id_card_file']) || !empty($v['license_file'])): ?>
          <ul class="doc-list">
            <?php foreach ($cdocs as $d): ?>
              <li><span><b><?= e($dk[$d['doc_kind']] ?? $d['doc_kind']) ?></b>
                  <span class="tiny muted" style="display:block"><?= e(thai_date($d['created_at'], true)) ?><?= $d['uploader'] ? ' · ' . e($d['uploader']) : '' ?><?= $d['note'] ? ' · ' . e($d['note']) : '' ?></span></span>
                <a class="btn btn-ghost btn-sm" href="<?= url('admin/doc-file.php?id=' . (int) $d['id']) ?>" target="_blank" rel="noopener">เปิดดู</a></li>
            <?php endforeach; ?>
            <?php foreach (['id_card' => 'id_card_file', 'license' => 'license_file'] as $lk => $lc): if (empty($v[$lc])) continue; ?>
              <li><span><b><?= $lk === 'id_card' ? 'บัตรประชาชน' : 'ใบขับขี่' ?></b>
                  <span class="tiny muted" style="display:block">ไฟล์เดิม (ก่อนเวอร์ชัน 5)</span></span>
                <a class="btn btn-ghost btn-sm" href="<?= url('admin/doc-file.php?customer=' . $id . '&legacy=' . $lk) ?>" target="_blank" rel="noopener">เปิดดู</a></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="small muted">ยังไม่มีเอกสารแนบ</p>
        <?php endif; ?>
        <div class="field"><label>แนบเอกสารใหม่</label>
          <select name="doc_kind">
            <?php foreach ($dk as $k => $lb): ?><option value="<?= e($k) ?>"><?= e($lb) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><input type="file" name="doc_file" accept="image/*,application/pdf" aria-label="ไฟล์เอกสาร" data-shrink data-limit="<?= upload_limit_bytes() ?>">
          <div class="hint">ถ่ายรูปจากมือถือได้เลย · JPG PNG PDF · กด "บันทึก" เพื่อแนบ</div></div>
        <div class="field"><label>หมายเหตุเอกสาร</label><input type="text" name="doc_note" maxlength="200" placeholder="ไม่บังคับ"></div>
        <p class="tiny muted mb-0">เอกสารเก็บในที่เก็บแบบปิด เปิดดูได้เฉพาะผู้ใช้หลังบ้าน และระบบบันทึกทุกครั้งว่าใครเปิดดู
          เก็บเท่าที่จำเป็นต่อการทำสัญญาเช่า ตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล</p>
      </div>

      <div class="panel mt-2" style="border-color:var(--danger)">
        <h3 class="small" style="color:var(--danger)">Blacklist</h3>
        <label class="checkline">
          <input type="checkbox" name="is_blacklist" value="1" <?= (int) ($v['is_blacklist'] ?? 0) ? 'checked' : '' ?>>
          <span class="small">ห้ามจองออนไลน์ / ต้องให้เจ้าหน้าที่อนุมัติก่อน</span>
        </label>
        <div class="field"><label>เหตุผล</label>
          <textarea name="blacklist_note"><?= e($v['blacklist_note'] ?? '') ?></textarea></div>
      </div>
    </div>
  </div>
</form>
<?php admin_foot(); ?>
