<?php
/**
 * ออก/แก้ไขเอกสาร — ดึงรายการจากการจองอัตโนมัติ แก้ไขเพิ่มได้
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

$id        = (int) ($_GET['id'] ?? 0);
$bookingId = (int) ($_GET['booking_id'] ?? 0);
$type      = $_GET['type'] ?? 'quotation';
$type      = in_array($type, ['quotation','invoice','receipt','contract'], true) ? $type : 'quotation';

$doc = $id ? db_one('SELECT * FROM documents WHERE id = :i', [':i' => $id]) : null;
if ($id && !$doc) {
    flash('danger', 'ไม่พบเอกสาร');
    redirect('admin/documents.php');
}
if ($doc) {
    $type      = $doc['doc_type'];
    $bookingId = (int) $doc['booking_id'];
}

// ---------- บันทึก ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $items = [];
        foreach ((array) ($_POST['desc'] ?? []) as $i => $d) {
            $d = trim((string) $d);
            if ($d === '') continue;
            $qty   = (float) ($_POST['qty'][$i] ?? 1);
            $price = (float) ($_POST['price'][$i] ?? 0);
            $items[] = ['desc' => $d, 'qty' => $qty, 'unit' => trim($_POST['unit'][$i] ?? 'รายการ'),
                        'price' => $price, 'amount' => round($qty * $price, 2)];
        }
        if (!$items) throw new RuntimeException('กรุณาใส่รายการอย่างน้อย 1 บรรทัด');

        $subtotal = array_sum(array_column($items, 'amount'));
        $discount = (float) ($_POST['discount'] ?? 0);
        $net      = max(0, $subtotal - $discount);
        $vatRate  = (float) ($_POST['vat_rate'] ?? 0);
        $vatAmt   = round($net * $vatRate / 100, 2);
        $whtRate  = (float) ($_POST['wht_rate'] ?? 0);
        $whtAmt   = round($net * $whtRate / 100, 2);
        $grand    = round($net + $vatAmt - $whtAmt, 2);

        $data = [
            'doc_type'     => $type,
            'booking_id'   => $bookingId ?: null,
            'customer_id'  => (int) ($_POST['customer_id'] ?? 0) ?: null,
            'issue_date'   => trim($_POST['issue_date'] ?? '') ?: date('Y-m-d'),
            'due_date'     => trim($_POST['due_date'] ?? '') ?: null,
            'bill_name'    => trim($_POST['bill_name'] ?? ''),
            'bill_tax_id'  => trim($_POST['bill_tax_id'] ?? '') ?: null,
            'bill_address' => trim($_POST['bill_address'] ?? '') ?: null,
            'items'        => json_encode($items, JSON_UNESCAPED_UNICODE),
            'subtotal'     => $subtotal,
            'discount'     => $discount,
            'vat_rate'     => $vatRate,
            'vat_amount'   => $vatAmt,
            'wht_rate'     => $whtRate,
            'wht_amount'   => $whtAmt,
            'grand_total'  => $grand,
            'note'         => trim($_POST['note'] ?? '') ?: null,
        ];

        if ($id) {
            db_update('documents', $data, 'id', $id);
            log_activity('document_updated', 'documents', $id);
            flash('ok', 'บันทึกเอกสารแล้ว');
        } else {
            $data['doc_no']     = next_doc_no($type);
            $data['status']     = 'draft';
            $data['created_by'] = auth_user()['id'];
            $id = db_insert('documents', $data);
            log_activity('document_created', 'documents', $id, $data['doc_no']);
            flash('ok', 'สร้างเอกสาร ' . $data['doc_no'] . ' แล้ว');
        }
        redirect('admin/document-print.php?id=' . $id);
    } catch (Throwable $ex) {
        flash('danger', $ex->getMessage());
    }
}

// ---------- เตรียมข้อมูลตั้งต้น ----------
$booking  = $bookingId ? db_one('SELECT b.*, c.brand, c.model, c.plate FROM bookings b
                                 JOIN cars c ON c.id = b.car_id WHERE b.id = :i', [':i' => $bookingId]) : null;
$customer = null;
if ($doc && $doc['customer_id']) {
    $customer = db_one('SELECT * FROM customers WHERE id = :i', [':i' => (int) $doc['customer_id']]);
} elseif ($booking && $booking['customer_id']) {
    $customer = db_one('SELECT * FROM customers WHERE id = :i', [':i' => (int) $booking['customer_id']]);
}

$items = $doc ? (json_decode((string) $doc['items'], true) ?: []) : [];
if (!$items && $booking) {
    // สร้างรายการจากการจองอัตโนมัติ
    $bd = json_decode((string) $booking['rate_breakdown'], true) ?: [];
    $carName = $booking['brand'] . ' ' . $booking['model'] . ' (' . $booking['plate'] . ')';
    foreach (($bd['rental'] ?? []) as $l) {
        $items[] = ['desc' => 'ค่าเช่ารถ ' . $carName . ' — ' . $l['label'], 'qty' => (float) $l['qty'],
                    'unit' => 'หน่วย', 'price' => (float) $l['unit'], 'amount' => (float) $l['amount']];
    }
    foreach (($bd['addons'] ?? []) as $l) {
        $items[] = ['desc' => $l['label'], 'qty' => (float) $l['qty'], 'unit' => 'หน่วย',
                    'price' => (float) $l['unit'], 'amount' => (float) $l['amount']];
    }
    // ค่าใช้จ่ายเพิ่มตอนคืนรถ — แยกบรรทัดตามรายการที่เก็บจริง (รายการที่ยกเว้นไม่ขึ้นในเอกสาร)
    // ยอดค่าใช้จ่ายเพิ่มเป็นยอดที่เก็บจากลูกค้าจริง (รวมภาษีแล้ว) — การจองที่คิด VAT ต้องถอด VAT ออกก่อนลงเป็นรายการ
    // ไม่งั้นเอกสารจะบวก VAT ซ้ำ ทำให้ยอดในใบเสร็จมากกว่ายอดที่เก็บจริง
    $xRate = (float) ($booking['vat_rate'] ?? 0);
    foreach (booking_extra_lines($booking, true) as $l) {
        $net = $xRate > 0 ? round($l['amount'] / (1 + $xRate / 100), 2) : $l['amount'];
        $items[] = ['desc' => trim($l['label'] . ($l['detail'] !== '' ? ' (' . $l['detail'] . ')' : '')), 'qty' => 1,
                    'unit' => 'รายการ', 'price' => $net, 'amount' => $net];
    }
}
if (!$items) {
    $items = [['desc' => '', 'qty' => 1, 'unit' => 'วัน', 'price' => 0, 'amount' => 0]];
}

$v = $doc ?: [
    'issue_date'   => date('Y-m-d'),
    'due_date'     => date('Y-m-d', strtotime('+7 days')),
    'bill_name'    => $customer['full_name'] ?? ($booking['contact_name'] ?? ''),
    'bill_tax_id'  => $customer['tax_id'] ?? '',
    'bill_address' => $customer['address'] ?? '',
    'discount'     => (float) ($booking['discount'] ?? 0),
    'vat_rate'     => (float) ($booking['vat_rate'] ?? (setting('vat_enabled') === '1' ? (float) setting('vat_rate', '7') : 0)),
    'wht_rate'     => 0,
    'note'         => '',
];

$typeLabel = ['quotation'=>'ใบเสนอราคา','invoice'=>'ใบแจ้งหนี้','receipt'=>'ใบเสร็จรับเงิน','contract'=>'สัญญาเช่า'];

admin_head(($id ? 'แก้ไข' : 'ออก') . $typeLabel[$type], 'documents.php');
?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="customer_id" value="<?= (int) ($customer['id'] ?? 0) ?>">
  <div class="split">
    <div>
      <div class="panel">
        <h3><?= e($typeLabel[$type]) ?><?= $doc ? ' ' . e($doc['doc_no']) : '' ?></h3>
        <?php if ($booking): ?>
          <p class="small muted">อ้างอิงการจอง
            <a href="<?= url('admin/booking-view.php?id=' . (int) $booking['id']) ?>"><?= e($booking['booking_no']) ?></a></p>
        <?php endif; ?>
        <div class="g2">
          <div class="field"><label>วันที่เอกสาร</label><input type="date" name="issue_date" value="<?= e($v['issue_date']) ?>"></div>
          <div class="field"><label>ครบกำหนด / ยืนราคาถึง</label><input type="date" name="due_date" value="<?= e($v['due_date']) ?>"></div>
        </div>
        <div class="field"><label>ชื่อผู้ซื้อ/ผู้เช่า *</label><input type="text" name="bill_name" value="<?= e($v['bill_name']) ?>" required></div>
        <div class="g2">
          <div class="field"><label>เลขผู้เสียภาษี</label><input type="text" name="bill_tax_id" value="<?= e($v['bill_tax_id']) ?>"></div>
        </div>
        <div class="field"><label>ที่อยู่</label><textarea name="bill_address"><?= e($v['bill_address']) ?></textarea></div>
      </div>

      <div class="panel mt-2">
        <h3>รายการ</h3>
        <div class="table-wrap">
          <table class="table" id="itemTable">
            <thead><tr><th style="width:46%">รายละเอียด</th><th style="width:12%">จำนวน</th>
                       <th style="width:14%">หน่วย</th><th style="width:16%">ราคา/หน่วย</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <tr>
                  <td><input type="text" name="desc[]" value="<?= e($it['desc']) ?>"></td>
                  <td><input type="number" name="qty[]" step="0.01" value="<?= e($it['qty']) ?>" class="calc"></td>
                  <td><input type="text" name="unit[]" value="<?= e($it['unit'] ?? 'รายการ') ?>"></td>
                  <td><input type="number" name="price[]" step="0.01" value="<?= e($it['price']) ?>" class="calc"></td>
                  <td class="right"><button type="button" class="btn btn-ghost btn-sm del">ลบ</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <button type="button" class="btn btn-ghost btn-sm mt-1" id="addRow">+ เพิ่มบรรทัด</button>
      </div>

      <div class="panel mt-2">
        <div class="field"><label>หมายเหตุท้ายเอกสาร</label>
          <textarea name="note" placeholder="เช่น ราคานี้ยังไม่รวมค่าน้ำมัน / ยืนราคา 7 วัน"><?= e($v['note']) ?></textarea></div>
      </div>
    </div>

    <div>
      <div class="panel" style="position:sticky;top:78px">
        <h3>สรุปยอด</h3>
        <div class="sum mb-2">
          <div class="sum-row"><span>รวมรายการ</span><span id="sSub">0.00</span></div>
          <div class="sum-row"><span>หลังหักส่วนลด</span><span id="sNet">0.00</span></div>
          <div class="sum-row"><span>VAT</span><span id="sVat">0.00</span></div>
          <div class="sum-row"><span>หัก ณ ที่จ่าย</span><span id="sWht">0.00</span></div>
          <div class="sum-row total"><span>ยอดสุทธิ</span><span id="sGrand">0.00</span></div>
        </div>
        <div class="field"><label>ส่วนลด (บาท)</label>
          <input type="number" name="discount" id="discount" step="0.01" min="0" value="<?= e($v['discount']) ?>" class="calc"></div>
        <div class="field"><label>VAT (%)</label>
          <input type="number" name="vat_rate" id="vat_rate" step="0.01" min="0" value="<?= e($v['vat_rate']) ?>" class="calc">
          <div class="hint">0 = ไม่คิด VAT</div></div>
        <div class="field"><label>หัก ณ ที่จ่าย (%)</label>
          <input type="number" name="wht_rate" id="wht_rate" step="0.01" min="0" value="<?= e($v['wht_rate']) ?>" class="calc">
          <div class="hint">ค่าเช่าทรัพย์สินนิติบุคคลปกติ 5%</div></div>
        <button class="btn btn-accent btn-block mt-2">บันทึกและพิมพ์</button>
        <a class="btn btn-ghost btn-block mt-1" href="<?= url('admin/documents.php') ?>">ยกเลิก</a>
      </div>
    </div>
  </div>
</form>

<script>
(function () {
  const tbody = document.querySelector('#itemTable tbody');
  const fmt = function (n) { return n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

  function calc() {
    let sub = 0;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      const q = parseFloat(tr.querySelector('[name="qty[]"]').value) || 0;
      const p = parseFloat(tr.querySelector('[name="price[]"]').value) || 0;
      sub += q * p;
    });
    const disc = parseFloat(document.getElementById('discount').value) || 0;
    const net  = Math.max(0, sub - disc);
    const vat  = net * (parseFloat(document.getElementById('vat_rate').value) || 0) / 100;
    const wht  = net * (parseFloat(document.getElementById('wht_rate').value) || 0) / 100;
    document.getElementById('sSub').textContent   = fmt(sub);
    document.getElementById('sNet').textContent   = fmt(net);
    document.getElementById('sVat').textContent   = fmt(vat);
    document.getElementById('sWht').textContent   = '-' + fmt(wht);
    document.getElementById('sGrand').textContent = '฿' + fmt(net + vat - wht);
  }

  document.addEventListener('input', function (e) { if (e.target.matches('.calc')) calc(); });
  document.getElementById('addRow').addEventListener('click', function () {
    const tr = tbody.rows[0].cloneNode(true);
    tr.querySelectorAll('input').forEach(function (i) { if (i.type === 'number') i.value = i.name === 'qty[]' ? 1 : 0; else i.value = ''; });
    tbody.appendChild(tr); calc();
  });
  document.addEventListener('click', function (e) {
    if (e.target.matches('.del')) {
      if (tbody.rows.length > 1) e.target.closest('tr').remove();
      calc();
    }
  });
  calc();
})();
</script>
<?php admin_foot(); ?>
