<?php
namespace App\Models;

use App\Core\Database;

/**
 * ตัวนับเลขที่ (การจอง / ลูกค้า / เอกสาร) — เพิ่มด้วย SQL เดียวแบบ atomic
 * หลายคำขอพร้อมกันได้เลขไม่ซ้ำ (ห้ามอ่านเลขล่าสุดแล้วบวกหนึ่งเอง)
 * แถวตัวนับถูกล็อกจนจบ transaction ที่เรียก → ควรขอเลขหลังตรวจเงื่อนไขอื่นเสร็จแล้ว
 */
final class Sequence
{
    /** ขอเลขถัดไปของ $name ในช่วง $period (ครั้งแรกได้ 1) เช่น ('booking', '261026') */
    public static function next(string $name, string $period = ''): int
    {
        // LAST_INSERT_ID(expr) จำค่าใหม่ไว้ในการเชื่อมต่อนี้ แล้วอ่านกลับด้วย SELECT LAST_INSERT_ID()
        Database::run(
            'INSERT INTO sequences (name, period, last_no) VALUES (:n, :p, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)',
            ['n' => $name, 'p' => $period]
        );
        return (int) Database::value('SELECT LAST_INSERT_ID()');
    }
}
