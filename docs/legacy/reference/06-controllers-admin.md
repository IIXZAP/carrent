# 06 — Controllers หลังบ้าน (`app/Controllers/Admin/*.php`)

เอกสารนี้อธิบายทุก class และทุก method ของ controller หลังบ้าน (back-office) 21 ไฟล์ สำหรับนักพัฒนามือใหม่ที่เพิ่งเริ่มกับ PHP MVC

## แนวคิดพื้นฐาน (อ่านก่อน)

- **Controller** ทำหน้าที่แค่ "รับคำขอ → เรียก Model/Service → เลือก View หรือ redirect" ไม่เขียน SQL เอง ทุกตัวสืบทอดจาก `App\Core\Controller` (ผ่าน `AdminController`) ซึ่งมี helper: `view()`, `redirect()`, `json()`, `back()`, `flash()`, `notFound()`, `halt()` และมี `$this->request` (`App\Core\Request`) ให้อ่านค่า GET/POST/ไฟล์
- **Router** (`app/routes.php`) แมป URL → `[Controller::class, 'method']` พารามิเตอร์ใน URL เช่น `{id:\d+}` จะถูกส่งเป็นอาร์กิวเมนต์ของเมธอด (`show(int $id)`)
- **CSRF**: ทุก route แบบ POST ถูกตรวจ CSRF โดย Router (ยกเว้นที่ระบุ `->noCsrf()` ซึ่งไม่มีในกลุ่ม /admin) ฟอร์มใน view ใช้ `csrf_field()`
- **Flash message**: `$this->flash(type, message)` เก็บข้อความใน session แล้วแสดงในหน้าถัดไป type ที่ใช้: `ok`, `warn`, `danger` (มี `info`/อื่นๆ ตาม view)
- **Pattern POST → Redirect → GET (PRG)**: เมื่อบันทึกสำเร็จจะ redirect เสมอ เมื่อบันทึกไม่ผ่านมักแสดงฟอร์มเดิมพร้อมค่าที่กรอก (`$this->request->all()`) และ flash `danger`
- **ActivityLog::log(action, table, refId, detail)** บันทึกประวัติการกระทำ (audit trail) ลงตาราง activity log

## สิทธิ์การเข้าถึงหลังบ้าน

| Middleware | ความหมาย | ที่มา |
|---|---|---|
| `AuthMiddleware` | ต้องล็อกอินแล้ว (role `admin` หรือ `staff` ก็ได้) ถ้ายังไม่ล็อกอิน → flash `warn` "กรุณาเข้าสู่ระบบก่อนใช้งาน" แล้ว redirect `admin/login` | `AuthService::check()` |
| `AdminMiddleware` | เฉพาะเจ้าของร้าน (role = `admin`) ใช้ซ้อนอยู่ใน group ของ AuthMiddleware ถ้าเป็นพนักงาน → flash `warn` "หน้านี้สำหรับเจ้าของร้านเท่านั้น" แล้ว redirect `admin/dashboard` | `AuthService::isAdmin()` |

หน้า `/admin/login`, `POST /admin/login`, `/admin/logout` อยู่นอก group (เข้าได้โดยไม่ล็อกอิน)

หมายเหตุ: นอกจาก middleware แล้วบาง controller เช็กสิทธิ์ในโค้ดเอง เช่น `is_admin()` ใน DashboardController/BookingController::index เพื่อซ่อนตัวเลขเงิน และ `BookingActionService::voidSignature` (act=`sign_void`) ที่อนุญาตเฉพาะเจ้าของร้าน

## ตาราง URL → Controller@method

URL ทั้งหมดมี prefix `/admin` (เช่น `/bookings` = `/admin/bookings`) คอลัมน์ "เฉพาะเจ้าของ" = ต้องผ่าน `AdminMiddleware`

| Method | URL | Controller@method | หน้าที่ | เฉพาะเจ้าของ? |
|---|---|---|---|---|
| GET | /login | AuthController@showLogin | แสดงฟอร์มล็อกอิน | ไม่ (public) |
| POST | /login | AuthController@login | ตรวจอีเมล/รหัสผ่าน | ไม่ (public) |
| GET | /logout | AuthController@logout | ออกจากระบบ | ไม่ (public) |
| GET | / | (closure) | redirect ไป `admin/dashboard` | ไม่ |
| GET | /dashboard | DashboardController@index | ภาพรวม | ไม่ (แต่ตัวเลขรายได้เห็นเฉพาะเจ้าของ) |
| GET | /bookings | BookingController@index | รายการจอง + ค้นหา | ไม่ (ยอดรวมเงินเห็นเฉพาะเจ้าของ) |
| GET | /bookings/new | BookingController@create | ฟอร์มสร้างการจอง | ไม่ |
| POST | /bookings/new | BookingController@store | บันทึกการจองใหม่ | ไม่ |
| GET | /bookings/{id} | BookingController@show | หน้างานของการจอง 1 รายการ | ไม่ |
| POST | /bookings/{id}/action | BookingController@action | ปุ่มต่างๆ บนหน้างาน | ไม่ (act=sign_void เจ้าของเท่านั้นในโค้ด) |
| GET | /bookings/{id}/preview/{return\|extend} | BookingController@preview | JSON ตัวอย่างคิดเงินคืนรถ/เช่าต่อ | ไม่ |
| GET | /bookings/{id}/edit | BookingController@edit | ฟอร์มแก้ไขการจอง | ไม่ |
| POST | /bookings/{id}/edit | BookingController@update | บันทึกการแก้ไข | ไม่ |
| GET | /bookings/{id}/contract | ContractController@show | พิมพ์สัญญาเช่า | ไม่ |
| GET | /bookings/{id}/inspection/{out\|in} | InspectionController@show | หน้าตรวจสภาพรถ | ไม่ |
| POST | /bookings/{id}/inspection/{out\|in} | InspectionController@save | บันทึก/ล็อกผลตรวจ | ไม่ |
| POST | /bookings/{id}/inspection/{out\|in}/photo | InspectionController@photo | อัปโหลดรูป 1 ใบ (JSON) | ไม่ |
| GET | /calendar | CalendarController@index | ปฏิทินรถ timeline | ไม่ |
| POST | /calendar/block | CalendarController@block | ปิดรถชั่วคราว | ไม่ |
| POST | /calendar/unblock | CalendarController@unblock | ยกเลิกการปิดรถ | ไม่ |
| GET | /payments | PaymentController@index | รายการสลิป/การชำระเงิน | ไม่ |
| POST | /payments/approve | PaymentController@approve | อนุมัติสลิป | ไม่ |
| POST | /payments/reject | PaymentController@reject | ปฏิเสธสลิป | ไม่ |
| GET | /cars | CarController@index | รายการรถ | ไม่ |
| POST | /cars/status | CarController@status | เปลี่ยนสถานะรถ | ไม่ |
| GET | /cars/new | CarController@create | ฟอร์มเพิ่มรถ | ไม่ |
| POST | /cars/new | CarController@store | บันทึกรถใหม่ | ไม่ |
| GET | /cars/{id}/edit | CarController@edit | ฟอร์มแก้ไขรถ | ไม่ |
| POST | /cars/{id}/edit | CarController@update | บันทึกการแก้ไขรถ | ไม่ |
| GET | /customers | CustomerController@index | รายการลูกค้า | ไม่ |
| GET | /customers/new | CustomerController@create | ฟอร์มเพิ่มลูกค้า | ไม่ |
| POST | /customers/new | CustomerController@store | บันทึกลูกค้าใหม่ | ไม่ |
| GET | /customers/{id}/edit | CustomerController@edit | ฟอร์มแก้ไขลูกค้า | ไม่ |
| POST | /customers/{id}/edit | CustomerController@update | บันทึกการแก้ไขลูกค้า | ไม่ |
| GET | /customer-files/{id} | CustomerController@file | เปิดเอกสารลูกค้า (private storage) | ไม่ |
| GET | /customers/{id}/legacy/{id_card\|license} | CustomerController@legacyFile | เปิดเอกสารแบบเก่า | ไม่ |
| GET | /maintenance | MaintenanceController@index | งานประจำรถ | ไม่ |
| POST | /maintenance/add | MaintenanceController@add | เพิ่มงาน | ไม่ |
| POST | /maintenance/done | MaintenanceController@complete | ทำงานเสร็จ | ไม่ |
| POST | /maintenance/skip | MaintenanceController@skip | ข้ามงาน | ไม่ |
| GET | /documents | DocumentController@index | รายการเอกสาร | ไม่ |
| POST | /documents/status | DocumentController@status | เปลี่ยนสถานะเอกสาร | ไม่ |
| GET | /documents/new | DocumentController@create | ฟอร์มออกเอกสาร | ไม่ |
| POST | /documents/new | DocumentController@store | บันทึกเอกสารใหม่ | ไม่ |
| GET | /documents/{id}/edit | DocumentController@edit | ฟอร์มแก้ไขเอกสาร | ไม่ |
| POST | /documents/{id}/edit | DocumentController@update | บันทึกการแก้ไข | ไม่ |
| GET | /documents/{id}/print | DocumentController@print | หน้าพิมพ์เอกสาร | ไม่ |
| GET | /reports | ReportController@index | รายงานรายได้/อัตราใช้รถ + CSV | **ใช่** |
| GET | /monthly-report | MonthlyReportController@index | รายงานรายเดือน | **ใช่** |
| POST | /monthly-report | MonthlyReportController@saveNote | บันทึกข้อเสนอแนะประจำเดือน | **ใช่** |
| GET | /ab-report | AbReportController@index | เทียบผล UX สองเวอร์ชัน | **ใช่** |
| GET | /line | LineController@index | หน้าตั้งค่า LINE | **ใช่** |
| POST | /line | LineController@update | บันทึก/ทดสอบ/ติดตั้งเมนู LINE | **ใช่** |
| GET | /settings | SettingController@index | ตั้งค่าระบบ | **ใช่** |
| POST | /settings | SettingController@update | บันทึกตั้งค่า / ทดสอบแจ้งเตือน | **ใช่** |
| GET | /car-types | CarTypeController@index | ประเภทรถ | **ใช่** |
| POST | /car-types/save | CarTypeController@save | เพิ่ม/แก้ประเภทรถ | **ใช่** |
| POST | /car-types/delete | CarTypeController@delete | ลบประเภทรถ | **ใช่** |
| GET | /users | UserController@index | ผู้ใช้งาน + ประวัติการใช้งาน | **ใช่** |
| POST | /users/save | UserController@save | เพิ่ม/แก้ผู้ใช้ | **ใช่** |
| POST | /users/delete | UserController@delete | ลบผู้ใช้ | **ใช่** |
| GET | /backups | BackupController@index | รายการไฟล์สำรอง | **ใช่** |
| GET | /backups/download | BackupController@download | ดาวน์โหลดไฟล์สำรอง | **ใช่** |
| POST | /backups | BackupController@update | สำรองทันที / ตั้งวันเก็บ | **ใช่** |
| GET | /health | HealthController@index | สุขภาพระบบ | **ใช่** |
| POST | /health | HealthController@migrateDocs | ย้ายเอกสารลูกค้าเก่าเข้า private storage | **ใช่** |

นอกจากนี้ routes.php มี redirect ถาวร (301) จาก URL แบบเก่า `/admin/*.php` ไปหน้าใหม่ (ไม่ผ่าน controller หลังบ้านเหล่านี้)

> ข้อสังเกต: หน้า "ชำระเงิน", "เอกสาร", "ลูกค้า", "รถ", "งานประจำรถ" เปิดให้พนักงาน (staff) เข้าได้ ส่วนรายงาน/ตั้งค่า/ผู้ใช้/สำรองข้อมูล/LINE/สุขภาพระบบ เป็นของเจ้าของร้านเท่านั้น

---

## app/Controllers/Admin/AdminController.php

- **หน้าที่ของไฟล์**: คลาสฐาน (abstract) ของ controller หลังบ้านทุกตัว ไม่มี URL ของตัวเอง ให้ helper ที่ใช้ซ้ำ: อ่าน user id ปัจจุบัน, ตอบ 404, redirect พร้อม flash, ส่งออก CSV
- ไม่ใช้ Model/Service โดยตรง (เรียกฟังก์ชัน global `auth_user()`)

### class AdminController (extends `App\Core\Controller`, abstract)

> docblock เหนือ `userId()` เขียนเรื่อง `@param $params` ซึ่งไม่ตรงกับโค้ด (เป็น comment ตกค้าง)

#### `protected userId(): ?int`
- **ทำอะไร**: เรียก `auth_user()` (ข้อมูล user ใน session) ถ้ามีคืน `(int) id` ถ้าไม่มีคืน `null` ใช้เติม `created_by`, `uploaded_by`, `approved_by` ฯลฯ
- **รับเข้า**: ไม่มี · **คืนค่า**: id ผู้ใช้ หรือ null · **View**: –

#### `protected missing(string $what = 'ไม่พบข้อมูลที่ต้องการ'): never`
- **ทำอะไร**: สร้างหน้า 404 ผ่าน `$this->notFound($what)` แล้ว `halt()` ซึ่งโยน `HttpException` ทำให้หยุดการทำงานทันทีและตอบ 404 (ไม่ return กลับ — ชนิดคืนค่า `never`)
- **รับเข้า**: ข้อความที่จะแสดง · **คืนค่า**: ไม่คืน · **View**: หน้า error 404 ของ `ErrorController`

#### `protected done(string $path, string $type, string $message): Response`
- **ทำอะไร**: `flash($type, $message)` แล้ว `redirect($path)` — ทางลัดที่ controller ส่วนใหญ่ใช้หลังทำงานเสร็จ
- **รับเข้า**: path ภายในระบบ (เช่น `admin/cars`), ชนิด flash, ข้อความ · **คืนค่า**: `Response` redirect

#### `protected csv(string $filename, array $header, iterable $rows): Response`
- **ทำอะไร**: เขียนข้อมูลลง `php://temp` โดยเริ่มด้วย BOM UTF-8 (`\xEF\xBB\xBF`) เพื่อให้ Excel อ่านภาษาไทยถูก → `fputcsv` หัวตาราง → `fputcsv` ทุกแถว → อ่านกลับเป็นสตริง → สร้าง `Response` 200 พร้อม header `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="..."`, `Cache-Control: no-store`
- **รับเข้า**: ชื่อไฟล์, แถวหัว, แถวข้อมูล · **คืนค่า**: `Response` ดาวน์โหลด CSV (ใช้โดย ReportController)
- หมายเหตุ: `$filename` ถูกใส่ใน header ตรงๆ ผู้เรียกต้องควบคุมค่าเอง (ปัจจุบันประกอบจากวันที่ที่ผ่านการตรวจแล้ว)

---

## app/Controllers/Admin/AuthController.php

- **หน้าที่ของไฟล์**: ล็อกอิน/ออกจากระบบหลังบ้าน URL: `GET/POST /admin/login`, `GET /admin/logout` (ไม่ผ่าน middleware) ใช้ `AuthService`, `Flash`, `Session`

### class AuthController (extends AdminController)

#### `public showLogin(): Response`
- **ทำอะไร**: ถ้าล็อกอินอยู่แล้ว (`AuthService::check()`) → redirect `admin/dashboard` ไม่เช่นนั้นแสดงฟอร์ม
- **รับเข้า**: ไม่มี · **คืนค่า**: redirect หรือ view · **View**: `admin/login` (title "เข้าสู่ระบบ")

#### `public login(): Response`
- **ทำอะไร**: อ่าน `email` (`str`) และ `password` (post ดิบ ไม่ trim) → `AuthService::attempt($email,$pass)` (ซึ่งตรวจล็อกหลังพยายามผิดหลายครั้ง, ตรวจ password_hash, regenerate session, บันทึก `login_failed` ใน ActivityLog เมื่อผิด) → สำเร็จ redirect `admin/dashboard` · ล้มเหลว: ถ้ายังไม่มี flash จาก service (เช่นข้อความถูกล็อก) จะ flash `danger` "อีเมลหรือรหัสผ่านไม่ถูกต้อง" → redirect `admin/login`
- **รับเข้า**: `email`, `password` · **คืนค่า**: redirect เสมอ

#### `public logout(): Response`
- **ทำอะไร**: `AuthService::logout()` (ล้าง session) → `Session::start()` เพื่อเปิด session ใหม่ให้เก็บ flash → flash `ok` "ออกจากระบบเรียบร้อย" → redirect `admin/login`
- **ข้อสังเกต**: logout ใช้ GET จึงไม่มี CSRF (ผลเสียต่ำ แต่คือพฤติกรรมที่เป็นอยู่)

---

## app/Controllers/Admin/DashboardController.php

- **หน้าที่ของไฟล์**: หน้า "ภาพรวม" `GET /admin/dashboard` ตัวเลขที่ดูทุกเช้า: งานรับ/คืนวันนี้ งานเกินกำหนด รายได้ อัตราการใช้รถ งานประจำรถใกล้ครบ
- Models/Service: `Car`, `Booking`, `Payment`, `Maintenance`, `Report`

### class DashboardController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**:
  1. คำนวณวันนี้ ต้น–ท้ายเดือน จำนวนวันในเดือน
  2. `$isOwner = is_admin()` — **รายได้เห็นเฉพาะเจ้าของ** พนักงานเห็นเฉพาะงานที่ต้องทำ
  3. `totalCars = Car::activeCount()`, `pending = Booking::countByStatus('pending')`, `slipWait = Payment::pendingCount()`
  4. อัตราการใช้รถ = `array_sum(Report::daysSoldByCar(เดือนนี้))` / (จำนวนรถ × วันในเดือน) × 100 (ไม่เกิน 100, ถ้าไม่มีรถ = 0)
  5. ถ้าเป็นเจ้าของ: `revenueMonth = Payment::revenueCollected` (ไม่นับเงินประกัน), `bookedMonth = Booking::bookedValue`, แนวโน้ม 6 เดือนย้อนหลัง (`trend[]` label ชื่อเดือนไทยจาก `TH_MONTHS`, value = revenueCollected ของเดือนนั้น ไม่ต่ำกว่า 0), `perCar = Report::revenuePerCar`
  6. ดึงรายการ: `activeNow` (สถานะ picked_up), `pickupToday`, `returnToday`, `overdue`, `dueTasks = Maintenance::dueUntil(วันนี้ + reminder_days_before วัน, 10 รายการ)`, `carStatus = Car::statusCounts()`
  7. `maxTrend`, `maxCarRev` (อย่างน้อย 1 กันหารศูนย์) สำหรับวาดกราฟแท่ง
- **รับเข้า**: ไม่มี (ใช้ setting `reminder_days_before` ค่าเริ่ม 30)
- **คืนค่า**: `Response` HTML · **View**: `admin/dashboard`

---

## app/Controllers/Admin/BookingController.php

- **หน้าที่ของไฟล์**: ศูนย์กลางของงานจอง — รายการ/ค้นหา, สร้าง, แก้ไข, หน้างาน 1 รายการ (ปุ่มยืนยัน ส่งมอบ รับคืน เช่าต่อ ยกเลิก รับเงิน ฯลฯ), และ endpoint JSON ตัวอย่างคิดเงิน
- URLs: `/admin/bookings`, `/bookings/new`, `/bookings/{id}`, `/bookings/{id}/action`, `/bookings/{id}/preview/{return|extend}`, `/bookings/{id}/edit`
- Models: `Booking`, `Car`, `Customer`, `Document`, `Inspection`, `Payment`, `Signature` · Services: `AdminBookingService`, `ExtendService`, `LateReturnService`, `DeliveryFeeService`, `BookingActionService`, `BookingService`, `InspectionService`, `LineService`

### class BookingController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**: อ่านตัวกรอง `q` (คำค้น), `status`, `car_id`, `from`, `to`, `page` (≥1) → `Booking::search($filters, $page, 25, $isOwner)` โดย `$isOwner = is_admin()` — ถ้าไม่ใช่เจ้าของจะไม่คำนวณยอดเงินรวม (พนักงานเห็นแต่จำนวนรายการ) → ส่ง `rows,total,pages,page,sumTotal,sumPaid,isOwner`, รายการรถสำหรับ dropdown `Car::options()` และ `query = $r->all()` (ไว้ทำลิงก์แบ่งหน้า)
- **รับเข้า**: GET `q,status,car_id,from,to,page` · **View**: `admin/bookings/index`

#### `public create(): Response`
- **ทำอะไร**: แสดงฟอร์มว่าง เรียก `$this->form(null)`
- **View**: `admin/bookings/form` (ผ่าน `form()`)

#### `public edit(int $id): Response`
- **ทำอะไร**: `find($id)` (ถ้าไม่พบ flash + redirect รายการ) แล้ว `form($booking)`
- **รับเข้า**: `{id}` ใน URL · **View**: `admin/bookings/form`

#### `public store(): Response`
- **ทำอะไร**: เรียก `AdminBookingService::save(null, $request)` ซึ่งตรวจชื่อ/เบอร์ (≥ 9 หลัก), หา/สร้างลูกค้าจากเบอร์, ตรวจรถว่างช่วงเวลา, คิดราคา (`PricingService`), สร้างเลข booking_no, status เริ่มต้น `confirmed` (หรือ `pending` ถ้าเลือก), source `admin|line|phone|web` และเขียน ActivityLog `booking_created_admin` → สำเร็จ: `done('admin/bookings/{id}', 'ok', ข้อความ "สร้างการจอง BKxxx แล้ว")` · ผิดพลาด (Throwable): flash `danger` ด้วย `BookingService::publicError(...)` (ซ่อนข้อความภายใน/DB) แล้วแสดงฟอร์มเดิมพร้อมค่าที่กรอก `form(null, true)`
- **รับเข้า**: ฟิลด์ฟอร์ม `car_id, pickup_at, return_at, contact_name, contact_phone, contact_email, contact_line, pickup_place, return_place, note, discount, deposit_amount, security_deposit, addons[], delivery_km, vat, status, source` ฯลฯ

#### `public update(int $id): Response`
- **ทำอะไร**: `find($id)` → `AdminBookingService::save($b, $request)` (ตรวจ field `rev` เทียบ `updated_at` กันแก้ทับกันจากหน้าอื่น; ถ้าสถานะล็อก — picked_up/returned/completed/cancelled — แก้ได้เฉพาะข้อมูลผู้ติดต่อ/หมายเหตุ ไม่แก้รถ/วัน/ราคา; เรียก `PaymentService::recalc`; ถ้าวันรับ/คืนเปลี่ยนจะปลดเครื่องหมายเตือนลูกค้า; ActivityLog `booking_updated`) → สำเร็จ redirect `admin/bookings/{id}` พร้อม flash `ok` "บันทึกการแก้ไขแล้ว" · ผิดพลาด flash `danger` แล้วโหลดข้อมูลล่าสุดมาแสดงฟอร์มพร้อมค่าที่กรอก
- **View**: `admin/bookings/form` เมื่อผิดพลาด

#### `private form(?array $b, bool $withOld = false): Response`
- **ทำอะไร**: ประกอบข้อมูลฟอร์มสร้าง/แก้ไข:
  - `$locked = BookingService::isLocked($b)` (view ใช้ปิดช่องที่แก้ไม่ได้)
  - ถ้า `$withOld` (บันทึกไม่ผ่าน) เติมค่าที่เพิ่งกรอกทับ: `car_id, pickup_place, return_place, contact_*, note, discount, deposit_amount, security_deposit`, `addons`, `pickup_at`, `return_at`, และ `vat` (เช็คบ็อกซ์)
  - ค่าเริ่มต้นวันรับ/คืน: ของเดิมถ้าแก้ไข, ไม่เช่นนั้น "พรุ่งนี้ 10:00" และ "มะรืน 10:00"
  - `vatOn`: ถ้ามี old ใช้ checkbox, ถ้าแก้ไขใช้ `vat_rate > 0`, ถ้าใหม่ใช้ setting `vat_enabled === '1'`
  - ระยะส่งรถ `deliveryKm` จาก `DeliveryFeeService::bookingKm`, ข้อความตาราง `tiersText`
  - รายการรถ `Car::active()`
- **View**: `admin/bookings/form` (title "สร้างการจองใหม่" หรือ "แก้ไขการจอง BKxxx") · เมื่อบันทึกไม่ผ่านตอบสถานะ HTTP 200

#### `public show(int $id): Response`
- **ทำอะไร**: `Booking::findWithCar($id)` (ไม่พบ → `missing('ไม่พบการจองนี้')` 404) → คำนวณ label/สี สถานะการจอง (`booking_status_label`) และการชำระเงิน (`payment_status_label`) → `due = grand_total + extra_charge`, `balance = due - paid_amount` → ดึงผลตรวจสภาพ `Inspection::get(id,'out'|'in')` และความคืบหน้า/แผนที่รูป (`InspectionService::progress/photoMap`) → ส่งทุกอย่างให้ view: รถ, `payments` (`Payment::forBooking`), ลูกค้า, เอกสาร (`Document::forBooking`), `breakdown` (rate_breakdown JSON), `held` (`Payment::securityHeld` = เงินประกันที่ร้านถืออยู่), `xLines` (`Booking::extraLines`), ลายเซ็นที่ใช้อยู่/ถูกยกเลิก (`Signature::active/voided` ของ `contract` และ `return`), `hasLine` (`LineService::userForBooking`), `nowLocal`
- **รับเข้า**: `{id}` · **View**: `admin/bookings/show`

#### `public action(int $id): Response`
- **ทำอะไร**: โหลด booking+car (ไม่พบ → 404) → `BookingActionService::perform($act, $b, $car, $request)` คืน `[tone, msg]` → flash แล้ว redirect กลับ `admin/bookings/{id}` เสมอ; ถ้ามี Throwable → flash `danger` ผ่าน `BookingService::publicError(..., 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง')` (ข้อความ `RuntimeException` ที่เป็นภาษาคนจะถูกแสดงตรงๆ)
- **รับเข้า**: POST `act` + ฟิลด์ตาม act (ดูตารางด้านล่าง) · **คืนค่า**: redirect

#### `public preview(int $id, string $type): Response`
- **ทำอะไร**: endpoint JSON (เรียกด้วย `fetch` จากหน้างาน) ให้ตัวเลขที่ตรงกับที่ระบบบันทึกจริง (ใช้ฟังก์ชันเดียวกัน)
  - ไม่พบ booking → `{ok:false,error:'not found'}` สถานะ 404
  - `type = return`: อ่าน `km` (≥ 0 หรือ null ถ้าว่าง), `fuel` (0–8 หรือ null), `at` (เวลาคืนจริง; ตัดไม่ให้เกินเวลาปัจจุบัน; ถ้าอ่านไม่ได้ใช้ตอนนี้) → `{ok:true} + LateReturnService::calculate($b,$car,$at,$km,$fuel)` (รายการ: คืนช้า น้ำมันขาด ไมล์เกิน ฯลฯ)
  - `type = extend`: `ExtendService::quote($b,$car,$ret)`; ถ้า `ok` เติม `new_return_text` (วันที่ไทย)
- **รับเข้า**: GET `km,fuel,at` (return) หรือ `ret` (extend) · **คืนค่า**: JSON · **View**: ไม่มี

#### `private find(int $id): array`
- **ทำอะไร**: `Booking::find($id)` ถ้าไม่มี flash `danger` "ไม่พบการจอง" แล้ว `halt(redirect admin/bookings)` ถ้ามีคืน array

### รายการ action ของหน้างาน (`POST /admin/bookings/{id}/action`, field `act`)

ตรรกะอยู่ใน `app/Services/BookingActionService.php` (controller เพียงส่งต่อ) ก่อนทำทุก action จะเช็ก "สถานะที่อนุญาต" ถ้าไม่ตรงจะโยนข้อความ: `ทำรายการนี้ไม่ได้ เพราะการจองอยู่ในสถานะ "<ชื่อสถานะ>" แล้ว — หน้านี้อาจเปิดค้างไว้ กรุณาตรวจสถานะล่าสุด` (กันกดย้อน/ข้ามขั้นเมื่อเปิดหลายแท็บ)

สถานะการจอง: `pending` (รอยืนยัน) → `confirmed` → `picked_up` (ส่งมอบแล้ว) → `returned` (รับคืนแล้ว) → `completed` (ปิดงาน); ทางแยก `cancelled`

| act | ปุ่ม | สถานะที่ทำได้ | ผลลัพธ์ / รายละเอียด |
|---|---|---|---|
| `confirm` | ยืนยันการจอง | `pending` | status → `confirmed`; ActivityLog `booking_confirmed`; ส่งแจ้งเตือนลูกค้า `NotificationService::bookingConfirmed` (ถ้าล้มเหลวเงียบ ไม่ทำให้พัง); flash ok "ยืนยันการจองแล้ว" |
| `pickup` | ส่งมอบรถ | `pending`, `confirmed` (ข้ามขั้น confirm ได้) | อ่าน `mileage_out` (≥0), `fuel_out` (0–8 default 8); status → `picked_up`, บันทึก `mileage_out`, `fuel_out`, `picked_up_at`=ตอนนี้; ถ้ามีไมล์ อัปเดตไมล์รถ (`Car::setMileage`); สร้างใบตรวจรอบส่งมอบถ้ายังไม่มี เติมไมล์/น้ำมันที่ว่าง แล้ว **ล็อก** ผลตรวจรอบ "out" (แก้ย้อนหลังไม่ได้); ActivityLog `booking_pickup`; flash ok "บันทึกการส่งมอบรถแล้ว" |
| `return` | รับคืนรถ | `picked_up` | ทำโดย `ReturnService::run`: อ่าน `mileage_in`, `fuel_in`; ตรวจไมล์คืนต้องไม่น้อยกว่าไมล์ส่งมอบ; เวลาคืน = ตอนนี้ เว้นแต่พนักงานติ๊ก `rt_manual` และกรอก `returned_at` (ต้องไม่อยู่อนาคตเกิน 5 นาที และไม่ก่อนเวลาส่งมอบ); คิดค่าใช้จ่ายเพิ่มอัตโนมัติ (`LateReturnService`) พนักงานแก้จำนวน/ยกเว้นรายการได้ผ่าน `chg[key][on|amount]` และเพิ่มรายการเอง `other_label`/`other_amount` (เพดาน 1,000,000); บันทึก status `returned`, `returned_at`, `extra_charge`, `extra_breakdown`, `damage_note`; คำนวณสถานะชำระเงินใหม่ (`PaymentService::recalc`); ล็อกใบตรวจรอบ "in"; ActivityLog `booking_return`; แจ้งลูกค้า (ล้มเหลวเงียบ) |
| `complete` | ปิดงาน | `returned` | status → `completed`; ActivityLog `booking_completed`; flash ok "ปิดงานเรียบร้อย" (ไม่ตรวจยอดค้างชำระในโค้ดนี้) |
| `cancel` | ยกเลิกการจอง | `pending`, `confirmed` เท่านั้น (ยกเลิกหลังส่งมอบไม่ได้ เพราะรถจะกลับมา "ว่าง" ทั้งที่ยังไม่คืน) | อ่าน `cancel_reason`; status → `cancelled`; ActivityLog `booking_cancelled`; flash `warn` "ยกเลิกการจองแล้ว" |
| `extend` | เช่าต่อ/เลื่อนวันคืน | `pending`, `confirmed`, `picked_up` | `ExtendService::run`: อ่าน `new_return`, `charge` (ลดราคาได้ แต่ไม่เกินที่ระบบคิด); ตรวจวันใหม่หลังกำหนดเดิม และรถไม่ชนการจองถัดไป; ในทรานแซกชันล็อกแถวรถแล้วตรวจซ้ำ จากนั้นอัปเดต return_at, rental_days, ยอดรวม/VAT/ส่วนลด, rate_breakdown; recalc การชำระ; ปลดเตือนคืนรถเดิม; ActivityLog `booking_extended`; แจ้งลูกค้า |
| `security_settle` | ปิดเงินประกัน (หัก/คืน) | `returned`, `completed`, `cancelled` | `SecurityDepositService::run`: ต้องมีเงินประกันที่ร้านถืออยู่ (`held > 0`) ไม่เช่นนั้นโยน "ไม่มีเงินประกัน..."; อ่าน `deduct` (หักจากยอดค้างเดิม ไม่เกินยอดค้าง), `add_amount`+`add_label` (ค่าใช้จ่ายที่พบหลังรับคืน — ต้องมี label), `refund_method` (cash/transfer/promptpay, default cash); บันทึกรายการ payment หัก/คืน, recalc, ActivityLog `security_settled`, แจ้งลูกค้า; ทำในทรานแซกชันที่ล็อกแถว booking กันกดซ้ำ |
| `payment` | บันทึกรับเงิน | **ทุกสถานะ** (ไม่อยู่ใน ALLOWED_STATUSES) | อ่าน `amount` (> 0 และ ≤ 5,000,000), `kind` (deposit/balance/security/extra/refund default balance), `method` (transfer/cash/card/promptpay default cash), `pnote`; insert payment สถานะ `approved` ทันที (approved_by = ผู้ใช้); `PaymentService::recalc`; ActivityLog `payment_added` |
| `note` | บันทึกหมายเหตุ | ทุกสถานะ | อัปเดตคอลัมน์ `note` (ว่าง = null) flash ok |
| `sign_send` | ส่งลิงก์เซ็นเอกสารทาง LINE | ตามชนิดเอกสาร: `contract` ที่ `pending/confirmed/picked_up`; `return` ที่ `returned/completed`; ห้ามถ้ายกเลิก | อ่าน `kind` (ไม่รู้จัก = contract); ต้องยังไม่มีลายเซ็นที่ใช้อยู่ และลูกค้าผูก LINE ไว้ และเปิดแจ้งเตือนลูกค้า LINE; ส่งผ่าน `LineService::notifyCustomer('sign_request')` แล้ว flush คิว ตรวจว่าสถานะ sent จริง ไม่เช่นนั้นโยนข้อความแนะนำให้ใช้ปุ่ม "คัดลอกลิงก์"; ActivityLog `signature_link_sent` |
| `sign_void` | ยกเลิกลายเซ็น (ให้ลูกค้าเซ็นใหม่) | ทุกสถานะ | **เฉพาะเจ้าของร้าน** (เช็ก `AuthService::isAdmin()` ในโค้ด); ต้องมีลายเซ็นที่ใช้อยู่ และ `void_reason` ≥ 3 ตัวอักษร; `Signature::void(...)` (ฉบับเดิมเก็บเป็นประวัติ); ActivityLog `signature_voided`; flash `warn` |

act อื่น → `['warn','ไม่รู้จักคำสั่งนี้']`

#### ปุ่มที่หน้า `admin/bookings/show` แสดงตามสถานะ (จาก view)

| สถานะ | ปุ่ม/ฟอร์มที่แสดงในส่วนจัดการ |
|---|---|
| `pending` | ยืนยันการจอง · ส่งมอบรถ · เช่าต่อ(เลื่อนวันคืน) · ยกเลิก · ตรวจสภาพรถ "out" |
| `confirmed` | ส่งมอบรถ · เช่าต่อ · ยกเลิก · ตรวจสภาพรถ "out" |
| `picked_up` | รับคืนรถ (ฟอร์มค่าใช้จ่ายเพิ่ม) · เช่าต่อ · ตรวจสภาพรถ "in" |
| `returned` | ปิดงาน · ปิดเงินประกัน (ถ้า `held > 0`) |
| `completed` | ปิดเงินประกัน (ถ้า `held > 0`) ไม่เช่นนั้นไม่มีปุ่มสถานะ |
| `cancelled` | ปิดเงินประกัน (ถ้า `held > 0`) ไม่เช่นนั้นไม่มีปุ่มสถานะ |
| ทุกสถานะ | บันทึกรับเงิน (`payment`), หมายเหตุ (`note`), ส่งลิงก์เซ็น/คัดลอกลิงก์/ยกเลิกลายเซ็น, ลิงก์ไปสัญญา, ตรวจสภาพ, ออกเอกสาร, แก้ไข |

(ปุ่มที่ view ซ่อนไว้ไม่ใช่ตัวป้องกันหลัก — `BookingActionService::ALLOWED_STATUSES` บังคับซ้ำที่ฝั่งเซิร์ฟเวอร์)

---

## app/Controllers/Admin/ContractController.php

- **หน้าที่ของไฟล์**: พิมพ์สัญญาเช่ารถจากข้อมูลการจอง พร้อมใบแนบสภาพรถ ก (ส่งมอบ)/ข (รับคืน) URL: `GET /admin/bookings/{id}/contract`
- ข้อความสัญญาอยู่ที่ `views/partials/contract_terms.php` (ควรให้ทนายตรวจก่อนใช้จริง)
- Models/Services: `Booking`, `Customer`, `Inspection`, `Signature`, `InspectionService`

### class ContractController (extends AdminController)

#### `public show(int $id): Response`
- **ทำอะไร**: `Booking::findByIdForContract($id)` (ไม่พบ → `missing('ไม่พบการจอง')`) → ดึงผลตรวจ out/in, ลูกค้า (ถ้ามี `customer_id`), `carRow` (brand/model/plate จากแถว booking), ลายเซ็นอิเล็กทรอนิกส์ที่ใช้อยู่ `sigC` (contract) / `sigR` (return), แผนที่รูป `posOut/posIn` และ `extraLines` (ค่าใช้จ่ายเพิ่ม)
- **รับเข้า**: `{id}` · **View**: `admin/contract`

---

## app/Controllers/Admin/InspectionController.php

- **หน้าที่ของไฟล์**: หน้าถ่ายรูปตรวจสภาพรถ (ออกแบบสำหรับมือถือ) — ตัวย่อรูปทำที่ฝั่งเบราว์เซอร์ก่อนอัปโหลดทีละใบ URL: `/admin/bookings/{id}/inspection/{out|in}` (GET/POST) และ `/photo` (POST JSON) · `kind`: `out` = ส่งมอบ, `in` = รับคืน (route จำกัดเฉพาะสองค่านี้)
- Models/Services: `Booking`, `Car`, `Inspection`, `ActivityLog`, `InspectionService`

### class InspectionController (extends AdminController)

#### `public show(int $id, string $kind): Response`
- **ทำอะไร**: โหลด booking ผ่าน `booking()` → ผลตรวจ `Inspection::get` → `phaseBlock = InspectionService::phaseBlock` (ข้อความเหตุผลที่แก้ไม่ได้ในสถานะนี้ เช่น ตรวจรอบ in ก่อนส่งมอบ) → `locked` = ใบตรวจถูกล็อก หรือมี phaseBlock → ค่าเริ่มต้นไมล์: ค่าที่บันทึกในใบตรวจ → mileage_out/in ของ booking → ไมล์ล่าสุดของรถ (`Car::mileage`); น้ำมันเริ่มต้น 8 → ส่งแผนที่รูป ความคืบหน้า ผลตรวจรอบตรงข้าม มุมถ่ายทั้งหมด (`InspectionService::positions()`)
- **View**: `admin/inspection` (title "ตรวจสภาพรถ ...")

#### `public save(int $id, string $kind): Response`
- **ทำอะไร**: (act = `save` หรือ `lock`)
  1. โหลด booking; ถ้า `phaseBlock` → flash `warn` + กลับหน้าตรวจ
  2. ถ้าใบตรวจถูกล็อกแล้ว → flash `warn` "ผลตรวจรอบนี้ถูกล็อกแล้ว แก้ไขไม่ได้"
  3. `Inspection::ensure` สร้างถ้ายังไม่มี แล้ว update: `mileage` (0–9,999,999, 0 = null), `fuel_level` (0–8), `note` (≤1000), `inspected_by`, `inspected_at`; ActivityLog `inspection_saved`
  4. ถ้า act = `lock`: ต้องติ๊ก `lock_ack` ก่อน ไม่เช่นนั้น flash `warn` "บันทึกแล้ว แต่ยังไม่ได้ล็อก..." (อยู่หน้าตรวจ) ถ้าติ๊ก → `InspectionService::lock` (ย้อนไม่ได้) → redirect หน้าการจอง flash ok
  5. act อื่น → redirect `admin/bookings/{id}` flash ok "บันทึกผลตรวจสภาพรถแล้ว"
- **รับเข้า**: `act`, `mileage`, `fuel_level`, `note`, `lock_ack`

#### `public photo(int $id, string $kind): Response`
- **ทำอะไร**: รับรูป 1 ใบ ตอบ JSON: ตรวจว่ามี booking (`Booking::find`), ตรวจ `phaseBlock`, ตรวจ `position` ว่าอยู่ใน `InspectionService::positions()` → `Inspection::ensure` → `InspectionService::savePhoto(insId, pos, file 'photo', caption)` (คืน path; ถ้า null = "ไม่ได้รับไฟล์รูป") → ActivityLog `inspection_photo` (detail `kind:pos`) → `{ok:true,url:upload_url(path),position}`; ข้อผิดพลาดใดๆ → `{ok:false,error:...}` สถานะ **400**
- **รับเข้า**: POST `position`, `photo` (ไฟล์), `caption` · **คืนค่า**: JSON
- สังเกต: ไม่ตรวจเองว่าใบตรวจถูกล็อกในเมธอดนี้ (ใช้ `phaseBlock` ซึ่งอิงสถานะการจอง และ `savePhoto` อาจตรวจเอง)

#### `private booking(int $id): array`
- **ทำอะไร**: `Booking::findWithCar($id)` ไม่พบ → `missing('ไม่พบการจองนี้')` (404)

---

## app/Controllers/Admin/CalendarController.php

- **หน้าที่ของไฟล์**: ปฏิทินรถแบบ timeline ทั้งเดือน (แถว = รถ, คอลัมน์ = วัน) และการปิดรถชั่วคราว (ซ่อม/เจ้าของใช้เอง) URL: `GET /admin/calendar`, `POST /calendar/block`, `POST /calendar/unblock`
- Models: `Car`, `Booking`, `CarBlock`, `ActivityLog`

### class CalendarController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**:
  1. อ่าน `m` (รูปแบบ `YYYY-MM`) ตรวจ regex ไม่ผ่านใช้เดือนปัจจุบัน; คำนวณวันแรก/วันที่ในเดือน/วันสุดท้าย
  2. `cars = Car::active()`
  3. วนการจองที่ชนเดือนนี้ (`Booking::forCalendar`) เติมตาราง `grid[car_id][day]` = `{kind=status, label (เลขจอง+ชื่อ 10 ตัวอักษร เฉพาะวันแรก), link (หน้าจอง), title}`; การจองข้ามเดือนถูกตัดที่วัน 1 / วันสุดท้าย
  4. วนช่วงปิดรถ (`CarBlock::inRange`) เติม `kind='block'` เฉพาะวันที่ยังไม่มีการจอง
  5. `util` = ช่องที่ถูกใช้ / (จำนวนรถ × วันในเดือน) × 100
  6. ส่ง `prev`/`next` (เดือนก่อน/ถัดไป), `today`, `allBlocks = CarBlock::upcoming(20)`
- **รับเข้า**: GET `m` · **View**: `admin/calendar`
- หมายเหตุ: ช่องที่การจองหลายรายการทับกันวันเดียว (เช่นรับ–คืนวันเดียวกัน) ตัวหลังเขียนทับตัวก่อน

#### `public block(): Response`
- **ทำอะไร**: ปิดรถชั่วคราว: อ่าน `car_id`, `start_at`, `end_at`, `reason` (≤250, default "ปิดการใช้งาน"), `m`; ตรวจ "กรุณาเลือกรถและช่วงเวลา" และ "วันสิ้นสุดต้องหลังวันเริ่ม" → `CarBlock::insert` (created_by = ผู้ใช้) → ActivityLog `car_blocked` → flash ok "บันทึกช่วงปิดรถแล้ว"; ข้อผิดพลาด: ถ้าเป็น `RuntimeException` แสดงข้อความ ไม่งั้น "บันทึกไม่สำเร็จ" (danger) → redirect `admin/calendar?m=...`
- หมายเหตุ: ไม่ตรวจว่าช่วงปิดรถชนการจองที่มีอยู่แล้ว (ตามโค้ดนี้)

#### `public unblock(): Response`
- **ทำอะไร**: `CarBlock::deleteById(block_id)` → flash ok "ยกเลิกช่วงปิดรถแล้ว" → redirect `admin/calendar?m=...` (ไม่เขียน ActivityLog และไม่ตรวจว่ามี block นั้นจริง)
- **รับเข้า**: POST `block_id`, `m`

---

## app/Controllers/Admin/PaymentController.php

- **หน้าที่ของไฟล์**: ตรวจสลิปโอนเงิน อนุมัติ/ปฏิเสธ (เป็น POST เท่านั้น กัน prefetch/สแกนเนอร์ยิงลิงก์) URL: `GET /admin/payments`, `POST /payments/approve|reject`
- Models/Services: `Payment`, `PaymentService`

### class PaymentController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**: อ่าน `status` (`pending|approved|rejected|all`, default `pending`) → `Payment::listByStatus(filter,200)`; สรุป `cntPending`, `sumPending`, `sumToday = Payment::revenueCollected(วันนี้,วันนี้)` (ไม่นับเงินประกัน หักรายการคืนเงิน)
- **View**: `admin/payments`

#### `public approve(): Response`
- **ทำอะไร**: `PaymentService::approve(payment_id, userId)` — ถ้า payment ไม่พบหรือไม่ใช่ pending คืน false; ถ้าสำเร็จ recalc การชำระ และถ้าการจองยัง `pending` และจ่ายถึงมัดจำแล้วจะยืนยันการจองอัตโนมัติ → flash ok "อนุมัติการชำระเงินแล้ว" (ถ้า false **ไม่มี flash** แค่ redirect) → redirect `backPath()`
- **รับเข้า**: POST `payment_id`, `back`

#### `public reject(): Response`
- **ทำอะไร**: อ่าน `reason` (≤250) → `PaymentService::reject(...)` (เหตุผลว่าง = "ข้อมูลไม่ถูกต้อง"; recalc; แจ้งลูกค้า) → ถ้าสำเร็จ flash `warn` "ปฏิเสธสลิปแล้ว" → redirect `backPath()`

#### `private backPath(): string`
- **ทำอะไร**: อ่าน `back`; อนุญาตเฉพาะ path ตรง regex `^admin/[A-Za-z0-9/_-]*$` (กัน open redirect) ไม่ตรง → `admin/payments`

---

## app/Controllers/Admin/CarController.php

- **หน้าที่ของไฟล์**: จัดการรถ — รายการ, เปลี่ยนสถานะ, เพิ่ม/แก้ไข (ข้อมูล ราคา รูป อุปกรณ์ วันหมดอายุประกัน/พ.ร.บ./ภาษี/เช็กระยะ) URL: `/admin/cars*`
- Models/Services: `Car`, `CarType`, `Maintenance`, `ActivityLog`, `UploadService`

### class CarController (extends AdminController)

ค่าคงที่ `STATUSES = ['available','maintenance','inactive']`

#### `public index(): Response`
- **ทำอะไร**: `Car::complianceMap()` (วันหมดอายุของรถทุกคัน) → วนรถทั้งหมด `Car::all()` เติม `active_booking = Car::activeRental(id)` และข้อมูล compliance
- **ส่ง**: `cars`, `today`, `warnDays` (setting `reminder_days_before` default 30) · **View**: `admin/cars/index`

#### `public status(): Response`
- **ทำอะไร**: อ่าน `car_id`, `status` (ไม่อยู่ใน STATUSES → `available`); ถ้าจะตั้ง `inactive` และรถยังมีการจองไม่จบ (`Car::openBookingCount`) → flash `danger` ห้ามปิด (แนะนำใช้ "ซ่อมบำรุง"); ไม่เช่นนั้น `Car::setStatus`, ActivityLog `car_status`, flash ok "อัปเดตสถานะรถแล้ว" → redirect `admin/cars`
- หมายเหตุ: สถานะที่ไม่รู้จักถูกแปลงเป็น `available` เงียบๆ และไม่ตรวจว่า car_id มีจริง

#### `public create(): Response` / `public edit(int $id): Response`
- **ทำอะไร**: `create` → `form(null)`; `edit` → `form($this->find($id))` · **View**: `admin/cars/form`

#### `public store(): Response` / `public update(int $id): Response`
- **ทำอะไร**: `store` → `save(null)`; `update` → `save($this->find($id))`

#### `private find(int $id): array`
- **ทำอะไร**: `Car::find`; ไม่พบ → `halt(done('admin/cars','danger','ไม่พบรถคันนี้'))`

#### `private form(?array $car, array $old = []): Response`
- **ทำอะไร**: `v = $old + ($car + Car::compliance(id))` (ค่าที่เพิ่งกรอกทับค่าจาก DB); `feats` = อุปกรณ์ (จาก `old['features']` แยกบรรทัด หรือ JSON ของรถ); `images` = รูปเพิ่มเติม (JSON); `limit = UploadService::limitBytes()` (ขนาดไฟล์สูงสุด)
- **View**: `admin/cars/form` (title "เพิ่มรถใหม่" หรือ "แก้ไขรถ <ทะเบียน>")

#### `private save(?array $car): Response`
- **ทำอะไร**: ใน try: `readCarFields()` → `checkRequired()` → รวมกับ `readFeatures()` + `readImages($car)` → `readDueDates()` → ถ้าแก้ไข `Car::updateById` + ActivityLog `car_updated` (detail = ทะเบียน) flash "บันทึกข้อมูลรถแล้ว"; ถ้าเพิ่ม `Car::insert` + `car_created` flash "เพิ่มรถเรียบร้อย" → `saveDueDates()` → redirect `admin/cars`. เมื่อ Throwable: flash `danger` (`errorMessage`) แล้วแสดงฟอร์มเดิมพร้อมค่าที่กรอก
- หมายเหตุ: ไม่ใช้ transaction — ถ้า `saveDueDates` ล้มเหลวหลังบันทึกรถแล้ว จะแสดงข้อผิดพลาดทั้งที่รถถูกบันทึกแล้ว; ไม่ตรวจทะเบียนซ้ำในโค้ดชั้นนี้ (อาจพึ่ง unique index ใน DB)

#### `private errorMessage(\Throwable $ex): string`
- **ทำอะไร**: ถ้าเป็น `RuntimeException` (และไม่ใช่ `PDOException`) คืนข้อความจริง ไม่เช่นนั้น "บันทึกไม่สำเร็จ กรุณาตรวจข้อมูลแล้วลองใหม่" (กันรั่วข้อความ DB)

#### `private readCarFields(): array`
- **ทำอะไร**: อ่านฟิลด์: `plate, province, brand, model, year (int|null), color, car_type, seats (default 5), gear (manual|auto), fuel (default เบนซิน), price_day/week/month, deposit, deposit_booking, delivery_fee, driver_fee_day, status (ตรวจกับ STATUSES), mileage, insurance_type, description, sort_order` — ค่าว่างของฟิลด์ทางเลือกเก็บเป็น `null`

#### `private checkRequired(array $data): void`
- **ทำอะไร**: โยน `RuntimeException`: "กรุณากรอก ทะเบียน / ยี่ห้อ / รุ่น" · "กรุณากรอกราคาเช่ารายวัน" (`price_day` ≤ 0) · "กรุณาเลือกประเภทรถ" (`!CarType::exists`) · "ปีรถต้องเป็น ค.ศ. (1990–2100)"

#### `private readFeatures(): array`
- **ทำอะไร**: แปลง textarea `features` (บรรทัดละรายการ) เป็น JSON (Unicode) หรือ null → `['features' => ...]`

#### `private readImages(?array $car): array`
- **ทำอะไร**: อัปโหลดรูปหน้าปก `cover_image` ผ่าน `UploadService::save(file,'cars',6)` (6 = MB สูงสุด) ถ้ามีไฟล์ใหม่ใส่ `cover_image`; เริ่มจากรายการรูปเดิมของรถ เพิ่มไฟล์ใหม่จาก `images[]` (ข้ามไฟล์ที่ error); ตัดรูปที่ติ๊ก `remove_images[]` ออกจากรายการ (ลบเฉพาะการอ้างอิง ไฟล์จริงคงอยู่); คืน `['images' => JSON|null]` (+ `cover_image` ถ้ามี)

#### `private readDueDates(): array`
- **ทำอะไร**: อ่านวันหมดอายุ `insurance_expire, act_expire, tax_expire, next_service_at` และ `next_service_km` (ว่าง = null) เป็นคีย์ `insurance, act, tax, service, service_km`

#### `private saveDueDates(int $carId, string $plate, array $due): void`
- **ทำอะไร**: เรียก `Maintenance::setPending` 4 ครั้ง (ต่อประกันภัย / ต่อ พ.ร.บ. / ต่อภาษีประจำปี / เช็กระยะ+เลขไมล์) — งานประจำรถเป็น "แหล่งข้อมูลเดียว" ของวันหมดอายุ

---

## app/Controllers/Admin/CarTypeController.php

- **หน้าที่ของไฟล์**: จัดการประเภทรถ (เจ้าของร้านเท่านั้น) URL: `GET /admin/car-types`, `POST /car-types/save|delete` · Models: `CarType`, `ActivityLog`

### class CarTypeController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**: อ่าน `edit` (รหัสที่จะแก้, ≤20) → ส่ง `types = CarType::allWithUsage()` (พร้อมจำนวนรถที่ใช้) และ `edit = findForEdit(code)`
- **View**: `admin/car_types/index`

#### `public save(): Response`
- **ทำอะไร**: อ่าน `original` (รหัสเดิม), `label_th` (≤60), `sort_order`; ชื่อว่าง → "กรุณากรอกชื่อประเภท"; ถ้ามี `original` = แก้ไข: `CarType::change(original,label,sort,is_active)` + ActivityLog `car_type_updated`; ไม่มี = เพิ่ม: `readNewCode()` + `CarType::create` + `car_type_created`; error → flash danger (`errorMessage`) → redirect `admin/car-types`

#### `public delete(): Response`
- **ทำอะไร**: อ่าน `code`; ถ้ามีรถใช้อยู่ (`CarType::usage`) → danger ห้ามลบ (แนะนำปิดใช้งานแทน); ไม่ใช้ → `CarType::remove` + ActivityLog `car_type_deleted` + flash `warn` "ลบประเภทรถแล้ว"

#### `private findForEdit(string $code): ?array`
- **ทำอะไร**: ว่าง → null; ไม่เช่นนั้นวนหาใน `allWithUsage()` ที่ `code` ตรง

#### `private readNewCode(): string`
- **ทำอะไร**: lower-case รหัส, ตรวจ `^[a-z0-9_-]{2,20}$` ("รหัสประเภทใช้ได้เฉพาะ..."), ตรวจไม่ซ้ำ (`CarType::exists` → "รหัสนี้มีอยู่แล้ว")

#### `private errorMessage(\Throwable $ex): string`
- **ทำอะไร**: เหมือน CarController แต่ข้อความทั่วไป "บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง"

---

## app/Controllers/Admin/CustomerController.php

- **หน้าที่ของไฟล์**: ฐานลูกค้า ค้นหา ประวัติ blacklist และเอกสารแนบที่เก็บใน **private storage** (เปิดตรงจาก URL ไม่ได้ ต้องผ่านหลังบ้าน และมี log ทุกครั้ง ตามหลัก PDPA) URL: `/admin/customers*`, `/admin/customer-files/{id}`
- Models/Services: `Customer`, `ActivityLog`, `PrivateFileService`, `UploadService`

### class CustomerController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**: อ่าน `q`, `only` (= `blacklist` แสดงเฉพาะแบล็กลิสต์) → `Customer::search(q, only, 200)` และ `Customer::totals()` · **View**: `admin/customers/index`

#### `public create()` / `edit(int $id)` / `store()` / `update(int $id)`
- **ทำอะไร**: `create` → `form(null)`; `edit` → `form(find($id))`; `store` → `save(null)`; `update` → `save(find($id))` · **View**: `admin/customers/form`

#### `public file(int $id): Response`
- **ทำอะไร**: `Customer::findDocument($id)` → `PrivateFileService::path(file_path)`; ไม่พบ → ตอบข้อความ 404 `Response::text('ไม่พบไฟล์เอกสาร', 404)`; พบ → ActivityLog `document_viewed` (ตาราง customer_documents, detail customer_id + kind) → `PrivateFileService::response(full, 'customer-<cid>-<kind>')` (ส่งไฟล์)
- **รับเข้า**: `{id}` = id ของเอกสาร (ไม่ใช่ id ลูกค้า)

#### `public legacyFile(int $id, string $kind): Response`
- **ทำอะไร**: เอกสารที่อัปโหลดก่อนเวอร์ชัน 5 (อยู่ `uploads/docs`): แปลง `kind` (`id_card|license`) เป็นคอลัมน์ `id_card_file|license_file` → `Customer::legacyFile`; อนุญาตเฉพาะ path ที่ขึ้นต้น `docs/` (กันเปิดไฟล์อื่นในโฟลเดอร์อัปโหลด) → `PrivateFileService::legacyPath`; ไม่พบ 404 ข้อความ; พบ → ActivityLog `document_viewed` → ส่งไฟล์

#### `private find(int $id): array`
- **ทำอะไร**: `Customer::find`; ไม่พบ → `halt(done('admin/customers','danger','ไม่พบลูกค้า'))`

#### `private form(?array $c, array $old = []): Response`
- **ทำอะไร**: เรียก `PrivateFileService::protectLegacyDocs()` (ป้องกันโฟลเดอร์เอกสารเก่า) → ส่ง `v = $old + $c`, `history = Customer::history(id,50)`, `cdocs = Customer::documents(id)`, `spend = Customer::spend(id)`, `docKinds`, `limit`
- **View**: `admin/customers/form`

#### `private save(?array $c): Response`
- **ทำอะไร**: อ่านฟิลด์ `type (individual|company), full_name, tax_id, phone, email, line_id, address, license_no, license_expire, is_blacklist (checkbox), blacklist_note, note`; ตรวจชื่อ+เบอร์ ("กรุณากรอกชื่อและเบอร์โทร"); **รับไฟล์ก่อนบันทึกข้อมูล** (`PrivateFileService::storeUpload(doc_file,'docs',8 MB)`) เพื่อไม่ให้บันทึกครึ่งๆ กลางๆ ถ้าไฟล์ไม่ผ่าน; `doc_kind` ตรวจกับ `customerDocKinds()` (default `id_card`); แก้ไข → `Customer::updateById` + log `customer_updated`; เพิ่ม → `Customer::nextCode()` + `insert` + log `customer_created`; ถ้ามีไฟล์ → `Customer::addDocument` (mime, size, `doc_note`, uploaded_by) + log `document_uploaded` + flash; redirect `admin/customers/{id}/edit`; error → flash danger แสดงฟอร์มพร้อมค่าที่กรอก
- หมายเหตุ: ไม่ตรวจเบอร์โทรซ้ำที่ชั้นนี้ และไม่ทำใน transaction

---

## app/Controllers/Admin/MaintenanceController.php

- **หน้าที่ของไฟล์**: งานประจำรถ — ประกัน/พ.ร.บ./ภาษี/เช็กระยะ/ยาง/ซ่อม URL: `GET /admin/maintenance`, `POST /maintenance/add|done|skip` · Models: `Maintenance`, `Car`, `ActivityLog`

### class MaintenanceController (extends AdminController)

ค่าคงที่ `TYPES = [service, insurance, act, tax, tyre, repair, other]`

#### `public index(): Response`
- **ทำอะไร**: `status` (`pending|done|skipped|all`, default pending) → `Maintenance::listByStatus(filter,200)`; สรุปเกินกำหนด `overdueCount`, ใกล้ถึง `dueBetweenCount(วันนี้, +reminder_days_before)`, ค่าใช้จ่ายปีนี้ `costForYear`; ส่งรถ active สำหรับฟอร์มเพิ่ม และ `typeLabel`
- **View**: `admin/maintenance/index`

#### `public add(): Response`
- **ทำอะไร**: insert งานใหม่สถานะ `pending`: `car_id`, `task_type` (ไม่อยู่ใน TYPES = other), `title` (≤250, default "งานประจำรถ"), `due_date`, `due_mileage`, `vendor`, `note`; log `maintenance_added`; flash ok "เพิ่มงานแล้ว" → redirect
- หมายเหตุ: ไม่ตรวจว่า `car_id` มีจริง (0 ได้ ถ้ามี FK จะเกิด error ที่ไม่ถูกจับ)

#### `public complete(): Response`
- **ทำอะไร**: อ่าน `id`, `cost`; ไม่พบงาน → redirect เงียบๆ; update สถานะ `done`, `done_date` (default วันนี้), `cost`, `vendor` (ถ้าไม่กรอกคงค่าเดิม); ถ้ากรอก `next_due` และชนิดอยู่ใน `insurance|act|tax|service` จะสร้างงานรอบถัดไป (pending) อัตโนมัติ — วันหมดอายุของรถอ่านจากงานที่ค้างจึงเปลี่ยนตามเอง; log `maintenance_done` (detail = cost); flash ok

#### `public skip(): Response`
- **ทำอะไร**: `Maintenance::skip(id)` → flash `warn` "ข้ามงานนี้แล้ว" (ไม่เขียน ActivityLog)

---

## app/Controllers/Admin/DocumentController.php

- **หน้าที่ของไฟล์**: ใบเสนอราคา/ใบแจ้งหนี้/ใบเสร็จ(/สัญญา) — ดึงรายการจากการจองอัตโนมัติ แก้ไขได้ พิมพ์เป็น PDF จากเบราว์เซอร์ URL: `/admin/documents*`
- Models: `Document`, `Booking`, `Customer`, `ActivityLog`
- ค่าคงที่ `TYPE_LABEL` = quotation/invoice/receipt/contract → ชื่อไทย

### class DocumentController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**: `type` (กรอง; ไม่อยู่ใน `Document::TYPES` = แสดงทั้งหมด) → `Document::listByType`, label สถานะ (draft/sent/accepted/paid/void), ยอดใบเสนอราคาที่ยังรอ `Document::pendingQuotationSum()` · **View**: `admin/documents/index`

#### `public status(): Response`
- **ทำอะไร**: `Document::setStatus(id, status)` (status ไม่อยู่ใน `Document::STATUSES` → `draft`) → flash ok → redirect `admin/documents` (ไม่ตรวจว่าเอกสารมีจริง และไม่มี ActivityLog)

#### `public create(): Response`
- **ทำอะไร**: `type` (default quotation) และ `booking_id` จาก query → `form(null, type, bookingId)` · **View**: `admin/documents/form`

#### `public edit(int $id): Response`
- **ทำอะไร**: `find($id)` แล้ว `form($doc, doc_type, booking_id)`

#### `public store(): Response` / `public update(int $id): Response`
- **ทำอะไร**: `store` → `save(null, type, booking_id)` (ประเภทมาจาก POST `type`, ไม่ถูกต้อง = quotation); `update` → `save($doc, doc['doc_type'], doc['booking_id'])` (ชนิดและการจองแก้ภายหลังไม่ได้)

#### `public print(int $id): Response`
- **ทำอะไร**: `find($id)` → ส่ง `doc`, `booking = Booking::findForDocument(...)` (ถ้ามี), `items` (JSON ถอดรหัส), `typeLabel`, `typeEn` (QUOTATION/INVOICE/RECEIPT/CONTRACT)
- **View**: `admin/documents/print`

#### `private find(int $id): array`
- **ทำอะไร**: `Document::find`; ไม่พบ → `halt(done('admin/documents','danger','ไม่พบเอกสาร'))`

#### `private form(?array $doc, string $type, int $bookingId, array $old = []): Response`
- **ทำอะไร**: โหลดการจอง (ถ้ามี) และลูกค้า (จากเอกสาร หรือจากการจอง); รายการ: ใช้ของเอกสาร → หรือสร้างจากการจอง (`itemsFromBooking`) → หรือบรรทัดว่าง 1 บรรทัด; ค่า `v` ถ้าเป็นเอกสารใหม่: วันที่ออก = วันนี้, ครบกำหนด +7 วัน, ชื่อ/เลขผู้เสียภาษี/ที่อยู่จากลูกค้าหรือการจอง, ส่วนลดและ vat_rate จากการจอง (หรือ setting `vat_enabled`/`vat_rate` ค่าเริ่ม 7), wht 0; ค่าที่กรอกล่าสุด (`$old`) ทับ
- **View**: `admin/documents/form` (title "ออก<ชนิด>" หรือ "แก้ไข<ชนิด>")

#### `private itemsFromBooking(array $booking): array`
- **ทำอะไร**: สร้างรายการเอกสารจาก `rate_breakdown`: บรรทัดค่าเช่า (`rental`), บริการเสริม (`addons`) และค่าใช้จ่ายเพิ่มตอนคืนรถ (`Booking::extraLines($booking,true)`) — ยอด extra เป็นยอดรวม VAT แล้ว ถ้าการจองคิด VAT จะถอด VAT ออก (`amount/(1+rate/100)`) เพื่อไม่ให้เอกสารบวก VAT ซ้ำ

#### `private save(?array $doc, string $type, int $bookingId): Response`
- **ทำอะไร**: อ่านอาร์เรย์ `desc[] qty[] price[] unit[]` ข้ามบรรทัดที่รายละเอียดว่าง; `amount = qty*price` ปัดสองตำแหน่ง; ไม่มีรายการ → "กรุณาใส่รายการอย่างน้อย 1 บรรทัด"; คำนวณ `subtotal`, `net = max(0, subtotal - discount)`, `vat = net*vat_rate/100`, `wht = net*wht_rate/100`, `grand = net + vat - wht`; บันทึก `doc_type, booking_id, customer_id, issue_date, due_date, bill_name, bill_tax_id, bill_address, items(JSON), ยอดต่างๆ, note`; แก้ไข → `Document::updateById` + log `document_updated`; ใหม่ → `doc_no = Document::nextNumber(type)`, status `draft`, `created_by` + insert + log `document_created`; สำเร็จ redirect `admin/documents/{id}/print`; error → flash แล้วแสดงฟอร์มพร้อมค่าที่กรอก
- หมายเหตุ: `booking_id`/`customer_id` ที่ส่งมาไม่ถูกตรวจว่ามีจริงก่อนบันทึก

---

## app/Controllers/Admin/ReportController.php

- **หน้าที่ของไฟล์**: รายงานรายได้ อัตราการใช้รถ ต้นทุนบำรุงรักษา กำไรต่อคัน และ export CSV (เจ้าของร้านเท่านั้น) URL: `GET /admin/reports` (`?export=csv`) · Models: `Report`, `Payment`, `Maintenance`

### class ReportController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**: `from`/`to` (default ต้น–ท้ายเดือนนี้; parse ไม่ได้ → ค่า default; ถ้า to < from สลับกัน); `days` = จำนวนวันในช่วง; `perCar = Report::performancePerCar`; ทับ `rent_days` ด้วย `Report::daysSoldByCar` (ตัวเลขตรงกับหน้าภาพรวมและรายงานรายเดือน); `costByCar = Maintenance::costByCar`; ถ้า `export=csv` → `exportCsv(...)`; ไม่เช่นนั้นคำนวณ `revenue`, `cost`, `rentDays`, `carCount`, `jobs`, `util` = rentDays/(รถ×วัน)×100, `collected = Payment::revenueCollected`, `avgTicket`, ข้อมูลกราฟรายวัน (`Payment::dailyRevenue`), `byType`, `bySource` (`Report::byCarType/bySourcePickup`), `srcLabel`
- **View**: `admin/reports/index`

#### `private exportCsv(array $perCar, array $costByCar, string $from, string $to, int $days): Response`
- **ทำอะไร**: สร้างแถว: ทะเบียน, ยี่ห้อ+รุ่น, จำนวนงาน, วันเช่า, อัตราใช้รถ %, รายได้, ค่าบำรุงรักษา, กำไรขั้นต้น, รายได้เฉลี่ย/วันที่ปล่อยเช่า → `$this->csv('report_<from>_<to>.csv', หัวภาษาไทย, rows)`

---

## app/Controllers/Admin/MonthlyReportController.php

- **หน้าที่ของไฟล์**: รายงานผลประกอบการรายเดือน (เทียบเดือนก่อน, รถทำเงินดี–ด้อย, ข้อสังเกตอัตโนมัติ, ช่องข้อเสนอแนะ) พิมพ์ PDF ได้ URL: `GET/POST /admin/monthly-report` (เจ้าของร้านเท่านั้น) · Service: `MonthlyReportService`, `ActivityLog`

### class MonthlyReportController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**: `m = month()` → `MonthlyReportService::build($m)` ได้อาร์เรย์ข้อมูล; เปลี่ยนคีย์ `title` เป็น `monthTitle` (เพราะ `title` ใช้เป็นชื่อหน้า) → ส่ง view พร้อม `title='รายงานรายเดือน'`, `note` (setting `report_note_<m>`), `srcLabel`
- **View**: `admin/monthly_report`

#### `public saveNote(): Response`
- **ทำอะไร**: `set_setting('report_note_'.$m, note ≤3000)` + ActivityLog `monthly_report_note` (detail = เดือน) → `done('admin/monthly-report?m=..','ok','บันทึกข้อเสนอแนะประจำเดือนแล้ว')`
- **รับเข้า**: `m` (ตรวจ) และ `note` (หมายเหตุ: `month()` อ่านจาก request เดียวกัน ฟอร์มต้องส่ง `m`)

#### `private month(): string`
- **ทำอะไร**: อ่าน `m` ตรวจ `YYYY-MM`; ไม่ถูกต้อง/ไม่มี → เดือนที่แล้ว (`first day of last month`)

---

## app/Controllers/Admin/AbReportController.php

- **หน้าที่ของไฟล์**: เทียบผล UX สองเวอร์ชัน (A/B) โดยต่อฐานข้อมูลอีกฝั่งเพิ่มอีกหนึ่ง connection แล้วเทียบ funnel URL: `GET /admin/ab-report` (เจ้าของร้านเท่านั้น) · Model: `AbEvent`; settings: `ab_other_db`, `ab_other_user`, `ab_other_pass`

### class AbReportController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**: อ่าน `from` (default 29 วันก่อน), `to` (default วันนี้), `device` (`all|mobile|desktop`, ไม่ถูกต้อง = all); `mine = AbEvent::funnel(from,to,device)`; หาฐานข้อมูลอีกฝั่ง: `ab_other_db` (default `carrent2` ถ้า `DB_NAME==='carrent'` มิฉะนั้น `carrent`), user/pass ว่างใช้ `DB_USER/DB_PASS`; ถ้าชื่อไม่ว่างและไม่ซ้ำกับ DB ปัจจุบัน สร้าง `PDO` ใหม่ (ERRMODE_EXCEPTION, FETCH_ASSOC) แล้วเรียก `AbEvent::funnel(..., $pdo)`; ถ้าต่อไม่ได้เก็บข้อความ error ไว้ใน `otherError`; ระบุเวอร์ชันของตัวเองจากค่าคงที่ `SITE_VARIANT` (ไม่ตั้ง = A) เพื่อจัด `A`/`B`
- **ส่ง**: `from,to,device,A,B,labelA,labelB,steps (AbEvent::STEPS),otherDb,otherError` · **View**: `admin/ab_report`
- หมายเหตุ: `otherError` เป็น `getMessage()` ของ PDO ซึ่งอาจมีชื่อโฮสต์/ผู้ใช้ DB แสดงให้เจ้าของร้านเห็น (ไม่ผ่าน publicError); รหัสผ่าน DB ฝั่งอื่นเก็บเป็นข้อความธรรมดาใน settings; วันที่ `from`/`to` ไม่ผ่านการ validate ในชั้นนี้

---

## app/Controllers/Admin/LineController.php

- **หน้าที่ของไฟล์**: เชื่อมต่อ LINE Official Account (token/secret/LIFF, ผู้รับแจ้งเตือน, ทดสอบ, แก้ข้อความ template, ติดตั้ง Rich Menu) URL: `GET/POST /admin/line` (เจ้าของร้านเท่านั้น)
- Models/Services: `Booking`, `LineEvent`, `Notification`, `ActivityLog`, `LineService`, `Database`

### class LineController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**:
  1. ถ้า setting `site_url` ว่างและมี host → `set_setting('site_url', site_url())` (จำที่อยู่เว็บไว้ให้ cron — เขียน setting ในคำขอ GET)
  2. อ่านสถานะ: มี token/secret, `line_liff_id`, รายชื่อ admin LINE id (แยกด้วย space , ;), เปิดแจ้งเตือน (`notify_line_enabled`), `webhookUrl = site_url('line/webhook')`
  3. สร้างรายการขั้นตอนตั้งค่า 6 ข้อ (`steps`) และนับที่ทำแล้ว
  4. ข้อความตัวอย่าง: ใช้การจองล่าสุด (`SELECT * FROM bookings ORDER BY id DESC LIMIT 1` ผ่าน `Database::one`) หรือข้อมูลสมมติ → `LineService::bookingVars`
  5. สร้าง `tplView` สำหรับทุก template ใน `LineService::templates()`: label, when, custom หรือไม่, ข้อความปัจจุบัน (custom หรือ default), เปิด/ปิด (`!templateOff`), พรีวิวที่เติมตัวแปรแล้ว (`LineService::fill`)
  6. สถิติ: `linked` (`Booking::linkedLineAccounts`), `sentMonth`, `failed7`, คนที่คุยล่าสุด `LineEvent::latestPeople(12)`, log ล่าสุด `Notification::recentLine(12)`, `tplVars`
- **View**: `admin/line`

#### `public update(): Response`
- **ทำอะไร**: switch ตาม `act`:
  - `save`: เก็บ `line_channel_token`/`line_channel_secret` เฉพาะที่ไม่ว่าง (ว่าง = คงค่าเดิม); `line_liff_id`; `line_admin_user_id` (ทำความสะอาดแล้วต่อด้วย comma); `notify_line_enabled`, `line_notify_customer` (checkbox → 1/0); `site_url` (ตัด `/` ท้าย; ว่าง หรือขึ้นต้น http(s):// จึงบันทึก); log `line_settings_updated`
  - `test`: เรียก LINE API `GET /v2/bot/info`; สำเร็จแสดงชื่อบัญชี + โควตา/ยอดใช้เดือนนี้ (ถ้าดึงได้); ล้มเหลว danger พร้อมสาเหตุ
  - `test_push`: `LineService::pushTo(line_admin_user_id, ข้อความทดสอบ)` → flash ok/danger
  - `add_admin` / `remove_admin`: ตรวจ `uid` ตรง `^[UCR][0-9a-f]{32}$` แล้วเพิ่ม/เอาออกจากรายการผู้รับแจ้งเตือน, log `line_admin_added|removed`; ไม่ตรง → danger "LINE user id ไม่ถูกรูปแบบ"
  - `tpl_save`: วนทุก template: ข้อความ (`tpl[key]`) ที่ว่างหรือเท่ากับค่า default จะเก็บเป็นค่าว่าง (เพื่อได้ default ใหม่เมื่ออัปเดตระบบ); `on[key]` ไม่ติ๊ก = ปิด รวมเป็น `tpl_off`; log `line_templates_updated`
  - `tpl_reset`: ตั้ง `tpl_<key>` เป็นว่าง (ถ้า key ถูกต้อง) flash ok
  - `richmenu`: `LineService::installRichMenu()` → log `line_richmenu` + flash
  - redirect `admin/line` (ต่อท้าย `#templates` เมื่อเป็น tpl_save/tpl_reset)
- สังเกต: act ที่ไม่รู้จักจะ redirect เฉยๆ ไม่มีข้อความ

---

## app/Controllers/Admin/SettingController.php

- **หน้าที่ของไฟล์**: ตั้งค่าระบบ: ข้อมูลร้าน สี บัญชีรับโอน เงื่อนไขเช่า (ค่าปรับ/ระยะเวลา/ค่าส่ง) และแจ้งเตือน (SMTP) URL: `GET/POST /admin/settings` (เจ้าของร้านเท่านั้น)
- ค่าคงที่: `KEYS` (whitelist คีย์ที่แก้ได้), `CHECKBOXES = [vat_enabled, notify_email_enabled]`, `COLORS = [brand_primary, brand_accent, brand_dark]`
- Models/Services: `ActivityLog`, `Notification`, `NotificationService`, `DeliveryFeeService`

### class SettingController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**: ส่ง `s = settings()` (ทุกค่า) และ `tiersText = DeliveryFeeService::tiersText()` · **View**: `admin/settings/index`

#### `public update(): Response`
- **ทำอะไร**:
  - ถ้า `act = test_notify`: สร้างข้อความทดสอบ → `NotificationService::queue('line', line_admin_user_id, ...)` และ `queue('email', notify_admin_email, ...)` → `flush(5)` → สำเร็จ (n>0) flash ok "ส่งทดสอบสำเร็จ n ข้อความ" ไม่เช่นนั้น warn พร้อมสาเหตุ `Notification::lastFailedError()`; redirect โดย **ไม่บันทึกค่าตั้งค่าอื่น**
  - ไม่เช่นนั้น วนทุกคีย์ใน `KEYS` (เฉพาะ whitelist จึงกัน mass-assignment): checkbox → `1`/`0` ตามว่าถูกส่งมาหรือไม่; คีย์อื่นบันทึกเมื่อมีใน POST (trim); `smtp_pass` ว่าง = ไม่แก้; สี (`COLORS`) ต้องเป็น `#RRGGBB` ไม่งั้นข้าม (ค่านี้ถูกฝังใน CSS ทุกหน้า) → ActivityLog `settings_updated` → flash ok "บันทึกการตั้งค่าแล้ว"
- หมายเหตุ: ค่าตัวเลข (เช่น `late_fee_per_hour`, `vat_rate`) ไม่ได้ validate ชนิดในชั้นนี้; `smtp_pass` เก็บเป็นข้อความธรรมดา และถูกส่งกลับไปให้ view ผ่าน `settings()` (view จะแสดงหรือไม่ขึ้นกับ view)

---

## app/Controllers/Admin/UserController.php

- **หน้าที่ของไฟล์**: จัดการผู้ใช้หลังบ้านและดูประวัติการใช้งาน (เจ้าของร้านเท่านั้น) URL: `GET /admin/users`, `POST /users/save|delete` · Models: `User`, `ActivityLog`

### class UserController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**: `edit` (id ที่จะแก้) → `User::find`; ส่ง `me = auth_user()`, `users = User::all()`, `logs = ActivityLog::recent(40)` · **View**: `admin/users/index`

#### `public save(): Response`
- **ทำอะไร**: อ่าน `id` (0 = เพิ่ม), `email` (≤150), `name`, `password`, `role`, `phone`, `is_active`
  - ตรวจ: ชื่อไม่ว่าง + อีเมลถูกรูป (`FILTER_VALIDATE_EMAIL`) · อีเมลไม่ซ้ำ (`User::emailTaken(email, uid)`)
  - `role` = `admin` หรือ `staff` (อื่นๆ = staff)
  - รหัสผ่าน (ถ้ากรอก): ยาว ≥ 8 → `password_hash(PASSWORD_DEFAULT)`
  - **แก้ไข**: ถ้าแก้ตัวเอง บังคับ `is_active=1`, `role=admin` (กันปิด/ลดสิทธิ์ตัวเอง); ถ้าเป็นการลดสิทธิ์/ปิดเจ้าของร้านที่ใช้งานอยู่ แล้วไม่เหลือ admin ที่ active อื่น (`User::activeAdminCount($uid) < 1`) → "ต้องมีเจ้าของร้านที่ใช้งานได้อย่างน้อย 1 คน"; `User::updateById` + log `user_updated`
  - **เพิ่ม**: ต้องมีรหัสผ่าน ("กรุณากำหนดรหัสผ่านสำหรับผู้ใช้ใหม่"); `User::insert` + log `user_created`
  - error → flash danger; redirect `admin/users` เสมอ

#### `public delete(): Response`
- **ทำอะไร**: ลบตัวเองไม่ได้; ถ้าเป้าหมายเป็น admin และไม่เหลือ admin active อื่น → "ต้องมีผู้ดูแลระบบอย่างน้อย 1 คน"; `User::deleteById` + log `user_deleted` + flash `warn` "ลบผู้ใช้แล้ว"; ถ้า DB ปฏิเสธ (มีประวัติอ้างอิง) แสดง "ลบไม่สำเร็จ ผู้ใช้นี้มีประวัติการใช้งานที่อ้างอิงอยู่ ให้ปิดบัญชีแทน"
- หมายเหตุ: การเช็ก `activeAdminCount` ยังทำงานแม้เป้าหมายจะเป็น admin ที่ถูกปิดอยู่ (ทำให้ลบ admin ที่ปิดบัญชีอยู่ไม่ได้ถ้าเป็น admin เดียวที่เหลือ... ตามเงื่อนไขในโค้ดเฉพาะกรณีไม่มี admin active อื่นเลย)

---

## app/Controllers/Admin/BackupController.php

- **หน้าที่ของไฟล์**: สำรองข้อมูล — ดูรายการ สำรองทันที ดาวน์โหลด ตั้งจำนวนวันเก็บไฟล์ (เจ้าของร้านเท่านั้น) URL: `GET /admin/backups`, `GET /backups/download`, `POST /admin/backups`
- Services/Models: `BackupService`, `UploadService`, `ActivityLog`

### class BackupController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**: `BackupService::list()`; เวลาสำรองล่าสุด setting `backup_last_at` (คำนวณอายุเป็นชั่วโมง `ageH` ถ้ามี); `total` = ผลรวมขนาดไฟล์; `keep` = `max(7, backup_keep_days default 90)`; ขนาดและจำนวนไฟล์ในโฟลเดอร์อัปโหลด `UploadService::dirUsage(UPLOAD_DIR)`
- **View**: `admin/backups/index`

#### `public download(): Response`
- **ทำอะไร**: `BackupService::find(name)` (ค้นจากรายการที่มีจริง กัน path traversal) ไม่พบ → danger "ไม่พบไฟล์สำรองนี้" redirect; พบ → log `backup_downloaded` (detail = ชื่อ) → `Response::file(path)` ด้วย header attachment, `Content-Type` เป็น `application/gzip` (ถ้า `.gz`) หรือ `application/sql`, `no-store`

#### `public update(): Response`
- **ทำอะไร**: `act = create`: `BackupService::create()` → log `backup_created` → flash ok บอกจำนวนตาราง/แถว/ขนาด; ล้มเหลว flash danger "สำรองไม่สำเร็จ: <ข้อความ>" (แสดงข้อความ exception ตรงๆ — เจ้าของร้านเท่านั้น) · `act = keep`: `backup_keep_days` = ค่าที่กรอกบีบให้อยู่ใน 7–3650 (default 90) → `set_setting` (ไม่มี ActivityLog) → flash ok · redirect `admin/backups`

---

## app/Controllers/Admin/HealthController.php

- **หน้าที่ของไฟล์**: หน้า "สุขภาพระบบ" รายการตรวจสำหรับรายงานรายไตรมาส (พิมพ์เป็น PDF ได้) และเครื่องมือย้ายเอกสารลูกค้าเก่าเข้า private storage URL: `GET/POST /admin/health` (เจ้าของร้านเท่านั้น) — **ต่างจาก** `/health` สาธารณะซึ่งเป็นของ `App\Controllers\HealthController` อีกตัว
- Models/Services: `Customer`, `ActivityLog`, `HealthService`, `PrivateFileService`

### class HealthController (extends AdminController)

#### `public index(): Response`
- **ทำอะไร**: `PrivateFileService::protectLegacyDocs()`; นับเอกสารเก่า `Customer::legacyDocCount()` (ถ้าตารางไม่มีให้ข้าม = 0); จัดกลุ่มผลจาก `HealthService::checks()` ตามระดับ (index 1 ของแต่ละรายการ: `fail|warn|ok`) เรียง fail → warn → ok; `overall` = fail ถ้ามี fail, warn ถ้ามี warn, ไม่เช่นนั้น ok; ส่ง `pingUrl = site_url('health')` และ `key` (setting `health_key`)
- **View**: `admin/health/index`

#### `public migrateDocs(): Response`
- **ทำอะไร**: `PrivateFileService::migrateLegacyDocs(userId)` คืนสถิติ `moved/missing/failed` → log `documents_migrated` → flash (`warn` ถ้ามี failed ไม่เช่นนั้น `ok`) สรุปจำนวน; Throwable → danger "ย้ายเอกสารไม่สำเร็จ: ..." → redirect `admin/health`

---

## สรุปข้อสังเกต/ความไม่สอดคล้องที่พบ (ไม่ได้แก้โค้ด)

1. `AdminController::userId()` มี docblock `@param $params` ที่ไม่ตรงกับโค้ด (comment ตกค้าง)
2. `BookingActionService`: act `payment` และ `note` ไม่จำกัดสถานะ (บันทึกรับเงินกับการจองที่ยกเลิกได้); act `complete` ไม่ตรวจยอดค้างชำระ
3. `CalendarController::unblock` และ `DocumentController::status` และ `MaintenanceController::skip` ไม่เขียน ActivityLog (ต่างจาก action อื่น) และไม่ตรวจว่า id มีจริง; `block` ไม่ตรวจชนการจองเดิม
4. `PaymentController::approve/reject` เมื่อ service คืน false (เช่นสลิปถูกจัดการไปแล้ว) ไม่มี flash บอกผู้ใช้
5. `CarController::status` แปลงสถานะที่ไม่รู้จักเป็น `available` เงียบๆ; `MaintenanceController::add` ไม่ตรวจ `car_id`
6. `CarController::save` / `CustomerController::save` ไม่ใช้ transaction: ถ้าขั้นตอนหลัง (saveDueDates / addDocument) ล้มเหลวจะเหลือข้อมูลที่บันทึกไปบางส่วนแต่ผู้ใช้เห็น error
7. `AbReportController`: แสดง `getMessage()` ของ PDO (อาจเผยชื่อโฮสต์/ผู้ใช้ DB) และเก็บรหัสผ่าน DB ฝั่งอื่นใน settings แบบข้อความธรรมดา
8. `SettingController`: `smtp_pass` เก็บข้อความธรรมดาในตาราง settings; ค่าตัวเลขไม่ถูก validate
9. `LineController::index` เขียน setting (`site_url`) ระหว่างคำขอ GET
10. `logout` เป็น GET (ไม่มี CSRF)
11. `UserController::delete`: ตรวจ `activeAdminCount($uid) < 1` โดยไม่สนใจว่าเป้าหมายยัง active อยู่หรือไม่ — พฤติกรรมอาจไม่ตรงเจตนา (ควรตรวจเฉพาะเมื่อเป้าหมายเป็น admin ที่ active)
