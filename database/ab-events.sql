-- ============================================================
--  ตารางเก็บสถิติเทียบ UX สองเวอร์ชัน
--
--  ติดตั้งใน "ทั้งสองฐานข้อมูล" — carrent และ carrent2
--  แต่ละเวอร์ชันเก็บของตัวเอง แล้วหน้า ab-report.php ดึงมารวมเทียบ
--
--  ไม่เก็บข้อมูลส่วนบุคคลใดๆ — ไม่มีชื่อ เบอร์ อีเมล ไม่มี IP
--  visitor_id เป็นเลขสุ่มที่ผูกกับเซสชันเท่านั้น หมดอายุพร้อมเซสชัน
--
--  วิธีใช้: phpMyAdmin → เลือกฐานข้อมูล → Import → ไฟล์นี้ (ทำ 2 รอบ คนละ DB)
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ab_events (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  variant     CHAR(1) NOT NULL COMMENT 'A = ดีไซน์เดิม, B = ดีไซน์ใหม่',
  step        VARCHAR(20) NOT NULL COMMENT 'view_home/view_car/start_form/submit/booked',
  visitor_id  CHAR(16) NOT NULL COMMENT 'เลขสุ่มต่อเซสชัน ไม่ผูกกับตัวบุคคล',
  car_id      INT UNSIGNED DEFAULT NULL,
  device      VARCHAR(10) DEFAULT NULL COMMENT 'mobile / desktop',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ab_step (step, created_at),
  KEY idx_ab_visitor (visitor_id),
  KEY idx_ab_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตรวจผล
SELECT 'ตาราง ab_events' AS 'รายการ',
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ab_events') AS 'สร้างแล้ว (ต้องได้ 1)';
