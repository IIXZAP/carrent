<?php /** ปิดเงินประกัน: $booking $held $owed $canSettle */ ?>
<a href="<?= e(url('/admin/bookings/' . $booking['id'])) ?>" class="text-sm text-slate-500 hover:text-slate-800">← <?= e($booking['booking_no']) ?></a>
<h1 class="text-2xl font-bold mt-2">ปิดเงินประกัน <span class="text-slate-400"><?= e($booking['booking_no']) ?></span></h1>

<div class="panel mt-6">
  <table class="table">
    <tr><td>เงินประกันที่ร้านถืออยู่</td><td class="text-right font-semibold">฿<?= e(money($held)) ?></td></tr>
    <tr><td>ยอดที่ลูกค้ายังค้างชำระ (ค่าเช่า + ค่าใช้จ่ายเพิ่ม − ที่จ่ายแล้ว)</td><td class="text-right">฿<?= e(money($owed)) ?></td></tr>
  </table>
</div>

<?php if (!$canSettle): ?>
  <div class="alert alert-error mt-4">
    <?= $held <= 0 ? 'ไม่มีเงินประกันที่ร้านถืออยู่ (อาจคืนไปแล้ว หรือยังไม่ได้รับ)' : 'ปิดเงินประกันได้หลังรับคืนรถ (หรือเมื่อยกเลิกการจอง) เท่านั้น' ?>
  </div>
<?php else: ?>
<form method="post" action="<?= e(url('/admin/bookings/' . $booking['id'] . '/security')) ?>" class="panel mt-6 grid gap-4 sm:grid-cols-2">
  <?= csrf_field() ?>
  <div class="sm:col-span-2"><h2 class="font-semibold">หักชำระ แล้วคืนส่วนที่เหลือ</h2>
    <p class="text-sm text-slate-500">ที่หักจากเงินประกันนับเป็นค่าเช่า/ค่าใช้จ่ายที่ชำระแล้ว ส่วนที่คืนไม่ใช่รายได้ ระบบหักได้ไม่เกินยอดค้างและไม่เกินเงินประกันที่ถือ</p></div>
  <div><label class="label" for="deduct">หักชำระยอดค้างเดิม (บาท)</label><input class="field" id="deduct" name="deduct" inputmode="decimal" value="<?= e(number_format(min($held, $owed), 2, '.', '')) ?>"></div>
  <div></div>
  <div><label class="label" for="add_amount">ค่าใช้จ่ายที่พบหลังรับคืน (บาท) <span class="text-slate-400">เช่น ค่าซ่อม ใบสั่ง</span></label><input class="field" id="add_amount" name="add_amount" inputmode="decimal"></div>
  <div><label class="label" for="add_label">เป็นค่าอะไร (บังคับถ้ามียอด)</label><input class="field" id="add_label" name="add_label" maxlength="120"></div>
  <div><label class="label" for="refund_method">คืนเงินที่เหลือโดย</label>
    <select class="field" id="refund_method" name="refund_method"><option value="cash">เงินสด</option><option value="transfer">โอนเงิน</option><option value="promptpay">พร้อมเพย์</option></select></div>
  <div><label class="label" for="refund_note">หมายเหตุการคืน</label><input class="field" id="refund_note" name="refund_note" maxlength="200"></div>
  <div class="sm:col-span-2"><button class="btn btn-primary" onclick="return confirm('ยืนยันปิดเงินประกัน? ทำได้ครั้งเดียว')">ปิดเงินประกัน</button></div>
</form>
<?php endif; ?>
