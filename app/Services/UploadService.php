<?php
namespace App\Services;

/**
 * รับไฟล์รูปที่ผู้ใช้อัปโหลด (สลิปโอนเงิน) เก็บใน storage/private — เปิดผ่านเว็บตรงๆ ไม่ได้
 * ต้องให้ controller ที่ตรวจสิทธิ์แล้วส่งไฟล์ให้ (serve)
 *
 * ความปลอดภัย: ตรวจจากเนื้อไฟล์จริง (ไม่เชื่อนามสกุล/Content-Type ที่ผู้ใช้ส่ง), ตั้งชื่อไฟล์ใหม่เป็นเลขสุ่ม,
 * นามสกุลมาจากชนิดที่ตรวจเจอ, โฟลเดอร์ย่อยจำกัดเฉพาะที่อนุญาต
 */
final class UploadService
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_PIXELS_SIDE = 12000;
    private const SUBDIRS = ['slips', 'inspections'];
    private const TYPES = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG  => ['png', 'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    /**
     * @param array $file   ค่าจาก $_FILES['ชื่อช่อง']
     * @param bool  $viaHttp true = ต้องมาจากการอัปโหลดจริง (is_uploaded_file) ; false ใช้ในเทสต์เท่านั้น
     * @return string path แบบ relative ใต้ storage/private เช่น "slips/3f9a....jpg"
     * @throws UserError
     */
    public static function saveImage(array $file, string $subdir, bool $viaHttp = true): string
    {
        if (!in_array($subdir, self::SUBDIRS, true)) {
            throw new \InvalidArgumentException('โฟลเดอร์ปลายทางไม่ได้รับอนุญาต');
        }
        self::checkUploadError((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE));

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || ($viaHttp && !is_uploaded_file($tmp)) || !is_file($tmp)) {
            throw new UserError('กรุณาแนบไฟล์รูป');
        }
        if (filesize($tmp) > self::MAX_BYTES) {
            throw new UserError('ไฟล์ใหญ่เกิน ' . (self::MAX_BYTES / 1024 / 1024) . ' MB');
        }

        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::TYPES[$info[2]])) {
            throw new UserError('รองรับเฉพาะไฟล์รูป JPG, PNG หรือ WEBP');
        }
        if ($info[0] > self::MAX_PIXELS_SIDE || $info[1] > self::MAX_PIXELS_SIDE || $info[0] < 1 || $info[1] < 1) {
            throw new UserError('ขนาดรูปไม่ถูกต้อง');
        }

        [$ext] = self::TYPES[$info[2]];
        $dir = storage_path($subdir);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('สร้างโฟลเดอร์เก็บไฟล์ไม่ได้: ' . $dir);
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        $moved = $viaHttp ? move_uploaded_file($tmp, $dest) : copy($tmp, $dest);
        if (!$moved) {
            throw new \RuntimeException('บันทึกไฟล์ไม่สำเร็จ');
        }
        chmod($dest, 0640);
        return $subdir . '/' . $name;
    }

    private static function checkUploadError(int $code): void
    {
        if ($code === UPLOAD_ERR_OK) {
            return;
        }
        if ($code === UPLOAD_ERR_NO_FILE) {
            throw new UserError('กรุณาแนบไฟล์รูป');
        }
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            throw new UserError('ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ กรุณาลดขนาดรูปแล้วลองใหม่');
        }
        throw new UserError('อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่');
    }

    /** path เต็มของไฟล์ที่เก็บไว้ — null ถ้าไม่ปลอดภัย/ไม่พบ (กัน path traversal) */
    public static function resolve(string $relative): ?string
    {
        if (!preg_match('#^(' . implode('|', self::SUBDIRS) . ')/[0-9a-f]{32}\.(jpg|png|webp)$#', $relative)) {
            return null;
        }
        $full = storage_path($relative);
        return is_file($full) ? $full : null;
    }

    /** ส่งไฟล์ให้เบราว์เซอร์ (เรียกหลังตรวจสิทธิ์แล้วเท่านั้น) */
    public static function serve(string $relative): void
    {
        $full = self::resolve($relative);
        if ($full === null) {
            abort(404);
        }
        $info = getimagesize($full);
        $mime = self::TYPES[$info[2]][1] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($full));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        readfile($full);
        exit;
    }

    public static function delete(string $relative): void
    {
        $full = self::resolve($relative);
        if ($full !== null) {
            @unlink($full);
        }
    }
}
