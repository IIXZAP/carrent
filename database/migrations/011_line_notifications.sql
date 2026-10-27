-- แจ้งเตือนร้านทาง LINE (ขั้น 7): คิวที่ส่งซ้ำได้อย่างปลอดภัย + ค่าตั้งต้นของหน้า "LINE" ในหลังบ้าน
-- sending = มี process กำลังส่งแถวนี้ (cron คืนเป็น queued ถ้าค้างเกิน 10 นาที) / dedupe_key กันแจ้งเหตุการณ์เดียวกันซ้ำ
-- retry_key = UUID ที่ส่งไปกับ LINE (X-Line-Retry-Key) ถ้าส่งซ้ำ LINE จะไม่ส่งข้อความเบิ้ลเมื่อรอบแรกถึงแล้ว

ALTER TABLE notifications
  MODIFY COLUMN status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  ADD COLUMN dedupe_key  VARCHAR(100) DEFAULT NULL AFTER ref_id,
  ADD COLUMN retry_key   CHAR(36)     DEFAULT NULL AFTER dedupe_key,
  ADD COLUMN attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN next_try_at DATETIME     DEFAULT NULL AFTER attempts,
  ADD COLUMN claimed_at  DATETIME     DEFAULT NULL AFTER next_try_at,
  ADD UNIQUE KEY uq_notif_dedupe (dedupe_key),
  ADD KEY idx_notif_due (status, next_try_at);

INSERT IGNORE INTO settings (skey, svalue) VALUES
  ('notify_line_enabled', '0'),
  ('line_channel_token',  ''),
  ('line_admin_user_id',  ''),
  ('notify_new_booking',  '1'),
  ('notify_new_slip',     '1'),
  ('notify_signed',       '1');
