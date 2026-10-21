-- ============================================================
--  thonglorcarrent — อัปเดตฐานข้อมูลเดิมให้เป็นเวอร์ชัน 2
--
--  ใช้เมื่อ "ติดตั้งไปแล้ว" และมีข้อมูลการจองอยู่
--  → ไม่ลบข้อมูลการจอง ลูกค้า หรือการชำระเงินใดๆ  และรันซ้ำได้ไม่เสียหาย
--
--  วิธีใช้: cPanel → phpMyAdmin → เลือกฐานข้อมูล → แท็บ Import → เลือกไฟล์นี้
--
--  หมายเหตุ: ไฟล์นี้เขียนให้ใช้ได้ทั้ง MySQL 5.7 / MySQL 8 และ MariaDB
--  (ไม่ใช้ "ADD COLUMN IF NOT EXISTS" ซึ่ง MySQL ไม่รองรับ)
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) เพิ่มคอลัมน์ segment (ป้ายกำกับประเภทรถ) — เพิ่มให้เฉพาะเมื่อยังไม่มี
-- ------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars' AND COLUMN_NAME = 'segment'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE cars ADD COLUMN segment VARCHAR(60) DEFAULT NULL AFTER car_type',
  'DO 0'
);
PREPARE s FROM @ddl;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 2) อัปเดตข้อมูลรถ 8 คัน — รูปภาพ ราคา ที่นั่ง ป้ายกำกับ
--    จับคู่ด้วย "ยี่ห้อ + รุ่น" จึงใช้ได้แม้ทะเบียนถูกแก้ไปแล้ว
-- ------------------------------------------------------------
UPDATE cars SET
  segment = 'รถเก๋ง (Eco Car)', seats = 5, gear = 'auto', fuel = 'เบนซิน', car_type = 'eco',
  price_day = 1200, price_week = 7500, price_month = 26000,
  cover_image = 'cars/yaris-ativ.webp',
  features = '["Apple CarPlay","กล้องหลัง","Cruise Control"]',
  description = 'อีโคคาร์ประหยัดน้ำมัน คล่องตัวในเมือง จอดง่าย เหมาะเดินทางคนเดียวหรือคู่'
WHERE brand = 'Toyota' AND model LIKE 'Yaris%';

UPDATE cars SET
  segment = 'รถเก๋ง (B-Segment)', seats = 5, gear = 'auto', fuel = 'เบนซิน', car_type = 'eco',
  price_day = 1300, price_week = 8100, price_month = 28000,
  cover_image = 'cars/honda-city.webp',
  features = '["Apple CarPlay","กล้องรอบคัน","Honda Sensing"]',
  description = 'ซิตี้คาร์ยอดนิยม ห้องโดยสารกว้างกว่าคู่แข่งในคลาส ประหยัดน้ำมัน'
WHERE brand = 'Honda' AND model = 'City';

UPDATE cars SET
  segment = 'รถเก๋ง (C-Segment)', seats = 5, gear = 'auto', fuel = 'เบนซิน', car_type = 'sedan',
  price_day = 1500, price_week = 9400, price_month = 32000,
  cover_image = 'cars/corolla-altis.webp',
  features = '["เบาะหนัง","Toyota Safety Sense","จอสัมผัส 9 นิ้ว"]',
  description = 'ซีดานระดับพรีเมียม นั่งสบาย เก็บเสียงดี เหมาะรับรองลูกค้าและเดินทางไกล'
WHERE brand = 'Toyota' AND model LIKE '%Altis%';

UPDATE cars SET
  segment = 'รถเก๋ง (C-Segment)', seats = 5, gear = 'auto', fuel = 'เบนซิน', car_type = 'sedan',
  price_day = 1600, price_week = 10000, price_month = 34000,
  cover_image = 'cars/honda-civic.webp',
  features = '["เบาะหนัง","ซันรูฟ","Honda Sensing"]',
  description = 'สปอร์ตซีดาน ขับสนุก ช่วงล่างหนึบ ภายในหรู เหมาะขับทางไกล'
WHERE brand = 'Honda' AND model = 'Civic';

UPDATE cars SET
  segment = 'รถ SUV (PPV)', seats = 7, gear = 'auto', fuel = 'ดีเซล', car_type = 'suv',
  price_day = 2200, price_week = 13800, price_month = 47000,
  cover_image = 'cars/fortuner.webp',
  features = '["7 ที่นั่ง","ขับเคลื่อน 4 ล้อ","เบาะหนัง"]',
  description = 'SUV 7 ที่นั่ง สูงโปร่ง ลุยได้ทุกเส้นทาง เหมาะเดินทางครอบครัวและต่างจังหวัด'
WHERE brand = 'Toyota' AND model = 'Fortuner';

UPDATE cars SET
  segment = 'รถ SUV (PPV)', seats = 7, gear = 'auto', fuel = 'ดีเซล', car_type = 'suv',
  price_day = 2200, price_week = 13800, price_month = 47000,
  cover_image = 'cars/pajero-sport.webp',
  features = '["7 ที่นั่ง","กล้องรอบคัน","เบาะหนัง"]',
  description = 'SUV สมรรถนะสูง นั่งสบาย ช่วงล่างนุ่ม เหมาะเดินทางไกลเป็นหมู่คณะ'
WHERE brand = 'Mitsubishi' AND model LIKE 'Pajero%';

UPDATE cars SET
  segment = 'รถกระบะ (4 ประตู)', seats = 5, gear = 'auto', fuel = 'ดีเซล', car_type = 'pickup',
  price_day = 1800, price_week = 11300, price_month = 38000,
  cover_image = 'cars/dmax-cab4.webp',
  features = '["แค็บ 4 ประตู","กระบะบรรทุก","ประหยัดน้ำมัน"]',
  description = 'กระบะ 4 ประตู บรรทุกของได้เยอะ ใช้งานได้ทั้งงานและเดินทางส่วนตัว'
WHERE brand = 'Isuzu' AND model LIKE 'D-Max%';

UPDATE cars SET
  segment = 'รถตู้ (Van)', seats = 14, gear = 'auto', fuel = 'ดีเซล', car_type = 'van',
  price_day = 2500, price_week = 15600, price_month = 53000,
  cover_image = 'cars/commuter.webp',
  features = '["14 ที่นั่ง","แอร์หลัง","ประตูสไลด์"]',
  description = 'รถตู้ 14 ที่นั่ง เหมาะรับส่งพนักงาน สัมมนา ทัวร์ และเดินทางเป็นกลุ่มใหญ่'
WHERE brand = 'Toyota' AND model = 'Commuter';

-- ------------------------------------------------------------
-- 3) แบรนด์ + สีใหม่ตามโลโก้ thonglorcarrent
-- ------------------------------------------------------------
INSERT INTO settings (skey, svalue) VALUES
('site_name',      'thonglorcarrent'),
('site_tagline',   'Drive a Better Bangkok'),
('brand_primary',  '#1f2d3d'),
('brand_accent',   '#e09a3c'),
('brand_dark',     '#16212e'),
('smtp_from_name', 'thonglorcarrent')
ON DUPLICATE KEY UPDATE svalue = VALUES(svalue);

-- ============================================================
--  ตรวจผล — ควรได้รถ 8 คัน และคอลัมน์ "สถานะรูป" ต้องเป็น ✅ ทุกแถว
-- ============================================================
SELECT
  id,
  CONCAT(brand, ' ', model)  AS 'รถ',
  segment                    AS 'ประเภท',
  seats                      AS 'ที่นั่ง',
  price_day                  AS 'ราคา/วัน',
  cover_image                AS 'ไฟล์รูป',
  CASE WHEN cover_image IS NULL OR cover_image = ''
       THEN 'ยังไม่มีรูป' ELSE 'มีรูปแล้ว' END AS 'สถานะรูป'
FROM cars
ORDER BY sort_order, id;
