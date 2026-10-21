<?php

namespace App\Models;

use App\Core\Database;

/**
 * ตัวนับเลขที่ (การจอง / ลูกค้า / เอกสาร)
 *
 * เพิ่มเลขด้วยคำสั่ง SQL เดียวแบบ atomic: ถ้ามีหลายคำขอเข้ามาพร้อมกัน แต่ละคำขอจะได้เลขไม่ซ้ำกัน
 * (ไม่ต้องอ่านเลขล่าสุดแล้วบวกหนึ่งเอง ซึ่งเสี่ยงได้เลขซ้ำ)
 */
final class Sequence
{
    /**
     * ขอเลขถัดไปของ $name ในช่วง $period (ครั้งแรกได้ 1)
     *
     * @param string $name   ชื่อตัวนับ เช่น 'booking' 'customer' 'quotation'
     * @param string $period ช่วงที่รีเซ็ตเลข เช่น '260919' (รายวัน) '2609' (รายเดือน) '' (ไม่รีเซ็ต)
     */
    public static function next(string $name, string $period = ''): int
    {
        // LAST_INSERT_ID(expr) จำค่าใหม่ไว้ในการเชื่อมต่อนี้ แล้วอ่านกลับด้วย SELECT LAST_INSERT_ID()
        Database::exec(
            'INSERT INTO sequences (name, period, last_no) VALUES (:n, :p, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)',
            [':n' => $name, ':p' => $period]
        );
        return (int) Database::value('SELECT LAST_INSERT_ID()');
    }
}
