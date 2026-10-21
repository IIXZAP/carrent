<?php
/** Model: คุยกับตาราง bookings */
class Booking
{
    /** รถคันนี้มีการจองที่ซ้อนทับกับช่วงเวลานี้ไหม (ชนกันเมื่อ เริ่ม < จบเดิม และ จบ > เริ่มเดิม) */
    public static function hasConflict(int $carId, string $pickup, string $return): bool
    {
        $row = Database::one(
            'SELECT COUNT(*) AS n FROM bookings
             WHERE car_id = :car AND pickup_at < :return AND return_at > :pickup',
            [':car' => $carId, ':pickup' => $pickup, ':return' => $return]
        );
        return (int) $row['n'] > 0;
    }

    /** บันทึกการจองใหม่ คืนเลขที่จอง */
    public static function create(array $data): string
    {
        $bookingNo = 'BK' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        Database::run(
            'INSERT INTO bookings (booking_no, car_id, name, phone, pickup_at, return_at, total, created_at)
             VALUES (:no, :car, :name, :phone, :pickup, :return, :total, :now)',
            [
                ':no'     => $bookingNo,
                ':car'    => $data['car_id'],
                ':name'   => $data['name'],
                ':phone'  => $data['phone'],
                ':pickup' => $data['pickup'],
                ':return' => $data['return'],
                ':total'  => $data['total'],
                ':now'    => date('Y-m-d H:i:s'),
            ]
        );
        return $bookingNo;
    }

    /** หาการจองจากเลขที่จอง (join เอาชื่อรถมาด้วย) */
    public static function findByNo(string $no): ?array
    {
        return Database::one(
            'SELECT b.*, c.brand, c.model
             FROM bookings b JOIN cars c ON c.id = b.car_id
             WHERE b.booking_no = :no',
            [':no' => $no]
        );
    }
}
