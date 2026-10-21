<?php
/** เพิ่ม / แก้ไขข้อมูลรถ — ตัวแปร: car, id, v, feats, images, limit */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'cars']);
?>
<form method="post" enctype="multipart/form-data" action="<?= url($id ? 'admin/cars/' . $id . '/edit' : 'admin/cars/new') ?>">
  <?= csrf_field() ?>
  <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
    <div class="min-w-0">
      <div class="panel">
        <h3>ข้อมูลรถ</h3>
        <div class="grid gap-x-3.5 sm:grid-cols-2">
          <div class="field"><label>ทะเบียน *</label><input type="text" name="plate" value="<?= e($v['plate'] ?? '') ?>" required></div>
          <div class="field"><label>จังหวัด</label><input type="text" name="province" value="<?= e($v['province'] ?? 'กรุงเทพมหานคร') ?>"></div>
          <div class="field"><label>ยี่ห้อ *</label><input type="text" name="brand" value="<?= e($v['brand'] ?? '') ?>" required></div>
          <div class="field"><label>รุ่น *</label><input type="text" name="model" value="<?= e($v['model'] ?? '') ?>" required></div>
          <div class="field"><label>ปี (ค.ศ.)</label><input type="number" name="year" value="<?= e($v['year'] ?? '') ?>" min="1990" max="2100"></div>
          <div class="field"><label>สี</label><input type="text" name="color" value="<?= e($v['color'] ?? '') ?>"></div>
          <div class="field"><label>ประเภท</label>
            <select name="car_type">
              <?php foreach (['eco', 'sedan', 'suv', 'pickup', 'van'] as $t): ?>
                <option value="<?= $t ?>" <?= selected(($v['car_type'] ?? '') === $t) ?>><?= e(car_type_label($t)) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>จำนวนที่นั่ง</label><input type="number" name="seats" value="<?= e($v['seats'] ?? 5) ?>" min="2" max="20"></div>
          <div class="field"><label>เกียร์</label>
            <select name="gear">
              <option value="auto"   <?= selected(($v['gear'] ?? 'auto') === 'auto') ?>>ออโต้</option>
              <option value="manual" <?= selected(($v['gear'] ?? '') === 'manual') ?>>ธรรมดา</option>
            </select></div>
          <div class="field"><label>เชื้อเพลิง</label><input type="text" name="fuel" value="<?= e($v['fuel'] ?? 'เบนซิน') ?>"></div>
          <div class="field"><label>เลขไมล์ปัจจุบัน (กม.)</label><input type="number" name="mileage" value="<?= e($v['mileage'] ?? 0) ?>" min="0"></div>
          <div class="field"><label>ลำดับการแสดง</label><input type="number" name="sort_order" value="<?= e($v['sort_order'] ?? 0) ?>"></div>
        </div>
        <div class="field"><label>จุดเด่น/อุปกรณ์ (บรรทัดละ 1 รายการ)</label>
          <textarea name="features" placeholder="Apple CarPlay&#10;กล้องหลัง&#10;Cruise Control"><?= e(implode("\n", $feats)) ?></textarea></div>
        <div class="field"><label>คำอธิบาย (แสดงหน้าเว็บลูกค้า)</label>
          <textarea name="description"><?= e($v['description'] ?? '') ?></textarea></div>
      </div>

      <div class="panel mt-4">
        <h3>ราคาเช่า</h3>
        <p class="text-sm text-muted">เว้นเรตสัปดาห์/เดือนเป็น 0 ระบบจะคิดจากรายวันให้อัตโนมัติ ลูกค้าจะได้ราคาที่ถูกที่สุดเสมอ</p>
        <div class="grid gap-x-3.5 sm:grid-cols-2 xl:grid-cols-3">
          <div class="field"><label>รายวัน (บาท) *</label><input type="number" name="price_day" step="0.01" min="0" value="<?= e($v['price_day'] ?? 0) ?>" required></div>
          <div class="field"><label>รายสัปดาห์ 7 วัน</label><input type="number" name="price_week" step="0.01" min="0" value="<?= e($v['price_week'] ?? 0) ?>"></div>
          <div class="field"><label>รายเดือน 30 วัน</label><input type="number" name="price_month" step="0.01" min="0" value="<?= e($v['price_month'] ?? 0) ?>"></div>
          <div class="field"><label>เงินประกัน (คืนเมื่อคืนรถ)</label><input type="number" name="deposit" step="0.01" min="0" value="<?= e($v['deposit'] ?? 0) ?>"></div>
          <div class="field"><label>มัดจำจอง</label><input type="number" name="deposit_booking" step="0.01" min="0" value="<?= e($v['deposit_booking'] ?? 0) ?>">
            <div class="hint">0 = คิด <?= e(setting('deposit_percent', '20')) ?>% ของยอดรวม</div></div>
          <div class="field"><label>ค่าส่งรถ/รับรถ (ต่อครั้ง)</label><input type="number" name="delivery_fee" step="0.01" min="0" value="<?= e($v['delivery_fee'] ?? 0) ?>"></div>
          <div class="field"><label>ค่าคนขับ (ต่อวัน)</label><input type="number" name="driver_fee_day" step="0.01" min="0" value="<?= e($v['driver_fee_day'] ?? 0) ?>"></div>
        </div>
      </div>

      <div class="panel mt-4">
        <h3>เอกสารและงานประจำรถ</h3>
        <p class="text-sm text-muted">กรอกวันครบกำหนด ระบบจะสร้างงานเตือนและแจ้งเตือนล่วงหน้า <?= e(setting('reminder_days_before', '30')) ?> วันอัตโนมัติ</p>
        <div class="grid gap-x-3.5 sm:grid-cols-2">
          <div class="field"><label>ประเภทประกัน</label><input type="text" name="insurance_type" value="<?= e($v['insurance_type'] ?? '') ?>" placeholder="ชั้น 1"></div>
          <div class="field"><label>ประกันหมดอายุ</label><input type="date" name="insurance_expire" value="<?= e($v['insurance_expire'] ?? '') ?>"></div>
          <div class="field"><label>พ.ร.บ. หมดอายุ</label><input type="date" name="act_expire" value="<?= e($v['act_expire'] ?? '') ?>"></div>
          <div class="field"><label>ภาษีหมดอายุ</label><input type="date" name="tax_expire" value="<?= e($v['tax_expire'] ?? '') ?>"></div>
          <div class="field"><label>เช็กระยะครั้งถัดไป (เลขไมล์)</label><input type="number" name="next_service_km" value="<?= e($v['next_service_km'] ?? '') ?>" min="0"></div>
          <div class="field"><label>เช็กระยะครั้งถัดไป (วันที่)</label><input type="date" name="next_service_at" value="<?= e($v['next_service_at'] ?? '') ?>"></div>
        </div>
      </div>
    </div>

    <div class="min-w-0">
      <div class="panel">
        <h3>สถานะ</h3>
        <div class="field">
          <select name="status">
            <option value="available"   <?= selected(($v['status'] ?? 'available') === 'available') ?>>พร้อมให้เช่า</option>
            <option value="maintenance" <?= selected(($v['status'] ?? '') === 'maintenance') ?>>เข้าศูนย์/ซ่อม</option>
            <option value="inactive"    <?= selected(($v['status'] ?? '') === 'inactive') ?>>ปิดใช้งาน (ซ่อนจากเว็บ)</option>
          </select>
        </div>
        <button class="btn btn-accent btn-block mt-3"><?= $id ? 'บันทึกการแก้ไข' : 'เพิ่มรถ' ?></button>
        <a class="btn btn-ghost btn-block mt-2" href="<?= url('admin/cars') ?>">ยกเลิก</a>
      </div>

      <div class="panel mt-4">
        <h3>รูปภาพ</h3>
        <?php if (!empty($car['cover_image'])): ?>
          <img class="mb-2.5 rounded-lg" src="<?= e(upload_url($car['cover_image'])) ?>" alt="">
        <?php endif; ?>
        <div class="field"><label>รูปหน้าปก</label><input type="file" name="cover_image" accept="image/*" data-shrink data-limit="<?= (int) $limit ?>"></div>
        <div class="field"><label>รูปเพิ่มเติม (เลือกได้หลายไฟล์)</label><input type="file" name="images[]" accept="image/*" multiple data-shrink data-limit="<?= (int) $limit ?>"></div>

        <?php if ($images): ?>
          <h4 class="mt-3 text-sm">รูปที่มีอยู่ (ติ๊กเพื่อลบ)</h4>
          <div class="grid grid-cols-3 gap-2">
            <?php foreach ($images as $im): ?>
              <label class="relative cursor-pointer">
                <img class="aspect-[4/3] rounded-md object-cover" src="<?= e(upload_url($im)) ?>" alt="">
                <input type="checkbox" name="remove_images[]" value="<?= e($im) ?>" class="absolute right-[5px] top-[5px]">
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>
