<?php
/**
 * Front controller — ทุกคำขอเข้าที่ไฟล์นี้ (ผ่าน .htaccess)
 * จากนั้น app/routes.php จับคู่ URL กับ controller
 *
 * โครงสร้าง MVC:
 *   app/Core         ตัวขับเคลื่อน (Router, Request, Response, View, Database)
 *   app/Models       เข้าถึงข้อมูลด้วย PDO
 *   app/Services     กฎธุรกิจ (คิดราคา ตรวจรถว่าง ชำระเงิน LINE ฯลฯ)
 *   app/Controllers  รับคำขอ → เรียก Model/Service → ส่งให้ View
 *   app/Views        หน้าเว็บ (PHP + Tailwind CSS)
 */
require_once __DIR__ . '/app/bootstrap.php';

\App\Core\Application::run();
