-- ============================================================
--  thonglorcarrent — ตั้งบัญชีผู้ดูแลระบบ
--
--  ใช้เมื่อ: ล็อกอินหลังบ้านไม่ได้ / ลืมรหัสผ่าน / เพิ่งเปลี่ยนชื่อโปรเจกต์
--
--  หลัง import ไฟล์นี้ จะเข้าระบบได้ด้วย
--      อีเมล    : admin@thonglorcarrent.com
--      รหัสผ่าน : Thonglor@2026
--
--  วิธีใช้: phpMyAdmin → เลือกฐานข้อมูล → แท็บ Import → เลือกไฟล์นี้
--  *** เปลี่ยนรหัสผ่านทันทีหลังเข้าได้ ที่เมนู ผู้ใช้งาน → แก้ไข ***
--
--  ใช้ได้ทั้ง MySQL 5.7 / MySQL 8 และ MariaDB
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) ถ้ายังมีบัญชีเดิมของชื่อโปรเจกต์เก่าอยู่ ให้เปลี่ยนเป็นอีเมลใหม่
--    (ทำเฉพาะเมื่อยังไม่มีใครใช้อีเมลใหม่ เพื่อไม่ให้ชนกัน)
-- ------------------------------------------------------------
UPDATE users
SET email = 'admin@thonglorcarrent.com'
WHERE email = 'admin@montracarrent.com'
  AND NOT EXISTS (
    SELECT 1 FROM (SELECT email FROM users) AS u
    WHERE u.email = 'admin@thonglorcarrent.com'
  );

-- ------------------------------------------------------------
-- 2) ตั้งรหัสผ่านให้บัญชีผู้ดูแล — ถ้ายังไม่มีบัญชีนี้ จะสร้างใหม่ให้
-- ------------------------------------------------------------
INSERT INTO users (name, email, password_hash, role, phone, is_active)
VALUES ('ผู้ดูแลระบบ', 'admin@thonglorcarrent.com',
        '$2y$12$/sBgENIC.cPZzLAF4GlfJu1OQCIr6dzTrLBbnMyRe9mUcat7QueYW',
        'admin', NULL, 1)
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  role          = 'admin',
  is_active     = 1;

-- ------------------------------------------------------------
-- 3) ตรวจผล — ต้องเห็น admin@thonglorcarrent.com และสถานะ "ใช้งานได้"
-- ------------------------------------------------------------
SELECT
  id,
  name                                             AS 'ชื่อ',
  email                                            AS 'อีเมลสำหรับล็อกอิน',
  role                                             AS 'สิทธิ์',
  CASE WHEN is_active = 1 THEN 'ใช้งานได้' ELSE 'ปิดอยู่' END AS 'สถานะ'
FROM users
ORDER BY id;
