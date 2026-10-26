# carrent — version 2 (เขียนใหม่ตั้งแต่ศูนย์) · PHP MVC + PDO + MySQL + Tailwind

เวอร์ชัน basic ของระบบเช่ารถ ใช้ schema ใหม่ (`database/migrations/001_schema.sql`)

> branch นี้ (`rewrite/v2`) มีเฉพาะโปรเจกต์ใหม่ที่ root — ระบบเดิมอยู่ใน branch `main`
> เอกสารของระบบเดิม (CODE_MAP, reference ทีละเมธอด, README เก่า) เก็บไว้ที่ `docs/legacy/` ใช้เป็นสเปกกฎธุรกิจตอน port
รันบน shared hosting ได้ (ไม่ต้องมี Composer/Node บนเซิร์ฟเวอร์ — `assets/css/app.css` build แล้ว commit ไว้)

## รันบนเครื่อง

```bash
# 1) สร้างฐานข้อมูล แล้วตั้งค่าเชื่อมต่อ (config/config.local.php หรือ env DB_HOST/DB_NAME/DB_USER/DB_PASS)
mysql -uroot -e "CREATE DATABASE carrent_v3 CHARACTER SET utf8mb4"

# 2) สร้างตาราง (รันซ้ำได้ ข้ามไฟล์ที่เคยรันแล้ว)
php bin/migrate.php

# 3) สร้างผู้ใช้หลังบ้าน (จะถามรหัสผ่าน อย่างน้อย 10 ตัว)
php bin/create-admin.php owner@example.com "เจ้าของร้าน" admin

# 4) เปิดเซิร์ฟเวอร์
php -S localhost:8000 index.php
```

หน้าลูกค้า `/` · หลังบ้าน `/admin`

แก้หน้าตา: `npm install && npm run build` (ต้นฉบับ `assets/css/src/input.css`)

## โครงสร้าง

```
index.php            front controller
app/bootstrap.php    autoload (App\), config, timezone, session, error handler
app/routes.php       ตาราง URL → [Controller, method] (+ middleware 'auth' / 'admin')
app/Core/            Router, Database (PDO), View, Session, Csrf, Flash
app/Models/          SQL ทั้งหมดอยู่ที่นี่ (Car, CarType, User)
app/Services/        กฎธุรกิจ (Auth, CarService)
app/Controllers/     บาง: รับค่า → เรียก Service/Model → ส่ง View
app/Views/           เทมเพลต PHP + Tailwind
database/migrations/ ไฟล์ .sql เรียงตามชื่อ ห้ามแก้ไฟล์ที่รันแล้ว — เพิ่มไฟล์ใหม่แทน
bin/                 migrate.php, create-admin.php
```

กฎ: SQL อยู่ใน Model เท่านั้น · View ครอบข้อมูลผู้ใช้ด้วย `e()` ทุกจุด · POST ทุกเส้นตรวจ CSRF โดย Router

ไฟล์ migration: ห้ามมี `;` หรือ `--` อยู่ในข้อความ string (ตัวรันแยกคำสั่งแบบง่าย)

## ทำเสร็จแล้ว

**slice 0–1**
- โครงแกน, migration runner, ล็อกอิน/ออกจากระบบ (role admin/staff)
- จัดการรถหลังบ้าน (เพิ่ม/แก้/ลบแบบ soft delete — ลบไม่ได้ถ้ามีการจองค้างอยู่)
- หน้าลูกค้า: รายการรถ + รายละเอียดรถ

**slice 2: ตรวจรถว่าง + คิดราคา**
- `PricingService` (ฟังก์ชันล้วน ไม่แตะ DB): นับวัน (ผ่อนผัน 2 ชม.), เหมาเดือน/สัปดาห์/วันที่ถูกที่สุด,
  บริการเสริม, ค่าส่งตามระยะทาง (`DeliveryFeeService`), VAT, มัดจำ, เงินประกัน
  — ยอดเงินปัดสตางค์ทุกขั้น และส่วนลดไม่เกินยอด เพื่อให้ผ่าน CHECK `subtotal`/`grand_total` ของตาราง `bookings`
- `AvailabilityService`: ตรวจรถว่าง (เว้นช่วงพักรถ `booking_gap_hours`, บล็อกวัน, รถที่เลยกำหนดคืนแต่ยังไม่รับคืนถือว่าไม่ว่าง),
  ตรวจเวลาทำการ/จองล่วงหน้า/ขั้นต่ำ/สูงสุด
- หน้าลูกค้า: หน้าแรกค้นหารถว่างตามวัน–เวลาพร้อมราคา, หน้ารถมีกล่องคำนวณราคา + บริการเสริม
- ค่าตั้งต้น: `database/migrations/002_seed_defaults.sql` (แก้ภายหลังที่ตาราง `settings` / `addons`)

**slice 3: สร้างการจอง**
- `BookingService::createBooking` — ที่เดียวที่สร้างการจอง (เว็บและหลังบ้านเรียกผ่านที่นี่)
  ทำใน transaction เดียว: **ล็อกแถวรถเป็นคำสั่งแรก** → ตรวจรถว่าง → คิดราคา → หา/สร้างลูกค้า (ตามเบอร์โทร) → ขอเลขที่จอง → บันทึก
  ถ้าไม่ว่าง/ผิดพลาด rollback หมด (ไม่มีลูกค้าหรือเลขที่จองค้าง)
- เว็บ: หน้ารถ → `/booking` (ฟอร์ม + สรุปราคา) → `/booking/success/{เลขที่จอง}` (เปิดได้เฉพาะเจ้าของ session เพราะเลขที่จองเดาได้)
  กฎกันจองรัว: ค้างมัดจำ ≤ 3 ใบ/เบอร์, ≤ 8 ใบ/ชั่วโมง/IP, ลูกค้า blacklist จองออนไลน์ไม่ได้
- หลังบ้าน: `/admin/bookings` (กรองสถานะ/ค้นหา), รายละเอียด, สร้างการจอง (โทร/LINE/หน้าร้าน — กรอกส่วนลด/VAT/มัดจำเองได้), ยกเลิก (atomic)
- เบอร์โทรเก็บเป็นตัวเลขล้วน (`Phone::normalize`: `+66 81 234 5678` = `081-234-5678` = `0812345678`)
- `Database::begin()` ซ้อนได้ (ชั้นในใช้ SAVEPOINT) เพื่อให้เทสต์ครอบด้วย transaction แล้ว rollback ได้

**ตั้งค่าเมื่อเว็บอยู่หลัง proxy/CDN:** ตั้ง env `TRUST_PROXY=1` (ไม่งั้นทุกคนมี IP เดียวกัน แล้วขีดจำกัด 8 ใบ/ชั่วโมง/IP จะบล็อกลูกค้าทั้งร้าน)
ห้ามเปิดถ้าเข้าเซิร์ฟเวอร์ตรงๆ เพราะผู้ใช้จะปลอม IP ได้

## เทสต์

```bash
# ใช้ฐานข้อมูลแยกสำหรับทดสอบ (เทสต์ test_db_* รันใน transaction แล้ว rollback ให้ ไม่เหลือข้อมูล)
export DB_NAME=carrent_v3_test DB_USER=... DB_PASS=...
php bin/migrate.php
php tests/run.php            # ทั้งหมด   |   php tests/run.php pricing   # เฉพาะชื่อที่มีคำนี้
```

- `tests/golden/rental_old.json` = ค่าที่ได้จาก `PricingService` **ของระบบเดิม** (700 เคสราคา + 26 เคสนับวัน)
  ตัวใหม่ต้องให้ค่าเท่ากันทุกเคส — สร้างใหม่เฉพาะเมื่อตั้งใจเปลี่ยนกฎราคา (วิธีอยู่ที่หัวไฟล์ `tests/golden/generate_from_old.php`)
- ถ้าต่อ DB ไม่ได้ เทสต์ที่ใช้ DB จะถูกข้ามและรันเนอร์คืน exit code 1 (ไม่นับว่าผ่าน)
- `tests/RaceTest.php` จองพร้อมกันด้วย process จริง 8 ตัว (`tests/workers/book.php`) — รถคันเดียวกันต้องได้ 1 ใบ,
  คนละคันต้องสำเร็จครบและเลขที่จองไม่ซ้ำ (ข้อมูลที่สร้างถูกลบเอง) ทดสอบแล้วว่าถ้าถอดการล็อกออก จองได้ 8 ใบ → เทสต์ล้ม

## หมายเหตุ

- การจองใหม่ยังเป็น `pending` / `unpaid` จนกว่าจะมีระบบชำระเงิน (ขั้น 4) — ยังไม่มีแจ้งเตือนร้านเมื่อมีการจองใหม่ (ขั้น 7)
- ลูกค้า 2 คนที่จองด้วยเบอร์ใหม่เดียวกันพร้อมกันอาจได้แถวลูกค้าซ้ำ (เบอร์ไม่ UNIQUE โดยออกแบบ เพราะบริษัทใช้เบอร์ร่วมได้)
- ค่าส่งรถคิดตามระยะทาง (`settings.delivery_tiers`) จึงไม่ได้ใช้คอลัมน์ `cars.delivery_fee` — พิจารณาตัดคอลัมน์นี้ใน migration ถัดไป

## ขั้นถัดไป (ตามลำดับ)

4. ชำระเงิน/สลิป (อนุมัติแบบ atomic, ยืนยันการจอง, คืนเงินเมื่อยกเลิก)
5. ส่งมอบ–รับคืน (`inspections`)
6. เอกสาร/ลายเซ็น
7. LINE
8. รายงาน/ซ่อมบำรุง/cron

## รอ optimize ภายหลัง

รูปรถ (`car_images`) · จำกัดจำนวนครั้งล็อกอินผิด · เปลี่ยนรหัสผ่าน · pagination/ค้นหา · เทสต์อัตโนมัติ · cache
