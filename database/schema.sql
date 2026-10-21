-- ============================================================
-- thonglorcarrent — Database Schema
-- MySQL 8 / MariaDB 10.4+ (cPanel shared hosting)
-- Charset: utf8mb4 (รองรับภาษาไทย + emoji)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1) ผู้ใช้ระบบหลังบ้าน (admin / staff)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120)  NOT NULL,
  email         VARCHAR(150)  NOT NULL,
  password_hash VARCHAR(255)  NOT NULL,
  role          ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  phone         VARCHAR(30)   DEFAULT NULL,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  last_login_at DATETIME      DEFAULT NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2) ลูกค้า
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(20)   DEFAULT NULL,          -- CUS-0001
  type           ENUM('individual','company') NOT NULL DEFAULT 'individual',
  full_name      VARCHAR(180)  NOT NULL,              -- ชื่อ-สกุล หรือ ชื่อบริษัท
  tax_id         VARCHAR(20)   DEFAULT NULL,          -- เลขผู้เสียภาษี (นิติบุคคล)
  phone          VARCHAR(30)   NOT NULL,
  email          VARCHAR(150)  DEFAULT NULL,
  line_id        VARCHAR(80)   DEFAULT NULL,
  address        TEXT          DEFAULT NULL,
  license_no     VARCHAR(40)   DEFAULT NULL,          -- เลขใบขับขี่
  license_expire DATE          DEFAULT NULL,
  id_card_file   VARCHAR(255)  DEFAULT NULL,          -- path ไฟล์สำเนาบัตร
  license_file   VARCHAR(255)  DEFAULT NULL,          -- path ไฟล์ใบขับขี่
  is_blacklist   TINYINT(1)    NOT NULL DEFAULT 0,
  blacklist_note TEXT          DEFAULT NULL,
  note           TEXT          DEFAULT NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_code (code),
  KEY idx_customers_phone (phone),
  KEY idx_customers_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3) รถ (8 คัน)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cars (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plate           VARCHAR(20)   NOT NULL,             -- ทะเบียน
  province        VARCHAR(60)   DEFAULT NULL,
  brand           VARCHAR(60)   NOT NULL,
  model           VARCHAR(80)   NOT NULL,
  year            SMALLINT      DEFAULT NULL,
  color           VARCHAR(40)   DEFAULT NULL,
  car_type        VARCHAR(40)   DEFAULT 'sedan',      -- sedan / suv / pickup / van / eco
  segment         VARCHAR(60)   DEFAULT NULL,         -- ป้ายกำกับ เช่น "รถเก๋ง (Eco Car)", "รถ SUV (PPV)"
  seats           TINYINT       DEFAULT 5,
  gear            ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  fuel            VARCHAR(30)   DEFAULT 'เบนซิน',
  -- ราคาเช่า
  price_day       DECIMAL(10,2) NOT NULL DEFAULT 0,
  price_week      DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 0 = ใช้ price_day x 7
  price_month     DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 0 = ใช้ price_day x 30
  deposit         DECIMAL(10,2) NOT NULL DEFAULT 0,   -- เงินประกัน (คืนเมื่อคืนรถ)
  deposit_booking DECIMAL(10,2) NOT NULL DEFAULT 0,   -- มัดจำเพื่อยืนยันการจอง
  -- บริการเสริมเริ่มต้นต่อคัน
  delivery_fee    DECIMAL(10,2) NOT NULL DEFAULT 0,
  driver_fee_day  DECIMAL(10,2) NOT NULL DEFAULT 0,
  -- สถานะรถ
  status          ENUM('available','maintenance','inactive') NOT NULL DEFAULT 'available',
  mileage         INT UNSIGNED  NOT NULL DEFAULT 0,
  -- เอกสาร/งานประจำรถ
  insurance_type  VARCHAR(40)   DEFAULT NULL,         -- ชั้น 1 / 2+ / 3
  insurance_expire DATE         DEFAULT NULL,
  act_expire      DATE          DEFAULT NULL,         -- พ.ร.บ.
  tax_expire      DATE          DEFAULT NULL,         -- ภาษีประจำปี
  next_service_km INT UNSIGNED  DEFAULT NULL,
  next_service_at DATE          DEFAULT NULL,
  cover_image     VARCHAR(255)  DEFAULT NULL,
  images          TEXT          DEFAULT NULL,         -- JSON array ของ path
  features        TEXT          DEFAULT NULL,         -- JSON array เช่น ["กล้องหน้า","Apple CarPlay"]
  description     TEXT          DEFAULT NULL,
  sort_order      SMALLINT      NOT NULL DEFAULT 0,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cars_plate (plate),
  KEY idx_cars_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4) การจอง
--    booking_no: BK260919-001
--    สถานะ: pending -> confirmed -> picked_up -> returned -> (completed)
--            cancelled ได้ทุกช่วงก่อน returned
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bookings (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_no      VARCHAR(24)   NOT NULL,
  customer_id     INT UNSIGNED  DEFAULT NULL,
  car_id          INT UNSIGNED  NOT NULL,
  -- ข้อมูลผู้จอง (เก็บซ้ำไว้ กรณีจองหน้าเว็บก่อนสร้างลูกค้า)
  contact_name    VARCHAR(180)  NOT NULL,
  contact_phone   VARCHAR(30)   NOT NULL,
  contact_email   VARCHAR(150)  DEFAULT NULL,
  contact_line    VARCHAR(80)   DEFAULT NULL,
  -- ช่วงเช่า
  pickup_at       DATETIME      NOT NULL,
  return_at       DATETIME      NOT NULL,
  pickup_place    VARCHAR(255)  DEFAULT NULL,
  return_place    VARCHAR(255)  DEFAULT NULL,
  rental_days     SMALLINT      NOT NULL DEFAULT 1,
  -- การคิดราคา (snapshot ตอนจอง — ราคาเปลี่ยนภายหลังไม่กระทบบิลเดิม)
  rate_breakdown  TEXT          DEFAULT NULL,         -- JSON อธิบายการคิดราคา
  rental_amount   DECIMAL(10,2) NOT NULL DEFAULT 0,   -- ค่าเช่าสุทธิหลังเรตเดือน/สัปดาห์
  addon_amount    DECIMAL(10,2) NOT NULL DEFAULT 0,   -- บริการเสริมรวม
  discount        DECIMAL(10,2) NOT NULL DEFAULT 0,
  subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0,
  vat_rate        DECIMAL(5,2)  NOT NULL DEFAULT 0,   -- 0 หรือ 7
  vat_amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
  grand_total     DECIMAL(10,2) NOT NULL DEFAULT 0,
  deposit_amount  DECIMAL(10,2) NOT NULL DEFAULT 0,   -- ยอดมัดจำที่ต้องโอน
  paid_amount     DECIMAL(10,2) NOT NULL DEFAULT 0,   -- รวมที่ยืนยันแล้ว
  security_deposit DECIMAL(10,2) NOT NULL DEFAULT 0,  -- เงินประกันที่รับไว้
  -- บริการเสริมที่เลือก (JSON)
  addons          TEXT          DEFAULT NULL,
  -- สถานะ
  status          ENUM('pending','confirmed','picked_up','returned','completed','cancelled')
                  NOT NULL DEFAULT 'pending',
  payment_status  ENUM('unpaid','deposit_paid','paid','refunded') NOT NULL DEFAULT 'unpaid',
  source          ENUM('web','admin','line','phone') NOT NULL DEFAULT 'web',
  -- รับ/คืนรถจริง
  mileage_out     INT UNSIGNED  DEFAULT NULL,
  mileage_in      INT UNSIGNED  DEFAULT NULL,
  fuel_out        TINYINT       DEFAULT NULL,         -- 0-8 (ขีดน้ำมัน)
  fuel_in         TINYINT       DEFAULT NULL,
  picked_up_at    DATETIME      DEFAULT NULL,
  returned_at     DATETIME      DEFAULT NULL,
  extra_charge    DECIMAL(10,2) NOT NULL DEFAULT 0,   -- ค่าปรับ/คืนช้า/น้ำมันขาด
  extra_note      TEXT          DEFAULT NULL,
  damage_note     TEXT          DEFAULT NULL,
  note            TEXT          DEFAULT NULL,
  cancel_reason   VARCHAR(255)  DEFAULT NULL,
  created_by      INT UNSIGNED  DEFAULT NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bookings_no (booking_no),
  KEY idx_bookings_car_range (car_id, pickup_at, return_at),
  KEY idx_bookings_status (status),
  KEY idx_bookings_customer (customer_id),
  CONSTRAINT fk_bookings_car FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5) การชำระเงิน / สลิป
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id    INT UNSIGNED  NOT NULL,
  kind          ENUM('deposit','balance','security','extra','refund') NOT NULL DEFAULT 'deposit',
  method        ENUM('transfer','cash','card','promptpay') NOT NULL DEFAULT 'transfer',
  amount        DECIMAL(10,2) NOT NULL DEFAULT 0,
  paid_at       DATETIME      DEFAULT NULL,           -- เวลาที่ลูกค้าระบุว่าโอน
  slip_file     VARCHAR(255)  DEFAULT NULL,
  bank_ref      VARCHAR(80)   DEFAULT NULL,
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_by   INT UNSIGNED  DEFAULT NULL,
  approved_at   DATETIME      DEFAULT NULL,
  reject_reason VARCHAR(255)  DEFAULT NULL,
  note          TEXT          DEFAULT NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payments_booking (booking_id),
  KEY idx_payments_status (status),
  CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6) ใบเสนอราคา / ใบเสร็จ
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  doc_type     ENUM('quotation','invoice','receipt','contract') NOT NULL DEFAULT 'quotation',
  doc_no       VARCHAR(24)   NOT NULL,
  booking_id   INT UNSIGNED  DEFAULT NULL,
  customer_id  INT UNSIGNED  DEFAULT NULL,
  issue_date   DATE          NOT NULL,
  due_date     DATE          DEFAULT NULL,
  bill_name    VARCHAR(180)  DEFAULT NULL,
  bill_tax_id  VARCHAR(20)   DEFAULT NULL,
  bill_address TEXT          DEFAULT NULL,
  items        TEXT          DEFAULT NULL,            -- JSON [{desc,qty,unit,price,amount}]
  subtotal     DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount     DECIMAL(10,2) NOT NULL DEFAULT 0,
  vat_rate     DECIMAL(5,2)  NOT NULL DEFAULT 0,
  vat_amount   DECIMAL(10,2) NOT NULL DEFAULT 0,
  wht_rate     DECIMAL(5,2)  NOT NULL DEFAULT 0,      -- หัก ณ ที่จ่าย (เช่าทรัพย์สิน 5%)
  wht_amount   DECIMAL(10,2) NOT NULL DEFAULT 0,
  grand_total  DECIMAL(10,2) NOT NULL DEFAULT 0,
  status       ENUM('draft','sent','accepted','paid','void') NOT NULL DEFAULT 'draft',
  note         TEXT          DEFAULT NULL,
  created_by   INT UNSIGNED  DEFAULT NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_documents_no (doc_no),
  KEY idx_documents_booking (booking_id),
  KEY idx_documents_type (doc_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7) งานประจำรถ / ซ่อมบำรุง
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS maintenances (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  car_id      INT UNSIGNED  NOT NULL,
  task_type   ENUM('service','insurance','act','tax','tyre','repair','other') NOT NULL DEFAULT 'service',
  title       VARCHAR(180)  NOT NULL,
  due_date    DATE          DEFAULT NULL,
  due_mileage INT UNSIGNED  DEFAULT NULL,
  done_date   DATE          DEFAULT NULL,
  cost        DECIMAL(10,2) NOT NULL DEFAULT 0,
  vendor      VARCHAR(150)  DEFAULT NULL,
  status      ENUM('pending','done','skipped') NOT NULL DEFAULT 'pending',
  notified_at DATETIME      DEFAULT NULL,
  note        TEXT          DEFAULT NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_maint_car (car_id),
  KEY idx_maint_due (due_date, status),
  CONSTRAINT fk_maint_car FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8) บล็อกวันรถไม่ว่าง (ซ่อม / เจ้าของใช้เอง)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS car_blocks (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  car_id     INT UNSIGNED NOT NULL,
  start_at   DATETIME     NOT NULL,
  end_at     DATETIME     NOT NULL,
  reason     VARCHAR(255) DEFAULT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_blocks_car_range (car_id, start_at, end_at),
  CONSTRAINT fk_blocks_car FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9) ตั้งค่าระบบ (key/value)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  skey       VARCHAR(80)  NOT NULL,
  svalue     TEXT         DEFAULT NULL,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10) Log การทำงาน (PDPA / ตรวจย้อนหลัง)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED DEFAULT NULL,
  action     VARCHAR(80)  NOT NULL,
  ref_table  VARCHAR(40)  DEFAULT NULL,
  ref_id     INT UNSIGNED DEFAULT NULL,
  detail     TEXT         DEFAULT NULL,
  ip         VARCHAR(45)  DEFAULT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_logs_ref (ref_table, ref_id),
  KEY idx_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11) คิวแจ้งเตือน (LINE / Email)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel    ENUM('line','email') NOT NULL DEFAULT 'line',
  recipient  VARCHAR(190) DEFAULT NULL,
  subject    VARCHAR(190) DEFAULT NULL,
  message    TEXT         NOT NULL,
  ref_table  VARCHAR(40)  DEFAULT NULL,
  ref_id     INT UNSIGNED DEFAULT NULL,
  status     ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  error      VARCHAR(255) DEFAULT NULL,
  sent_at    DATETIME     DEFAULT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
