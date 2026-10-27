<?php
use App\Services\UploadService;
use App\Services\UserError;

/** สร้างไฟล์รูปจริงใน temp แล้วคืนโครงสร้างแบบ $_FILES */
function t_upload_file(string $type = 'png', ?string $rawContent = null): array
{
    $path = tempnam(sys_get_temp_dir(), 'slip');
    if ($rawContent !== null) {
        file_put_contents($path, $rawContent);
    } else {
        $img = imagecreatetruecolor(40, 30);
        $type === 'jpg' ? imagejpeg($img, $path) : ($type === 'webp' ? imagewebp($img, $path) : imagepng($img, $path));
    }
    return ['name' => 'สลิป.' . $type, 'type' => 'image/' . $type, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
}

function t_upload_expect_refused(array $file, string $label): void
{
    $refused = false;
    try {
        $saved = UploadService::saveImage($file, 'slips', false);
        UploadService::delete($saved);
    } catch (UserError $e) {
        $refused = true;
    }
    @unlink($file['tmp_name']);
    assert_true($refused, $label);
}

function test_upload_accepts_real_images_and_names_them_randomly(): void
{
    $names = [];
    foreach (['png', 'jpg', 'webp'] as $type) {
        $file = t_upload_file($type);
        $saved = UploadService::saveImage($file, 'slips', false);
        $names[] = $saved;
        try {
            assert_true(preg_match('#^slips/[0-9a-f]{32}\.' . $type . '$#', $saved) === 1, "$type → $saved");
            assert_true(UploadService::resolve($saved) !== null, 'resolve ได้');
            assert_true(strpos($saved, 'สลิป') === false, 'ไม่ใช้ชื่อที่ผู้ใช้ตั้ง');
        } finally {
            UploadService::delete($saved);
            @unlink($file['tmp_name']);
        }
    }
    assert_same(3, count(array_unique($names)));
}

function test_upload_refuses_non_images_even_with_image_extension(): void
{
    t_upload_expect_refused(t_upload_file('png', "<?php system(\$_GET['c']); ?>"), 'สคริปต์ PHP นามสกุล .png');
    t_upload_expect_refused(t_upload_file('png', "GIF89a<?php echo 1;"), 'GIF (ไม่อยู่ในชนิดที่รับ)');
    t_upload_expect_refused(t_upload_file('png', '%PDF-1.4 fake'), 'PDF');
    t_upload_expect_refused(t_upload_file('png', ''), 'ไฟล์ว่าง');
}

function test_upload_refuses_real_images_of_unsupported_types(): void
{
    $gif = tempnam(sys_get_temp_dir(), 'slip');
    imagegif(imagecreatetruecolor(20, 20), $gif);
    $bmp = tempnam(sys_get_temp_dir(), 'slip');
    imagebmp(imagecreatetruecolor(20, 20), $bmp);
    foreach (['gif' => $gif, 'bmp' => $bmp] as $type => $path) {
        t_upload_expect_refused(['name' => "x.$type", 'type' => "image/$type", 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)], "รูป $type จริง แต่ไม่ใช่ชนิดที่รับ");
    }
}

function test_upload_refuses_oversized_file(): void
{
    $file = t_upload_file('png');
    $fh = fopen($file['tmp_name'], 'ab');
    ftruncate($fh, UploadService::MAX_BYTES + 1);
    fclose($fh);
    clearstatcache();
    t_upload_expect_refused($file, 'ใหญ่เกิน 8MB');
}

function test_upload_refuses_upload_errors(): void
{
    foreach ([UPLOAD_ERR_NO_FILE, UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL] as $code) {
        $refused = false;
        try {
            UploadService::saveImage(['error' => $code, 'tmp_name' => ''], 'slips', false);
        } catch (UserError $e) {
            $refused = true;
        }
        assert_true($refused, "error code $code");
    }
}

function test_upload_rejects_unlisted_subdir_and_bad_paths(): void
{
    $threw = false;
    try {
        UploadService::saveImage(t_upload_file(), '../../public', false);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, 'โฟลเดอร์นอกรายการต้องถูกปฏิเสธ');

    foreach (['../../.env', 'slips/../../config/config.php', 'slips/abc.jpg', 'slips/' . str_repeat('a', 32) . '.php', '/etc/passwd', 'slips/' . str_repeat('a', 32) . '.jpg/../../x'] as $bad) {
        assert_same(null, UploadService::resolve($bad), $bad);
    }
}
