<?php
namespace App\Services;

use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Customer;

/** ข้อมูลลูกค้าที่พนักงานกรอก: ตรวจ · สร้าง · แก้ · ลบ (soft) */
final class CustomerService
{
    public const TYPES = ['individual' => 'บุคคลธรรมดา', 'company' => 'นิติบุคคล'];

    /** @return array{0:array,1:array} [ข้อมูลที่แปลงแล้ว, error รายช่อง] */
    public static function validate(array $in): array
    {
        $errors = [];
        $text = static fn(string $k): string => is_string($in[$k] ?? null) ? trim(preg_replace('/\s+/u', ' ', $in[$k])) : '';
        $raw = static fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $d = [];

        $d['type'] = $raw('type') ?: 'individual';
        if (!isset(self::TYPES[$d['type']])) {
            $errors['type'] = 'ประเภทลูกค้าไม่ถูกต้อง';
        }
        $d['full_name'] = $text('full_name');
        if ($d['full_name'] === '' || mb_strlen($d['full_name']) > 180) {
            $errors['full_name'] = 'กรุณากรอกชื่อ (ไม่เกิน 180 ตัวอักษร)';
        }
        $d['phone'] = Phone::normalize($raw('phone'));
        if ($d['phone'] === '') {
            $errors['phone'] = 'เบอร์โทรไม่ถูกต้อง (9–15 หลัก)';
        }
        $d['email'] = $raw('email') ?: null;
        if ($d['email'] !== null && (mb_strlen($d['email']) > 150 || !filter_var($d['email'], FILTER_VALIDATE_EMAIL))) {
            $errors['email'] = 'อีเมลไม่ถูกต้อง';
        }
        $d['tax_id'] = $raw('tax_id') ?: null;
        if ($d['tax_id'] !== null && preg_match('/^[0-9A-Za-z\-]{1,20}\z/', $d['tax_id']) !== 1) {
            $errors['tax_id'] = 'เลขผู้เสียภาษีต้องเป็นตัวเลข/ขีด ไม่เกิน 20 ตัว';
        }
        $d['line_id'] = $raw('line_id') ?: null;
        if ($d['line_id'] !== null && mb_strlen($d['line_id']) > 80) {
            $errors['line_id'] = 'LINE ID ยาวเกินไป';
        }
        $d['address'] = $raw('address') ?: null;
        if ($d['address'] !== null && mb_strlen($d['address']) > 2000) {
            $errors['address'] = 'ที่อยู่ยาวเกินไป (ไม่เกิน 2,000 ตัวอักษร)';
        }
        $d['license_no'] = $raw('license_no') ?: null;
        if ($d['license_no'] !== null && mb_strlen($d['license_no']) > 40) {
            $errors['license_no'] = 'เลขใบขับขี่ยาวเกินไป';
        }
        $d['license_expire'] = $raw('license_expire') ?: null;
        if ($d['license_expire'] !== null) {
            $dt = \DateTime::createFromFormat('!Y-m-d', $d['license_expire']);
            if (!$dt || $dt->format('Y-m-d') !== $d['license_expire']) {
                $errors['license_expire'] = 'วันหมดอายุใบขับขี่ไม่ถูกต้อง';
            }
        }
        $d['is_blacklist'] = $raw('is_blacklist') !== '' && $raw('is_blacklist') !== '0' ? 1 : 0;
        $d['blacklist_note'] = $raw('blacklist_note') ?: null;
        if ($d['is_blacklist'] === 1 && $d['blacklist_note'] === null) {
            $errors['blacklist_note'] = 'กรุณาระบุเหตุผลที่ขึ้น Blacklist';
        }
        if ($d['blacklist_note'] !== null && mb_strlen($d['blacklist_note']) > 1000) {
            $errors['blacklist_note'] = 'เหตุผลยาวเกินไป';
        }
        $d['note'] = $raw('note') ?: null;
        if ($d['note'] !== null && mb_strlen($d['note']) > 2000) {
            $errors['note'] = 'หมายเหตุยาวเกินไป';
        }
        return [$d, $errors];
    }

    private static function duplicatePhone(array $data, ?int $exceptId): UserError
    {
        $other = Customer::findByPhone($data['phone']);
        return new UserError('เบอร์นี้มีลูกค้าอยู่แล้ว' . ($other !== null && (int) $other['id'] !== $exceptId ? ' (' . ($other['code'] ?: '#' . $other['id']) . ' ' . $other['full_name'] . ')' : '') . ' — เปิดข้อมูลเดิมแทนการสร้างซ้ำ');
    }

    /** @throws UserError */
    public static function create(array $data, ?int $userId): int
    {
        try {
            $id = Customer::insertFull($data, Customer::nextCode());
        } catch (\PDOException $e) {
            if (Customer::isDuplicatePhone($e)) {
                throw self::duplicatePhone($data, null);
            }
            throw $e;
        }
        ActivityLog::log('customer_created', 'customers', $id, null, $userId, null);
        return $id;
    }

    /** @throws UserError */
    public static function update(int $id, array $data, ?int $userId): void
    {
        $current = Customer::find($id);
        if ($current === null || $current['deleted_at'] !== null) {
            throw new UserError('ไม่พบลูกค้า');
        }
        try {
            Customer::updateById($id, $data);
        } catch (\PDOException $e) {
            if (Customer::isDuplicatePhone($e)) {
                throw self::duplicatePhone($data, $id);
            }
            throw $e;
        }
        ActivityLog::log('customer_updated', 'customers', $id, null, $userId, null);
    }

    /** ลบ (ซ่อน) ลูกค้า — ประวัติการจองเก่ายังอยู่ ; ลบไม่ได้ถ้ายังมีการจองที่ไม่จบ @throws UserError */
    public static function softDelete(int $id, ?int $userId): void
    {
        $customer = Customer::find($id);
        if ($customer === null || $customer['deleted_at'] !== null) {
            throw new UserError('ไม่พบลูกค้า');
        }
        if (Customer::openBookingCount($id) > 0) {
            throw new UserError('ลบไม่ได้ เพราะลูกค้ารายนี้ยังมีการจองที่ยังไม่จบ');
        }
        if (!Customer::softDelete($id)) {
            throw new UserError('ไม่พบลูกค้า');
        }
        ActivityLog::log('customer_deleted', 'customers', $id, null, $userId, null);
    }
}
