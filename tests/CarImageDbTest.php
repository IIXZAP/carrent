<?php
use App\Core\Database;
use App\Models\Car;
use App\Services\CarImageService;
use App\Services\UserError;

register_shutdown_function(static function (): void {
    foreach (glob(APP_ROOT . '/uploads/cars/t-legacy-*') ?: [] as $f) {
        @unlink($f);
    }
});

/** ไฟล์รูปจริงใน temp ในรูปแบบ $_FILES */
function t_img_file(string $type = 'jpg', int $w = 400, int $h = 300): array
{
    $path = tempnam(sys_get_temp_dir(), 'car');
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
    imagefilledrectangle($im, 5, 5, max(6, intdiv($w, 2)), max(6, intdiv($h, 2)), imagecolorallocate($im, 20, 20, 200));
    match ($type) { 'jpg' => imagejpeg($im, $path), 'webp' => imagewebp($im, $path), 'gif' => imagegif($im, $path), 'bmp' => imagebmp($im, $path), default => imagepng($im, $path) };
    return ['name' => 'รูป.' . $type, 'type' => 'image/' . $type, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
}

/** JPEG ที่มี EXIF Orientation ตามที่ระบุ (กล้องมือถือบันทึกภาพแนวตั้งเป็นภาพแนวนอน + ธง orientation) */
function t_img_jpeg_with_orientation(int $orientation, int $w, int $h): array
{
    $file = t_img_file('jpg', $w, $h);
    $jpg = (string) file_get_contents($file['tmp_name']);
    // TIFF little-endian, 1 entry: tag 0x0112 (Orientation) type SHORT count 1 value $orientation
    $tiff = "II\x2A\x00\x08\x00\x00\x00" . "\x01\x00" . "\x12\x01\x03\x00\x01\x00\x00\x00" . pack('v', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
    $app1 = "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\x00\x00" . $tiff;
    file_put_contents($file['tmp_name'], substr($jpg, 0, 2) . $app1 . substr($jpg, 2));
    $file['size'] = filesize($file['tmp_name']);
    return $file;
}

function t_img_cleanup(array $paths): void
{
    foreach ($paths as $p) {
        $full = CarImageService::absPath($p);
        if ($full) {
            @unlink($full);
        }
    }
}

// ---------- รับรูป ----------

function test_carimg_store_accepts_real_images_and_renames_randomly(): void
{
    $saved = [];
    foreach (['jpg', 'png', 'webp'] as $type) {
        $file = t_img_file($type);
        $path = CarImageService::store($file, false);
        $saved[] = $path;
        @unlink($file['tmp_name']);
        assert_true(preg_match('#^cars/[0-9a-f]{32}\.(webp|jpg)$#', $path) === 1, "$type → $path");
        $full = CarImageService::absPath($path);
        assert_true($full !== null && getimagesize($full) !== false, 'ไฟล์ที่เก็บเปิดเป็นรูปได้');
    }
    assert_same(3, count(array_unique($saved)));
    t_img_cleanup($saved);
}

function test_carimg_store_downsizes_large_images_keeping_aspect_but_never_upscales(): void
{
    $big = t_img_file('jpg', 3200, 2000);
    $p1 = CarImageService::store($big, false);
    [$w, $h] = getimagesize(CarImageService::absPath($p1));
    assert_same(1600, $w);
    assert_same(1000, $h);
    $small = t_img_file('png', 320, 200);
    $p2 = CarImageService::store($small, false);
    [$w2, $h2] = getimagesize(CarImageService::absPath($p2));
    assert_same([320, 200], [$w2, $h2], 'รูปเล็กห้ามขยาย');
    t_img_cleanup([$p1, $p2]);
    @unlink($big['tmp_name']);
    @unlink($small['tmp_name']);
}

function test_carimg_store_applies_exif_orientation_from_phone_photos(): void
{
    // ภาพจริงแนวตั้ง 300×400 ที่กล้องบันทึกเป็น 400×300 + orientation 6 (หมุน 90° ตามเข็ม)
    $file = t_img_jpeg_with_orientation(6, 400, 300);
    $p = CarImageService::store($file, false);
    [$w, $h] = getimagesize(CarImageService::absPath($p));
    assert_same([300, 400], [$w, $h], 'ต้องหมุนตาม EXIF ไม่งั้นรูปรถจากมือถือจะนอนข้าง');
    t_img_cleanup([$p]);
    @unlink($file['tmp_name']);
}

function test_carimg_store_rejects_non_images_and_bad_files(): void
{
    $cases = [];
    $txt = tempnam(sys_get_temp_dir(), 'car');
    file_put_contents($txt, 'hello');
    $cases['ข้อความ'] = ['name' => 'a.jpg', 'type' => 'image/jpeg', 'tmp_name' => $txt, 'error' => UPLOAD_ERR_OK, 'size' => 5];
    $php = tempnam(sys_get_temp_dir(), 'car');
    file_put_contents($php, "<?php echo 1;");
    $cases['php เปลี่ยนนามสกุล'] = ['name' => 'a.jpg', 'type' => 'image/jpeg', 'tmp_name' => $php, 'error' => UPLOAD_ERR_OK, 'size' => 13];
    $cases['gif'] = t_img_file('gif');
    $cases['bmp'] = t_img_file('bmp');
    $cases['ไม่ได้แนบ'] = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
    $cases['ใหญ่เกินที่เซิร์ฟเวอร์รับ'] = ['name' => 'a.jpg', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0];
    $huge = t_img_file('png', 13000, 8);
    $cases['กว้างเกินกำหนด'] = $huge;
    $fat = t_img_file('png');
    file_put_contents($fat['tmp_name'], str_repeat("\0", 9 * 1024 * 1024), FILE_APPEND);
    $cases['ไฟล์เกิน 8MB'] = ['size' => filesize($fat['tmp_name'])] + $fat;
    foreach ($cases as $name => $file) {
        t_expect_refused(fn() => CarImageService::store($file, false), $name);
        @unlink($file['tmp_name']);
    }
    assert_same([], glob(APP_ROOT . '/uploads/cars/*.tmp') ?: []);
}

function test_carimg_store_drops_payload_appended_to_a_real_image(): void
{
    $file = t_img_file('jpg');
    file_put_contents($file['tmp_name'], '<?php system($_GET["c"]); ?>', FILE_APPEND);
    $p = CarImageService::store($file, false);
    assert_true(strpos((string) file_get_contents(CarImageService::absPath($p)), '<?php') === false, 'ข้อมูลแฝงท้ายไฟล์ต้องหลุดจากการเข้ารหัสใหม่');
    t_img_cleanup([$p]);
    @unlink($file['tmp_name']);
}

function test_carimg_abs_path_is_confined(): void
{
    foreach (['../config/config.php', 'cars/../../config/config.php', '/etc/passwd', 'cars/' . str_repeat('a', 32) . '.php', "cars/x\0.jpg", 'slips/' . str_repeat('a', 32) . '.jpg', ''] as $bad) {
        assert_same(null, CarImageService::absPath($bad), json_encode($bad));
    }
}

// ---------- รูปปก ----------

function test_db_carimg_set_cover_replaces_and_deletes_only_our_own_old_file(): void
{
    $car = t_make_car();
    $f1 = t_img_file('jpg');
    $p1 = CarImageService::setCover($car, $f1, false);
    assert_same($p1, Car::find($car)['cover_image']);
    $f2 = t_img_file('png');
    $p2 = CarImageService::setCover($car, $f2, false);
    assert_same($p2, Car::find($car)['cover_image']);
    assert_same(null, CarImageService::absPath($p1), 'ไฟล์ปกเก่าที่ระบบสร้างเองต้องถูกลบ');
    assert_true(CarImageService::absPath($p2) !== null);
    CarImageService::removeCover($car);
    assert_same(null, Car::find($car)['cover_image']);
    assert_same(null, CarImageService::absPath($p2));
    @unlink($f1['tmp_name']);
    @unlink($f2['tmp_name']);
}

function test_db_carimg_legacy_named_cover_files_are_never_deleted(): void
{
    // รูปที่มากับ repo (เช่น cars/yaris-ativ.webp) ชื่อไม่ใช่รหัสสุ่ม — ห้ามลบ แม้ถูกเปลี่ยนปก
    $legacy = APP_ROOT . '/uploads/cars/t-legacy-' . bin2hex(random_bytes(3)) . '.webp';
    file_put_contents($legacy, 'x');
    $car = t_make_car();
    Database::run('UPDATE cars SET cover_image = :c WHERE id = :i', ['c' => 'cars/' . basename($legacy), 'i' => $car]);
    $f = t_img_file('jpg');
    $p = CarImageService::setCover($car, $f, false);
    assert_true(is_file($legacy), 'ห้ามลบไฟล์เดิมที่ไม่ได้ถูกสร้างโดยระบบอัปโหลด');
    CarImageService::removeCover($car);
    t_img_cleanup([$p]);
    @unlink($legacy);
    @unlink($f['tmp_name']);
}

function test_db_carimg_bad_upload_changes_nothing(): void
{
    $car = t_make_car();
    $f = t_img_file('jpg');
    $p = CarImageService::setCover($car, $f, false);
    $before = glob(APP_ROOT . '/uploads/cars/*') ?: [];
    $bad = tempnam(sys_get_temp_dir(), 'car');
    file_put_contents($bad, 'not an image');
    t_expect_refused(fn() => CarImageService::setCover($car, ['name' => 'a.jpg', 'type' => 'image/jpeg', 'tmp_name' => $bad, 'error' => 0, 'size' => 12], false), 'ไฟล์ไม่ใช่รูป');
    assert_same($p, Car::find($car)['cover_image'], 'ปกเดิมต้องอยู่');
    assert_same(count($before), count(glob(APP_ROOT . '/uploads/cars/*') ?: []), 'ไม่มีไฟล์ค้าง');
    t_expect_refused(fn() => CarImageService::setCover(99999999, $f, false), 'ไม่มีรถ');
    CarImageService::removeCover($car);
    @unlink($f['tmp_name']);
    @unlink($bad);
}

// ---------- แกลเลอรี ----------

function test_db_carimg_gallery_limit_order_and_removal(): void
{
    $car = t_make_car();
    $ids = [];
    $paths = [];
    for ($i = 0; $i < CarImageService::MAX_GALLERY; $i++) {
        $f = t_img_file('jpg', 100 + $i, 80);
        $ids[] = CarImageService::addGallery($car, $f, false);
        @unlink($f['tmp_name']);
    }
    $list = Car::images($car);
    assert_same(CarImageService::MAX_GALLERY, count($list));
    assert_same($ids, array_map('intval', array_column($list, 'id')), 'เรียงตามลำดับที่เพิ่ม');
    $extra = t_img_file('jpg');
    t_expect_refused(fn() => CarImageService::addGallery($car, $extra, false), 'เกินจำนวนสูงสุด');
    assert_same(CarImageService::MAX_GALLERY, count(Car::images($car)));
    $first = $list[0]['path'];
    CarImageService::removeGallery($ids[0]);
    assert_same(null, CarImageService::absPath($first), 'ลบรูปแล้วไฟล์ต้องหาย');
    assert_same(CarImageService::MAX_GALLERY - 1, count(Car::images($car)));
    t_expect_refused(fn() => CarImageService::removeGallery($ids[0]), 'ลบซ้ำ');
    CarImageService::addGallery($car, $extra, false);   // ว่างหนึ่งช่องแล้ว
    foreach (Car::images($car) as $img) {
        CarImageService::removeGallery((int) $img['id']);
    }
    assert_same([], Car::images($car));
    @unlink($extra['tmp_name']);
}

function test_db_carimg_deleting_car_row_removes_gallery_rows_but_cascade_leaves_no_orphans_in_db(): void
{
    $car = t_make_car();
    $f = t_img_file('png');
    $id = CarImageService::addGallery($car, $f, false);
    $path = Database::value('SELECT path FROM car_images WHERE id = :i', ['i' => $id]);
    Database::run('DELETE FROM cars WHERE id = :i', ['i' => $car]);
    assert_same(0, (int) Database::value('SELECT COUNT(*) FROM car_images WHERE car_id = :c', ['c' => $car]));
    t_img_cleanup([$path]);
    @unlink($f['tmp_name']);
}
