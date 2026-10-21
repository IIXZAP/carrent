<?php
/**
 * เพิ่ม / แก้ไขข้อมูลรถ + อัปโหลดรูป + วันครบกำหนดเอกสาร
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

// ไฟล์ใหญ่เกิน post_max_size → PHP ทิ้ง $_POST ทั้งก้อน รวมทั้ง CSRF token
// ต้องดักก่อน ไม่งั้นหน้าจะเด้งออกแบบไม่มีข้อความบอกว่าเกิดอะไรขึ้น
if (post_too_large()) {
    flash('danger', 'ไฟล์ที่อัปโหลดใหญ่เกินไป (เซิร์ฟเวอร์รับได้ไม่เกิน '
        . round(upload_limit_bytes() / 1048576, 1) . ' MB) กรุณาย่อรูปก่อนอัปโหลด');
    redirect(safe_referer_path('admin/dashboard.php'));
}

$id  = (int) ($_GET['id'] ?? 0);
$car = $id ? db_one('SELECT * FROM cars WHERE id = :i', [':i' => $id]) : null;
if ($id && !$car) {
    flash('danger', 'ไม่พบรถคันนี้');
    redirect('admin/cars.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $data = [
            'plate'            => trim($_POST['plate'] ?? ''),
            'province'         => trim($_POST['province'] ?? '') ?: null,
            'brand'            => trim($_POST['brand'] ?? ''),
            'model'            => trim($_POST['model'] ?? ''),
            'year'             => (int) ($_POST['year'] ?? 0) ?: null,
            'color'            => trim($_POST['color'] ?? '') ?: null,
            'car_type'         => in_array($_POST['car_type'] ?? '', ['eco','sedan','suv','pickup','van'], true) ? $_POST['car_type'] : 'sedan',
            'seats'            => (int) ($_POST['seats'] ?? 5),
            'gear'             => ($_POST['gear'] ?? 'auto') === 'manual' ? 'manual' : 'auto',
            'fuel'             => trim($_POST['fuel'] ?? 'เบนซิน'),
            'price_day'        => (float) ($_POST['price_day'] ?? 0),
            'price_week'       => (float) ($_POST['price_week'] ?? 0),
            'price_month'      => (float) ($_POST['price_month'] ?? 0),
            'deposit'          => (float) ($_POST['deposit'] ?? 0),
            'deposit_booking'  => (float) ($_POST['deposit_booking'] ?? 0),
            'delivery_fee'     => (float) ($_POST['delivery_fee'] ?? 0),
            'driver_fee_day'   => (float) ($_POST['driver_fee_day'] ?? 0),
            'status'           => in_array($_POST['status'] ?? '', ['available','maintenance','inactive'], true) ? $_POST['status'] : 'available',
            'mileage'          => (int) ($_POST['mileage'] ?? 0),
            'insurance_type'   => trim($_POST['insurance_type'] ?? '') ?: null,
            'insurance_expire' => trim($_POST['insurance_expire'] ?? '') ?: null,
            'act_expire'       => trim($_POST['act_expire'] ?? '') ?: null,
            'tax_expire'       => trim($_POST['tax_expire'] ?? '') ?: null,
            'next_service_km'  => (int) ($_POST['next_service_km'] ?? 0) ?: null,
            'next_service_at'  => trim($_POST['next_service_at'] ?? '') ?: null,
            'description'      => trim($_POST['description'] ?? '') ?: null,
            'sort_order'       => (int) ($_POST['sort_order'] ?? 0),
        ];
        if ($data['plate'] === '' || $data['brand'] === '' || $data['model'] === '') {
            throw new RuntimeException('กรุณากรอก ทะเบียน / ยี่ห้อ / รุ่น');
        }
        if ($data['price_day'] <= 0) {
            throw new RuntimeException('กรุณากรอกราคาเช่ารายวัน');
        }

        // อุปกรณ์/จุดเด่น (บรรทัดละ 1 รายการ)
        $feat = array_values(array_filter(array_map('trim', explode("\n", (string) ($_POST['features'] ?? '')))));
        $data['features'] = $feat ? json_encode($feat, JSON_UNESCAPED_UNICODE) : null;

        // รูปหน้าปก
        $cover = upload_file($_FILES['cover_image'] ?? [], 'cars', 6);
        if ($cover) {
            $data['cover_image'] = $cover;
        }

        // รูปเพิ่มเติม
        $existing = $car ? (json_decode((string) $car['images'], true) ?: []) : [];
        if (!empty($_FILES['images']['name'][0])) {
            foreach ($_FILES['images']['name'] as $i => $nm) {
                if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $one = [
                    'name' => $_FILES['images']['name'][$i], 'type' => $_FILES['images']['type'][$i],
                    'tmp_name' => $_FILES['images']['tmp_name'][$i], 'error' => $_FILES['images']['error'][$i],
                    'size' => $_FILES['images']['size'][$i],
                ];
                $path = upload_file($one, 'cars', 6);
                if ($path) $existing[] = $path;
            }
        }
        // ลบรูปที่ติ๊กไว้ (ลบเฉพาะการอ้างอิง — ไฟล์จริงคงอยู่ในเซิร์ฟเวอร์)
        $remove = (array) ($_POST['remove_images'] ?? []);
        if ($remove) {
            $existing = array_values(array_diff($existing, $remove));
        }
        $data['images'] = $existing ? json_encode(array_values($existing), JSON_UNESCAPED_UNICODE) : null;

        if ($id) {
            db_update('cars', $data, 'id', $id);
            log_activity('car_updated', 'cars', $id, $data['plate']);
            flash('ok', 'บันทึกข้อมูลรถแล้ว');
        } else {
            $id = db_insert('cars', $data);
            log_activity('car_created', 'cars', $id, $data['plate']);
            flash('ok', 'เพิ่มรถเรียบร้อย');
        }

        // สร้างงานประจำรถอัตโนมัติจากวันครบกำหนด
        foreach ([['insurance', 'ต่อประกันภัย', $data['insurance_expire']],
                  ['act', 'ต่อ พ.ร.บ.', $data['act_expire']],
                  ['tax', 'ต่อภาษีประจำปี', $data['tax_expire']],
                  ['service', 'เช็กระยะ/เปลี่ยนถ่ายน้ำมัน', $data['next_service_at']]] as [$tt, $title, $due]) {
            if (!$due) continue;
            $exists = db_val(
                "SELECT id FROM maintenances WHERE car_id = :c AND task_type = :t AND due_date = :d AND status = 'pending'",
                [':c' => $id, ':t' => $tt, ':d' => $due]
            );
            if (!$exists) {
                db_insert('maintenances', [
                    'car_id' => $id, 'task_type' => $tt,
                    'title' => $title . ' ' . $data['plate'], 'due_date' => $due, 'status' => 'pending',
                ]);
            }
        }
        redirect('admin/cars.php');
    } catch (Throwable $ex) {
        flash('danger', $ex->getMessage());
    }
}

$v      = $car ?: [];
$feats  = $car ? (json_decode((string) $car['features'], true) ?: []) : [];
$images = $car ? (json_decode((string) $car['images'], true) ?: []) : [];

admin_head($id ? 'แก้ไขรถ ' . $car['plate'] : 'เพิ่มรถใหม่', 'cars.php');
?>
<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="split">
    <div>
      <div class="panel">
        <h3>ข้อมูลรถ</h3>
        <div class="g2">
          <div class="field"><label>ทะเบียน *</label><input type="text" name="plate" value="<?= e($v['plate'] ?? '') ?>" required></div>
          <div class="field"><label>จังหวัด</label><input type="text" name="province" value="<?= e($v['province'] ?? 'กรุงเทพมหานคร') ?>"></div>
          <div class="field"><label>ยี่ห้อ *</label><input type="text" name="brand" value="<?= e($v['brand'] ?? '') ?>" required></div>
          <div class="field"><label>รุ่น *</label><input type="text" name="model" value="<?= e($v['model'] ?? '') ?>" required></div>
          <div class="field"><label>ปี (ค.ศ.)</label><input type="number" name="year" value="<?= e($v['year'] ?? '') ?>" min="1990" max="2100"></div>
          <div class="field"><label>สี</label><input type="text" name="color" value="<?= e($v['color'] ?? '') ?>"></div>
          <div class="field"><label>ประเภท</label>
            <select name="car_type">
              <?php foreach (['eco','sedan','suv','pickup','van'] as $t): ?>
                <option value="<?= $t ?>" <?= ($v['car_type'] ?? '') === $t ? 'selected' : '' ?>><?= e(car_type_label($t)) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>จำนวนที่นั่ง</label><input type="number" name="seats" value="<?= e($v['seats'] ?? 5) ?>" min="2" max="20"></div>
          <div class="field"><label>เกียร์</label>
            <select name="gear">
              <option value="auto"   <?= ($v['gear'] ?? '') === 'auto' ? 'selected' : '' ?>>ออโต้</option>
              <option value="manual" <?= ($v['gear'] ?? '') === 'manual' ? 'selected' : '' ?>>ธรรมดา</option>
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

      <div class="panel mt-2">
        <h3>ราคาเช่า</h3>
        <p class="small muted">เว้นเรตสัปดาห์/เดือนเป็น 0 ระบบจะคิดจากรายวันให้อัตโนมัติ ลูกค้าจะได้ราคาที่ถูกที่สุดเสมอ</p>
        <div class="g3">
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

      <div class="panel mt-2">
        <h3>เอกสารและงานประจำรถ</h3>
        <p class="small muted">กรอกวันครบกำหนด ระบบจะสร้างงานเตือนและแจ้งเตือนล่วงหน้า <?= e(setting('reminder_days_before', '30')) ?> วันอัตโนมัติ</p>
        <div class="g2">
          <div class="field"><label>ประเภทประกัน</label><input type="text" name="insurance_type" value="<?= e($v['insurance_type'] ?? '') ?>" placeholder="ชั้น 1"></div>
          <div class="field"><label>ประกันหมดอายุ</label><input type="date" name="insurance_expire" value="<?= e($v['insurance_expire'] ?? '') ?>"></div>
          <div class="field"><label>พ.ร.บ. หมดอายุ</label><input type="date" name="act_expire" value="<?= e($v['act_expire'] ?? '') ?>"></div>
          <div class="field"><label>ภาษีหมดอายุ</label><input type="date" name="tax_expire" value="<?= e($v['tax_expire'] ?? '') ?>"></div>
          <div class="field"><label>เช็กระยะครั้งถัดไป (เลขไมล์)</label><input type="number" name="next_service_km" value="<?= e($v['next_service_km'] ?? '') ?>" min="0"></div>
          <div class="field"><label>เช็กระยะครั้งถัดไป (วันที่)</label><input type="date" name="next_service_at" value="<?= e($v['next_service_at'] ?? '') ?>"></div>
        </div>
      </div>
    </div>

    <div>
      <div class="panel">
        <h3>สถานะ</h3>
        <div class="field">
          <select name="status">
            <option value="available"   <?= ($v['status'] ?? '') === 'available' ? 'selected' : '' ?>>พร้อมให้เช่า</option>
            <option value="maintenance" <?= ($v['status'] ?? '') === 'maintenance' ? 'selected' : '' ?>>เข้าศูนย์/ซ่อม</option>
            <option value="inactive"    <?= ($v['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>ปิดใช้งาน (ซ่อนจากเว็บ)</option>
          </select>
        </div>
        <button class="btn btn-accent btn-block mt-2"><?= $id ? 'บันทึกการแก้ไข' : 'เพิ่มรถ' ?></button>
        <a class="btn btn-ghost btn-block mt-1" href="<?= url('admin/cars.php') ?>">ยกเลิก</a>
      </div>

      <div class="panel mt-2">
        <h3>รูปภาพ</h3>
        <?php if (!empty($v['cover_image'])): ?>
          <img src="<?= e(upload_url($v['cover_image'])) ?>" alt="" style="border-radius:8px;margin-bottom:10px">
        <?php endif; ?>
        <div class="field"><label>รูปหน้าปก</label><input type="file" name="cover_image" accept="image/*" data-shrink data-limit="<?= upload_limit_bytes() ?>"></div>
        <div class="field"><label>รูปเพิ่มเติม (เลือกได้หลายไฟล์)</label><input type="file" name="images[]" accept="image/*" multiple data-shrink data-limit="<?= upload_limit_bytes() ?>"></div>

        <?php if ($images): ?>
          <h4 class="small mt-2">รูปที่มีอยู่ (ติ๊กเพื่อลบ)</h4>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
            <?php foreach ($images as $im): ?>
              <label style="position:relative;cursor:pointer">
                <img src="<?= e(upload_url($im)) ?>" alt="" style="border-radius:6px;aspect-ratio:4/3;object-fit:cover">
                <input type="checkbox" name="remove_images[]" value="<?= e($im) ?>" style="position:absolute;top:5px;right:5px">
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>
<?php admin_foot(); ?>
