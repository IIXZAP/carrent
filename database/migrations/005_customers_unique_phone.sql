-- เบอร์โทรของลูกค้าที่ยังใช้งานอยู่ต้องไม่ซ้ำ (กันสร้างลูกค้าซ้ำเมื่อจองพร้อมกันด้วยเบอร์ใหม่เดียวกัน)
-- ลูกค้าที่ถูกลบ (deleted_at ไม่ว่าง) ได้ phone_active = NULL จึงไม่ขวางการใช้เบอร์เดิมอีกครั้ง
-- ถ้ารัน migration นี้กับข้อมูลที่มีเบอร์ซ้ำอยู่ จะล้ม: ต้องรวมแถวลูกค้าซ้ำก่อน
--   ตรวจ: SELECT phone, COUNT(*) FROM customers WHERE deleted_at IS NULL GROUP BY phone HAVING COUNT(*) > 1
ALTER TABLE customers
  ADD COLUMN phone_active VARCHAR(30) AS (IF(deleted_at IS NULL, phone, NULL)) VIRTUAL,
  ADD UNIQUE KEY uq_customers_phone_active (phone_active);
