-- เงินประกัน: เพิ่มชนิดรายการ "คืนเงินประกัน" และวิธี "หักจากเงินประกัน" (ชำระยอดค้างด้วยเงินประกัน)
-- (kind = refund ใช้เฉพาะ "คืนค่าเช่า" — เงินประกันแยกออกมาเพื่อไม่ปนกับรายได้)
ALTER TABLE payments
  MODIFY kind   ENUM('deposit','balance','security','security_refund','extra','refund') NOT NULL DEFAULT 'deposit',
  MODIFY method ENUM('transfer','cash','card','promptpay','deduct') NOT NULL DEFAULT 'transfer';

-- สรุปการชำระเงินต่อการจอง (นับเฉพาะ approved) — สูตรเดียวกับ Payment::netPaid / securityHeld ในโค้ด
--   รายได้ = deposit/balance/extra (รวมที่หักจากเงินประกัน) − refund ; เงินประกันไม่ใช่รายได้
--   เงินประกันที่ร้านถือ = security − security_refund − รายการที่ method = deduct
CREATE OR REPLACE VIEW v_booking_payment AS
SELECT
  b.id AS booking_id,
  b.booking_no,
  b.grand_total,
  COALESCE(SUM(CASE WHEN p.status = 'approved' THEN
                      CASE WHEN p.kind = 'refund' THEN -p.amount
                           WHEN p.kind IN ('security', 'security_refund') THEN 0
                           ELSE p.amount END
                    END), 0) AS paid_total,
  COALESCE(SUM(CASE WHEN p.status = 'approved' THEN
                      CASE WHEN p.kind = 'security' THEN p.amount
                           WHEN p.kind = 'security_refund' THEN -p.amount
                           WHEN p.method = 'deduct' THEN -p.amount
                           ELSE 0 END
                    END), 0) AS security_held,
  COALESCE(SUM(CASE WHEN p.status = 'approved' AND p.kind = 'refund' THEN p.amount END), 0) AS refunded_total,
  b.grand_total + b.extra_charge - COALESCE(SUM(CASE WHEN p.status = 'approved' THEN
                      CASE WHEN p.kind = 'refund' THEN -p.amount
                           WHEN p.kind IN ('security', 'security_refund') THEN 0
                           ELSE p.amount END
                    END), 0) AS balance_due
FROM bookings b
LEFT JOIN payments p ON p.booking_id = b.id
GROUP BY b.id, b.booking_no, b.grand_total, b.extra_charge;

-- ข้อมูลติดต่อร้าน / บัญชีรับโอน (แก้ได้ที่ หลังบ้าน → ตั้งค่าร้าน) — เว้นว่าง = ไม่แสดง
INSERT IGNORE INTO settings (skey, svalue) VALUES
  ('phone',             ''),
  ('bank_name',         ''),
  ('bank_account_no',   ''),
  ('bank_account_name', ''),
  ('promptpay',         '');
