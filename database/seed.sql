-- ============================================================
-- thonglorcarrent — ข้อมูลตั้งต้น (รันหลัง schema.sql)
-- ผู้ใช้เริ่มต้น: admin@thonglorcarrent.com / Thonglor@2026
-- *** เปลี่ยนรหัสผ่านทันทีหลังติดตั้ง ***
-- ============================================================
SET NAMES utf8mb4;

INSERT INTO users (name, email, password_hash, role, phone, is_active) VALUES
('ผู้ดูแลระบบ', 'admin@thonglorcarrent.com',
 '$2y$12$/sBgENIC.cPZzLAF4GlfJu1OQCIr6dzTrLBbnMyRe9mUcat7QueYW',
 'admin', '088-949-2824', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ------------------------------------------------------------
-- ตั้งค่าระบบ (แก้ภายหลังได้ที่ หลังบ้าน → ตั้งค่า / เชื่อมต่อ LINE)
-- ------------------------------------------------------------
INSERT INTO settings (skey, svalue) VALUES
  ('airport_close_time','24:00'),
  ('airport_open_time','05:00'),
  ('backup_keep_days','90'),
  ('backup_last_at',''),
  ('bank_account_name','บริษัท ทองหล่อ คาร์เร้นท์ จำกัด'),
  ('bank_account_no','xxx-x-xxxxx-x'),
  ('bank_name','ธนาคารกสิกรไทย'),
  ('booking_deposit','1000'),
  ('booking_gap_hours','4'),
  ('booking_lead_hours','3'),
  ('brand_accent','#e09a3c'),
  ('brand_dark','#16212e'),
  ('brand_primary','#1f2d3d'),
  ('close_time','20:00'),
  ('company_address',''),
  ('company_name','บริษัท ทองหล่อ คาร์เร้นท์ จำกัด'),
  ('company_tax_id',''),
  ('cron_key','CHANGE-THIS-CRON-KEY'),
  ('cron_last_at',''),
  ('currency','THB'),
  ('delivery_tiers','30:0,50:500,80:1000,120:1500,200:2500'),
  ('deposit_percent','20'),
  ('doc_retention_days','0'),
  ('email','admin@thonglorcarrent.com'),
  ('facebook','บริษัท ทองหล่อ คาร์เร้นท์ จำกัด'),
  ('free_delivery_km','30'),
  ('free_km_per_day','0'),
  ('fuel_fee_per_level','300'),
  ('late_fee_per_hour','200'),
  ('late_full_day_hours','6'),
  ('late_grace_hours','2'),
  ('line_admin_user_id',''),
  ('line_channel_secret',''),
  ('line_channel_token',''),
  ('line_id','@606jyaeh'),
  ('line_liff_id',''),
  ('line_notify_customer','1'),
  ('line_richmenu_id',''),
  ('min_rental_days','1'),
  ('notify_admin_email','admin@thonglorcarrent.com'),
  ('notify_email_enabled','0'),
  ('notify_line_enabled','0'),
  ('office_phone','02-460-0100'),
  ('open_time','08:00'),
  ('over_km_fee','4'),
  ('pdpa_policy_text','ข้าพเจ้ายินยอมให้บริษัทเก็บรวบรวม ใช้ และเปิดเผยข้อมูลส่วนบุคคลของข้าพเจ้า รวมถึงสำเนาบัตรประจำตัวประชาชนและใบอนุญาตขับขี่ เพื่อวัตถุประสงค์ในการทำสัญญาเช่ารถ การยืนยันตัวตน การเรียกร้องค่าเสียหาย และการปฏิบัติตามกฎหมายที่เกี่ยวข้อง'),
  ('pending_expire_hours','0'),
  ('phone','088-949-2824'),
  ('phone2','084-294-6694'),
  ('promptpay_id','0889492824'),
  ('reminder_days_before','30'),
  ('security_deposit_default','3000'),
  ('site_name','thonglorcarrent'),
  ('site_tagline','Drive a Better Bangkok'),
  ('site_url',''),
  ('smtp_from_name','thonglorcarrent'),
  ('smtp_host',''),
  ('smtp_pass',''),
  ('smtp_port','587'),
  ('smtp_secure','tls'),
  ('smtp_user',''),
  ('vat_enabled','0'),
  ('vat_rate','7'),
  ('wht_rate','5')
ON DUPLICATE KEY UPDATE svalue = svalue;

-- รหัสลับของหน้าตรวจสุขภาพระบบ — สุ่มให้ครั้งแรกครั้งเดียว
INSERT INTO settings (skey, svalue)
SELECT 'health_key', MD5(CONCAT(RAND(), NOW(), DATABASE()))
ON DUPLICATE KEY UPDATE svalue = svalue;

-- ------------------------------------------------------------
-- รถตัวอย่าง 8 คัน
-- ------------------------------------------------------------
INSERT INTO cars
(plate, province, brand, model, year, color, car_type, segment, seats, gear, fuel,
 price_day, price_week, price_month, deposit, deposit_booking,
 delivery_fee, driver_fee_day, status, mileage,
 insurance_type,
 cover_image, features, description, sort_order)
VALUES
('1กก 1001','กรุงเทพมหานคร','Toyota','Yaris Ativ',2023,'ขาว','eco','รถเก๋ง (Eco Car)',5,'auto','เบนซิน',
 1200, 7500, 26000, 3000, 1000, 300, 1200, 'available', 32000,
 'ชั้น 1',
 'cars/yaris-ativ.webp',
 '["Apple CarPlay","กล้องหลัง","Cruise Control"]',
 'อีโคคาร์ประหยัดน้ำมัน คล่องตัวในเมือง จอดง่าย เหมาะเดินทางคนเดียวหรือคู่',1),

('1กก 1002','กรุงเทพมหานคร','Honda','City',2023,'เทา','eco','รถเก๋ง (B-Segment)',5,'auto','เบนซิน',
 1300, 8100, 28000, 3000, 1000, 300, 1200, 'available', 28500,
 'ชั้น 1',
 'cars/honda-city.webp',
 '["Apple CarPlay","กล้องรอบคัน","Honda Sensing"]',
 'ซิตี้คาร์ยอดนิยม ห้องโดยสารกว้างกว่าคู่แข่งในคลาส ประหยัดน้ำมัน',2),

('2ขข 2001','กรุงเทพมหานคร','Toyota','Corolla Altis',2023,'ขาว','sedan','รถเก๋ง (C-Segment)',5,'auto','เบนซิน',
 1500, 9400, 32000, 5000, 1500, 300, 1500, 'available', 45000,
 'ชั้น 1',
 'cars/corolla-altis.webp',
 '["เบาะหนัง","Toyota Safety Sense","จอสัมผัส 9 นิ้ว"]',
 'ซีดานระดับพรีเมียม นั่งสบาย เก็บเสียงดี เหมาะรับรองลูกค้าและเดินทางไกล',3),

('2ขข 2002','กรุงเทพมหานคร','Honda','Civic',2023,'เทา','sedan','รถเก๋ง (C-Segment)',5,'auto','เบนซิน',
 1600, 10000, 34000, 5000, 1500, 300, 1500, 'available', 21000,
 'ชั้น 1',
 'cars/honda-civic.webp',
 '["เบาะหนัง","ซันรูฟ","Honda Sensing"]',
 'สปอร์ตซีดาน ขับสนุก ช่วงล่างหนึบ ภายในหรู เหมาะขับทางไกล',4),

('3คค 3001','กรุงเทพมหานคร','Toyota','Fortuner',2023,'ขาว','suv','รถ SUV (PPV)',7,'auto','ดีเซล',
 2200, 13800, 47000, 8000, 2500, 500, 1800, 'available', 62000,
 'ชั้น 1',
 'cars/fortuner.webp',
 '["7 ที่นั่ง","ขับเคลื่อน 4 ล้อ","เบาะหนัง"]',
 'SUV 7 ที่นั่ง สูงโปร่ง ลุยได้ทุกเส้นทาง เหมาะเดินทางครอบครัวและต่างจังหวัด',5),

('3คค 3002','กรุงเทพมหานคร','Mitsubishi','Pajero Sport',2023,'ดำ','suv','รถ SUV (PPV)',7,'auto','ดีเซล',
 2200, 13800, 47000, 8000, 2500, 500, 1800, 'available', 58000,
 'ชั้น 1',
 'cars/pajero-sport.webp',
 '["7 ที่นั่ง","กล้องรอบคัน","เบาะหนัง"]',
 'SUV สมรรถนะสูง นั่งสบาย ช่วงล่างนุ่ม เหมาะเดินทางไกลเป็นหมู่คณะ',6),

('4งง 4001','กรุงเทพมหานคร','Isuzu','D-Max Cab 4',2023,'เงิน','pickup','รถกระบะ (4 ประตู)',5,'auto','ดีเซล',
 1800, 11300, 38000, 5000, 1500, 400, 1500, 'available', 40000,
 'ชั้น 1',
 'cars/dmax-cab4.webp',
 '["แค็บ 4 ประตู","กระบะบรรทุก","ประหยัดน้ำมัน"]',
 'กระบะ 4 ประตู บรรทุกของได้เยอะ ใช้งานได้ทั้งงานและเดินทางส่วนตัว',7),

('5จจ 5001','กรุงเทพมหานคร','Toyota','Commuter',2023,'ขาว','van','รถตู้ (Van)',14,'auto','ดีเซล',
 2500, 15600, 53000, 10000, 3000, 600, 2000, 'available', 120000,
 'ชั้น 1',
 'cars/commuter.webp',
 '["14 ที่นั่ง","แอร์หลัง","ประตูสไลด์"]',
 'รถตู้ 14 ที่นั่ง เหมาะรับส่งพนักงาน สัมมนา ทัวร์ และเดินทางเป็นกลุ่มใหญ่',8)
ON DUPLICATE KEY UPDATE
  brand = VALUES(brand), model = VALUES(model), segment = VALUES(segment),
  year = VALUES(year), color = VALUES(color), car_type = VALUES(car_type),
  seats = VALUES(seats), gear = VALUES(gear), fuel = VALUES(fuel),
  price_day = VALUES(price_day), price_week = VALUES(price_week), price_month = VALUES(price_month),
  deposit = VALUES(deposit), deposit_booking = VALUES(deposit_booking),
  cover_image = VALUES(cover_image), features = VALUES(features), description = VALUES(description);

-- งานประจำรถตัวอย่าง (ประกัน / พ.ร.บ. / ภาษี) — ระบบเตือนล่วงหน้าอัตโนมัติ
-- วันหมดอายุของรถอยู่ที่ตาราง maintenances ที่เดียว (แก้ได้ที่ หลังบ้าน → จัดการรถ)
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'insurance', CONCAT('ต่อประกัน ', brand, ' ', model, ' (', plate, ')'), '2027-03-31', 'pending' FROM cars WHERE plate = '1กก 1001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'act', CONCAT('ต่อ พ.ร.บ. ', plate), '2027-03-31', 'pending' FROM cars WHERE plate = '1กก 1001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'tax', CONCAT('ต่อภาษีประจำปี ', plate), '2027-03-31', 'pending' FROM cars WHERE plate = '1กก 1001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'insurance', CONCAT('ต่อประกัน ', brand, ' ', model, ' (', plate, ')'), '2027-05-31', 'pending' FROM cars WHERE plate = '1กก 1002';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'act', CONCAT('ต่อ พ.ร.บ. ', plate), '2027-05-31', 'pending' FROM cars WHERE plate = '1กก 1002';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'tax', CONCAT('ต่อภาษีประจำปี ', plate), '2027-05-31', 'pending' FROM cars WHERE plate = '1กก 1002';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'insurance', CONCAT('ต่อประกัน ', brand, ' ', model, ' (', plate, ')'), '2027-01-31', 'pending' FROM cars WHERE plate = '2ขข 2001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'act', CONCAT('ต่อ พ.ร.บ. ', plate), '2027-01-31', 'pending' FROM cars WHERE plate = '2ขข 2001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'tax', CONCAT('ต่อภาษีประจำปี ', plate), '2027-01-31', 'pending' FROM cars WHERE plate = '2ขข 2001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'insurance', CONCAT('ต่อประกัน ', brand, ' ', model, ' (', plate, ')'), '2027-08-31', 'pending' FROM cars WHERE plate = '2ขข 2002';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'act', CONCAT('ต่อ พ.ร.บ. ', plate), '2027-08-31', 'pending' FROM cars WHERE plate = '2ขข 2002';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'tax', CONCAT('ต่อภาษีประจำปี ', plate), '2027-08-31', 'pending' FROM cars WHERE plate = '2ขข 2002';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'insurance', CONCAT('ต่อประกัน ', brand, ' ', model, ' (', plate, ')'), '2027-02-28', 'pending' FROM cars WHERE plate = '3คค 3001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'act', CONCAT('ต่อ พ.ร.บ. ', plate), '2027-02-28', 'pending' FROM cars WHERE plate = '3คค 3001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'tax', CONCAT('ต่อภาษีประจำปี ', plate), '2027-02-28', 'pending' FROM cars WHERE plate = '3คค 3001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'insurance', CONCAT('ต่อประกัน ', brand, ' ', model, ' (', plate, ')'), '2027-04-30', 'pending' FROM cars WHERE plate = '3คค 3002';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'act', CONCAT('ต่อ พ.ร.บ. ', plate), '2027-04-30', 'pending' FROM cars WHERE plate = '3คค 3002';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'tax', CONCAT('ต่อภาษีประจำปี ', plate), '2027-04-30', 'pending' FROM cars WHERE plate = '3คค 3002';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'insurance', CONCAT('ต่อประกัน ', brand, ' ', model, ' (', plate, ')'), '2027-06-30', 'pending' FROM cars WHERE plate = '4งง 4001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'act', CONCAT('ต่อ พ.ร.บ. ', plate), '2027-06-30', 'pending' FROM cars WHERE plate = '4งง 4001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'tax', CONCAT('ต่อภาษีประจำปี ', plate), '2027-06-30', 'pending' FROM cars WHERE plate = '4งง 4001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'insurance', CONCAT('ต่อประกัน ', brand, ' ', model, ' (', plate, ')'), '2027-07-31', 'pending' FROM cars WHERE plate = '5จจ 5001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'act', CONCAT('ต่อ พ.ร.บ. ', plate), '2027-07-31', 'pending' FROM cars WHERE plate = '5จจ 5001';
INSERT INTO maintenances (car_id, task_type, title, due_date, status)
SELECT id, 'tax', CONCAT('ต่อภาษีประจำปี ', plate), '2027-07-31', 'pending' FROM cars WHERE plate = '5จจ 5001';
