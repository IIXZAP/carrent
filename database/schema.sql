-- ============================================================
-- thonglorcarrent — โครงสร้างฐานข้อมูล (ติดตั้งใหม่ ไฟล์เดียวจบ)
-- รองรับ MySQL 8.0+ / MariaDB 10.4+ (ทดสอบแนวทางกับ MySQL 8.4 และ MariaDB 10.11)
-- Charset: utf8mb4 (ภาษาไทย + emoji)
--
-- ลำดับติดตั้ง: schema.sql → seed.sql
-- (ไฟล์ update-v2..v5 เดิมรวมเข้ามาในไฟล์นี้หมดแล้ว)
--
-- จุดเด่นของโครงสร้างนี้
--   * Foreign key ครบ: ข้อมูลการเงิน/เอกสาร/หลักฐาน (payments, documents,
--     inspections, signatures) ใช้ RESTRICT — ลบการจองแล้วหลักฐานไม่หายตาม
--   * CHECK: ยอดเงินไม่ติดลบ, คืนรถหลังรับรถ, ขีดน้ำมัน 0–8, ไมล์คืน >= ไมล์รับ
--   * car_types: เพิ่ม/แก้ประเภทรถได้จากหลังบ้าน ไม่ต้องแก้โค้ด
--   * วันหมดอายุประกัน/พ.ร.บ./ภาษี/เช็กระยะ เก็บที่ maintenances ที่เดียว
--     (ดูสรุปต่อคันได้จาก VIEW v_car_compliance)
--   * sequences: ตัวนับเลขที่การจอง/ลูกค้า/เอกสาร ไม่ซ้ำแม้มีหลายคำขอพร้อมกัน
--
-- หมายเหตุ
--   * CHECK บังคับใช้จริงเมื่อ MySQL >= 8.0.16 หรือ MariaDB >= 10.2
--   * Timezone: แอปตั้ง SET time_zone ทุก connection เอง (DATETIME ไม่เก็บ timezone)
--   * ไฟล์นี้ลบและสร้างตารางใหม่ทั้งหมด — ห้ามรันซ้ำบนฐานข้อมูลที่มีข้อมูลจริง
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW  IF EXISTS v_car_compliance;
DROP TABLE IF EXISTS ab_events, line_events, signatures, inspection_photos, inspections,
                     customer_documents, sequences, notifications, activity_logs, settings,
                     car_blocks, maintenances, documents, payments, bookings, cars, car_types,
                     customers, users;

-- ------------------------------------------------------------
-- 1) ผู้ใช้ระบบหลังบ้าน (admin / staff)
-- ------------------------------------------------------------
CREATE TABLE users (
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
--    id_card_file / license_file: ช่องเดิมของเอกสารที่อัปโหลดก่อนมีที่เก็บแบบปิด
--    (เอกสารใหม่อยู่ที่ customer_documents)
-- ------------------------------------------------------------
CREATE TABLE customers (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(20)   DEFAULT NULL,
  type           ENUM('individual','company') NOT NULL DEFAULT 'individual',
  full_name      VARCHAR(180)  NOT NULL,
  tax_id         VARCHAR(20)   DEFAULT NULL,
  phone          VARCHAR(30)   NOT NULL,
  email          VARCHAR(150)  DEFAULT NULL,
  line_id        VARCHAR(80)   DEFAULT NULL,
  line_user_id   VARCHAR(60)   DEFAULT NULL,
  address        TEXT          DEFAULT NULL,
  license_no     VARCHAR(40)   DEFAULT NULL,
  license_expire DATE          DEFAULT NULL,
  id_card_file   VARCHAR(255)  DEFAULT NULL,
  license_file   VARCHAR(255)  DEFAULT NULL,
  is_blacklist   TINYINT(1)    NOT NULL DEFAULT 0,
  blacklist_note TEXT          DEFAULT NULL,
  note           TEXT          DEFAULT NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_code (code),
  KEY idx_customers_phone (phone),
  KEY idx_customers_name (full_name),
  KEY idx_cust_line (line_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3) ประเภทรถ (จัดการได้ที่ หลังบ้าน → ประเภทรถ)
-- ------------------------------------------------------------
CREATE TABLE car_types (
  code       VARCHAR(20)  NOT NULL,                  -- eco / sedan / suv / pickup / van ...
  label_th   VARCHAR(60)  NOT NULL,                  -- ป้ายที่แสดงบนเว็บ
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,        -- 0 = ซ่อนจากตัวเลือก (รถเดิมยังใช้ได้)
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO car_types (code, label_th, sort_order) VALUES
  ('eco',    'อีโคคาร์', 10),
  ('sedan',  'รถเก๋ง',   20),
  ('suv',    'SUV',      30),
  ('pickup', 'กระบะ',    40),
  ('van',    'รถตู้',    50);

-- ------------------------------------------------------------
-- 4) รถ
--    segment: ป้ายย่อยของคัน (ข้อความอิสระ เช่น "รถเก๋ง (C-Segment)")
--    insurance_type: ชั้นประกันที่แสดงให้ลูกค้าเห็น (วันหมดอายุอยู่ที่ maintenances)
-- ------------------------------------------------------------
CREATE TABLE cars (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plate           VARCHAR(20)   NOT NULL,
  province        VARCHAR(60)   DEFAULT NULL,
  brand           VARCHAR(60)   NOT NULL,
  model           VARCHAR(80)   NOT NULL,
  year            SMALLINT UNSIGNED DEFAULT NULL,    -- ปี ค.ศ.
  color           VARCHAR(40)   DEFAULT NULL,
  car_type        VARCHAR(20)   NOT NULL DEFAULT 'sedan',
  segment         VARCHAR(60)   DEFAULT NULL,
  seats           TINYINT UNSIGNED DEFAULT 5,
  gear            ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  fuel            VARCHAR(30)   DEFAULT 'เบนซิน',
  price_day       DECIMAL(10,2) NOT NULL DEFAULT 0,
  price_week      DECIMAL(10,2) NOT NULL DEFAULT 0,  -- 0 = คิดจากราคารายวัน
  price_month     DECIMAL(10,2) NOT NULL DEFAULT 0,  -- 0 = คิดจากราคารายวัน
  deposit         DECIMAL(10,2) NOT NULL DEFAULT 0,  -- เงินประกันความเสียหาย (คืนเมื่อคืนรถ)
  deposit_booking DECIMAL(10,2) NOT NULL DEFAULT 0,  -- มัดจำเพื่อยืนยันการจอง
  delivery_fee    DECIMAL(10,2) NOT NULL DEFAULT 0,
  driver_fee_day  DECIMAL(10,2) NOT NULL DEFAULT 0,
  status          ENUM('available','maintenance','inactive') NOT NULL DEFAULT 'available',
  mileage         INT UNSIGNED  NOT NULL DEFAULT 0,
  insurance_type  VARCHAR(40)   DEFAULT NULL,
  cover_image     VARCHAR(255)  DEFAULT NULL,
  images          TEXT          DEFAULT NULL,        -- JSON array ของ path
  features        TEXT          DEFAULT NULL,        -- JSON array เช่น ["กล้องหน้า","Apple CarPlay"]
  description     TEXT          DEFAULT NULL,
  sort_order      SMALLINT      NOT NULL DEFAULT 0,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cars_plate (plate),
  KEY idx_cars_status (status),
  KEY idx_cars_type (car_type),
  CONSTRAINT fk_cars_type FOREIGN KEY (car_type) REFERENCES car_types(code) ON UPDATE CASCADE,
  CONSTRAINT chk_cars_year   CHECK (year IS NULL OR year BETWEEN 1990 AND 2100),
  CONSTRAINT chk_cars_prices CHECK (price_day >= 0 AND price_week >= 0 AND price_month >= 0
                                    AND deposit >= 0 AND deposit_booking >= 0
                                    AND delivery_fee >= 0 AND driver_fee_day >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5) การจอง
--    สถานะ: pending → confirmed → picked_up → returned → completed (cancelled ได้ก่อน returned)
--    ยอดเงินเป็น snapshot ตอนจอง (ราคาเปลี่ยนภายหลังไม่กระทบบิลเดิม)
--    paid_amount: ผลรวมที่ approved แล้ว (PaymentService คำนวณใหม่ทุกครั้งที่มีรายการเงิน)
--    ลายเซ็นอยู่ที่ตาราง signatures (ไม่เก็บซ้ำในการจอง)
-- ------------------------------------------------------------
CREATE TABLE bookings (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_no       VARCHAR(24)   NOT NULL,
  customer_id      INT UNSIGNED  DEFAULT NULL,
  car_id           INT UNSIGNED  NOT NULL,
  contact_name     VARCHAR(180)  NOT NULL,
  contact_phone    VARCHAR(30)   NOT NULL,
  contact_email    VARCHAR(150)  DEFAULT NULL,
  contact_line     VARCHAR(80)   DEFAULT NULL,
  line_user_id     VARCHAR(60)   DEFAULT NULL,       -- LINE ที่ยืนยันการจองนี้แล้ว (ไว้ส่งแจ้งเตือน)
  pickup_at        DATETIME      NOT NULL,
  return_at        DATETIME      NOT NULL,
  pickup_place     VARCHAR(255)  DEFAULT NULL,
  return_place     VARCHAR(255)  DEFAULT NULL,
  delivery_km      DECIMAL(7,1)  DEFAULT NULL,       -- ระยะรับ–ส่งรถถึงที่ (กม.)
  rental_days      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  rate_breakdown   TEXT          DEFAULT NULL,       -- JSON อธิบายการคิดราคา
  addons           TEXT          DEFAULT NULL,       -- JSON บริการเสริมที่เลือก (snapshot)
  rental_amount    DECIMAL(10,2) NOT NULL DEFAULT 0,
  addon_amount     DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount         DECIMAL(10,2) NOT NULL DEFAULT 0,
  subtotal         DECIMAL(10,2) NOT NULL DEFAULT 0,
  vat_rate         DECIMAL(5,2)  NOT NULL DEFAULT 0,
  vat_amount       DECIMAL(10,2) NOT NULL DEFAULT 0,
  grand_total      DECIMAL(10,2) NOT NULL DEFAULT 0,
  deposit_amount   DECIMAL(10,2) NOT NULL DEFAULT 0, -- มัดจำที่ต้องโอน
  paid_amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
  security_deposit DECIMAL(10,2) NOT NULL DEFAULT 0, -- เงินประกันที่รับไว้
  status           ENUM('pending','confirmed','picked_up','returned','completed','cancelled')
                   NOT NULL DEFAULT 'pending',
  payment_status   ENUM('unpaid','deposit_paid','paid','refunded') NOT NULL DEFAULT 'unpaid',
  source           ENUM('web','admin','line','phone') NOT NULL DEFAULT 'web',
  mileage_out      INT UNSIGNED  DEFAULT NULL,
  mileage_in       INT UNSIGNED  DEFAULT NULL,
  fuel_out         TINYINT UNSIGNED DEFAULT NULL,    -- 0–8 ขีด
  fuel_in          TINYINT UNSIGNED DEFAULT NULL,
  picked_up_at     DATETIME      DEFAULT NULL,
  returned_at      DATETIME      DEFAULT NULL,
  extra_charge     DECIMAL(10,2) NOT NULL DEFAULT 0, -- ค่าใช้จ่ายเพิ่มตอนคืนรถ (ไม่รวมใน grand_total)
  extra_note       TEXT          DEFAULT NULL,
  extra_breakdown  TEXT          DEFAULT NULL,       -- JSON แยกรายการค่าใช้จ่ายเพิ่ม
  damage_note      TEXT          DEFAULT NULL,
  note             TEXT          DEFAULT NULL,
  cancel_reason    VARCHAR(255)  DEFAULT NULL,
  sign_token       VARCHAR(64)   DEFAULT NULL,       -- รหัสลิงก์เซ็นเอกสาร (สุ่ม 128 บิต)
  created_by       INT UNSIGNED  DEFAULT NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bookings_no (booking_no),
  KEY idx_bookings_car_range (car_id, pickup_at, return_at),
  KEY idx_bookings_status (status),
  KEY idx_bookings_customer (customer_id),
  KEY idx_bookings_pickup (pickup_at),
  KEY idx_bookings_payment (payment_status),
  KEY idx_bookings_phone (contact_phone),
  KEY idx_bookings_created_by (created_by),
  KEY idx_bookings_line_user (line_user_id),
  KEY idx_bk_sign_token (sign_token),
  CONSTRAINT fk_bookings_car        FOREIGN KEY (car_id)      REFERENCES cars(id)      ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_customer   FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_bookings_created_by FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL,
  CONSTRAINT chk_bookings_range   CHECK (return_at > pickup_at),
  CONSTRAINT chk_bookings_days    CHECK (rental_days >= 1),
  CONSTRAINT chk_bookings_mileage CHECK (mileage_in IS NULL OR mileage_out IS NULL OR mileage_in >= mileage_out),
  CONSTRAINT chk_bookings_fuel    CHECK ((fuel_out IS NULL OR fuel_out <= 8) AND (fuel_in IS NULL OR fuel_in <= 8)),
  CONSTRAINT chk_bookings_amounts CHECK (rental_amount >= 0 AND addon_amount >= 0 AND discount >= 0
                                         AND subtotal >= 0 AND vat_amount >= 0 AND grand_total >= 0
                                         AND deposit_amount >= 0 AND paid_amount >= 0
                                         AND security_deposit >= 0 AND extra_charge >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6) การชำระเงิน / สลิป
--    amount เก็บเป็นบวกเสมอ — ทิศทางเงินดูจาก kind
--    method 'deduct' = หักจากเงินประกัน (ไม่มีเงินเข้า–ออกจริง)
-- ------------------------------------------------------------
CREATE TABLE payments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id    INT UNSIGNED  NOT NULL,
  kind          ENUM('deposit','balance','security','extra','refund','security_refund') NOT NULL DEFAULT 'deposit',
  method        ENUM('transfer','cash','card','promptpay','deduct') NOT NULL DEFAULT 'transfer',
  amount        DECIMAL(10,2) NOT NULL DEFAULT 0,
  paid_at       DATETIME      DEFAULT NULL,
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
  KEY idx_payments_paid_at (paid_at),
  KEY idx_payments_approved_by (approved_by),
  CONSTRAINT fk_payments_booking     FOREIGN KEY (booking_id)  REFERENCES bookings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_approved_by FOREIGN KEY (approved_by) REFERENCES users(id)    ON DELETE SET NULL,
  CONSTRAINT chk_payments_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7) ใบเสนอราคา / ใบแจ้งหนี้ / ใบเสร็จ / สัญญา
--    bill_*, vat_rate, wht_rate เป็น snapshot ตามวันที่ออกเอกสาร
-- ------------------------------------------------------------
CREATE TABLE documents (
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
  items        TEXT          DEFAULT NULL,           -- JSON รายการในเอกสาร
  subtotal     DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount     DECIMAL(10,2) NOT NULL DEFAULT 0,
  vat_rate     DECIMAL(5,2)  NOT NULL DEFAULT 0,
  vat_amount   DECIMAL(10,2) NOT NULL DEFAULT 0,
  wht_rate     DECIMAL(5,2)  NOT NULL DEFAULT 0,
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
  KEY idx_documents_customer (customer_id),
  KEY idx_documents_type (doc_type),
  KEY idx_documents_issue (issue_date),
  KEY idx_documents_created_by (created_by),
  CONSTRAINT fk_documents_booking    FOREIGN KEY (booking_id)  REFERENCES bookings(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_documents_customer   FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_documents_created_by FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL,
  CONSTRAINT chk_documents_amounts CHECK (subtotal >= 0 AND discount >= 0 AND vat_amount >= 0
                                          AND wht_amount >= 0 AND grand_total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8) งานประจำรถ — แหล่งความจริงของประกัน / พ.ร.บ. / ภาษี / เช็กระยะ
--    insurance, act, tax : due_date = วันหมดอายุ
--    service             : due_date และ/หรือ due_mileage = กำหนดเข้าศูนย์
-- ------------------------------------------------------------
CREATE TABLE maintenances (
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
  KEY idx_maint_car_type (car_id, task_type, status),
  CONSTRAINT fk_maint_car FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE,
  CONSTRAINT chk_maint_cost CHECK (cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9) บล็อกวันรถไม่ว่าง (ซ่อม / เจ้าของใช้เอง)
-- ------------------------------------------------------------
CREATE TABLE car_blocks (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  car_id     INT UNSIGNED NOT NULL,
  start_at   DATETIME     NOT NULL,
  end_at     DATETIME     NOT NULL,
  reason     VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_blocks_car_range (car_id, start_at, end_at),
  KEY idx_blocks_created_by (created_by),
  CONSTRAINT fk_blocks_car        FOREIGN KEY (car_id)     REFERENCES cars(id)  ON DELETE CASCADE,
  CONSTRAINT fk_blocks_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_blocks_range CHECK (end_at > start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10) ตั้งค่าระบบ (key/value)
-- ------------------------------------------------------------
CREATE TABLE settings (
  skey       VARCHAR(80)  NOT NULL,
  svalue     TEXT         DEFAULT NULL,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11) บันทึกกิจกรรม (PDPA / ตรวจย้อนหลัง / นับความพยายามล็อกอินผิด)
--     ref_table + ref_id เป็นการอ้างอิงแบบหลวม (ไม่มี FK โดยออกแบบ)
-- ------------------------------------------------------------
CREATE TABLE activity_logs (
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
  KEY idx_logs_created (created_at),
  KEY idx_logs_user (user_id),
  CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 12) คิวแจ้งเตือน (LINE / Email)
--     tpl_key + ref_id: กันส่งข้อความเตือนซ้ำต่อการจอง
-- ------------------------------------------------------------
CREATE TABLE notifications (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel    ENUM('line','email') NOT NULL DEFAULT 'line',
  tpl_key    VARCHAR(40)  DEFAULT NULL,
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
  KEY idx_notif_status (status),
  KEY idx_notif_tpl (tpl_key, ref_id),
  KEY idx_notif_ref (ref_table, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 13) ตัวนับเลขที่ (การจอง / ลูกค้า / เอกสาร)
--     name   : 'booking' | 'customer' | 'quotation' | 'invoice' | 'receipt' | 'contract'
--     period : '260919' (รายวัน) | '2609' (รายเดือน) | '' (ไม่รีเซ็ต)
--     เพิ่มเลขด้วยคำสั่งเดียวแบบ atomic — ดู App\Models\Sequence::next()
-- ------------------------------------------------------------
CREATE TABLE sequences (
  name    VARCHAR(30)  NOT NULL,
  period  VARCHAR(10)  NOT NULL DEFAULT '',
  last_no INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (name, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 14) เอกสารลูกค้า (สำเนาบัตร / ใบขับขี่) — ไฟล์อยู่ใน storage/private
-- ------------------------------------------------------------
CREATE TABLE customer_documents (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id   INT UNSIGNED DEFAULT NULL,
  booking_id    INT UNSIGNED DEFAULT NULL,
  doc_kind      ENUM('id_card','driver_license','passport','other') NOT NULL DEFAULT 'id_card',
  file_path     VARCHAR(255) NOT NULL,
  mime          VARCHAR(60)  DEFAULT NULL,
  file_size     INT UNSIGNED DEFAULT NULL,
  number_masked VARCHAR(40)  DEFAULT NULL,           -- เก็บเฉพาะเลขบางส่วน เช่น x-xxxx-xxxxx-45-6
  expires_on    DATE         DEFAULT NULL,
  note          VARCHAR(255) DEFAULT NULL,
  uploaded_by   INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_doc_customer (customer_id),
  KEY idx_doc_booking (booking_id),
  KEY idx_doc_uploaded_by (uploaded_by),
  CONSTRAINT fk_cdoc_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_cdoc_booking  FOREIGN KEY (booking_id)  REFERENCES bookings(id)  ON DELETE SET NULL,
  CONSTRAINT fk_cdoc_user     FOREIGN KEY (uploaded_by) REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 15) ตรวจสภาพรถก่อน–หลัง (ถ่ายรูปผูกกับการจอง) — locked_at ไม่ว่าง = ล็อกแล้วแก้ไม่ได้
-- ------------------------------------------------------------
CREATE TABLE inspections (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id   INT UNSIGNED NOT NULL,
  kind         ENUM('out','in') NOT NULL,            -- out = ตอนส่งมอบ, in = ตอนรับคืน
  mileage      INT UNSIGNED DEFAULT NULL,
  fuel_level   TINYINT UNSIGNED DEFAULT NULL,        -- 0–8 ขีด
  note         TEXT         DEFAULT NULL,
  inspected_by INT UNSIGNED DEFAULT NULL,
  inspected_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at    DATETIME     DEFAULT NULL,
  locked_by    INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_kind (booking_id, kind),
  KEY idx_insp_inspected_by (inspected_by),
  KEY idx_insp_locked_by (locked_by),
  CONSTRAINT fk_insp_booking   FOREIGN KEY (booking_id)   REFERENCES bookings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_insp_inspector FOREIGN KEY (inspected_by) REFERENCES users(id)    ON DELETE SET NULL,
  CONSTRAINT fk_insp_locker    FOREIGN KEY (locked_by)    REFERENCES users(id)    ON DELETE SET NULL,
  CONSTRAINT chk_insp_fuel CHECK (fuel_level IS NULL OR fuel_level <= 8)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inspection_photos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  inspection_id INT UNSIGNED NOT NULL,
  position      VARCHAR(30)  NOT NULL,               -- front/rear/left/right/interior/dashboard/fuel/damage
  file_path     VARCHAR(255) NOT NULL,
  caption       VARCHAR(255) DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  uploaded_by   INT UNSIGNED DEFAULT NULL,
  superseded_at DATETIME     DEFAULT NULL,           -- ถูกถ่ายแทนด้วยรูปใหม่ (เก็บไว้เป็นประวัติ)
  PRIMARY KEY (id),
  KEY idx_photo_insp (inspection_id),
  KEY idx_photo_uploaded_by (uploaded_by),
  CONSTRAINT fk_photo_insp FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE,
  CONSTRAINT fk_photo_user FOREIGN KEY (uploaded_by)   REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 16) ลายเซ็นอิเล็กทรอนิกส์ — ไม่ลบ ขอเซ็นใหม่ = ทำเครื่องหมาย voided_at
--     snapshot = สำเนาข้อมูล + ข้อสัญญา ณ เวลาที่เซ็น, snapshot_hash = sha256 ของ snapshot
-- ------------------------------------------------------------
CREATE TABLE signatures (
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
  KEY idx_sig_booking (booking_id, kind, voided_at),
  KEY idx_sig_voided_by (voided_by),
  CONSTRAINT fk_sig_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sig_voider  FOREIGN KEY (voided_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 17) เหตุการณ์จาก LINE webhook (เก็บ 30 วันแล้วลบอัตโนมัติ)
-- ------------------------------------------------------------
CREATE TABLE line_events (
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
-- 18) สถิติเทียบดีไซน์ A/B — ไม่เก็บข้อมูลส่วนบุคคล
-- ------------------------------------------------------------
CREATE TABLE ab_events (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  variant    CHAR(1)      NOT NULL,                  -- A = ดีไซน์เดิม, B = ดีไซน์ใหม่
  step       VARCHAR(20)  NOT NULL,                  -- view_home/view_car/start_form/submit/booked
  visitor_id CHAR(16)     NOT NULL,                  -- เลขสุ่มต่อเซสชัน ไม่ผูกกับตัวบุคคล
  car_id     INT UNSIGNED DEFAULT NULL,
  device     VARCHAR(10)  DEFAULT NULL,              -- mobile / desktop
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ab_step (step, created_at),
  KEY idx_ab_visitor (visitor_id),
  KEY idx_ab_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- VIEW: สรุปวันหมดอายุ / กำหนดเข้าศูนย์ของรถแต่ละคัน
--       (เอาจากงานประจำรถที่ยัง pending — ใช้ค่าที่ใกล้ถึงที่สุด)
-- ============================================================
CREATE VIEW v_car_compliance AS
SELECT
  c.id AS car_id,
  c.plate,
  (SELECT MIN(m.due_date) FROM maintenances m
    WHERE m.car_id = c.id AND m.task_type = 'insurance' AND m.status = 'pending') AS insurance_expire,
  (SELECT MIN(m.due_date) FROM maintenances m
    WHERE m.car_id = c.id AND m.task_type = 'act'       AND m.status = 'pending') AS act_expire,
  (SELECT MIN(m.due_date) FROM maintenances m
    WHERE m.car_id = c.id AND m.task_type = 'tax'       AND m.status = 'pending') AS tax_expire,
  (SELECT MIN(m.due_date) FROM maintenances m
    WHERE m.car_id = c.id AND m.task_type = 'service'   AND m.status = 'pending') AS next_service_at,
  (SELECT MIN(m.due_mileage) FROM maintenances m
    WHERE m.car_id = c.id AND m.task_type = 'service'   AND m.status = 'pending') AS next_service_km
FROM cars c;

-- ============================================================
-- แนวทางกันจองซ้อนทับ (MySQL ไม่มี exclusion constraint)
-- โค้ดทำใน transaction เดียวกันทุกครั้งที่สร้าง/แก้การจอง:
--   SELECT id FROM cars WHERE id = :car_id FOR UPDATE;   -- ล็อกคิวของรถคันนี้
--   นับ bookings (pending/confirmed/picked_up) และ car_blocks ที่ช่วงเวลาทับกัน
--   ถ้า > 0 → ROLLBACK แจ้งรถไม่ว่าง
-- ============================================================
