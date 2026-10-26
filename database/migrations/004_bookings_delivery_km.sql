-- ระยะทางส่ง–รับรถ (กม.) ที่ใช้คิดค่าส่งตอนจอง เก็บไว้เพื่อให้คิดราคาซ้ำได้ตรงเดิม
ALTER TABLE bookings
  ADD COLUMN delivery_km DECIMAL(6,1) DEFAULT NULL AFTER return_place,
  ADD CONSTRAINT chk_bookings_delivery_km CHECK (delivery_km IS NULL OR delivery_km >= 0);
