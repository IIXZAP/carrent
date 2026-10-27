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

<?php if (!empty($car['id'])): ?>
<div class="panel mt-6" id="images">
  <h3>รูปรถ</h3>
  <p class="text-sm text-muted">รูปปกใช้ในการ์ดรถและหน้าแรก · รูปเพิ่มเติมขึ้นเป็นแกลเลอรีในหน้ารายละเอียดรถ (ไม่เกิน <?= (int) \App\Services\CarImageService::MAX_GALLERY ?> รูป) ·
    ระบบย่อรูปและหมุนตามกล้องให้อัตโนมัติ (JPG / PNG / WEBP)</p>

  <div class="mt-4 grid gap-4 sm:grid-cols-[220px_1fr]">
    <div>
      <div class="grid aspect-[4/3] place-items-center overflow-hidden rounded-xl border border-line bg-white">
        <?php if (upload_exists($car['cover_image'] ?? null)): ?>
          <img class="h-full w-full object-contain" src="<?= e(upload_url($car['cover_image'])) ?>" alt="รูปปก">
        <?php else: ?><span class="text-sm text-muted">ยังไม่มีรูปปก</span><?php endif; ?>
      </div>
      <?php if (!empty($car['cover_image'])): ?>
        <form method="post" action="<?= e(url('/admin/cars/' . $car['id'] . '/cover/delete')) ?>" class="mt-2" onsubmit="return confirm('ลบรูปปก?')">
          <?= csrf_field() ?><button class="btn btn-ghost btn-sm btn-block text-danger">ลบรูปปก</button>
        </form>
      <?php endif; ?>
    </div>
    <form method="post" enctype="multipart/form-data" action="<?= e(url('/admin/cars/' . $car['id'] . '/cover')) ?>" class="self-start">
      <?= csrf_field() ?>
      <div class="field"><label for="cover"><?= !empty($car['cover_image']) ? 'เปลี่ยนรูปปก' : 'อัปโหลดรูปปก' ?></label>
        <input type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp" data-shrink required>
        <div class="hint">แนะนำรูปรถทั้งคันพื้นหลังขาว แนวนอน</div></div>
      <button class="btn btn-sm">อัปโหลดรูปปก</button>
    </form>
  </div>

  <h4 class="mt-6">แกลเลอรี (<?= count($images) ?>/<?= (int) \App\Services\CarImageService::MAX_GALLERY ?>)</h4>
  <?php if ($images): ?>
    <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
      <?php foreach ($images as $img): ?>
        <div class="rounded-xl border border-line bg-white p-2">
          <img class="aspect-[4/3] w-full rounded-lg object-cover" loading="lazy" src="<?= e(upload_url($img['path'])) ?>" alt="">
          <form method="post" action="<?= e(url('/admin/car-images/' . $img['id'] . '/delete')) ?>" class="mt-2" onsubmit="return confirm('ลบรูปนี้?')">
            <?= csrf_field() ?><button class="btn btn-ghost btn-sm btn-block text-danger">ลบ</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (count($images) < \App\Services\CarImageService::MAX_GALLERY): ?>
    <form method="post" enctype="multipart/form-data" action="<?= e(url('/admin/cars/' . $car['id'] . '/images')) ?>" class="mt-3">
      <?= csrf_field() ?>
      <div class="field"><label for="images-input">เพิ่มรูป (เลือกได้หลายไฟล์)</label>
        <input type="file" id="images-input" name="images[]" accept="image/jpeg,image/png,image/webp" multiple data-shrink required></div>
      <button class="btn btn-sm">อัปโหลดรูป</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
