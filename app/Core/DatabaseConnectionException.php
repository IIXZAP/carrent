<?php

namespace App\Core;

/** เชื่อมต่อฐานข้อมูลไม่ได้ — แยกชนิดออกมาเพื่อให้หน้า /health ตอบ FAIL (503) และหน้าอื่นแสดงข้อความกลาง */
class DatabaseConnectionException extends \Exception
{
}
