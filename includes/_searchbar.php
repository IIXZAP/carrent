<?php
/**
 * แถบค้นหารถว่าง — ใช้ทั้งหน้าแรกและหน้ารายการรถ
 * ตัวเลือก: $sbPick, $sbRet (ค่า datetime-local), $sbType
 */
$sbPick = $sbPick ?? date('Y-m-d\TH:i', strtotime('tomorrow 10:00'));
$sbRet  = $sbRet  ?? date('Y-m-d\TH:i', strtotime('tomorrow +1 day 10:00'));
$sbType = $sbType ?? '';

// แยกวันกับเวลา เพื่อให้กรอกง่ายบนมือถือ
$pd = substr($sbPick, 0, 10);  $pt = substr($sbPick, 11, 5) ?: '10:00';
$rd = substr($sbRet, 0, 10);   $rt = substr($sbRet, 11, 5) ?: '10:00';
?>
<form class="searchbar" action="<?= url('cars.php') ?>" method="get" id="searchForm">
  <div class="sb-title"><?= icon('search') ?> ค้นหารถว่าง</div>
  <div class="sb-grid">
    <div class="field f-date">
      <label for="pd">วันรับรถ</label>
      <div class="f-icon"><?= icon('calendar') ?>
        <input type="date" id="pd" name="pd" value="<?= e($pd) ?>" required>
      </div>
    </div>
    <div class="field">
      <label for="pt">เวลา</label>
      <div class="f-icon"><?= icon('clock') ?>
        <input type="time" id="pt" name="pt" value="<?= e($pt) ?>" step="1800" required>
      </div>
    </div>
    <div class="field f-date">
      <label for="rd">วันคืนรถ</label>
      <div class="f-icon"><?= icon('calendar') ?>
        <input type="date" id="rd" name="rd" value="<?= e($rd) ?>" required>
      </div>
    </div>
    <div class="field">
      <label for="rt">เวลา</label>
      <div class="f-icon"><?= icon('clock') ?>
        <input type="time" id="rt" name="rt" value="<?= e($rt) ?>" step="1800" required>
      </div>
    </div>
    <div class="field f-full">
      <label for="type">ประเภทรถ</label>
      <div class="f-icon"><?= icon('car') ?>
        <select id="type" name="type">
          <option value="">ทั้งหมด</option>
          <?php foreach (['eco','sedan','suv','pickup','van'] as $t): ?>
            <option value="<?= $t ?>" <?= $sbType === $t ? 'selected' : '' ?>><?= e(car_type_label($t)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field f-full">
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
