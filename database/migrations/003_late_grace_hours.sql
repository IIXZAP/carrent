-- ผ่อนผันคืนช้า (ชั่วโมง): ใช้ทั้งตอนนับจำนวนวันเช่า และตอนคิดค่าปรับคืนช้า ต้องเป็นค่าเดียวกัน
INSERT IGNORE INTO settings (skey, svalue) VALUES ('late_grace_hours', '2');
