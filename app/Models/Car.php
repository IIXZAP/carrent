<?php
namespace App\Models;

use App\Core\Database;

final class Car
{
    private const COLUMNS = [
        'plate', 'province', 'brand', 'model', 'year', 'color', 'car_type_code', 'seats',
        'gear', 'fuel', 'price_day', 'price_week', 'price_month',
        'security_deposit', 'booking_deposit', 'status', 'description',
    ];

    /** รถทั้งหมดสำหรับหลังบ้าน (ยกเว้นที่ลบแล้ว) */
    public static function allForAdmin(): array
    {
        return Database::all(
            'SELECT c.*, t.label_th AS type_label
               FROM cars c JOIN car_types t ON t.code = c.car_type_code
              WHERE c.deleted_at IS NULL
              ORDER BY c.sort_order, c.id'
        );
    }

    /** รถที่โชว์หน้าเว็บลูกค้า */
    public static function allPublic(): array
    {
        return Database::all(
            "SELECT c.*, t.label_th AS type_label
               FROM cars c JOIN car_types t ON t.code = c.car_type_code
              WHERE c.deleted_at IS NULL AND c.status = 'available'
              ORDER BY c.sort_order, c.price_day, c.id"
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT c.*, t.label_th AS type_label
               FROM cars c JOIN car_types t ON t.code = c.car_type_code
              WHERE c.id = :id AND c.deleted_at IS NULL',
            ['id' => $id]
        );
    }

    public static function plateExists(string $plate, int $exceptId = 0): bool
    {
        return Database::value(
            'SELECT 1 FROM cars WHERE plate = :plate AND id <> :id',
            ['plate' => $plate, 'id' => $exceptId]
        ) !== null;
    }

    public static function countByStatus(): array
    {
        $rows = Database::all(
            'SELECT status, COUNT(*) AS n FROM cars WHERE deleted_at IS NULL GROUP BY status'
        );
        $out = ['available' => 0, 'maintenance' => 0, 'inactive' => 0];
        foreach ($rows as $row) {
            $out[$row['status']] = (int) $row['n'];
        }
        return $out;
    }

    public static function hasOpenBookings(int $id): bool
    {
        return Database::value(
            "SELECT 1 FROM bookings
              WHERE car_id = :id AND status IN ('pending','confirmed','picked_up') LIMIT 1",
            ['id' => $id]
        ) !== null;
    }


    /** สถานะรถ (null = ไม่พบ/ถูกลบแล้ว) */
    public static function statusOf(int $id): ?string
    {
        $status = Database::value('SELECT status FROM cars WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
        return $status === null ? null : (string) $status;
    }

    /**
     * ล็อกแถวรถไว้จนจบ transaction — ใช้ก่อนตรวจรถว่าง+สร้างการจอง
     * กันสองคำขอจองรถคันเดียวกันพร้อมกัน (ต้องเรียกหลัง Database::begin())
     */
    public static function lockForUpdate(int $id): ?array
    {
        return Database::one('SELECT * FROM cars WHERE id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => $id]);
    }

    /** ราคาต่อวัน (รวมรถที่ถูกลบแล้ว — การจองเก่ายังต้องคิดค่าปรับจากราคารถคันนี้ได้) */
    public static function priceDay(int $id): float
    {
        return (float) Database::value('SELECT price_day FROM cars WHERE id = :id', ['id' => $id]);
    }

    /** เลขไมล์ของรถเพิ่มได้อย่างเดียว (ไม่ถอยหลังเมื่อบันทึกเลขต่ำกว่าเดิมผิด) */
    public static function bumpMileage(int $id, int $km): void
    {
        Database::run('UPDATE cars SET mileage = GREATEST(mileage, :km) WHERE id = :id', ['km' => max(0, $km), 'id' => $id]);
    }

    /**
     * รถที่ว่างตลอดช่วง [from, to) (ช่วงที่ส่งมาควรขยายด้วยเวลาพักรถแล้ว)
     * กติกาต้องตรงกับ AvailabilityService::isCarAvailable
     */
    public static function availableBetween(string $from, string $to, ?string $typeCode = null): array
    {
        $sql = "SELECT c.*, t.label_th AS type_label
                  FROM cars c JOIN car_types t ON t.code = c.car_type_code
                 WHERE c.deleted_at IS NULL AND c.status = 'available'
                   AND NOT EXISTS (
                         SELECT 1 FROM bookings b
                          WHERE b.car_id = c.id AND b.status IN ('pending','confirmed','picked_up')
                            AND b.pickup_at < :to1 AND b.return_at > :from1)
                   AND NOT EXISTS (
                         SELECT 1 FROM bookings o
                          WHERE o.car_id = c.id AND o.status = 'picked_up' AND o.return_at < NOW())
                   AND NOT EXISTS (
                         SELECT 1 FROM car_blocks k
                          WHERE k.car_id = c.id AND k.start_at < :to2 AND k.end_at > :from2)";
        $params = ['from1' => $from, 'to1' => $to, 'from2' => $from, 'to2' => $to];
        if ($typeCode !== null && $typeCode !== '') {
            $sql .= ' AND c.car_type_code = :type';
            $params['type'] = $typeCode;
        }
        return Database::all($sql . ' ORDER BY c.sort_order, c.price_day, c.id', $params);
    }

    /** รถที่แสดงในปฏิทิน: ยังไม่ลบ และไม่ปิดใช้งาน */
    public static function forCalendar(): array
    {
        return Database::all("SELECT * FROM cars WHERE deleted_at IS NULL AND status <> 'inactive' ORDER BY sort_order, id");
    }

    /** รูปแกลเลอรีของรถ เรียงตามลำดับ */
    public static function images(int $carId): array
    {
        return Database::all('SELECT * FROM car_images WHERE car_id = :c ORDER BY sort_order, id', ['c' => $carId]);
    }

    public static function create(array $data): int
    {
        $cols = implode(', ', self::COLUMNS);
        $marks = ':' . implode(', :', self::COLUMNS);
        return Database::insert("INSERT INTO cars ($cols) VALUES ($marks)", self::pick($data));
    }

    public static function update(int $id, array $data): void
    {
        $sets = [];
        foreach (self::COLUMNS as $col) {
            $sets[] = "$col = :$col";
        }
        $params = self::pick($data) + ['id' => $id];
        Database::run('UPDATE cars SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    public static function softDelete(int $id): void
    {
        Database::run('UPDATE cars SET deleted_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    private static function pick(array $data): array
    {
        $out = [];
        foreach (self::COLUMNS as $col) {
            $out[$col] = $data[$col] ?? null;
        }
        return $out;
    }
}
