<?php
/**
 * แถบค้นหารถว่าง — ใช้ทั้งหน้าแรกและหน้ารายการรถ
 * ตัวเลือก: $sbPick, $sbRet (ค่า datetime-local), $sbType
 */
$sbPick = $sbPick ?? date('Y-m-d\TH:i', strtotime('tomorrow 10:00'));
$sbRet  = $sbRet  ?? date('Y-m-d\TH:i', strtotime('tomorrow +1 day 10:00'));
$sbType = $sbType ?? '';

// แยกวันกับเวลา เพื่อให้กรอกง่ายบนมือถือ
$pd = substr($sbPick, 0, 10);
$pt = substr($sbPick, 11, 5) ?: '10:00';
$rd = substr($sbRet, 0, 10);
$rt = substr($sbRet, 11, 5) ?: '10:00';

$iconWrap = 'relative [&>svg]:pointer-events-none [&>svg]:absolute [&>svg]:left-[13px] [&>svg]:top-1/2 [&>svg]:z-[1] [&>svg]:h-[17px] [&>svg]:w-[17px] [&>svg]:-translate-y-1/2 [&>svg]:text-brand [&_input]:pl-[37px] [&_select]:pl-[37px]';
?>
<form class="relative z-[5] mt-[18px] rounded-[18px] bg-white p-4 shadow-lift lg:-mt-[26px] lg:p-5" action="<?= e(url('/cars')) ?>" method="get" id="searchForm">
  <div class="mb-3 flex items-center gap-[7px] text-[0.95rem] font-bold text-dark"><?= icon('search', 'h-[17px] w-[17px] text-brand') ?> ค้นหารถว่าง</div>
  <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-[1.4fr_0.9fr] sm:gap-3 lg:grid-cols-[1.25fr_1fr_1.25fr_1fr_1.05fr_auto] lg:items-end">
    <div class="field col-span-2 sm:col-span-1 sm:mb-0">
      <label for="pd">วันรับรถ</label>
      <div class="<?= $iconWrap ?>"><?= icon('calendar') ?>
        <input type="date" id="pd" name="pd" value="<?= e($pd) ?>" required>
      </div>
    </div>
    <div class="field sm:mb-0">
      <label for="pt">เวลา</label>
      <div class="<?= $iconWrap ?>"><?= icon('clock') ?>
        <input type="time" id="pt" name="pt" value="<?= e($pt) ?>" step="1800" required class="!pr-1.5">
      </div>
    </div>
    <div class="field col-span-2 sm:col-span-1 sm:mb-0">
      <label for="rd">วันคืนรถ</label>
      <div class="<?= $iconWrap ?>"><?= icon('calendar') ?>
        <input type="date" id="rd" name="rd" value="<?= e($rd) ?>" required>
      </div>
    </div>
    <div class="field sm:mb-0">
      <label for="rt">เวลา</label>
      <div class="<?= $iconWrap ?>"><?= icon('clock') ?>
        <input type="time" id="rt" name="rt" value="<?= e($rt) ?>" step="1800" required class="!pr-1.5">
      </div>
    </div>
    <div class="field col-span-2 sm:mb-0 lg:col-span-1">
      <label for="type">ประเภทรถ</label>
      <div class="<?= $iconWrap ?>"><?= icon('car') ?>
        <select id="type" name="type">
          <option value="">ทั้งหมด</option>
          <?php foreach (car_type_options() as $t => $label): ?>
            <option value="<?= e($t) ?>"<?= selected($sbType === $t) ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field col-span-2 sm:mb-0 lg:col-span-1">
      <button type="submit" class="btn btn-block btn-lg"><?= icon('search') ?> ค้นหารถว่าง</button>
    </div>
  </div>
</form>

<script>
(function () {
  var f = document.getElementById('searchForm');
  if (!f) return;
  var pd = f.pd, rd = f.rd;
  var today = new Date().toISOString().slice(0, 10);
  pd.min = today;
  // วันคืนต้องไม่ก่อนวันรับ
  function sync() {
    rd.min = pd.value || today;
    if (rd.value && rd.value < rd.min) rd.value = rd.min;
  }
  pd.addEventListener('change', sync);
  sync();
})();
</script>
