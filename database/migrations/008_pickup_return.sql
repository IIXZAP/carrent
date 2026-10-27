-- ส่งมอบ–รับคืนรถ: รายละเอียดค่าใช้จ่ายเพิ่มตอนรับคืน (คืนช้า/น้ำมัน/ไมล์/อื่นๆ) เก็บทั้ง "ยอดแนะนำ" และ "ยอดที่คิดจริง"
-- เพื่อตรวจย้อนหลังได้ว่าพนักงานยกเว้น/แก้ยอดรายการไหน
ALTER TABLE bookings ADD COLUMN extra_breakdown JSON DEFAULT NULL AFTER extra_note;

-- กติกาค่าปรับตอนรับคืน (แก้ได้ที่ หลังบ้าน → ตั้งค่าร้าน)
--   คืนช้า: ผ่อนผัน late_grace_hours ชม. · เกินแล้วคิดชั่วโมงละ late_fee_per_hour · เกิน late_full_day_hours ชม. คิดเป็นค่าเช่า 1 วันเต็ม
--   น้ำมันขาด: ขีดละ fuel_fee_per_level · ระยะทางเกิน: เกิน (free_km_per_day × จำนวนวัน) กม. ละ over_km_fee (free_km_per_day = 0 = ไม่จำกัดระยะ)
INSERT IGNORE INTO settings (skey, svalue) VALUES
  ('late_full_day_hours', '6'),
  ('late_fee_per_hour',   '200'),
  ('fuel_fee_per_level',  '300'),
  ('free_km_per_day',     '0'),
  ('over_km_fee',         '4');
