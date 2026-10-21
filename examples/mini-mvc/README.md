# mini-mvc — ตัวอย่าง MVC แบบ basic สำหรับ junior

ระบบจองรถเล็กๆ ที่ใช้โครงสร้างเดียวกับโปรเจกต์หลัก แต่ตัดให้เหลือเฉพาะแก่น (ไม่มีหลังบ้าน ล็อกอิน LINE ฯลฯ)
เหมาะอ่านก่อนเปิดโปรเจกต์จริง

## รัน

```bash
cd examples/mini-mvc
php -S localhost:8000 index.php
```
เปิด http://localhost:8000 — ฐานข้อมูลเป็นไฟล์ SQLite สร้างเองครั้งแรก (`storage/app.sqlite`) ไม่ต้องติดตั้งอะไรเพิ่ม

## โครงสร้าง (13 ไฟล์)

```
index.php                      จุดเข้าเดียว + ตารางเส้นทาง
app/
  Router.php                   จับคู่ URL → Controller
  Database.php                 PDO (prepared statement) + สร้างตารางตัวอย่าง
  helpers.php                  e() กัน XSS, view(), redirect(), flash(), csrf
  Controllers/
    CarController.php         รายการรถ / รายละเอียดรถ
    BookingController.php     รับฟอร์มจอง → ตรวจข้อมูล → บันทึก
  Models/
    Car.php                    SQL ของตาราง cars
    Booking.php                SQL ของตาราง bookings (ตรวจรถว่าง, สร้างเลขที่จอง)
  Views/
    layout.php                 โครงหน้ากลาง
    cars/index.php, cars/show.php, booking/success.php
```

## การเดินทางของ 1 คำขอ (ตอนกดจอง)

```
POST /booking
  → index.php          อ่าน method + path แล้วส่งให้ Router
  → Router             จับคู่ POST /booking → BookingController::store
  → Controller         ตรวจ CSRF → อ่านฟอร์ม → validate()
  → Model              Booking::hasConflict() เช็กรถว่าง → Booking::create() บันทึก
  → redirect           /booking/success/BK261021-E6B9
GET /booking/success/{no}
  → Controller::success → Booking::findByNo → view('booking/success')
```

## กฎ 4 ข้อของ MVC ที่ตัวอย่างนี้ใช้

1. **Controller บาง** — แค่รับค่า เรียก Model แล้วส่งต่อให้ View
2. **SQL อยู่ใน Model เท่านั้น** — ไม่เขียน SQL ใน Controller หรือ View
3. **View แค่แสดงผล** — ข้อความจากผู้ใช้ผ่าน `e()` ทุกครั้ง
4. **ตรวจแล้ว return ทันที** (early return) — ไม่เขียน if ซ้อนหลายชั้น

## ความปลอดภัยพื้นฐานที่มีอยู่แล้ว

| เรื่อง | ทำที่ไหน |
|---|---|
| SQL injection | `Database::all/one/run` ใช้ prepared statement (`:name` + array) เสมอ |
| XSS | `e()` ใน View ทุกจุดที่แสดงข้อมูลผู้ใช้ |
| CSRF | `csrf_field()` ในฟอร์ม + `csrf_valid()` ใน `BookingController::store` |

## แบบฝึกหัด (ทำตามลำดับ)

1. เพิ่มหน้า `GET /about` — สร้างเมธอดใน `CarController` (หรือ `PageController` ใหม่), เพิ่มบรรทัดใน `index.php`, สร้าง View
2. เพิ่มช่อง "อีเมล" ในฟอร์มจอง — แก้ View, `validate()`, `Booking::create()` และตาราง `bookings`
3. ไม่ให้จองย้อนหลัง — เพิ่มเงื่อนไขใน `validate()` ว่าวันรับรถต้องไม่เป็นอดีต
4. เพิ่มราคารายสัปดาห์ถูกกว่า — แก้การคิดราคาใน `store()` (แล้วลองย้ายไปเป็นฟังก์ชันของตัวเอง)
5. เปลี่ยน SQLite เป็น MySQL — แก้ DSN ใน `Database.php` (ดู `app/Core/Database.php` ของโปรเจกต์หลัก)

## ที่ตัวอย่างนี้ตัดออก (ดูในโปรเจกต์จริง)

`Response` class, middleware, route group, autoloader (PSR-4/namespace), service layer (`WebBookingService`),
transaction + lock กันจองซ้อนตอนมีหลายคนกดพร้อมกัน, ล็อกอิน, แจ้งเตือน LINE
