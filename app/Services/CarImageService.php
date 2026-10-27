<?php
namespace App\Services;

use App\Core\Database;
use App\Models\Car;

/**
 * รูปรถ (เปิดดูได้สาธารณะ ใช้โชว์หน้าเว็บ) เก็บที่ uploads/cars/
 *
 * ทุกรูปถูก "เข้ารหัสใหม่" ด้วย GD: ข้อมูลแฝงท้ายไฟล์/EXIF (รวมพิกัด GPS) หลุดหมด · หมุนตาม EXIF ให้รูปจากมือถือตั้งตรง ·
 * ย่อให้ด้านยาวไม่เกิน 1600 px (ไม่ขยายรูปเล็ก) · ตั้งชื่อใหม่เป็นเลขสุ่ม · เก็บเป็น WEBP (ถ้าโฮสต์ไม่รองรับใช้ JPEG)
 * ลบไฟล์เฉพาะที่ระบบอัปโหลดสร้างเอง (ชื่อเลขสุ่ม) — รูปเดิมที่มากับโปรเจกต์ไม่ถูกลบ
 */
final class CarImageService
{
    public const MAX_GALLERY = 8;
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_SIDE_IN = 12000;
    private const MAX_PIXELS_IN = 40_000_000;
    private const MAX_SIDE_OUT = 1600;
    private const OWN_NAME = '#^cars/[0-9a-f]{32}\.(webp|jpg)$#';

    private static function dir(): string
    {
        return APP_ROOT . '/uploads/cars';
    }

    /** path เต็มของรูปรถ — null ถ้าไม่ใช่ path ในโฟลเดอร์รูปรถ/ไม่มีไฟล์ (กัน path traversal) */
    public static function absPath(string $rel): ?string
    {
        if (!preg_match('#^cars/[A-Za-z0-9._-]{1,120}\.(webp|jpg|jpeg|png)\z#', $rel) || str_contains($rel, '..')) {
            return null;
        }
        $full = APP_ROOT . '/uploads/' . $rel;
        return is_file($full) ? $full : null;
    }

    /** @return string path แบบ relative เช่น cars/3f9a….webp @throws UserError */
    public static function store(array $file, bool $viaHttp = true): string
    {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            throw new UserError('ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ กรุณาลดขนาดรูปแล้วลองใหม่');
        }
        if ($code !== UPLOAD_ERR_OK) {
            throw new UserError('กรุณาแนบไฟล์รูป');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || ($viaHttp && !is_uploaded_file($tmp))) {
            throw new UserError('กรุณาแนบไฟล์รูป');
        }
        if (filesize($tmp) > self::MAX_BYTES) {
            throw new UserError('ไฟล์ใหญ่เกิน ' . (self::MAX_BYTES / 1024 / 1024) . ' MB');
        }
        $info = @getimagesize($tmp);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new UserError('รองรับเฉพาะไฟล์รูป JPG, PNG หรือ WEBP');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_SIDE_IN || $info[1] > self::MAX_SIDE_IN || $info[0] * $info[1] > self::MAX_PIXELS_IN) {
            throw new UserError('ขนาดรูปไม่ถูกต้องหรือความละเอียดสูงเกินไป กรุณาลดขนาดรูปแล้วลองใหม่');
        }

        $image = @imagecreatefromstring((string) file_get_contents($tmp));
        if (!$image) {
            throw new UserError('เปิดไฟล์รูปไม่ได้ กรุณาลองรูปอื่น');
        }
        $image = self::orient($image, $info[2] === IMAGETYPE_JPEG ? self::exifOrientation($tmp) : 1);
        $image = self::fit($image);

        $useWebp = function_exists('imagewebp');
        $ext = $useWebp ? 'webp' : 'jpg';
        ob_start();
        if ($useWebp) {
            imagesavealpha($image, true);
            imagewebp($image, null, 82);
        } else {
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            imagejpeg($flat, null, 85);
            imagedestroy($flat);
        }
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        if ($bytes === '') {
            throw new \RuntimeException('บันทึกรูปไม่สำเร็จ');
        }

        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('สร้างโฟลเดอร์เก็บรูปไม่ได้');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $name, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('บันทึกรูปไม่สำเร็จ');
        }
        chmod($dir . '/' . $name, 0644);
        return 'cars/' . $name;
    }

    private static function exifOrientation(string $file): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($file);
        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    /** หมุน/พลิกให้ตั้งตรงตามค่า EXIF Orientation (1,2,3,4,6,8) */
    private static function orient(\GdImage $im, int $o): \GdImage
    {
        $out = match ($o) {
            2 => (imageflip($im, IMG_FLIP_HORIZONTAL) ? $im : $im),
            3 => imagerotate($im, 180, 0),
            4 => (imageflip($im, IMG_FLIP_VERTICAL) ? $im : $im),
            6 => imagerotate($im, -90, 0),
            8 => imagerotate($im, 90, 0),
            default => $im,
        };
        if ($out === false) {
            return $im;
        }
        if ($out !== $im) {
            imagedestroy($im);
        }
        return $out;
    }

    /** ย่อให้ด้านยาวไม่เกิน MAX_SIDE_OUT (ไม่ขยายรูปเล็ก) */
    private static function fit(\GdImage $im): \GdImage
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $long = max($w, $h);
        if ($long <= self::MAX_SIDE_OUT) {
            return $im;
        }
        $ratio = self::MAX_SIDE_OUT / $long;
        $scaled = imagescale($im, max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio)), IMG_BICUBIC);
        if ($scaled === false) {
            return $im;
        }
        imagedestroy($im);
        return $scaled;
    }

    /** ลบไฟล์เฉพาะที่ระบบอัปโหลดสร้างเอง (ชื่อเลขสุ่ม) */
    private static function deleteOwn(?string $rel): void
    {
        if ($rel !== null && preg_match(self::OWN_NAME, $rel) === 1 && ($full = self::absPath($rel)) !== null) {
            @unlink($full);
        }
    }

    /** @throws UserError */
    public static function setCover(int $carId, array $file, bool $viaHttp = true): string
    {
        if (Car::find($carId) === null) {
            throw new UserError('ไม่พบรถ');
        }
        $path = self::store($file, $viaHttp);
        try {
            $old = Database::transaction(static function () use ($carId, $path): ?string {
                $car = Car::lockForUpdate($carId);
                if ($car === null) {
                    throw new UserError('ไม่พบรถ');
                }
                Database::run('UPDATE cars SET cover_image = :p WHERE id = :id', ['p' => $path, 'id' => $carId]);
                return $car['cover_image'];
            });
        } catch (\Throwable $e) {
            self::deleteOwn($path);
            throw $e;
        }
        self::deleteOwn($old);
        return $path;
    }

    /** @throws UserError */
    public static function removeCover(int $carId): void
    {
        $old = Database::transaction(static function () use ($carId): ?string {
            $car = Car::lockForUpdate($carId);
            if ($car === null) {
                throw new UserError('ไม่พบรถ');
            }
            Database::run('UPDATE cars SET cover_image = NULL WHERE id = :id', ['id' => $carId]);
            return $car['cover_image'];
        });
        self::deleteOwn($old);
    }

    /** เพิ่มรูปแกลเลอรี คืน id — จำกัด MAX_GALLERY รูป/คัน (นับใน transaction ที่ล็อกแถวรถ พร้อมกันก็ไม่เกิน) @throws UserError */
    public static function addGallery(int $carId, array $file, bool $viaHttp = true): int
    {
        if (Car::find($carId) === null) {
            throw new UserError('ไม่พบรถ');
        }
        $path = self::store($file, $viaHttp);
        try {
            return (int) Database::transaction(static function () use ($carId, $path): int {
                if (Car::lockForUpdate($carId) === null) {
                    throw new UserError('ไม่พบรถ');
                }
                if ((int) Database::value('SELECT COUNT(*) FROM car_images WHERE car_id = :c', ['c' => $carId]) >= self::MAX_GALLERY) {
                    throw new UserError('เพิ่มรูปได้ไม่เกิน ' . self::MAX_GALLERY . ' รูปต่อคัน');
                }
                $next = (int) Database::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM car_images WHERE car_id = :c', ['c' => $carId]);
                return Database::insert('INSERT INTO car_images (car_id, path, sort_order) VALUES (:c, :p, :s)', ['c' => $carId, 'p' => $path, 's' => $next]);
            });
        } catch (\Throwable $e) {
            self::deleteOwn($path);
            throw $e;
        }
    }

    /** @throws UserError */
    public static function removeGallery(int $imageId): void
    {
        $row = Database::one('SELECT * FROM car_images WHERE id = :i', ['i' => $imageId]);
        if ($row === null || Database::run('DELETE FROM car_images WHERE id = :i', ['i' => $imageId])->rowCount() !== 1) {
            throw new UserError('ไม่พบรูปนี้ (อาจถูกลบไปแล้ว)');
        }
        self::deleteOwn($row['path']);
    }
}
