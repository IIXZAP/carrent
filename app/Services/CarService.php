<?php
namespace App\Services;

use App\Models\Car;
use App\Models\CarType;

/** กฎของฟอร์มรถ: ตรวจและแปลงค่าให้พร้อมบันทึก */
final class CarService
{
    public const GEARS    = ['auto' => 'เกียร์ออโต้', 'manual' => 'เกียร์ธรรมดา'];
    public const FUELS    = ['petrol' => 'เบนซิน', 'diesel' => 'ดีเซล', 'hybrid' => 'ไฮบริด', 'ev' => 'ไฟฟ้า', 'lpg' => 'LPG'];
    public const STATUSES = ['available' => 'พร้อมให้เช่า', 'maintenance' => 'ซ่อมบำรุง', 'inactive' => 'ปิดใช้งาน'];

    /** @return array{0: array, 1: array} [ข้อมูลที่แปลงแล้ว, ข้อผิดพลาดรายช่อง] */
    public static function validate(array $in, int $carId = 0): array
    {
        $errors = [];
        $data = [];

        $data['plate'] = self::text($in, 'plate');
        if ($data['plate'] === '' || mb_strlen($data['plate']) > 20) {
            $errors['plate'] = 'กรุณากรอกทะเบียน (ไม่เกิน 20 ตัวอักษร)';
        } elseif (Car::plateExists($data['plate'], $carId)) {
            $errors['plate'] = 'ทะเบียนนี้มีในระบบแล้ว';
        }

        $data['brand'] = self::text($in, 'brand');
        if ($data['brand'] === '') {
            $errors['brand'] = 'กรุณากรอกยี่ห้อ';
        }
        $data['model'] = self::text($in, 'model');
        if ($data['model'] === '') {
            $errors['model'] = 'กรุณากรอกรุ่น';
        }
        $data['province']    = self::text($in, 'province') ?: null;
        $data['color']       = self::text($in, 'color') ?: null;
        $data['description'] = self::text($in, 'description') ?: null;

        $data['car_type_code'] = self::text($in, 'car_type_code');
        if (!CarType::exists($data['car_type_code'])) {
            $errors['car_type_code'] = 'กรุณาเลือกประเภทรถ';
        }

        $data['year'] = self::intOrNull($in, 'year');
        if ($data['year'] !== null && ($data['year'] < 1990 || $data['year'] > 2100)) {
            $errors['year'] = 'ปีต้องเป็น ค.ศ. ระหว่าง 1990–2100';
        }
        $data['seats'] = self::intOrNull($in, 'seats') ?? 5;
        if ($data['seats'] < 1 || $data['seats'] > 60) {
            $errors['seats'] = 'จำนวนที่นั่งต้องอยู่ระหว่าง 1–60';
        }

        $data['gear'] = self::choice($in, 'gear', self::GEARS, $errors, 'กรุณาเลือกเกียร์');
        $data['fuel'] = self::choice($in, 'fuel', self::FUELS, $errors, 'กรุณาเลือกเชื้อเพลิง');
        $data['status'] = self::choice($in, 'status', self::STATUSES, $errors, 'กรุณาเลือกสถานะ');

        $data['price_day'] = self::money($in, 'price_day', true, $errors);
        $data['price_week'] = self::money($in, 'price_week', false, $errors);
        $data['price_month'] = self::money($in, 'price_month', false, $errors);
        $data['security_deposit'] = self::money($in, 'security_deposit', true, $errors);
        $data['booking_deposit'] = self::money($in, 'booking_deposit', true, $errors);

        return [$data, $errors];
    }

    /** ค่าเริ่มต้นของฟอร์มเพิ่มรถใหม่ */
    public static function defaults(): array
    {
        return [
            'car_type_code' => 'sedan', 'seats' => 5, 'gear' => 'auto', 'fuel' => 'petrol',
            'status' => 'available', 'price_day' => 0, 'security_deposit' => 0, 'booking_deposit' => 0,
        ];
    }

    private static function text(array $in, string $key): string
    {
        $v = $in[$key] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    private static function intOrNull(array $in, string $key): ?int
    {
        $v = self::text($in, $key);
        return ctype_digit($v) ? (int) $v : null;
    }

    private static function choice(array $in, string $key, array $allowed, array &$errors, string $msg): string
    {
        $v = self::text($in, $key);
        if (!array_key_exists($v, $allowed)) {
            $errors[$key] = $msg;
            return (string) array_key_first($allowed);
        }
        return $v;
    }

    /** เงิน: ตัดคอมมาออก ต้องเป็นตัวเลข >= 0 ; ช่องไม่บังคับปล่อยว่างได้ (= NULL) */
    private static function money(array $in, string $key, bool $required, array &$errors): ?string
    {
        $v = str_replace(',', '', self::text($in, $key));
        if ($v === '') {
            if ($required) {
                $errors[$key] = 'กรุณากรอกจำนวนเงิน';
            }
            return $required ? '0' : null;
        }
        if (!is_numeric($v) || (float) $v < 0 || (float) $v > 99999999) {
            $errors[$key] = 'จำนวนเงินไม่ถูกต้อง';
            return '0';
        }
        return number_format((float) $v, 2, '.', '');
    }
}
