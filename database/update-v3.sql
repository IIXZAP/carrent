-- ============================================================
--  thonglorcarrent — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 3
--
--  เพิ่ม 3 ฟีเจอร์:
--    1) ถ่ายรูปสภาพรถก่อน–หลัง ผูกกับใบจอง
--    2) เซ็นสัญญาบนมือถือ + เก็บเอกสารลูกค้า (บัตรประชาชน / ใบขับขี่)
--    3) เชื่อม LINE Official Account
--
--  ปลอดภัย: ไม่ลบข้อมูลเดิมใดๆ และรันซ้ำได้ไม่เสียหาย
--  รองรับทั้ง MySQL 5.7 / MySQL 8 และ MariaDB (ไม่ใช้ IF NOT EXISTS กับคอลัมน์)
--
--  วิธีใช้: cPanel → phpMyAdmin → เลือกฐานข้อมูล → แท็บ Import → เลือกไฟล์นี้
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) ตารางการตรวจสภาพรถ (1 ใบจอง มีได้ 2 ครั้ง: ตอนส่งมอบ + ตอนรับคืน)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspections (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id    INT UNSIGNED NOT NULL,
  kind          ENUM('out','in') NOT NULL COMMENT 'out = ตอนส่งมอบ, in = ตอนรับคืน',
  mileage       INT UNSIGNED DEFAULT NULL,
  fuel_level    TINYINT UNSIGNED DEFAULT NULL COMMENT 'ระดับน้ำมัน 0-8 ขีด',
  note          TEXT,
  inspected_by  INT UNSIGNED DEFAULT NULL,
  inspected_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_kind (booking_id, kind),
  KEY idx_insp_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2) รูปถ่ายของการตรวจสภาพแต่ละครั้ง
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspection_photos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  inspection_id  INT UNSIGNED NOT NULL,
  position       VARCHAR(30) NOT NULL COMMENT 'front/rear/left/right/interior/dashboard/fuel/damage',
  file_path      VARCHAR(255) NOT NULL,
  caption        VARCHAR(255) DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_photo_insp (inspection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3) เอกสารลูกค้า (บัตรประชาชน / ใบขับขี่)
--    ไฟล์เก็บใน uploads/private/ ซึ่งกันการเข้าถึงตรงผ่าน URL
--    เปิดดูได้เฉพาะผ่าน admin/secure-file.php ที่ตรวจสิทธิ์และบันทึก log ทุกครั้ง
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_documents (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id  INT UNSIGNED DEFAULT NULL,
  booking_id   INT UNSIGNED DEFAULT NULL,
  doc_kind     ENUM('id_card','driver_license','passport','other') NOT NULL DEFAULT 'id_card',
  file_path    VARCHAR(255) NOT NULL,
  number_masked VARCHAR(40) DEFAULT NULL COMMENT 'เก็บเฉพาะเลขบางส่วน เช่น x-xxxx-xxxxx-45-6',
  expires_on   DATE DEFAULT NULL,
  note         VARCHAR(255) DEFAULT NULL,
  uploaded_by  INT UNSIGNED DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_doc_customer (customer_id),
  KEY idx_doc_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4) คอลัมน์ใหม่ในตาราง bookings — ลายเซ็น / PDPA / LINE
-- ------------------------------------------------------------
-- signature_file : ไฟล์ลายเซ็น PNG
-- signed_at      : เวลาที่เซ็น
-- signed_ip      : IP ที่ใช้เซ็น (ไว้อ้างอิงถ้ามีข้อพิพาท)
-- consent_at     : เวลาที่ลูกค้ายอมรับนโยบายข้อมูลส่วนบุคคล
-- line_user_id   : ผู้ใช้ LINE ที่ผูกกับการจองนี้ (ไว้ส่งแจ้งเตือนตรง)

SET @t := 'bookings';

SET @c := 'signature_file';
SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = @c);
SET @ddl := IF(@n = 0, 'ALTER TABLE bookings ADD COLUMN signature_file VARCHAR(255) DEFAULT NULL AFTER damage_note', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := 'signed_at';
SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = @c);
SET @ddl := IF(@n = 0, 'ALTER TABLE bookings ADD COLUMN signed_at DATETIME DEFAULT NULL AFTER signature_file', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := 'signed_ip';
SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = @c);
SET @ddl := IF(@n = 0, 'ALTER TABLE bookings ADD COLUMN signed_ip VARCHAR(45) DEFAULT NULL AFTER signed_at', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := 'consent_at';
SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = @c);
SET @ddl := IF(@n = 0, 'ALTER TABLE bookings ADD COLUMN consent_at DATETIME DEFAULT NULL AFTER signed_ip', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := 'line_user_id';
SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = @c);
SET @ddl := IF(@n = 0, 'ALTER TABLE bookings ADD COLUMN line_user_id VARCHAR(60) DEFAULT NULL AFTER consent_at', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 5) คอลัมน์ใหม่ในตาราง customers — ผูกบัญชี LINE
-- ------------------------------------------------------------
SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'line_user_id');
SET @ddl := IF(@n = 0, 'ALTER TABLE customers ADD COLUMN line_user_id VARCHAR(60) DEFAULT NULL', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND INDEX_NAME = 'idx_cust_line');
SET @ddl := IF(@n = 0, 'ALTER TABLE customers ADD KEY idx_cust_line (line_user_id)', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 6) ค่าตั้งต้นของ LINE + นโยบายเอกสาร
-- ------------------------------------------------------------
INSERT INTO settings (skey, svalue) VALUES
('line_channel_token',   ''),
('line_channel_secret',  ''),
('line_liff_id',         ''),
('line_admin_user_id',   ''),
('doc_retention_days',   '0'),
('pdpa_policy_text',     'ข้าพเจ้ายินยอมให้บริษัทเก็บรวบรวม ใช้ และเปิดเผยข้อมูลส่วนบุคคลของข้าพเจ้า รวมถึงสำเนาบัตรประจำตัวประชาชนและใบอนุญาตขับขี่ เพื่อวัตถุประสงค์ในการทำสัญญาเช่ารถ การยืนยันตัวตน การเรียกร้องค่าเสียหาย และการปฏิบัติตามกฎหมายที่เกี่ยวข้อง')
ON DUPLICATE KEY UPDATE svalue = svalue;

-- ============================================================
--  ตรวจผล — ต้องได้ 3 ตารางใหม่ และคอลัมน์ใหม่ครบ 5 คอลัมน์
-- ============================================================
SELECT 'ตารางใหม่' AS 'รายการ',
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('inspections','inspection_photos','customer_documents')) AS 'พบ',
       3 AS 'ควรได้'
UNION ALL
SELECT 'คอลัมน์ใหม่ใน bookings',
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'
          AND COLUMN_NAME IN ('signature_file','signed_at','signed_ip','consent_at','line_user_id')),
       5
UNION ALL
SELECT 'ค่าตั้งต้น LINE',
       (SELECT COUNT(*) FROM settings
        WHERE skey IN ('line_channel_token','line_channel_secret','line_liff_id')),
       3;
