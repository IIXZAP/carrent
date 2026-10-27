<?php /** $doc (null = สร้างใหม่) $form */
$types = \App\Services\DocumentService::TYPES;
$items = $form['items'] ?? [];
$items = array_values($items);
while (count($items) < 3) { $items[] = ['description' => '', 'qty' => '1', 'unit' => '', 'price' => '']; }
$action = $doc ? '/admin/documents/' . $doc['id'] : '/admin/documents';
?>
<a href="<?= e(url('/admin/documents')) ?>" class="text-sm text-slate-500 hover:text-slate-800">← เอกสาร</a>
<h1 class="text-2xl font-bold mt-2"><?= e($title) ?></h1>
<form method="post" action="<?= e(url($action)) ?>" class="panel mt-6 grid gap-4 sm:grid-cols-2">
  <?= csrf_field() ?>
  <input type="hidden" name="booking_id" value="<?= e($form['booking_id'] ?? '') ?>">
  <div><label class="label" for="doc_type">ชนิดเอกสาร</label>
    <?php if ($doc): ?><input class="field" value="<?= e($types[$doc['doc_type']]) ?>" disabled>
    <?php else: ?><select class="field" id="doc_type" name="doc_type"><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>" <?= ($form['doc_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?></div>
  <div><label class="label" for="issue_date">วันที่ออกเอกสาร</label><input class="field" type="date" id="issue_date" name="issue_date" value="<?= e($form['issue_date'] ?? date('Y-m-d')) ?>" required></div>
  <div><label class="label" for="bill_name">ชื่อผู้รับเอกสาร</label><input class="field" id="bill_name" name="bill_name" maxlength="180" value="<?= e($form['bill_name'] ?? '') ?>" required></div>
  <div><label class="label" for="bill_tax_id">เลขประจำตัวผู้เสียภาษี</label><input class="field" id="bill_tax_id" name="bill_tax_id" maxlength="20" value="<?= e($form['bill_tax_id'] ?? '') ?>"></div>
  <div class="sm:col-span-2"><label class="label" for="bill_address">ที่อยู่</label><textarea class="field" id="bill_address" name="bill_address" rows="2"><?= e($form['bill_address'] ?? '') ?></textarea></div>
  <div><label class="label" for="due_date">วันครบกำหนด (ถ้ามี)</label><input class="field" type="date" id="due_date" name="due_date" value="<?= e($form['due_date'] ?? '') ?>"></div>
  <div></div>

  <div class="sm:col-span-2 overflow-x-auto">
    <table class="table">
      <thead><tr><th>รายการ</th><th class="w-24">จำนวน</th><th class="w-24">หน่วย</th><th class="w-32">ราคา/หน่วย</th></tr></thead>
      <tbody>
      <?php foreach ($items as $i => $it): ?>
        <tr>
          <td><input class="field" name="items[<?= $i ?>][description]" maxlength="255" value="<?= e($it['description'] ?? '') ?>"></td>
          <td><input class="field" name="items[<?= $i ?>][qty]" inputmode="decimal" value="<?= e($it['qty'] ?? '1') ?>"></td>
          <td><input class="field" name="items[<?= $i ?>][unit]" maxlength="20" value="<?= e($it['unit'] ?? '') ?>"></td>
          <td><input class="field" name="items[<?= $i ?>][price]" inputmode="decimal" value="<?= e($it['price'] ?? '') ?>"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="text-xs text-slate-500 mt-1">เว้นแถวว่างได้ — ระบบคำนวณยอดรวม ภาษี และหัก ณ ที่จ่ายให้เอง</p>
  </div>
  <div><label class="label" for="discount">ส่วนลด (บาท)</label><input class="field" id="discount" name="discount" inputmode="decimal" value="<?= e($form['discount'] ?? 0) ?>"></div>
  <div><label class="label" for="vat_rate">VAT (%)</label><input class="field" id="vat_rate" name="vat_rate" inputmode="decimal" value="<?= e($form['vat_rate'] ?? 0) ?>"></div>
  <div><label class="label" for="wht_rate">หัก ณ ที่จ่าย (%)</label><input class="field" id="wht_rate" name="wht_rate" inputmode="decimal" value="<?= e($form['wht_rate'] ?? 0) ?>"></div>
  <div class="sm:col-span-2"><label class="label" for="note">หมายเหตุ</label><textarea class="field" id="note" name="note" rows="2"><?= e($form['note'] ?? '') ?></textarea></div>
  <div class="sm:col-span-2"><button class="btn btn-primary">บันทึก</button></div>
</form>
