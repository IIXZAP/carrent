-- ข้อมูลตั้งต้นของกฎราคา/การจอง (แก้ค่าได้ภายหลังที่ตาราง settings / addons)
-- ราคาค่าส่งรถคิดตามระยะทางจาก settings.delivery_tiers  รูปแบบ "ถึงกม.:ราคา" คั่นด้วย ,
INSERT IGNORE INTO addons (code, name, unit, price, sort_order) VALUES
  ('return_delivery', 'รับรถคืนถึงที่',                    'trip', 0,   15),
  ('child_seat',      'คาร์ซีทเด็ก',                       'day',  150, 30),
  ('insurance_plus',  'ประกันเสริม ลดค่าเสียหายส่วนแรก',   'day',  300, 40),
  ('gps',             'GPS / Pocket WiFi',                  'day',  100, 50);

INSERT IGNORE INTO settings (skey, svalue) VALUES
  ('open_time',                '08:00'),
  ('close_time',               '20:00'),
  ('airport_open_time',        '05:00'),
  ('airport_close_time',       '24:00'),
  ('booking_lead_hours',       '3'),
  ('booking_gap_hours',        '4'),
  ('min_rental_days',          '1'),
  ('booking_deposit',          '1000'),
  ('deposit_percent',          '20'),
  ('security_deposit_default', '3000'),
  ('vat_enabled',              '0'),
  ('vat_rate',                 '7'),
  ('free_delivery_km',         '30'),
  ('delivery_tiers',           '30:0,50:500,80:1000,120:1500,200:2500');
