<?php

namespace App\Services;

/**
 * อัปโหลดไฟล์เข้าโฟลเดอร์ uploads (ตรวจชนิดจริงด้วย finfo — ไม่เชื่อ extension)
 * ไฟล์ที่ห้ามเปิดผ่านลิงก์ตรงให้ใช้ PrivateFileService แทน
 */
final class UploadService
{
    private const ALLOWED = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** ขนาดอัปโหลดสูงสุดที่ใช้ได้จริง (หน่วยไบต์) — ค่าที่น้อยกว่าระหว่าง upload_max กับ post_max */
    public static function limitBytes(): int
    {
        $limits = array_filter([
            self::iniToBytes((string) ini_get('upload_max_filesize')),
            self::iniToBytes((string) ini_get('post_max_size')),
        ]);
        return $limits ? min($limits) : 2 * 1024 * 1024;
    }

    /** แปลงค่าแบบ php.ini เช่น "8M" "2G" "512K" เป็นไบต์ */
    private static function iniToBytes(string $value): int
    {
        $value  = trim($value);
        $number = (int) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
        }
        return $number;
    }

    /** แปลงรหัสข้อผิดพลาดของ PHP เป็นภาษาที่แอดมินหน้างานเข้าใจและแก้ได้เอง */
    public static function errorMessage(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (ขีดจำกัด ' . ini_get('upload_max_filesize') . ') '
                     . 'กรุณาถ่ายผ่านหน้าเว็บบนมือถือ ระบบจะย่อรูปให้อัตโนมัติ';
            case UPLOAD_ERR_PARTIAL:
                return 'อัปโหลดไม่ครบ สัญญาณอาจหลุดกลางคัน กรุณาลองใหม่';
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
                return 'เซิร์ฟเวอร์เขียนไฟล์ไม่ได้ กรุณาติดต่อผู้ดูแลระบบ';
        }
        return 'อัปโหลดไฟล์ไม่สำเร็จ (code ' . $code . ')';
    }

    /**
     * บันทึกไฟล์ที่อัปโหลด คืน path แบบสัมพัทธ์จาก uploads/ เช่น slips/20261021_103000_ab12….jpg
     * คืน null ถ้าไม่ได้แนบไฟล์
     * @throws \RuntimeException ไฟล์ไม่ผ่านการตรวจ
     */
    public static function save(array $file, string $subdir, int $maxMB = 8): ?string
    {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::errorMessage((int) $file['error']));
        }
        if ($file['size'] > $maxMB * 1024 * 1024) {
            throw new \RuntimeException("ไฟล์ใหญ่เกิน {$maxMB} MB");
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!isset(self::ALLOWED[$mime])) {
            throw new \RuntimeException('รองรับเฉพาะไฟล์ JPG, PNG, WEBP และ PDF');
        }

        $dir = rtrim(UPLOAD_DIR, '/') . '/' . trim($subdir, '/');
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('สร้างโฟลเดอร์อัปโหลดไม่สำเร็จ');
        }
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . self::ALLOWED[$mime];
        if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            throw new \RuntimeException('บันทึกไฟล์ไม่สำเร็จ');
        }
        return trim($subdir, '/') . '/' . $name;
    }

    /**
     * ย่อรูปฝั่งเซิร์ฟเวอร์ — กันกรณีเบราว์เซอร์ย่อไม่สำเร็จ
     *
     * ปกติหน้าเว็บย่อให้ก่อนส่งแล้ว แต่ถ้า JS ถูกปิด เบราว์เซอร์เก่า
     * หรือแอดมินอัปจากคอมโดยตรง รูปดิบ 4–5 MB จะกินพื้นที่ shared hosting เร็วมาก
     * ฟังก์ชันนี้ย่อซ้ำให้เสมอ และถ้าเครื่องไม่มี GD ก็ปล่อยไฟล์เดิมไว้ ไม่ทำให้ระบบพัง
     */
    public static function shrink(string $fullPath, int $maxSide = 1600, int $quality = 82): void
    {
        if (!function_exists('imagecreatefromjpeg') || !is_file($fullPath)) {
            return;
        }
        $info = @getimagesize($fullPath);
        if (!$info) {
            return;
        }
        [$w, $h, $type] = $info;
        if (max($w, $h) <= $maxSide) {
            return;   // เล็กอยู่แล้ว (เบราว์เซอร์ย่อมาให้แล้ว)
        }
        $src = self::openImage($fullPath, $type);
        if (!$src) {
            return;
        }
        $scale  = $maxSide / max($w, $h);
        $newW   = max(1, (int) round($w * $scale));
        $newH   = max(1, (int) round($h * $scale));
        $resized = imagecreatetruecolor($newW, $newH);
        if ($type !== IMAGETYPE_JPEG) {
            // PNG / WEBP: คงพื้นโปร่งใสไว้
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        self::writeImage($resized, $fullPath, $type, $quality);
        imagedestroy($src);
        imagedestroy($resized);
    }

    /** บันทึกรูปในชนิดเดิมของไฟล์ (นามสกุลกับเนื้อไฟล์จะได้ตรงกัน) */
    private static function writeImage($image, string $path, int $type, int $quality): void
    {
        if ($type === IMAGETYPE_PNG) {
            @imagepng($image, $path, 6);
        } elseif ($type === IMAGETYPE_WEBP && function_exists('imagewebp')) {
            @imagewebp($image, $path, $quality);
        } else {
            @imagejpeg($image, $path, $quality);
        }
    }

    /** เปิดรูปตามชนิด (JPEG / PNG / WEBP) คืน false ถ้าเปิดไม่ได้ */
    private static function openImage(string $path, int $type)
    {
        if ($type === IMAGETYPE_JPEG) {
            return @imagecreatefromjpeg($path);
        }
        if ($type === IMAGETYPE_PNG) {
            return @imagecreatefrompng($path);
        }
        if ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
            return @imagecreatefromwebp($path);
        }
        return false;
    }

    /** ขนาดรวมและจำนวนไฟล์ในโฟลเดอร์ (หยุดนับที่ 20,000 ไฟล์ กันช้า) */
    public static function dirUsage(string $dir): array
    {
        $bytes = 0;
        $files = 0;
        if (!is_dir($dir)) {
            return [0, 0];
        }
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $bytes += $f->getSize();
                    if (++$files >= 20000) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // อ่านโฟลเดอร์ไม่ได้ — คืนเท่าที่นับได้
        }
        return [$bytes, $files];
    }
}
