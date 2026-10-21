<?php
/**
 * ตรวจสลิป / อนุมัติการชำระเงิน
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

// ---------- อนุมัติสลิป (POST เท่านั้น) ----------
// เดิมใช้ลิงก์ GET ?approve= ซึ่งทำให้ token ติดไปกับ URL / ประวัติเบราว์เซอร์ / log ของเซิร์ฟเวอร์
// และอาจถูกยิงซ้ำโดย prefetch หรือสแกนเนอร์ จึงเปลี่ยนเป็น POST ทั้งหมด
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'approve') {
    csrf_check();
    $pid = (int) ($_POST['payment_id'] ?? 0);
    $p   = db_one('SELECT * FROM payments WHERE id = :i', [':i' => $pid]);
    if ($p && $p['status'] === 'pending') {
        db_update('payments', [
            'status' => 'approved', 'approved_by' => auth_user()['id'], 'approved_at' => date('Y-m-d H:i:s'),
        ], 'id', $pid);
        recalc_booking_payment((int) $p['booking_id']);
        // จองที่ยังรอยืนยัน + จ่ายมัดจำแล้ว → ยืนยันอัตโนมัติ
        $bk = db_one('SELECT * FROM bookings WHERE id = :b', [':b' => (int) $p['booking_id']]);
        if ($bk && $bk['status'] === 'pending' && (float) $bk['paid_amount'] >= (float) $bk['deposit_amount']) {
            db_update('bookings', ['status' => 'confirmed'], 'id', (int) $bk['id']);
            $car = db_one('SELECT * FROM cars WHERE id = :c', [':c' => (int) $bk['car_id']]);
            $bk  = db_one('SELECT * FROM bookings WHERE id = :b', [':b' => (int) $bk['id']]);
            try { notify_booking_confirmed($bk, $car); } catch (Throwable $e) {}
        } elseif ($bk) {
            // ไม่ได้เปลี่ยนสถานะการจอง → แจ้งลูกค้าทาง LINE ว่าได้รับเงินแล้ว
            // (ถ้าเพิ่งยืนยันการจองไปข้างบน ลูกค้าได้ข้อความยืนยันแล้ว ไม่ส่งซ้ำสองข้อความ)
            try { notify_slip_result($bk, (float) $p['amount'], true); } catch (Throwable $e) {}
        }
        log_activity('payment_approved', 'payments', $pid, $p['amount']);
        flash('ok', 'อนุมัติการชำระเงินแล้ว');
    }
    redirect('admin/payments.php');
}

// ---------- ปฏิเสธสลิป ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'reject') {
    csrf_check();
    $pid = (int) $_POST['payment_id'];
    $p   = db_one('SELECT * FROM payments WHERE id = :i', [':i' => $pid]);
    if ($p && $p['status'] === 'pending') {
        db_update('payments', [
            'status' => 'rejected', 'approved_by' => auth_user()['id'], 'approved_at' => date('Y-m-d H:i:s'),
            'reject_reason' => trim($_POST['reason'] ?? '') ?: 'ข้อมูลไม่ถูกต้อง',
        ], 'id', $pid);
        recalc_booking_payment((int) $p['booking_id']);
        $bk = db_one('SELECT * FROM bookings WHERE id = :b', [':b' => (int) $p['booking_id']]);
        if ($bk) {
            try {
                notify_slip_result($bk, (float) $p['amount'], false, trim($_POST['reason'] ?? '') ?: 'ข้อมูลไม่ถูกต้อง');
            } catch (Throwable $e) {}
        }
        log_activity('payment_rejected', 'payments', $pid);
        flash('warn', 'ปฏิเสธสลิปแล้ว');
    }
    redirect('admin/payments.php');
}

$filter = $_GET['status'] ?? 'pending';
$filter = in_array($filter, ['pending', 'approved', 'rejected', 'all'], true) ? $filter : 'pending';

$w = $filter === 'all' ? '1=1' : 'p.status = :st';
$prm = $filter === 'all' ? [] : [':st' => $filter];

$rows = db_all(
    "SELECT p.*, b.booking_no, b.contact_name, b.contact_phone, b.grand_total, b.paid_amount, b.extra_charge,
            c.plate, c.brand, c.model, u.name AS approver
     FROM payments p
     JOIN bookings b ON b.id = p.booking_id
     JOIN cars c ON c.id = b.car_id
     LEFT JOIN users u ON u.id = p.approved_by
     WHERE $w ORDER BY p.status = 'pending' DESC, p.id DESC LIMIT 200",
    $prm
);

$cntPending = (int) db_val("SELECT COUNT(*) FROM payments WHERE status = 'pending'");
$sumPending = (float) db_val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'pending'");
// รายได้วันนี้ใช้นิยามเดียวกับรายงาน: ไม่นับเงินประกัน และหักรายการคืนเงิน
$sumToday   = revenue_collected(date('Y-m-d'), date('Y-m-d'));

$kindLabel   = ['deposit'=>'มัดจำ','balance'=>'ค่าเช่า','security'=>'เงินประกัน','extra'=>'ค่าใช้จ่ายเพิ่ม','refund'=>'คืนเงินค่าเช่า','security_refund'=>'คืนเงินประกัน'];
$methodLabel = ['cash'=>'เงินสด','transfer'=>'โอนธนาคาร','promptpay'=>'PromptPay','card'=>'บัตรเครดิต','deduct'=>'หักจากเงินประกัน'];

admin_head('ชำระเงิน', 'payments.php');
?>
<div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
  <div class="stat <?= $cntPending ? 'danger' : 'ok' ?>">
    <div class="lb">สลิปรอตรวจสอบ</div><div class="vl"><?= $cntPending ?></div>
    <div class="sub">มูลค่า ฿<?= money0($sumPending) ?></div>
  </div>
  <div class="stat ok"><div class="lb">อนุมัติวันนี้</div><div class="vl">฿<?= money0($sumToday) ?></div></div>
</div>

<div class="toolbar">
  <?php foreach (['pending'=>'รอตรวจสอบ','approved'=>'อนุมัติแล้ว','rejected'=>'ไม่ผ่าน','all'=>'ทั้งหมด'] as $k => $l): ?>
    <a class="btn btn-sm <?= $filter === $k ? '' : 'btn-ghost' ?>" href="?status=<?= $k ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>การจอง</th><th>ลูกค้า</th><th>ประเภท</th><th class="num">จำนวน</th><th>วันที่โอน</th><th>สลิป</th><th>สถานะ</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="center muted" style="padding:30px">ไม่มีรายการ</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $due = (float) $r['grand_total'] + (float) $r['extra_charge']; ?>
          <tr>
            <td>
              <a href="<?= url('admin/booking-view.php?id=' . (int) $r['booking_id']) ?>"><b><?= e($r['booking_no']) ?></b></a>
              <div class="tiny muted"><?= e($r['brand'] . ' ' . $r['model']) ?> · <?= e($r['plate']) ?></div>
            </td>
            <td class="small"><?= e($r['contact_name']) ?><div class="tiny muted"><?= e($r['contact_phone']) ?></div></td>
            <td class="small"><?= e($kindLabel[$r['kind']] ?? $r['kind']) ?><div class="tiny muted"><?= e($methodLabel[$r['method']] ?? $r['method']) ?></div></td>
            <td class="num">
              <b><?= money($r['amount']) ?></b>
              <div class="tiny muted">ยอดรวม <?= money0($due) ?> · จ่ายแล้ว <?= money0($r['paid_amount']) ?></div>
            </td>
            <td class="small"><?= e(thai_date($r['paid_at'] ?: $r['created_at'], true)) ?></td>
            <td>
              <?php if ($r['slip_file']): ?>
                <a href="<?= e(upload_url($r['slip_file'])) ?>" target="_blank">
                  <?php if (preg_match('/\.(jpe?g|png|webp)$/i', $r['slip_file'])): ?>
                    <img src="<?= e(upload_url($r['slip_file'])) ?>" alt="สลิป" style="width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid var(--line)">
                  <?php else: ?>ดูไฟล์ PDF<?php endif; ?>
                </a>
              <?php else: ?><span class="muted">-</span><?php endif; ?>
            </td>
            <td>
              <?php if ($r['status'] === 'approved'): ?>
                <span class="badge badge-ok">อนุมัติแล้ว</span>
                <div class="tiny muted"><?= e($r['approver'] ?: '') ?><br><?= e(thai_date($r['approved_at'])) ?></div>
              <?php elseif ($r['status'] === 'rejected'): ?>
                <span class="badge badge-danger">ไม่ผ่าน</span>
                <div class="tiny muted"><?= e($r['reject_reason']) ?></div>
              <?php else: ?>
                <span class="badge badge-warn">รอตรวจ</span>
              <?php endif; ?>
            </td>
            <td class="right" style="white-space:nowrap">
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post" style="display:inline-block"
                      onsubmit="return confirm('ยืนยันว่าได้รับเงิน <?= money($r['amount']) ?> บาทแล้ว?')">
                  <?= csrf_field() ?><input type="hidden" name="act" value="approve">
                  <input type="hidden" name="payment_id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm">อนุมัติ</button>
                </form>
                <details style="display:inline-block">
                  <summary class="btn btn-ghost btn-sm" style="list-style:none;cursor:pointer">ไม่ผ่าน</summary>
                  <form method="post" style="position:absolute;z-index:20;background:#fff;border:1px solid var(--line);
                        border-radius:8px;padding:12px;box-shadow:var(--shadow);width:250px;margin-top:6px">
                    <?= csrf_field() ?><input type="hidden" name="act" value="reject">
                    <input type="hidden" name="payment_id" value="<?= (int) $r['id'] ?>">
                    <div class="field"><label>เหตุผล</label><input type="text" name="reason" required></div>
                    <button class="btn btn-danger btn-sm btn-block">ยืนยันปฏิเสธ</button>
                  </form>
                </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php admin_foot(); ?>
