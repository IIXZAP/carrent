# แผนที่โค้ด — แต่ละไฟล์ทำหน้าที่อะไร

เอกสารนี้อธิบายทุกไฟล์ในโปรเจกต์แบบสั้นๆ เรียงจาก "จุดเริ่มต้น" ไปหา "รายละเอียด"
ถ้าเพิ่งเปิดโปรเจกต์ครั้งแรก ให้อ่านแค่หัวข้อ **0** กับ **1** ก่อน

---

## 0. ภาพรวมใน 1 นาที

```
ลูกค้า/พนักงานเปิด URL
   → index.php                  จุดเข้าเดียวของทั้งเว็บ
   → app/routes.php             ดูว่า URL นี้ใครรับผิดชอบ
   → Controller                 "พนักงานรับเรื่อง": รับค่าจากฟอร์ม แล้วส่งต่อ
   → Service                    "ผู้จัดการ": กฎธุรกิจ (คิดราคา ตรวจรถว่าง ฯลฯ)
   → Model                      "คนถือกุญแจฐานข้อมูล": เขียน/อ่าน SQL
   → View                       "หน้าตา": HTML + Tailwind
```

กฎจำง่าย: **Controller บาง · Service คิด · Model คุยกับฐานข้อมูล · View แสดงผล**

---

## 1. อ่านก่อน 4 ไฟล์

| ลำดับ | ไฟล์ | ทำไมต้องอ่าน |
|---|---|---|
| 1 | `app/routes.php` | สารบัญ URL ทั้งระบบ |
| 2 | `app/Controllers/BookingController.php` | ตัวอย่าง Controller (ลูกค้ากดจอง) |
| 3 | `app/Services/WebBookingService.php` | ตัวอย่างกฎธุรกิจ (ขั้นตอนการจอง) |
| 4 | `app/Views/public/car.php` | หน้าที่ลูกค้าเห็น มีฟอร์มจอง |

---

## 2. ไฟล์ระดับบนสุด

| ไฟล์ | หน้าที่ |
|---|---|
| `index.php` | จุดเข้าของทุกคำขอ เรียก `Application::run()` |
| `.htaccess` | ส่งทุก URL ที่ไม่ใช่ไฟล์จริงมาที่ `index.php` และปิดโฟลเดอร์ภายใน (app, config, database, storage) ไม่ให้เปิดผ่านเว็บ |
| `config/config.php` | ค่าที่ต้องแก้ตอนติดตั้ง: ฐานข้อมูล, เขตเวลา, `BASE_URL` |
| `cron/daily.php` | จุดเรียกงานรายวัน ตรวจรหัสแล้วเรียก `CronService` |
| `robots.txt` | บอก search engine ว่าหน้าไหนไม่ต้องเก็บ |
| `package.json`, `tailwind.config.js` | ตั้งค่าการสร้าง CSS ด้วย Tailwind (`npm run build`) |
| `README.md` | วิธีติดตั้ง โครงสร้าง แผนที่ URL |
| `.gitignore` | ไฟล์ที่ไม่ต้องเก็บใน git (node_modules, ไฟล์สำรอง ฯลฯ) |

---

## 3. `app/Core/` — เครื่องยนต์ของระบบ (ไม่เกี่ยวกับธุรกิจรถเช่า)

| ไฟล์ | หน้าที่ |
|---|---|
| `Application.php` | รับคำขอ → ส่งให้ Router → ส่งผลลัพธ์กลับ |
| `Router.php` | จับคู่ URL กับ Controller, รัน middleware, ตรวจ CSRF อัตโนมัติ, ส่งต่อ URL แบบเก่า (`.php`) ไปที่อยู่ใหม่ |
| `Route.php` | เส้นทาง 1 รายการ (method + pattern เช่น `/cars/{id}`) |
| `Request.php` | ห่อ `$_GET`/`$_POST`/`$_FILES` ไว้ที่เดียว มีตัวช่วยอ่านค่า `str()`, `int()`, `float()` |
| `Response.php` | ผลลัพธ์ที่ Controller ส่งกลับ (HTML, redirect, JSON, ไฟล์) |
| `Controller.php` | คลาสแม่ของ Controller ทุกตัว: `view()`, `redirect()`, `json()`, `flash()` |
| `Model.php` | คลาสแม่ของ Model: หาตาม id, เพิ่ม, แก้, ลบ, นับ |
| `View.php` | แสดงเทมเพลต PHP ธรรมดา รองรับ layout และ partial |
| `Database.php` | เชื่อม MySQL ด้วย PDO ใช้ prepared statement เท่านั้น |
| `DatabaseConnectionException.php` | ข้อผิดพลาดตอนเชื่อมฐานข้อมูลไม่ได้ |
| `Session.php` | ตั้งค่าเซสชันและคุกกี้ให้ปลอดภัย |
| `Csrf.php` | สร้าง/ตรวจโทเคนกันการหลอกส่งฟอร์มจากเว็บอื่น |
| `Flash.php` | ข้อความแจ้งผลที่แสดงครั้งเดียวในหน้าถัดไป |
| `ErrorHandler.php` | ข้อผิดพลาดที่ไม่ได้ดักไว้ → แสดงหน้าสั้นๆ ไม่เผยรายละเอียดภายใน |
| `HttpException.php` | โยนเพื่อหยุดงานแล้วตอบ Response ทันที (เช่น 403, 404) |

---

## 4. `app/Middleware/` — ด่านตรวจก่อนเข้าหน้า

| ไฟล์ | หน้าที่ |
|---|---|
| `AuthMiddleware.php` | ต้องล็อกอินหลังบ้านก่อน ไม่งั้นพาไปหน้า login |
| `AdminMiddleware.php` | เฉพาะ "เจ้าของร้าน" (พนักงานทั่วไปเข้าไม่ได้ เช่น หน้าตั้งค่า ผู้ใช้ สำรองข้อมูล) |

---

## 5. `app/Controllers/` — หน้าเว็บฝั่งลูกค้า, API, LINE

| ไฟล์ | หน้าที่ |
|---|---|
| `HomeController.php` | หน้าแรก |
| `CarController.php` | รายการรถ (ค้นหาตามวัน/ประเภท) และหน้ารายละเอียดรถ |
| `BookingController.php` | รับฟอร์มจอง → เรียก `WebBookingService` → หน้า "จองสำเร็จ" |
| `TrackController.php` | ลูกค้าตรวจสอบการจองด้วยเลขที่จอง + เบอร์โทร และแนบสลิปโอนเงิน |
| `SignController.php` | หน้าเซ็นสัญญา/ใบรับคืนรถบนมือถือ (ผ่านลิงก์เฉพาะ ไม่ต้องล็อกอิน) |
| `PageController.php` | หน้าข้อมูลทั่วไป: วิธีจอง, คำถามที่พบบ่อย, ติดต่อ, เงื่อนไข |
| `ApiController.php` | `quote` คิดราคาให้หน้าเว็บแบบทันที, `track` เก็บสถิติ A/B |
| `LiffController.php` | หน้า "การจองของฉัน" ในแอป LINE (ผูกบัญชี ดูการจอง แจ้งโอน) |
| `LineWebhookController.php` | รับเหตุการณ์จาก LINE (เพิ่มเพื่อน, ข้อความ) ตรวจลายเซ็นของ LINE |
| `SitemapController.php` | สร้าง `sitemap.xml` ให้ Google |
| `HealthController.php` | จุดตรวจ `/health` สำหรับบริการเฝ้าระวังเว็บล่ม |
| `InstallController.php` | หน้า `/check` ตรวจการติดตั้งหลังอัปโหลดขึ้นโฮสต์ |
| `ErrorController.php` | หน้า 404 |

### `app/Controllers/Admin/` — หลังบ้าน

| ไฟล์ | หน้าที่ |
|---|---|
| `AdminController.php` | คลาสแม่ของ Controller หลังบ้านทุกตัว: ตอบ 404, กลับไปหน้ารายการพร้อมข้อความ, ส่งออก CSV |
| `AuthController.php` | ล็อกอิน/ล็อกเอาต์ |
| `DashboardController.php` | หน้าภาพรวม: งานวันนี้, รถเลยกำหนดคืน, รายได้ |
| `BookingController.php` | รายการจอง, สร้าง/แก้ไข, หน้างาน 1 รายการ (ยืนยัน ส่งมอบ รับคืน ยกเลิก บันทึกเงิน) |
| `PaymentController.php` | ตรวจสลิป: อนุมัติ/ปฏิเสธ |
| `CarController.php` | เพิ่ม/แก้ไขรถ รูปรถ และสร้างงานประจำรถ (ประกัน, พ.ร.บ., ภาษี) |
| `CustomerController.php` | ข้อมูลลูกค้า และเอกสาร (บัตร/ใบขับขี่) |
| `CalendarController.php` | ปฏิทินรถ และบล็อกวันไม่ให้จอง |
| `MaintenanceController.php` | งานประจำรถ: เสร็จ / ข้าม |
| `DocumentController.php` | ใบเสนอราคา/ใบแจ้งหนี้/ใบเสร็จรับเงิน/สัญญาเช่า สร้าง แก้ พิมพ์ |
| `ContractController.php` | พิมพ์สัญญาเช่า |
| `InspectionController.php` | ถ่ายรูปตรวจสภาพรถ (ใช้บนมือถือหน้างาน) |
| `ReportController.php` | รายงานรายได้ตามช่วงเวลา + ส่งออก CSV |
| `MonthlyReportController.php` | รายงานผลประกอบการรายเดือน (พิมพ์เป็น PDF ได้) |
| `AbReportController.php` | เทียบผล A/B ของหน้าเว็บสองดีไซน์ |
| `LineController.php` | ตั้งค่า LINE, แก้ข้อความแจ้งเตือน, ติดตั้งเมนูแชต |
| `SettingController.php` | ตั้งค่าร้าน (ชื่อ เบอร์ สี บัญชีธนาคาร เวลาทำการ ฯลฯ) |
| `UserController.php` | จัดการผู้ใช้หลังบ้าน |
| `BackupController.php` | สำรอง/ดาวน์โหลดไฟล์สำรองฐานข้อมูล |
| `HealthController.php` | หน้า "สุขภาพระบบ" และปุ่มย้ายเอกสารเก่าเข้าที่เก็บปิด |

---

## 6. `app/Services/` — กฎธุรกิจ ("ผู้จัดการ")

### การจอง
| ไฟล์ | หน้าที่ |
|---|---|
| `WebBookingService.php` | ขั้นตอนลูกค้าจองผ่านเว็บ: ตรวจข้อมูล → ตรวจรถว่าง → กันจองถี่ → คิดราคา → บันทึก → แจ้งร้าน |
| `AdminBookingService.php` | พนักงานสร้าง/แก้การจองจากหลังบ้าน |
| `BookingActionService.php` | ปุ่มบนหน้างาน: ยืนยัน, ส่งมอบ, ปิดงาน, ยกเลิก, บันทึกเงิน, ส่งลิงก์เซ็น |
| `BookingService.php` | ฟังก์ชันเล็กๆ ที่การจองทุกแบบใช้ร่วมกัน |
| `ReturnService.php` | รับคืนรถ: เลขไมล์ น้ำมัน เวลาคืนจริง ค่าใช้จ่ายเพิ่ม |
| `ExtendService.php` | เช่าต่อ / เลื่อนวันคืน |
| `SecurityDepositService.php` | ปิดเงินประกัน: หักยอดค้างแล้วคืนส่วนที่เหลือ |
| `AvailabilityService.php` | ตรวจรถว่าง, เวลาพักรถระหว่างงาน, เวลาทำการรับ–คืน |

### ราคาและค่าธรรมเนียม
| ไฟล์ | หน้าที่ |
|---|---|
| `PricingService.php` | คิดค่าเช่า (รายวัน/สัปดาห์/เดือนแบบถูกที่สุด) + ออปชัน + มัดจำ |
| `DeliveryFeeService.php` | ค่ารับ–ส่งรถถึงที่ตามระยะทาง |
| `LateReturnService.php` | ค่าปรับคืนช้า, น้ำมันขาด, ระยะทางเกิน |
| `PaymentService.php` | รวมยอดที่ชำระแล้ว, รับแจ้งโอน, อนุมัติ/ปฏิเสธสลิป |

### ติดต่อลูกค้า / เอกสาร
| ไฟล์ | หน้าที่ |
|---|---|
| `LineService.php` | คุยกับ LINE: ส่งข้อความ, ตรวจลายเซ็น/ID token, แบบข้อความ, ผูกบัญชี, เมนูแชต |
| `NotificationService.php` | คิวแจ้งเตือน LINE + อีเมล (ส่งทันที ถ้าพลาด cron ลองใหม่) และข้อความสำเร็จรูป |
| `SignatureService.php` | ลายเซ็นอิเล็กทรอนิกส์: ตรวจรูป, เก็บสำเนาข้อมูล ณ ตอนเซ็น, เช็กว่าแก้ไขหลังเซ็นหรือไม่ |
| `InspectionService.php` | ตรวจสภาพรถก่อน–หลัง: ตำแหน่งรูป, บันทึกรูป, ล็อกผลตรวจ |

### ระบบและงานเบื้องหลัง
| ไฟล์ | หน้าที่ |
|---|---|
| `AuthService.php` | ล็อกอินหลังบ้าน + ป้องกันเดารหัสผ่าน |
| `AdminNavService.php` | เมนูหลังบ้าน (บางเมนูเห็นเฉพาะเจ้าของร้าน) |
| `UploadService.php` | อัปโหลดรูปสาธารณะ (ตรวจชนิดจริง) และย่อรูป |
| `PrivateFileService.php` | ที่เก็บไฟล์แบบปิด: ลายเซ็น บัตรประชาชน ใบขับขี่ (เปิดผ่านลิงก์ตรงไม่ได้) |
| `BackupService.php` | สำรองฐานข้อมูลด้วย PHP ล้วน และลบไฟล์สำรองเก่า |
| `HealthService.php` | ตรวจสุขภาพระบบ (PHP, ฐานข้อมูล, ไฟล์, cron, LINE) |
| `CronService.php` | งานรายวัน: เตือนงานประจำรถ, สรุปงานวันนี้, ยกเลิกจองไม่จ่าย, เตือนลูกค้า, สำรองข้อมูล |
| `MonthlyReportService.php` | รวมตัวเลขรายงานรายเดือน เทียบเดือนก่อน และสรุปข้อสังเกต |
| `AbTrackService.php` | เก็บสถิติเทียบดีไซน์ A/B (ไม่เก็บข้อมูลส่วนบุคคล) |

---

## 7. `app/Models/` — คุยกับฐานข้อมูล (หนึ่งไฟล์ ≈ หนึ่งตาราง)

| ไฟล์ | หน้าที่ |
|---|---|
| `Booking.php` | การจอง (ตารางใหญ่ที่สุด): ค้นหา ตรวจชน รายการค่าใช้จ่ายเพิ่ม ผูก LINE |
| `Car.php` | รถ |
| `CarBlock.php` | ช่วงวันที่บล็อกไม่ให้จอง |
| `Customer.php` | ลูกค้า + เอกสารลูกค้า |
| `Payment.php` | รายการเงิน/สลิป |
| `Document.php` | เอกสาร (ใบเสนอราคา/ใบแจ้งหนี้/ใบเสร็จ/สัญญา) |
| `Inspection.php` | ใบตรวจสภาพรถ + รูป |
| `Signature.php` | ลายเซ็น |
| `Maintenance.php` | งานประจำรถ |
| `Notification.php` | คิวแจ้งเตือน |
| `LineEvent.php` | เหตุการณ์ที่รับจาก LINE |
| `ActivityLog.php` | บันทึกกิจกรรม และนับความพยายามล็อกอินผิด |
| `Setting.php` | ตั้งค่าระบบแบบ key/value |
| `User.php` | ผู้ใช้หลังบ้าน |
| `AbEvent.php` | สถิติ A/B |
| `Report.php` | คำสั่งค้นข้อมูลสำหรับรายงาน (อ่านอย่างเดียว) |

---

## 8. `app/Helpers/functions.php` — ฟังก์ชันช่วยที่เรียกได้ทุกที่

`e()` กัน XSS · `url()`/`site_url()` สร้างลิงก์ · `money()` แสดงเงิน · `thai_date()` วันที่ไทย ·
`baht_text()` อ่านจำนวนเงินเป็นคำไทย · `phone_digits()`/`phone_match()` เทียบเบอร์โทร ·
`booking_status_label()` ป้ายสถานะ · `brand_style()` สีแบรนด์ · `icon()` ไอคอน SVG · `setting()` อ่านค่าตั้งค่า

`app/bootstrap.php` โหลดทุกอย่างตอนเริ่ม (autoloader, helpers, session, ErrorHandler)

---

## 9. `app/Views/` — หน้าตา (HTML + Tailwind)

| โฟลเดอร์ | เนื้อหา |
|---|---|
| `layouts/` | โครงหน้า: `public` (ลูกค้า), `admin` (หลังบ้าน), `admin_print` (พิมพ์), `admin_login`, `focus` (หน้ามือถือสั้นๆ) |
| `partials/` | ชิ้นส่วนใช้ซ้ำ: `car_card` การ์ดรถ, `quote_box` กล่องราคา, `searchbar`, `flash`, `pagination`, `contract_terms` ข้อสัญญา |
| `public/` | หน้าลูกค้า: `home`, `cars`, `car` (รายละเอียด+ฟอร์มจอง), `booking_success`, `track`, `sign`, `liff`, `how_to`, `faq`, `contact`, `terms` |
| `admin/` | หน้าหลังบ้าน แยกโฟลเดอร์ตามเรื่อง: `bookings`, `cars`, `customers`, `documents`, `maintenance`, `reports`, `settings`, `users`, `backups`, `health` + ไฟล์เดี่ยว `dashboard`, `calendar`, `payments`, `line`, `contract`, `inspection`, `monthly_report`, `ab_report`, `login` |
| `errors/404.php` | หน้าไม่พบ |
| `install/` | หน้าตรวจการติดตั้ง |

---

## 10. อื่นๆ

| ไฟล์/โฟลเดอร์ | หน้าที่ |
|---|---|
| `database/schema.sql` | โครงสร้างตารางตั้งต้น |
| `database/update-v2..v5.sql`, `ab-events.sql`, `update-rules.sql` | อัปเดตโครงสร้าง/กฎตามเวอร์ชัน (รันตามลำดับใน README) |
| `database/seed.sql` | ข้อมูลตัวอย่าง (รถ ผู้ใช้เริ่มต้น ตั้งค่า) |
| `database/reset-admin.sql` | ตั้งบัญชีผู้ดูแลใหม่ กรณีลืมรหัสผ่าน |
| `assets/css/src/input.css` | ต้นฉบับ CSS (คอมโพเนนต์ เช่น `btn`, `panel`, `table`) |
| `assets/css/app.css` | CSS ที่ build แล้ว (commit ไว้ โฮสต์ไม่ต้องมี Node) |
| `assets/js/upload-shrink.js` | ย่อรูปในมือถือก่อนอัปโหลด กันเกินขีดจำกัดเซิร์ฟเวอร์ |
| `uploads/` | รูปรถ สลิป รูปตรวจสภาพ (เปิดผ่านเว็บได้ แต่ห้ามรันสคริปต์) |
| `storage/` | ไฟล์สำรองและเอกสารลูกค้า (ปิดไม่ให้เปิดผ่านเว็บ) |

---

## ตัวอย่างเส้นทางที่พบบ่อย

**ลูกค้าจองรถ**
`car.php` (ฟอร์ม) → `BookingController::store` → `WebBookingService::create`
→ `AvailabilityService` + `PricingService` + `Booking` (Model) → `NotificationService` → `booking_success.php`

**ลูกค้าแนบสลิป**
`track.php` → `TrackController::slip` → `PaymentService::submitSlip` → ร้านเห็นที่ `admin/payments` → `PaymentController::approve`

**ร้านรับคืนรถ**
`admin/bookings/show.php` → `Admin\BookingController` → `ReturnService::run` (คำนวณค่าปรับ ผ่าน `LateReturnService`) → แจ้งลูกค้าทาง LINE → ลูกค้าเซ็นที่ `sign.php` → `SignatureService::save`

**งานรายวัน**
cron → `cron/daily.php` → `CronService::run`
