-- ============================================================
--  thonglorcarrent — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 5
--
--    1) คืนรถ: บันทึกรายการค่าใช้จ่ายเพิ่มแยกรายการ / คืนเงินประกัน / หักจากเงินประกัน
--    2) เซ็นสัญญาบนมือถือ: ตารางลายเซ็น + รหัสลิงก์เซ็นชื่อ
--    3) เอกสารลูกค้า: ข้อมูลไฟล์สำหรับที่เก็บแบบปิด (storage/private)
--
--  ปลอดภัย: ไม่ลบข้อมูลเดิมใดๆ และรันซ้ำได้ไม่เสียหาย
--  รองรับ MySQL 5.7 / MySQL 8 / MariaDB · รันได้ทั้ง carrent และ carrent2
--  ต้องรัน update-v4.sql มาก่อน
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) payments — ชนิดรายการใหม่
--    security_refund = คืนเงินประกันให้ลูกค้า (ไม่ใช่รายได้ติดลบ เพราะเงินประกันไม่เคยเป็นรายได้)
--    method deduct   = หักจากเงินประกันที่ถือไว้ (ใช้ชำระยอดค้าง/ค่าเสียหาย)
-- ------------------------------------------------------------
ALTER TABLE payments
  MODIFY kind   ENUM('deposit','balance','security','extra','refund','security_refund') NOT NULL DEFAULT 'deposit',
  MODIFY method ENUM('transfer','cash','card','promptpay','deduct') NOT NULL DEFAULT 'transfer';

-- ------------------------------------------------------------
-- 2) bookings — รายการค่าใช้จ่ายเพิ่มตอนคืนรถ (JSON) + รหัสลิงก์เซ็นสัญญา
-- ------------------------------------------------------------
SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'extra_breakdown');
SET @ddl := IF(@n = 0, 'ALTER TABLE bookings ADD COLUMN extra_breakdown TEXT DEFAULT NULL AFTER extra_note', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'sign_token');
SET @ddl := IF(@n = 0, 'ALTER TABLE bookings ADD COLUMN sign_token VARCHAR(64) DEFAULT NULL', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ระยะทางส่งรถ (กม.) — เดิมไม่ได้เก็บ ทำให้แก้ไขการจองในหลังบ้านแล้วค่าส่งรถกลายเป็น 0
SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'delivery_km');
SET @ddl := IF(@n = 0, 'ALTER TABLE bookings ADD COLUMN delivery_km DECIMAL(7,1) DEFAULT NULL', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND INDEX_NAME = 'idx_bk_sign_token');
SET @ddl := IF(@n = 0, 'ALTER TABLE bookings ADD KEY idx_bk_sign_token (sign_token)', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 3) signatures — ลายเซ็นอิเล็กทรอนิกส์ของผู้เช่า
--    kind contract = เซ็นรับเงื่อนไขและสภาพรถวันรับรถ
--    kind return   = เซ็นรับทราบสภาพรถและค่าใช้จ่ายวันคืนรถ
--    snapshot      = สำเนาข้อมูลและข้อสัญญา ณ เวลาที่เซ็น ใช้ตรวจว่ามีการแก้หลังเซ็นหรือไม่
--    voided_at     = ลายเซ็นที่ถูกขอเซ็นใหม่ — ไม่ลบทิ้ง เก็บไว้เป็นประวัติ
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS signatures (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id    INT UNSIGNED NOT NULL,
  kind          ENUM('contract','return') NOT NULL DEFAULT 'contract',
  file_path     VARCHAR(255) NOT NULL,
  signer_name   VARCHAR(180) NOT NULL,
  consent_pdpa  TINYINT(1)   NOT NULL DEFAULT 0,
  snapshot      MEDIUMTEXT   DEFAULT NULL,
  snapshot_hash CHAR(64)     DEFAULT NULL,
  ip            VARCHAR(45)  DEFAULT NULL,
  user_agent    VARCHAR(255) DEFAULT NULL,
  signed_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_at     DATETIME     DEFAULT NULL,
  voided_by     INT UNSIGNED DEFAULT NULL,
  void_reason   VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_sig_booking (booking_id, kind, voided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4) customer_documents — ชนิดไฟล์และขนาด (ไฟล์จริงเก็บในโฟลเดอร์ปิด storage/private)
-- ------------------------------------------------------------
-- ตารางนี้มากับ update-v3.sql — สร้างให้ที่นี่ด้วยเผื่อฐานข้อมูลที่ข้ามไฟล์นั้นมา (มีอยู่แล้วจะไม่ถูกแตะ)
CREATE TABLE IF NOT EXISTS customer_documents (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id  INT UNSIGNED DEFAULT NULL,
  booking_id   INT UNSIGNED DEFAULT NULL,
  doc_kind     ENUM('id_card','driver_license','passport','other') NOT NULL DEFAULT 'id_card',
  file_path    VARCHAR(255) NOT NULL,
  mime         VARCHAR(60) DEFAULT NULL,
  file_size    INT UNSIGNED DEFAULT NULL,
  number_masked VARCHAR(40) DEFAULT NULL,
  expires_on   DATE DEFAULT NULL,
  note         VARCHAR(255) DEFAULT NULL,
  uploaded_by  INT UNSIGNED DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_doc_customer (customer_id),
  KEY idx_doc_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_documents' AND COLUMN_NAME = 'mime');
SET @ddl := IF(@n = 0, 'ALTER TABLE customer_documents ADD COLUMN mime VARCHAR(60) DEFAULT NULL AFTER file_path', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_documents' AND COLUMN_NAME = 'file_size');
SET @ddl := IF(@n = 0, 'ALTER TABLE customer_documents ADD COLUMN file_size INT UNSIGNED DEFAULT NULL AFTER mime', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 5) ค่าตั้งใหม่ — แก้ได้ที่ หลังบ้าน → ตั้งค่า
-- ------------------------------------------------------------
INSERT INTO settings (skey, svalue) VALUES
('late_grace_hours',     '2'),     -- ผ่อนผันคืนช้ากี่ชั่วโมง (ไม่คิดเงิน)
('late_full_day_hours',  '6'),     -- คืนช้าเกินกี่ชั่วโมงคิดเป็นค่าเช่าเพิ่มเต็มวัน
('fuel_fee_per_level',   '300'),
('pending_expire_hours', '0'),     -- ยกเลิกการจองที่ไม่จ่ายมัดจำอัตโนมัติหลังกี่ชั่วโมง (0 = ไม่ยกเลิกอัตโนมัติ)   -- ค่าน้ำมันที่ขาด ต่อ 1 ขีด (1/8 ถัง) รวมค่าบริการ
('pdpa_policy_text',     'ข้าพเจ้ายินยอมให้ผู้ให้เช่าเก็บรวบรวม ใช้ และเปิดเผยข้อมูลส่วนบุคคลของข้าพเจ้า รวมถึงสำเนาบัตรประจำตัวประชาชน ใบอนุญาตขับขี่ และลายมือชื่ออิเล็กทรอนิกส์ เพื่อการทำสัญญาเช่ารถ การยืนยันตัวตน การเรียกร้องค่าเสียหาย และการปฏิบัติตามกฎหมายที่เกี่ยวข้อง')
ON DUPLICATE KEY UPDATE svalue = svalue;

-- ============================================================
--  ตรวจผล
-- ============================================================
SELECT 'ชนิดรายการเงินใหม่ (security_refund / deduct)' AS 'รายการ',
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
          AND ((COLUMN_NAME = 'kind' AND COLUMN_TYPE LIKE '%security_refund%')
            OR (COLUMN_NAME = 'method' AND COLUMN_TYPE LIKE '%deduct%'))) AS 'พบ', 2 AS 'ควรได้'
UNION ALL
SELECT 'คอลัมน์ใหม่ใน bookings',
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'
          AND COLUMN_NAME IN ('extra_breakdown','sign_token','delivery_km')), 3
UNION ALL
SELECT 'ตาราง signatures',
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'signatures'), 1
UNION ALL
SELECT 'คอลัมน์ใหม่ใน customer_documents',
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_documents'
          AND COLUMN_NAME IN ('mime','file_size')), 2
UNION ALL
SELECT 'ค่าตั้งใหม่',
       (SELECT COUNT(*) FROM settings
        WHERE skey IN ('late_grace_hours','late_full_day_hours','fuel_fee_per_level','pending_expire_hours')), 4;
