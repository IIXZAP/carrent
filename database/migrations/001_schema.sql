-- ============================================================
-- thonglorcarrent — Database Schema v3 (สำหรับเขียนระบบใหม่)
-- MySQL 8 / MariaDB 10.4+  ·  utf8mb4
--
-- ต่อยอดจาก schema_v2 ที่ออกแบบไว้ โดย
--   [+] เพิ่มตารางที่ระบบจริงใช้แต่ v2 ขาด:
--         customer_documents, inspections, inspection_photos,
--         signatures, line_events
--   [-] ตัด ab_events ทิ้ง (เลิกทำ A/B test)
--   [-] ตัด bookings.mileage_out/in, fuel_out/in
--         → แหล่งจริงอยู่ที่ inspections (kind = out/in)
--   [-] ตัด bookings.paid_amount → ใช้ VIEW v_booking_payment
--   [-] ตัด customers.id_card_file / license_file
--         → ใช้ customer_documents (หลายไฟล์ต่อคน ผูกกับ booking ได้)
--   [✓] เปิด CHECK: subtotal / grand_total ต้องตรงสูตร
--         (ถ้า service คิดราคาปัดเศษต่างจากนี้ ให้ปรับสูตรหรือลบสองบรรทัดนั้น)
--
-- ใช้สำหรับติดตั้งใหม่เท่านั้น (ไม่มีส่วน migrate จาก v1)
-- CHECK บังคับใช้จริงเมื่อ MySQL >= 8.0.16 หรือ MariaDB >= 10.2
-- Timezone: ให้แอปรัน  SET time_zone = '+07:00';  ทุก connection
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1) ผู้ใช้ระบบหลังบ้าน
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120)  NOT NULL,
  email         VARCHAR(150)  NOT NULL,             -- แอป lowercase ก่อนบันทึก
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
--    ไฟล์บัตร/ใบขับขี่อยู่ที่ customer_documents
--    PDPA: license_no / tax_id ควรเข้ารหัสที่ชั้นแอป
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(20)   DEFAULT NULL,          -- CUS-0001
  type           ENUM('individual','company') NOT NULL DEFAULT 'individual',
  full_name      VARCHAR(180)  NOT NULL,
  tax_id         VARCHAR(20)   DEFAULT NULL,
  phone          VARCHAR(30)   NOT NULL,
  email          VARCHAR(150)  DEFAULT NULL,
  line_id        VARCHAR(80)   DEFAULT NULL,
  address        TEXT          DEFAULT NULL,
  license_no     VARCHAR(40)   DEFAULT NULL,
  license_expire DATE          DEFAULT NULL,
  is_blacklist   TINYINT(1)    NOT NULL DEFAULT 0,
  blacklist_note TEXT          DEFAULT NULL,
  note           TEXT          DEFAULT NULL,
  deleted_at     DATETIME      DEFAULT NULL,          -- soft delete
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_code (code),
  KEY idx_customers_phone (phone),
  KEY idx_customers_name (full_name),
  KEY idx_customers_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3) ประเภทรถ (lookup)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS car_types (
  code       VARCHAR(20)  NOT NULL,
  label_th   VARCHAR(60)  NOT NULL,
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO car_types (code, label_th, sort_order) VALUES
  ('eco',    'รถเก๋ง (Eco Car)', 10),
  ('sedan',  'รถเก๋ง (Sedan)',   20),
  ('suv',    'รถ SUV',           30),
  ('ppv',    'รถ SUV (PPV)',     40),
  ('pickup', 'รถกระบะ',          50),
  ('van',    'รถตู้',            60);

-- ------------------------------------------------------------
-- 4) รถ
--    ประกัน/พ.ร.บ./ภาษี/เข้าศูนย์ อยู่ที่ maintenances (ดู v_car_compliance)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cars (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plate            VARCHAR(20)   NOT NULL,
  province         VARCHAR(60)   DEFAULT NULL,
  brand            VARCHAR(60)   NOT NULL,
  model            VARCHAR(80)   NOT NULL,
  year             SMALLINT UNSIGNED DEFAULT NULL,    -- ค.ศ.
  color            VARCHAR(40)   DEFAULT NULL,
  car_type_code    VARCHAR(20)   NOT NULL DEFAULT 'sedan',
  seats            TINYINT UNSIGNED DEFAULT 5,
  gear             ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  fuel             ENUM('petrol','diesel','hybrid','ev','lpg') NOT NULL DEFAULT 'petrol',
  price_day        DECIMAL(10,2) NOT NULL DEFAULT 0,
  price_week       DECIMAL(10,2) DEFAULT NULL,        -- NULL = price_day x 7
  price_month      DECIMAL(10,2) DEFAULT NULL,        -- NULL = price_day x 30
  security_deposit DECIMAL(10,2) NOT NULL DEFAULT 0,  -- เงินประกัน (คืนเมื่อคืนรถ)
  booking_deposit  DECIMAL(10,2) NOT NULL DEFAULT 0,  -- มัดจำยืนยันการจอง
  delivery_fee     DECIMAL(10,2) DEFAULT NULL,        -- NULL = ใช้ราคาจาก addons
  driver_fee_day   DECIMAL(10,2) DEFAULT NULL,
  status           ENUM('available','maintenance','inactive') NOT NULL DEFAULT 'available',
  mileage          INT UNSIGNED  NOT NULL DEFAULT 0,
  cover_image      VARCHAR(255)  DEFAULT NULL,
  description      TEXT          DEFAULT NULL,
  sort_order       SMALLINT      NOT NULL DEFAULT 0,
  deleted_at       DATETIME      DEFAULT NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cars_plate (plate),
  KEY idx_cars_status (status),
  KEY idx_cars_type (car_type_code),
  CONSTRAINT fk_cars_type FOREIGN KEY (car_type_code) REFERENCES car_types(code) ON UPDATE CASCADE,
  CONSTRAINT chk_cars_year   CHECK (year IS NULL OR year BETWEEN 1990 AND 2100),
  CONSTRAINT chk_cars_prices CHECK (price_day >= 0
                                    AND (price_week  IS NULL OR price_week  >= 0)
                                    AND (price_month IS NULL OR price_month >= 0)
                                    AND security_deposit >= 0 AND booking_deposit >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS car_images (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  car_id     INT UNSIGNED NOT NULL,
  path       VARCHAR(255) NOT NULL,
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_car_images_car (car_id, sort_order),
  CONSTRAINT fk_car_images_car FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS car_features (
  car_id  INT UNSIGNED NOT NULL,
  feature VARCHAR(80)  NOT NULL,
  PRIMARY KEY (car_id, feature),
  CONSTRAINT fk_car_features_car FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5) บริการเสริม (master)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS addons (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(30)   NOT NULL,
  name       VARCHAR(120)  NOT NULL,
  unit       ENUM('trip','day') NOT NULL DEFAULT 'trip',
  price      DECIMAL(10,2) NOT NULL DEFAULT 0,
  is_active  TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order SMALLINT      NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_addons_code (code),
  CONSTRAINT chk_addons_price CHECK (price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO addons (code, name, unit, price, sort_order) VALUES
  ('delivery', 'ค่าบริการส่งรถ', 'trip', 0, 10),
  ('driver',   'ค่าคนขับ',       'day',  0, 20);

-- ------------------------------------------------------------
-- 6) การจอง
--    สถานะ: pending -> confirmed -> picked_up -> returned -> completed
--           cancelled ได้ทุกช่วงก่อน returned
--    ยอดเงินเป็น snapshot ตอนจอง
--    ยอดที่ชำระแล้ว: ดู v_booking_payment (ไม่เก็บซ้ำที่นี่)
--    เลขไมล์/ขีดน้ำมัน: ดู inspections (kind = out / in)
--    extra_charge: ไม่รวมใน grand_total — เก็บเงินผ่าน payments.kind='extra'
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bookings (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_no       VARCHAR(24)   NOT NULL,            -- BK260919-001 (จาก sequences)
  customer_id      INT UNSIGNED  DEFAULT NULL,
  car_id           INT UNSIGNED  NOT NULL,
  contact_name     VARCHAR(180)  NOT NULL,            -- snapshot ผู้จอง
  contact_phone    VARCHAR(30)   NOT NULL,
  contact_email    VARCHAR(150)  DEFAULT NULL,
  contact_line     VARCHAR(80)   DEFAULT NULL,
  pickup_at        DATETIME      NOT NULL,
  return_at        DATETIME      NOT NULL,
  pickup_place     VARCHAR(255)  DEFAULT NULL,
  return_place     VARCHAR(255)  DEFAULT NULL,
  rental_days      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  rate_breakdown   JSON          DEFAULT NULL,        -- อธิบายการคิดราคา (อ่านอย่างเดียว ห้าม query ลึก)
  rental_amount    DECIMAL(10,2) NOT NULL DEFAULT 0,
  addon_amount     DECIMAL(10,2) NOT NULL DEFAULT 0,  -- รวมจาก booking_addons
  discount         DECIMAL(10,2) NOT NULL DEFAULT 0,
  subtotal         DECIMAL(10,2) NOT NULL DEFAULT 0,
  vat_rate         DECIMAL(5,2)  NOT NULL DEFAULT 0,  -- 0 หรือ 7
  vat_amount       DECIMAL(10,2) NOT NULL DEFAULT 0,
  grand_total      DECIMAL(10,2) NOT NULL DEFAULT 0,
  deposit_amount   DECIMAL(10,2) NOT NULL DEFAULT 0,  -- ยอดมัดจำที่ต้องโอน
  security_deposit DECIMAL(10,2) NOT NULL DEFAULT 0,  -- เงินประกันที่ต้องรับ
  status           ENUM('pending','confirmed','picked_up','returned','completed','cancelled')
                   NOT NULL DEFAULT 'pending',
  payment_status   ENUM('unpaid','deposit_paid','paid','refunded') NOT NULL DEFAULT 'unpaid',
  source           ENUM('web','admin','line','phone') NOT NULL DEFAULT 'web',
  picked_up_at     DATETIME      DEFAULT NULL,        -- เวลารับรถจริง
  returned_at      DATETIME      DEFAULT NULL,        -- เวลาคืนรถจริง (ใช้คิดค่าปรับคืนช้า)
  extra_charge     DECIMAL(10,2) NOT NULL DEFAULT 0,  -- ค่าปรับ/คืนช้า/น้ำมันขาด
  extra_note       TEXT          DEFAULT NULL,
  damage_note      TEXT          DEFAULT NULL,
  note             TEXT          DEFAULT NULL,
  cancel_reason    VARCHAR(255)  DEFAULT NULL,
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
  CONSTRAINT fk_bookings_car        FOREIGN KEY (car_id)      REFERENCES cars(id)      ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_customer   FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_bookings_created_by FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL,
  CONSTRAINT chk_bookings_range   CHECK (return_at > pickup_at),
  CONSTRAINT chk_bookings_days    CHECK (rental_days >= 1),
  CONSTRAINT chk_bookings_actual  CHECK (picked_up_at IS NULL OR returned_at IS NULL OR returned_at >= picked_up_at),
  CONSTRAINT chk_bookings_amounts CHECK (rental_amount >= 0 AND addon_amount >= 0 AND discount >= 0
                                         AND subtotal >= 0 AND vat_amount >= 0 AND grand_total >= 0
                                         AND deposit_amount >= 0
                                         AND security_deposit >= 0 AND extra_charge >= 0),
  CONSTRAINT chk_bookings_subtotal CHECK (subtotal    = rental_amount + addon_amount - discount),
  CONSTRAINT chk_bookings_grand    CHECK (grand_total = subtotal + vat_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_addons (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT UNSIGNED NOT NULL,
  addon_id   INT UNSIGNED DEFAULT NULL,
  name       VARCHAR(120)  NOT NULL,                  -- snapshot ชื่อ
  qty        DECIMAL(8,2)  NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,        -- snapshot ราคา
  amount     DECIMAL(10,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_ba_booking (booking_id),
  KEY idx_ba_addon (addon_id),
  CONSTRAINT fk_ba_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ba_addon   FOREIGN KEY (addon_id)   REFERENCES addons(id)   ON DELETE SET NULL,
  CONSTRAINT chk_ba_values CHECK (qty > 0 AND unit_price >= 0 AND amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7) การชำระเงิน / สลิป
--    amount เป็นบวกเสมอ — ทิศทางดูจาก kind
--      deposit/balance/extra = เงินเข้า, refund = เงินออก,
--      security = เงินประกันที่รับไว้ (ไม่นับเป็นรายได้)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id    INT UNSIGNED  NOT NULL,
  kind          ENUM('deposit','balance','security','extra','refund') NOT NULL DEFAULT 'deposit',
  method        ENUM('transfer','cash','card','promptpay') NOT NULL DEFAULT 'transfer',
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
-- 8) ใบเสนอราคา / ใบแจ้งหนี้ / ใบเสร็จ / สัญญา
--    bill_* และ vat/wht เป็น snapshot ตามวันที่ออกเอกสาร
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

CREATE TABLE IF NOT EXISTS document_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id INT UNSIGNED NOT NULL,
  line_no     SMALLINT UNSIGNED NOT NULL,
  description VARCHAR(255)  NOT NULL,
  qty         DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit        VARCHAR(20)   DEFAULT NULL,
  price       DECIMAL(10,2) NOT NULL DEFAULT 0,
  amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_doc_line (document_id, line_no),
  CONSTRAINT fk_di_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT chk_di_values CHECK (qty > 0 AND price >= 0 AND amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9) งานประจำรถ / ซ่อมบำรุง (แหล่งจริงของประกัน/พ.ร.บ./ภาษี/เข้าศูนย์)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS maintenances (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  car_id      INT UNSIGNED  NOT NULL,
  task_type   ENUM('service','insurance','act','tax','tyre','repair','other') NOT NULL DEFAULT 'service',
  title       VARCHAR(180)  NOT NULL,
  detail      VARCHAR(100)  DEFAULT NULL,
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
-- 10) บล็อกวันรถไม่ว่าง (ซ่อม / เจ้าของใช้เอง)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS car_blocks (
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
-- 11) ตรวจสภาพรถตอนส่งมอบ (out) / รับคืน (in)
--     แหล่งจริงของเลขไมล์และขีดน้ำมัน
--     กฎ "ไมล์คืน >= ไมล์รับ" เทียบข้ามแถว MySQL CHECK ทำไม่ได้ → ตรวจที่ ReturnService
--     locked_at ไม่ว่าง = ล็อกผลตรวจแล้ว ห้ามแก้
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspections (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id   INT UNSIGNED NOT NULL,
  kind         ENUM('out','in') NOT NULL,
  mileage      INT UNSIGNED DEFAULT NULL,
  fuel_level   TINYINT UNSIGNED DEFAULT NULL,        -- 0-8 ขีด
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

CREATE TABLE IF NOT EXISTS inspection_photos (
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
-- 12) ลายเซ็น (หลักฐานทางกฎหมาย — ห้ามลบ ใช้ void แทน)
--     snapshot = ข้อความสัญญา ณ ตอนเซ็น, snapshot_hash = SHA-256 ของ snapshot
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
  KEY idx_sig_booking (booking_id, kind, voided_at),
  KEY idx_sig_voided_by (voided_by),
  CONSTRAINT fk_sig_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sig_voider  FOREIGN KEY (voided_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 13) เอกสารลูกค้า (สำเนาบัตร / ใบขับขี่ / พาสปอร์ต)
--     ไฟล์เก็บนอก public_html · เก็บเลขแบบ mask เท่านั้น
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_documents (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id   INT UNSIGNED DEFAULT NULL,
  booking_id    INT UNSIGNED DEFAULT NULL,
  doc_kind      ENUM('id_card','driver_license','passport','other') NOT NULL DEFAULT 'id_card',
  file_path     VARCHAR(255) NOT NULL,
  mime          VARCHAR(60)  DEFAULT NULL,
  file_size     INT UNSIGNED DEFAULT NULL,
  number_masked VARCHAR(40)  DEFAULT NULL,           -- เช่น x-xxxx-xxxxx-45-6
  expires_on    DATE         DEFAULT NULL,
  note          VARCHAR(255) DEFAULT NULL,
  uploaded_by   INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cdoc_customer (customer_id),
  KEY idx_cdoc_booking (booking_id),
  KEY idx_cdoc_uploaded_by (uploaded_by),
  CONSTRAINT fk_cdoc_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_cdoc_booking  FOREIGN KEY (booking_id)  REFERENCES bookings(id)  ON DELETE SET NULL,
  CONSTRAINT fk_cdoc_user     FOREIGN KEY (uploaded_by) REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 14) ตั้งค่าระบบ (key/value)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  skey       VARCHAR(80)  NOT NULL,
  svalue     TEXT         DEFAULT NULL,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 15) Log การทำงาน (PDPA / ตรวจย้อนหลัง)
--     ref_table + ref_id เป็น polymorphic (ไม่มี FK โดยออกแบบ)
--     ควรมี job archive/ลบตามระยะเวลา (เช่น 1-2 ปี)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED DEFAULT NULL,
  action     VARCHAR(80)  NOT NULL,
  ref_table  VARCHAR(40)  DEFAULT NULL,
  ref_id     INT UNSIGNED DEFAULT NULL,
  detail     TEXT         DEFAULT NULL,                -- JSON {"before":{},"after":{}}
  ip         VARCHAR(45)  DEFAULT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_logs_ref (ref_table, ref_id),
  KEY idx_logs_created (created_at),
  KEY idx_logs_user (user_id),
  CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 16) คิวแจ้งเตือน (LINE / Email)
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
  KEY idx_notif_status (status),
  KEY idx_notif_ref (ref_table, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 17) log ข้อความที่เข้ามาจาก LINE webhook
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS line_events (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type   VARCHAR(20)  NOT NULL,
  line_user_id VARCHAR(60)  DEFAULT NULL,
  display_name VARCHAR(120) DEFAULT NULL,
  text         VARCHAR(500) DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_le_user (line_user_id),
  KEY idx_le_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 18) ตัวนับเลขที่เอกสาร (กันเลขซ้ำเมื่อมีหลาย request พร้อมกัน)
--     name   : 'booking' | 'customer' | 'quotation' | 'invoice' | 'receipt' | 'contract'
--     period : '260919' (รายวัน) | '2026' (รายปี) | '' (ไม่รีเซ็ต)
--     ต้องอยู่ใน transaction เดียวกับการ INSERT เอกสาร:
--       START TRANSACTION;
--       INSERT INTO sequences (name, period, last_no) VALUES ('booking','260919',1)
--         ON DUPLICATE KEY UPDATE last_no = last_no + 1;
--       SELECT last_no FROM sequences WHERE name='booking' AND period='260919';
--       -- ประกอบเป็น BK260919-001 แล้ว INSERT bookings
--       COMMIT;
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sequences (
  name    VARCHAR(30)  NOT NULL,
  period  VARCHAR(10)  NOT NULL DEFAULT '',
  last_no INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (name, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- VIEWS
-- ============================================================

-- วันหมดอายุ/กำหนดเข้าศูนย์ต่อคัน (จาก maintenances ที่ยัง pending)
CREATE OR REPLACE VIEW v_car_compliance AS
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

-- สรุปการชำระเงินต่อการจอง (นับเฉพาะ approved) — แหล่งจริงของ "ยอดที่ชำระแล้ว"
CREATE OR REPLACE VIEW v_booking_payment AS
SELECT
  b.id AS booking_id,
  b.booking_no,
  b.grand_total,
  COALESCE(SUM(CASE WHEN p.status='approved' AND p.kind IN ('deposit','balance','extra')
                    THEN p.amount END), 0) AS paid_total,
  COALESCE(SUM(CASE WHEN p.status='approved' AND p.kind = 'security'
                    THEN p.amount END), 0) AS security_held,
  COALESCE(SUM(CASE WHEN p.status='approved' AND p.kind = 'refund'
                    THEN p.amount END), 0) AS refunded_total,
  b.grand_total - COALESCE(SUM(CASE WHEN p.status='approved' AND p.kind IN ('deposit','balance','extra')
                                    THEN p.amount END), 0) AS balance_due
FROM bookings b
LEFT JOIN payments p ON p.booking_id = b.id
GROUP BY b.id, b.booking_no, b.grand_total;

-- ============================================================
-- กันจองซ้อนทับ (MySQL ไม่มี exclusion constraint)
-- ต้องทำใน transaction เดียวกันทุกครั้งที่สร้าง/แก้ booking
-- และทุกช่องทาง (เว็บ / แอดมิน / LINE) ต้องผ่าน service ตัวเดียว:
--
--   START TRANSACTION;
--   SELECT id FROM cars WHERE id = :car_id FOR UPDATE;
--   SELECT
--     (SELECT COUNT(*) FROM bookings
--       WHERE car_id = :car_id
--         AND status IN ('pending','confirmed','picked_up')
--         AND pickup_at < :return_at AND return_at > :pickup_at)
--   + (SELECT COUNT(*) FROM car_blocks
--       WHERE car_id = :car_id
--         AND start_at < :return_at AND end_at > :pickup_at) AS conflicts;
--   -- conflicts > 0 => ROLLBACK  /  = 0 => INSERT bookings; COMMIT;
-- ============================================================
