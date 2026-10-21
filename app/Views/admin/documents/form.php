<?php
/** ออก/แก้ไขเอกสาร — ตัวแปร: doc, id, type, typeName, booking, customer, items, v */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'documents']);
?>
<form method="post" action="<?= url($id ? 'admin/documents/' . $id . '/edit' : 'admin/documents/new') ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="type" value="<?= e($type) ?>">
  <input type="hidden" name="booking_id" value="<?= (int) ($booking['id'] ?? 0) ?>">
  <input type="hidden" name="customer_id" value="<?= (int) ($customer['id'] ?? 0) ?>">
  <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
    <div class="min-w-0">
      <div class="panel">
        <h3><?= e($typeName) ?><?= $doc ? ' ' . e($doc['doc_no']) : '' ?></h3>
        <?php if ($booking): ?>
          <p class="text-sm text-muted">อ้างอิงการจอง
            <a href="<?= url('admin/bookings/' . (int) $booking['id']) ?>"><?= e($booking['booking_no']) ?></a></p>
        <?php endif; ?>
        <div class="grid gap-x-3.5 sm:grid-cols-2">
          <div class="field"><label>วันที่เอกสาร</label><input type="date" name="issue_date" value="<?= e($v['issue_date']) ?>"></div>
          <div class="field"><label>ครบกำหนด / ยืนราคาถึง</label><input type="date" name="due_date" value="<?= e($v['due_date']) ?>"></div>
        </div>
        <div class="field"><label>ชื่อผู้ซื้อ/ผู้เช่า *</label><input type="text" name="bill_name" value="<?= e($v['bill_name']) ?>" required></div>
        <div class="grid gap-x-3.5 sm:grid-cols-2">
          <div class="field"><label>เลขผู้เสียภาษี</label><input type="text" name="bill_tax_id" value="<?= e($v['bill_tax_id']) ?>"></div>
        </div>
        <div class="field"><label>ที่อยู่</label><textarea name="bill_address"><?= e($v['bill_address']) ?></textarea></div>
      </div>

      <div class="panel mt-4">
        <h3>รายการ</h3>
        <div class="table-wrap">
          <table class="table min-w-[640px]" id="itemTable">
            <thead><tr><th class="w-[46%]">รายละเอียด</th><th class="w-[12%]">จำนวน</th>
                       <th class="w-[14%]">หน่วย</th><th class="w-[16%]">ราคา/หน่วย</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <tr>
                  <td><input type="text" name="desc[]" value="<?= e($it['desc']) ?>"></td>
                  <td><input type="number" name="qty[]" step="0.01" value="<?= e($it['qty']) ?>" class="calc"></td>
                  <td><input type="text" name="unit[]" value="<?= e($it['unit'] ?? 'รายการ') ?>"></td>
                  <td><input type="number" name="price[]" step="0.01" value="<?= e($it['price']) ?>" class="calc"></td>
                  <td class="text-right"><button type="button" class="btn btn-ghost btn-sm del">ลบ</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <button type="button" class="btn btn-ghost btn-sm mt-2" id="addRow">+ เพิ่มบรรทัด</button>
      </div>

      <div class="panel mt-4">
        <div class="field"><label>หมายเหตุท้ายเอกสาร</label>
          <textarea name="note" placeholder="เช่น ราคานี้ยังไม่รวมค่าน้ำมัน / ยืนราคา 7 วัน"><?= e($v['note']) ?></textarea></div>
      </div>
    </div>

    <div class="min-w-0">
      <div class="panel lg:sticky lg:top-[78px]">
        <h3>สรุปยอด</h3>
        <div class="sum mb-3">
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
        <button class="btn btn-accent btn-block mt-3">บันทึกและพิมพ์</button>
        <a class="btn btn-ghost btn-block mt-2" href="<?= url('admin/documents') ?>">ยกเลิก</a>
      </div>
    </div>
  </div>
</form>

<?php $this->start('after_main'); ?>
<script>
(function () {
  var tbody = document.querySelector('#itemTable tbody');
  var fmt = function (n) { return n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

  function calc() {
    var sub = 0;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      var q = parseFloat(tr.querySelector('[name="qty[]"]').value) || 0;
      var p = parseFloat(tr.querySelector('[name="price[]"]').value) || 0;
      sub += q * p;
    });
    var disc = parseFloat(document.getElementById('discount').value) || 0;
    var net  = Math.max(0, sub - disc);
    var vat  = net * (parseFloat(document.getElementById('vat_rate').value) || 0) / 100;
    var wht  = net * (parseFloat(document.getElementById('wht_rate').value) || 0) / 100;
    document.getElementById('sSub').textContent   = fmt(sub);
    document.getElementById('sNet').textContent   = fmt(net);
    document.getElementById('sVat').textContent   = fmt(vat);
    document.getElementById('sWht').textContent   = '-' + fmt(wht);
    document.getElementById('sGrand').textContent = '฿' + fmt(net + vat - wht);
  }

  document.addEventListener('input', function (e) { if (e.target.matches('.calc')) calc(); });
  document.getElementById('addRow').addEventListener('click', function () {
    var tr = tbody.rows[0].cloneNode(true);
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
<?php $this->end(); ?>
