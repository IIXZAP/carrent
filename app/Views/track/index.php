<div class="max-w-md mx-auto panel mt-4">
  <h1 class="text-xl font-bold">ตรวจสอบการจอง / แจ้งโอนเงิน</h1>
  <p class="text-sm text-slate-500 mt-1">กรอกเลขที่จองและเบอร์โทรที่ใช้จอง</p>
  <form method="post" action="<?= e(url('/track')) ?>" class="mt-6 space-y-4">
    <?= csrf_field() ?>
    <div>
      <label class="label" for="booking_no">เลขที่จอง</label>
      <input class="field" id="booking_no" name="booking_no" value="<?= e($no) ?>" placeholder="BK261026-001" required autocomplete="off">
    </div>
    <div>
      <label class="label" for="phone">เบอร์โทรที่ใช้จอง</label>
      <input class="field" id="phone" name="phone" type="tel" required>
    </div>
    <button class="btn btn-primary w-full">ตรวจสอบ</button>
  </form>
</div>
