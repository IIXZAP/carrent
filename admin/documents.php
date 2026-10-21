<?php
/**
 * รายการเอกสาร — ใบเสนอราคา / ใบแจ้งหนี้ / ใบเสร็จ
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'status') {
    csrf_check();
    $st = in_array($_POST['status'] ?? '', ['draft','sent','accepted','paid','void'], true) ? $_POST['status'] : 'draft';
    db_update('documents', ['status' => $st], 'id', (int) $_POST['id']);
    flash('ok', 'อัปเดตสถานะเอกสารแล้ว');
    redirect('admin/documents.php');
}

$type = $_GET['type'] ?? '';
$w    = in_array($type, ['quotation','invoice','receipt','contract'], true) ? 'd.doc_type = :t' : '1=1';
$prm  = $w === '1=1' ? [] : [':t' => $type];

$rows = db_all(
    "SELECT d.*, b.booking_no, c.full_name
     FROM documents d
     LEFT JOIN bookings b ON b.id = d.booking_id
     LEFT JOIN customers c ON c.id = d.customer_id
     WHERE $w ORDER BY d.id DESC LIMIT 200",
    $prm
);

$typeLabel   = ['quotation'=>'ใบเสนอราคา','invoice'=>'ใบแจ้งหนี้','receipt'=>'ใบเสร็จรับเงิน','contract'=>'สัญญาเช่า'];
$statusLabel = ['draft'=>['ร่าง','muted'],'sent'=>['ส่งแล้ว','info'],'accepted'=>['ลูกค้าตอบรับ','primary'],
                'paid'=>['ชำระแล้ว','ok'],'void'=>['ยกเลิก','danger']];

$sumQuote = (float) db_val("SELECT COALESCE(SUM(grand_total),0) FROM documents WHERE doc_type='quotation' AND status IN ('sent','accepted')");

admin_head('เอกสาร', 'documents.php');
?>
<div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
  <div class="stat accent"><div class="lb">ใบเสนอราคารอลูกค้าตอบ</div><div class="vl">฿<?= money0($sumQuote) ?></div></div>
</div>

<div class="toolbar">
  <a class="btn btn-sm <?= $type === '' ? '' : 'btn-ghost' ?>" href="?">ทั้งหมด</a>
  <?php foreach ($typeLabel as $k => $l): ?>
    <a class="btn btn-sm <?= $type === $k ? '' : 'btn-ghost' ?>" href="?type=<?= $k ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
  <div class="spacer"></div>
  <a class="btn btn-sm" href="<?= url('admin/document-edit.php?type=quotation') ?>">+ ใบเสนอราคา</a>
  <a class="btn btn-ghost btn-sm" href="<?= url('admin/document-edit.php?type=receipt') ?>">+ ใบเสร็จ</a>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>เลขที่</th><th>ประเภท</th><th>ลูกค้า</th><th>การจอง</th><th>วันที่</th>
                 <th class="num">ยอดสุทธิ</th><th>สถานะ</th><th></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="center muted" style="padding:30px">ยังไม่มีเอกสาร</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): [$sl, $sc] = $statusLabel[$r['status']] ?? [$r['status'], 'muted']; ?>
          <tr>
            <td><b><?= e($r['doc_no']) ?></b></td>
            <td class="small"><?= e($typeLabel[$r['doc_type']] ?? $r['doc_type']) ?></td>
            <td class="small"><?= e($r['bill_name'] ?: ($r['full_name'] ?: '-')) ?></td>
            <td class="small">
              <?= $r['booking_no'] ? '<a href="' . url('admin/booking-view.php?id=' . (int) $r['booking_id']) . '">' . e($r['booking_no']) . '</a>' : '-' ?>
            </td>
            <td class="small"><?= e(thai_date($r['issue_date'])) ?></td>
            <td class="num">฿<?= money($r['grand_total']) ?></td>
            <td>
              <form method="post" onchange="this.submit()">
                <?= csrf_field() ?><input type="hidden" name="act" value="status">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <select name="status" style="padding:4px 8px;font-size:.8rem">
                  <?php foreach ($statusLabel as $k => $v2): ?>
                    <option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= e($v2[0]) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td class="right" style="white-space:nowrap">
              <a class="btn btn-sm" href="<?= url('admin/document-print.php?id=' . (int) $r['id']) ?>" target="_blank">พิมพ์</a>
              <a class="btn btn-ghost btn-sm" href="<?= url('admin/document-edit.php?id=' . (int) $r['id']) ?>">แก้ไข</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php admin_foot(); ?>
