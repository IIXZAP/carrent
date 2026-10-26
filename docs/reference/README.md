# เอกสารอ้างอิงโค้ด — อธิบายทุกคลาสและทุกเมธอด

แต่ละไฟล์อธิบายว่าเมธอดทำอะไรทีละขั้น รับ/คืนอะไร ใครเรียกใช้ และเหตุผลด้านความปลอดภัย
ถ้ายังไม่เคยอ่านโค้ดนี้ ให้เริ่มจาก `../CODE_MAP.md` (ภาพรวมระดับไฟล์) ก่อน

| ไฟล์ | ครอบคลุม |
|---|---|
| [01-core.md](01-core.md) | `index.php`, `bootstrap.php`, `config.php`, `cron/daily.php`, `app/Core/*` (Router, Request, Response, View, Database, Session, Csrf ...) |
| [02-models-middleware.md](02-models-middleware.md) | Models ทุกตัว (หนึ่งไฟล์ ≈ หนึ่งตาราง) และ Middleware |
| [03-services-booking-pricing.md](03-services-booking-pricing.md) | การจอง คิดราคา ค่ารับ–ส่ง ค่าปรับคืนช้า การชำระเงิน เช่าต่อ รับคืน เงินประกัน |
| [04-services-system.md](04-services-system.md) | LINE, แจ้งเตือน, ลายเซ็น, ตรวจสภาพรถ, ล็อกอิน, อัปโหลด, ไฟล์ปิด, สำรองข้อมูล, สุขภาพระบบ, cron, รายงานรายเดือน |
| [05-controllers-public-helpers-routes.md](05-controllers-public-helpers-routes.md) | ตาราง URL ทั้งหมด, ฟังก์ชันช่วย (`e()`, `url()`, `money()` ...), Controller ฝั่งลูกค้า/API/LINE |
| [06-controllers-admin.md](06-controllers-admin.md) | Controller หลังบ้านทั้งหมด |

หมายเหตุ: เอกสารเหล่านี้เขียนจากการอ่านโค้ด ณ วันที่เขียน ถ้าแก้โค้ดภายหลัง ควรอัปเดตส่วนที่เกี่ยวข้อง
ท้ายแต่ละไฟล์มีรายการ "จุดที่น่าสังเกต" ที่ผู้เขียนพบ (ยังไม่ใช่บั๊กที่ยืนยันทั้งหมด)
