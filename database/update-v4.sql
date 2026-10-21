-- ============================================================
--  thonglorcarrent — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 4
--
--  เพิ่มให้ระบบครบตามใบเสนอราคา:
--    1) ผลตรวจสภาพรถ: บันทึกผู้ถ่ายรายรูป เก็บประวัติรูปที่ถ่ายซ้ำ และล็อกผลตรวจ
--    2) LINE Official Account: บันทึกเหตุการณ์จาก webhook + กันส่งแจ้งเตือนซ้ำ
--    3) งานดูแลระบบ: สำรองข้อมูล / ตรวจสุขภาพระบบ
--
--  ปลอดภัย: ไม่ลบข้อมูลเดิมใดๆ และรันซ้ำได้ไม่เสียหาย
--  รองรับ MySQL 5.7 / MySQL 8 / MariaDB
--  รันได้ทั้งฐานข้อมูล carrent และ carrent2
--
--  วิธีใช้: cPanel → phpMyAdmin → เลือกฐานข้อมูล → แท็บ Import → เลือกไฟล์นี้
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) inspection_photos — ใครถ่าย + รูปนี้ถูกถ่ายแทนเมื่อไหร่
--    รูปที่ถูกถ่ายซ้ำจะไม่ถูกลบ แค่ประทับเวลา superseded_at ไว้เป็นประวัติ
-- ------------------------------------------------------------
SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspection_photos' AND COLUMN_NAME = 'uploaded_by');
SET @ddl := IF(@n = 0, 'ALTER TABLE inspection_photos ADD COLUMN uploaded_by INT UNSIGNED DEFAULT NULL', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspection_photos' AND COLUMN_NAME = 'superseded_at');
SET @ddl := IF(@n = 0, 'ALTER TABLE inspection_photos ADD COLUMN superseded_at DATETIME DEFAULT NULL', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 2) inspections — ล็อกผลตรวจ (ล็อกแล้วแก้ไขไม่ได้อีก)
-- ------------------------------------------------------------
SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND COLUMN_NAME = 'locked_at');
SET @ddl := IF(@n = 0, 'ALTER TABLE inspections ADD COLUMN locked_at DATETIME DEFAULT NULL', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND COLUMN_NAME = 'locked_by');
SET @ddl := IF(@n = 0, 'ALTER TABLE inspections ADD COLUMN locked_by INT UNSIGNED DEFAULT NULL', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 3) notifications — รหัสแบบข้อความ ใช้กันส่งเตือนเรื่องเดิมซ้ำ
--    (เช่น เตือนวันรับรถของการจองเดียวกัน ส่งครั้งเดียวพอ)
-- ------------------------------------------------------------
SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'tpl_key');
SET @ddl := IF(@n = 0, 'ALTER TABLE notifications ADD COLUMN tpl_key VARCHAR(40) DEFAULT NULL AFTER channel', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notif_tpl');
SET @ddl := IF(@n = 0, 'ALTER TABLE notifications ADD KEY idx_notif_tpl (tpl_key, ref_id)', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 4) line_events — เหตุการณ์ที่ LINE ส่งเข้ามา (เพิ่มเพื่อน / ข้อความ / เลิกติดตาม)
--    ใช้ดูว่าใครทักเข้ามา และหยิบ LINE user id ของเจ้าของร้านมาตั้งเป็นผู้รับแจ้งเตือน
--    เก็บย้อนหลัง 30 วัน (งานรายวันลบของเก่าให้เอง)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS line_events (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type    VARCHAR(20)  NOT NULL,
  line_user_id  VARCHAR(60)  DEFAULT NULL,
  display_name  VARCHAR(120) DEFAULT NULL,
  text          VARCHAR(500) DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_le_user (line_user_id),
  KEY idx_le_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5) ค่าตั้งใหม่
-- ------------------------------------------------------------
INSERT INTO settings (skey, svalue) VALUES
('site_url',             ''),      -- ที่อยู่เว็บเต็ม ใช้สร้างลิงก์ในข้อความ LINE (ระบบเติมให้เองเมื่อเปิดหน้า ตั้งค่า LINE)
('line_richmenu_id',     ''),      -- เมนูด้านล่างของ LINE OA ที่ระบบสร้างให้
('line_notify_customer', '1'),     -- ส่งแจ้งเตือนถึงลูกค้าทาง LINE (1 = เปิด)
('backup_keep_days',     '90'),    -- เก็บไฟล์สำรองย้อนหลังกี่วัน
('backup_last_at',       ''),
('cron_last_at',         '')
ON DUPLICATE KEY UPDATE svalue = svalue;

-- รหัสลับของหน้าตรวจสุขภาพระบบ — สุ่มให้ครั้งแรกครั้งเดียว
INSERT INTO settings (skey, svalue)
SELECT 'health_key', MD5(CONCAT(RAND(), NOW(), DATABASE()))
ON DUPLICATE KEY UPDATE svalue = svalue;

-- ============================================================
--  ตรวจผล
-- ============================================================
SELECT 'คอลัมน์ใหม่ใน inspection_photos' AS 'รายการ',
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspection_photos'
          AND COLUMN_NAME IN ('uploaded_by','superseded_at')) AS 'พบ', 2 AS 'ควรได้'
UNION ALL
SELECT 'คอลัมน์ใหม่ใน inspections',
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections'
          AND COLUMN_NAME IN ('locked_at','locked_by')), 2
UNION ALL
SELECT 'คอลัมน์ tpl_key ใน notifications',
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'tpl_key'), 1
UNION ALL
SELECT 'ตาราง line_events',
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'line_events'), 1
UNION ALL
SELECT 'ค่าตั้งใหม่',
       (SELECT COUNT(*) FROM settings
        WHERE skey IN ('site_url','line_richmenu_id','line_notify_customer','backup_keep_days','health_key')), 5;
