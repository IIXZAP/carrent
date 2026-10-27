<?php
use App\Services\CarService;

/** ช่องกรอกข้อความหนึ่งช่อง + ข้อความ error ของช่องนั้น */
$input = static function (string $name, string $label, string $type = 'text') use ($car, $errors): void { ?>
  <div>
    <label class="label" for="<?= e($name) ?>"><?= e($label) ?></label>
    <input class="<?= isset($errors[$name]) ? 'field-error' : '' ?>" id="<?= e($name) ?>" type="<?= e($type) ?>" name="<?= e($name) ?>" value="<?= e(old($car, $name)) ?>">
    <?php if (isset($errors[$name])): ?><p class="text-sm text-danger mt-1"><?= e($errors[$name]) ?></p><?php endif; ?>
  </div>
<?php };

/** ช่องเลือกจากรายการ */
$select = static function (string $name, string $label, array $options) use ($car, $errors): void { ?>
  <div>
    <label class="label" for="<?= e($name) ?>"><?= e($label) ?></label>
    <select class="<?= isset($errors[$name]) ? 'field-error' : '' ?>" id="<?= e($name) ?>" name="<?= e($name) ?>">
      <?php foreach ($options as $value => $text): ?>
        <option value="<?= e($value) ?>" <?= (string) old($car, $name) === (string) $value ? 'selected' : '' ?>><?= e($text) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (isset($errors[$name])): ?><p class="text-sm text-danger mt-1"><?= e($errors[$name]) ?></p><?php endif; ?>
  </div>
<?php };

$typeOptions = [];
foreach ($types as $t) {
    $typeOptions[$t['code']] = $t['label_th'];
}
?>
<p class="mb-3 text-sm"><a href="<?= e(url('/admin/cars')) ?>">← รถทั้งหมด</a></p>

<form method="post" action="<?= e(url($action)) ?>" class="panel mt-6 space-y-6">
  <?= csrf_field() ?>
  <div class="grid gap-4 sm:grid-cols-3">
    <?php $input('plate', 'ทะเบียน'); $input('province', 'จังหวัด'); $select('status', 'สถานะ', CarService::STATUSES); ?>
    <?php $input('brand', 'ยี่ห้อ'); $input('model', 'รุ่น'); $select('car_type_code', 'ประเภทรถ', $typeOptions); ?>
    <?php $input('year', 'ปี (ค.ศ.)', 'number'); $input('color', 'สี'); $input('seats', 'จำนวนที่นั่ง', 'number'); ?>
    <?php $select('gear', 'เกียร์', CarService::GEARS); $select('fuel', 'เชื้อเพลิง', CarService::FUELS); ?>
  </div>

  <div>
    <h2 class="font-semibold">ราคา (บาท)</h2>
    <p class="text-sm text-muted">ราคาสัปดาห์/เดือน ถ้าเว้นว่างจะใช้ราคาต่อวัน × 7 / × 30</p>
    <div class="grid gap-4 sm:grid-cols-3 mt-3">
      <?php $input('price_day', 'ต่อวัน'); $input('price_week', 'ต่อสัปดาห์ (ไม่บังคับ)'); $input('price_month', 'ต่อเดือน (ไม่บังคับ)'); ?>
      <?php $input('security_deposit', 'เงินประกัน (คืนเมื่อคืนรถ)'); $input('booking_deposit', 'มัดจำยืนยันการจอง'); ?>
    </div>
  </div>

  <div>
    <label class="label" for="description">รายละเอียด</label>
    <textarea id="description" name="description" rows="3"><?= e(old($car, 'description')) ?></textarea>
  </div>

  <div class="flex gap-3">
    <button class="btn">บันทึก</button>
    <a class="btn btn-ghost" href="<?= e(url('/admin/cars')) ?>">ยกเลิก</a>
  </div>
</form>
