# 02 — Models และ Middleware (อ้างอิงทุกคลาส/ทุกเมธอด)

เอกสารนี้อธิบาย `app/Models/*.php` (18 ไฟล์) และ `app/Middleware/*.php` (2 ไฟล์) สำหรับ junior developer ที่เพิ่งเริ่มกับ PHP MVC

## Model ในโปรเจกต์นี้คืออะไร

- **Model = ชั้นที่คุยกับฐานข้อมูล** Controller/Service ไม่เขียน SQL เอง แต่เรียก `Booking::search(...)`, `Car::findPublic(...)` ฯลฯ
- ทุกเมธอดเป็น **`public static`** (ไม่ต้อง `new`) และคืนค่าเป็น **array ธรรมดา** (associative array ต่อ 1 แถว) ไม่ใช่ object
- SQL ทั้งหมดรันผ่าน `App\Core\Database` ซึ่งใช้ **PDO prepared statements** (ใส่ค่าด้วย placeholder เช่น `:id` ไม่ต่อสตริง จึงกัน SQL injection)
  - `Database::one($sql,$p)` → 1 แถว หรือ `null`
  - `Database::all($sql,$p)` → array ของแถว
  - `Database::value($sql,$p)` → ค่าเดียว (คอลัมน์แรกของแถวแรก; ไม่พบ = `false`)
  - `Database::exec($sql,$p)` → รัน INSERT/UPDATE/DELETE คืนจำนวนแถวที่กระทบ (int)
  - `Database::insert($table,$data)` → คืน id ที่เพิ่ง insert ; `Database::update($table,$data,$col,$val)` → จำนวนแถว
- ส่วนที่ต่อตัวเลข `LIMIT` ลงใน SQL ตรงๆ (เช่น `LIMIT $limit`) ทุกที่จะ **clamp ด้วย `max(1, min(N, $limit))`** ก่อน เพื่อให้เป็นจำนวนเต็มปลอดภัยและไม่ให้ขอแถวมากเกินไป
- **คลาสแม่ `App\Core\Model`** (abstract) ให้เมธอดพื้นฐานที่ลูกทุกตัวที่ `extends Model` ได้ฟรี โดยใช้ `static::$table` และ `static::$primaryKey` (ค่าเริ่มต้น `'id'`):
  - `find(int $id): ?array` — SELECT * ตาม primary key
  - `insert(array $data): int` — INSERT แล้วคืน id
  - `updateById(int $id, array $data): int` — UPDATE ตาม primary key คืนจำนวนแถว
  - `deleteById(int $id): int` — DELETE ตาม primary key
  - `count(): int` — `COUNT(*)` ทั้งตาราง
- มี 2 คลาสที่ **ไม่** extends Model เพราะไม่ได้ทำ CRUD ตารางเดียวแบบมาตรฐาน: `Report` (อ่านอย่างเดียว), `Sequence` และ `Setting` (ใช้ SQL เฉพาะ)
- ฟังก์ชันช่วยที่ Model เรียกใช้จาก `Helpers/functions.php`: `phone_digits()`, `phone_sql()`, `phone_match()` (เทียบเบอร์โทรแบบตัวเลขล้วน)

### ตาราง Model → Table

| Model | Table (schema.sql) | หมายเหตุ |
|---|---|---|
| AbEvent | `ab_events` | สถิติกรวย A/B test |
| ActivityLog | `activity_logs` | audit log + นับความพยายามล้มเหลว |
| Booking | `bookings` (JOIN `cars`, `payments`, `car_blocks`) | ตารางหลักของระบบ |
| Car | `cars`, VIEW `v_car_compliance` | |
| CarBlock | `car_blocks` | ช่วงปิดรถ |
| CarType | `car_types` (PK = `code`) | |
| Customer | `customers`, `customer_documents` | |
| Document | `documents` | ใบเสนอราคา/ใบแจ้งหนี้/ใบเสร็จ/สัญญา |
| Inspection | `inspections`, `inspection_photos` | |
| LineEvent | `line_events` | |
| Maintenance | `maintenances` | |
| Notification | `notifications` | คิวแจ้งเตือน |
| Payment | `payments` | |
| Report | `bookings`, `cars` (อ่านอย่างเดียว) | ไม่ extends Model |
| Sequence | `sequences` (PK = `name`,`period`) | ไม่ extends Model |
| Setting | `settings` (`skey`,`svalue`) | ไม่ extends Model |
| Signature | `signatures` | |
| User | `users` | |

### ความสัมพันธ์หลัก (Foreign key)
`bookings.car_id → cars.id` (RESTRICT), `bookings.customer_id → customers.id` (SET NULL), `payments.booking_id → bookings.id`, `documents.booking_id/customer_id`, `inspections.booking_id` (UNIQUE booking_id+kind), `inspection_photos.inspection_id`, `signatures.booking_id`, `car_blocks.car_id` และ `maintenances.car_id` (CASCADE), `cars.car_type → car_types.code`, `customer_documents.customer_id` (CASCADE). `*_by` ต่างๆ ชี้ `users.id` (SET NULL).

---
# ส่วนที่ 1 — Middleware

Middleware คือคลาสที่ Router (`app/Core/Router.php` บรรทัด ~118) สร้างด้วย `new $class()` แล้วเรียก `handle($request)` **ก่อน** เข้า Controller: ถ้าคืน `null` = ผ่านไปต่อ ถ้าคืน `Response` = หยุดและส่ง Response นั้นออกไปเลย (เช่น redirect) ผูกกับ route ใน `app/routes.php` ด้วย `['middleware' => [...]]`

## app/Middleware/AuthMiddleware.php
- **หน้าที่ของไฟล์**: ด่านตรวจว่า "ล็อกอินหลังบ้านแล้ว" (admin หรือ staff) ใช้กับ route group `/admin` ทั้งหมดยกเว้น login/logout (`routes.php` บรรทัด 92)

### class AuthMiddleware (final)
- บทบาท: ไม่มี constant; ไม่มี state

#### `public handle(Request $request): ?Response`
- **ทำอะไร**: เรียก `AuthService::check()`; ถ้าล็อกอินอยู่ → คืน `null` (ผ่าน) ถ้าไม่ → ใส่ flash message ระดับ `warn` ว่า "กรุณาเข้าสู่ระบบก่อนใช้งาน" แล้ว redirect ไป `admin/login`
- **รับเข้า**: `$request` — Request ปัจจุบัน (ไม่ได้ใช้ในเมธอดนี้)
- **คืนค่า**: `null` = ปล่อยผ่าน, `Response` (redirect) = ถูกบล็อก
- **เรียกใช้โดย**: `Router` (dispatch), ลงทะเบียนที่ `app/routes.php:92`

## app/Middleware/AdminMiddleware.php
- **หน้าที่ของไฟล์**: ด่านที่สองสำหรับหน้า "เฉพาะเจ้าของร้าน" (role = admin) — ต้องซ้อนอยู่ใน group ของ AuthMiddleware (ใช้ต่อกัน) ผูกที่ `routes.php:150` (รายงาน ฯลฯ)

### class AdminMiddleware (final)

#### `public handle(Request $request): ?Response`
- **ทำอะไร**: เรียก `AuthService::isAdmin()`; เป็น admin → `null`; ไม่ใช่ (เป็น staff) → flash `warn` "หน้านี้สำหรับเจ้าของร้านเท่านั้น" แล้ว redirect ไป `admin/dashboard` (ไม่แสดงหน้าขาว)
- **รับเข้า**: `$request` (ไม่ได้ใช้)
- **คืนค่า**: `null` หรือ `Response` redirect
- **เรียกใช้โดย**: `Router`, `app/routes.php:150`
- หมายเหตุ: เมธอดนี้ไม่ตรวจว่าล็อกอินแล้วหรือยัง — พึ่งพา AuthMiddleware ที่ครอบอยู่ภายนอก

---
# ส่วนที่ 2 — Models

## app/Models/AbEvent.php
- **หน้าที่ของไฟล์**: อ่านสถิติกรวยการจอง (A/B test ดีไซน์) จากตาราง `ab_events` (คอลัมน์: `variant` A/B, `step`, `visitor_id` เลขสุ่มต่อเซสชัน, `car_id`, `device`, `created_at`) ไม่เก็บข้อมูลส่วนบุคคล ผู้เรียก: `AbReportController`

### class AbEvent extends Model
- table `ab_events`; `STEPS = ['view_home','view_car','start_form','submit','booked']` — ลำดับขั้นของกรวย

#### `public static funnel(string $from, string $to, string $device = 'all', ?PDO $pdo = null): array`
- **ทำอะไร**: นับ **จำนวนคนไม่ซ้ำ** (`COUNT(DISTINCT visitor_id)`) ต่อ step ในช่วงวันที่ `$from..$to` (เทียบ `DATE(created_at)`) กรอง `device` ถ้าไม่ใช่ `all` แล้วเติมค่า 0 ให้ step ที่ไม่มีข้อมูล ถ้าส่ง `$pdo` จะรันบนฐานข้อมูลนั้น (ใช้เทียบผลกับอีกเวอร์ชัน) ไม่งั้นใช้ Database ปกติ
- **รับเข้า**: `$from`,`$to` วันที่ `Y-m-d`; `$device` all|mobile|desktop; `$pdo` ตัวเลือก
- **คืนค่า**: array `[step => int]` ครบทุก step ใน `STEPS`
- **เรียกใช้โดย**: `Admin/AbReportController` (2 จุด)

## app/Models/ActivityLog.php
- **หน้าที่ของไฟล์**: บันทึกกิจกรรม (PDPA/ตรวจย้อนหลัง) ในตาราง `activity_logs` (`user_id`, `action`, `ref_table`, `ref_id`, `detail`, `ip`) และใช้ตารางเดียวกันนับความพยายามล้มเหลว (rate limit) โดยเก็บใน DB ไม่ใช่ session เพราะผู้โจมตีทิ้งคุกกี้ได้

### class ActivityLog extends Model
- table `activity_logs`; action ที่ถูกใช้นับ: `login_failed`, `liff_link_fail`, `booking_created`

#### `public static log(string $action, ?string $table = null, ?int $refId = null, $detail = null): void`
- **ทำอะไร**: INSERT 1 แถว โดยเติม `user_id` จาก `$_SESSION['user']['id']`, `ip` จาก `REMOTE_ADDR`; ถ้า `$detail` ไม่ใช่ string/null จะ `json_encode` (UNESCAPED_UNICODE) ครอบด้วย try/catch — **ล้มเหลวเงียบ** เพื่อไม่ให้ระบบหลักพัง
- **รับเข้า**: ชื่อ action, ตาราง/id อ้างอิงที่เกี่ยวข้อง, รายละเอียด
- **คืนค่า**: ไม่มี (void)
- **เรียกใช้โดย**: Controllers/Services จำนวนมาก (พบ ~58 จุด เช่น `Admin/CarController`, `CustomerController`, `BackupController`, `AuthService`, `WebBookingService`)

#### `public static loginFailures(string $email, string $ip, string $since): int`
- **ทำอะไร**: นับแถว `login_failed` ตั้งแต่ `$since` ที่ `detail` = อีเมลนี้ **หรือ** (`ip` ตรงและไม่ว่าง) — กันเดารหัสทั้งแบบหลายครั้งต่ออีเมลเดียว และหลายอีเมลจาก IP เดียว
- **คืนค่า**: int จำนวนครั้ง
- **เรียกใช้โดย**: `Services/AuthService`

#### `public static clearLoginFailures(string $email): void`
- **ทำอะไร**: DELETE แถว `login_failed` ของอีเมลนี้ (เรียกหลังล็อกอินสำเร็จ เพื่อรีเซ็ตตัวนับ)
- **เรียกใช้โดย**: `Services/AuthService`

#### `public static liffFailsByUser(string $uid): int`
- **ทำอะไร**: นับ `liff_link_fail` ที่ `detail` = LINE user id ภายใน 15 นาทีล่าสุด
- **เรียกใช้โดย**: `LiffController`

#### `public static liffFailsByIp(string $ip): int`
- **ทำอะไร**: นับ `liff_link_fail` ของ IP นี้ใน 15 นาทีล่าสุด (กันสลับบัญชี LINE หลายบัญชีจากเครื่องเดียว)
- **เรียกใช้โดย**: `LiffController`

#### `public static bookingsFromIpSince(string $ip, string $since): int`
- **ทำอะไร**: นับ `booking_created` จาก IP นี้ตั้งแต่ `$since` (ip ต้องไม่ว่าง) ใช้กันจองถี่ผิดปกติ
- **เรียกใช้โดย**: `Services/WebBookingService`

#### `public static recent(int $limit = 40): array`
- **ทำอะไร**: ดึงประวัติล่าสุดเรียง `id` จากใหม่ไปเก่า LEFT JOIN `users` เอา `name`; `$limit` clamp 1–500
- **คืนค่า**: array ของแถว (`activity_logs.*` + `name`)
- **เรียกใช้โดย**: `Admin/UserController`

## app/Models/Booking.php
- **หน้าที่ของไฟล์**: Model ใหญ่สุด ตาราง `bookings` (สถานะ `pending/confirmed/picked_up/returned/completed/cancelled`; เงิน: `grand_total`, `paid_amount`, `extra_charge`; เวลา: `pickup_at`,`return_at`; `source` web/admin/line/phone; `line_user_id`; `sign_token`; `extra_breakdown` JSON) JOIN `cars` เป็นหลัก ผู้เรียก: Controllers (admin+public), `AvailabilityService`, `CronService`, `LineService`, `SignatureService`

### class Booking extends Model
- `STATUSES` = ค่า status ทั้งหก ใช้ validate ใน `search()`

#### `public static findByNo(string $no): ?array`
- **ทำอะไร**: หาการจอง 1 แถวตาม `booking_no` (ไม่ JOIN)
- **คืนค่า**: แถวหรือ `null` — **เรียกใช้โดย**: `LiffController`, `LineWebhookController`

#### `public static findWithCar(int $id): ?array`
- **ทำอะไร**: การจอง + `brand, model, plate, color` และ `mileage AS car_mileage` ของรถ (INNER JOIN cars) สำหรับหน้างานหลังบ้าน
- **คืนค่า**: แถวหรือ `null` — **เรียกใช้โดย**: `Admin/BookingController`, `Admin/InspectionController`

#### `public static findByNoWithCar(string $no): ?array`
- **ทำอะไร**: เหมือนข้างบนแต่ค้นด้วย `booking_no` และ join แค่ `brand, model, plate, color` (หน้าลูกค้า)
- **เรียกใช้โดย**: `BookingController` (public), `TrackController`

#### `public static findByNoForContract(string $no): ?array`
- **ทำอะไร**: การจอง + ข้อมูลรถสำหรับเขียนสัญญา (`brand, model, plate, province, color, year, insurance_type`) ค้นด้วย `booking_no`
- **เรียกใช้โดย**: `SignController`

#### `public static findByIdForContract(int $id): ?array`
- **ทำอะไร**: เหมือนข้างบนแต่ค้นด้วย `id` — **เรียกใช้โดย**: `Admin/ContractController`

#### `public static findForDocument(int $id): ?array`
- **ทำอะไร**: การจอง + `brand, model, plate` สำหรับเอกสาร/ฟอร์มสร้างเอกสาร — **เรียกใช้โดย**: `Admin/DocumentController` (2)

#### `public static lock(int $id): void`
- **ทำอะไร**: `SELECT id ... FOR UPDATE` ล็อกแถวการจองจนจบ transaction เพื่อกันสองคำขอแก้การจองเดียวกันพร้อมกัน (ต้องเรียกภายใน transaction)
- **เรียกใช้โดย**: `SecurityDepositService`, `SignatureService`

#### `public static nextNumber(): string`
- **ทำอะไร**: สร้างเลขที่การจองรูป `BK` + `ymd` + `-` + เลขรันวันนี้ 3 หลัก โดยขอเลขจาก `Sequence::next('booking', $day)` (atomic ไม่ซ้ำแม้จองพร้อมกัน)
- **คืนค่า**: string เช่น `BK260919-001` — **เรียกใช้โดย**: `AdminBookingService`, `WebBookingService`

#### `public static recentDuplicate(string $no, int $carId, string $pickDb, string $retDb, string $sinceDb): ?array`
- **ทำอะไร**: หาการจองที่ `booking_no`, รถ, เวลารับ/คืนตรงกันและสร้างตั้งแต่ `$sinceDb` — ตรวจการกดยืนยันซ้ำ/กดย้อนกลับแล้วส่งใหม่
- **รับเข้า**: เวลารูปแบบ DB (`Y-m-d H:i:s`)
- **คืนค่า**: `['booking_no'=>...]` หรือ `null` — **เรียกใช้โดย**: `BookingController` (public)

#### `public static openUnpaidCountByPhone(string $phone): int`
- **ทำอะไร**: นับการจอง `pending` ที่ `paid_amount <= 0` ของเบอร์นี้ โดยคัดใน SQL ด้วย 7 ตัวท้ายของเบอร์ (`phone_sql(contact_phone) LIKE`) แล้วยืนยันเต็มเบอร์ใน PHP ด้วย `phone_match()` (เบอร์ใน DB มีหลายรูปแบบ)
- **คืนค่า**: int — **เรียกใช้โดย**: `WebBookingService` (จำกัดจองค้างจ่ายต่อเบอร์)

#### `public static conflictCount(int $carId, string $from, string $to, ?int $ignoreId = null): int`
- **ทำอะไร**: นับการจองของรถนี้ที่ status `pending/confirmed/picked_up` และเวลาทับกับช่วงที่ถาม (เงื่อนไขทับซ้อน `pickup_at < $to AND return_at > $from`); `$ignoreId` ใช้ข้ามการจองตัวเองตอนเช่าต่อ/แก้ไข
- **คืนค่า**: int (0 = ว่าง) — **เรียกใช้โดย**: `AvailabilityService`

#### `public static overdueOutCount(int $carId, ?int $ignoreId = null): int`
- **ทำอะไร**: นับการจอง `picked_up` ที่ `return_at < NOW()` ของรถนี้ = รถยังไม่คืนทั้งที่เลยกำหนด จึงยังไม่ว่างจริง
- **เรียกใช้โดย**: `AvailabilityService`

#### `public static busyRanges(int $carId, string $from, string $to): array`
- **ทำอะไร**: `UNION ALL` ช่วงที่รถไม่ว่าง 2 แหล่ง: การจอง (`kind='booking'`, `ref`=เลขการจอง) และช่วงปิดรถ `car_blocks` (`kind='block'`, `ref`=เหตุผล) เรียงตาม `start_at` ใช้วาดปฏิทิน (ใช้ placeholder แยกชื่อ `:c2,:f2,:t2` เพราะ PDO ไม่ให้ใช้ชื่อซ้ำ)
- **คืนค่า**: array ของ `{start_at,end_at,kind,ref}` — **เรียกใช้โดย**: `AvailabilityService`

#### `public static nextPickupAfter(int $carId, int $exceptId, string $afterAt): ?array`
- **ทำอะไร**: การจองถัดไป (`pickup_at >= $afterAt`) ของรถคันนี้ที่ไม่ใช่ `$exceptId` และ status ยัง active; ใช้บอกเหตุผลว่าเช่าต่อไม่ได้เพราะมีคิวถัดไป
- **คืนค่า**: `['pickup_at'=>...]` หรือ `null` — **เรียกใช้โดย**: `ExtendService`

#### `public static search(array $f, int $page, int $per = 25, bool $withSums = false): array`
- **ทำอะไร**: ค้นหาการจองหลังบ้านพร้อมแบ่งหน้า ตัวกรองใน `$f`: `q` (LIKE บน `booking_no`, `contact_name`, `contact_phone`, `c.plate`), `status` (ต้องอยู่ใน `STATUSES`), `car_id`, `from`/`to` (เทียบ `DATE(pickup_at)`) เรียง `pickup_at DESC, id DESC`; clamp `$page` ให้อยู่ใน 1..pages; ถ้า `$withSums` จะรวม `grand_total+extra_charge` และ `paid_amount` ของ **ทุกแถวที่ตรงเงื่อนไข (ไม่ใช่แค่หน้านี้)** โดยไม่นับ `cancelled` (เป็นข้อมูลที่เจ้าของร้านเห็นเท่านั้น)
- **คืนค่า**: `['rows','total','pages','page','sum_total','sum_paid']`
- **เรียกใช้โดย**: `Admin/BookingController`

#### `public static pickupsOn(string $date): array`
- **ทำอะไร**: การจองที่ `DATE(pickup_at)=$date` และ status `pending/confirmed` (ยังไม่รับรถ) + ข้อมูลรถ เรียงเวลารับ
- **เรียกใช้โดย**: `Admin/DashboardController`, `CronService`

#### `public static returnsOn(string $date): array`
- **ทำอะไร**: การจอง `picked_up` ที่ `DATE(return_at)=$date` + ข้อมูลรถ เรียงตามเวลาคืน — **เรียกใช้โดย**: `DashboardController`, `CronService`

#### `public static overdue(): array`
- **ทำอะไร**: `picked_up` และ `return_at < NOW()` + `brand, model, plate, price_day` เรียงตาม `return_at` — **เรียกใช้โดย**: `DashboardController`, `CronService`

#### `public static stalePending(): array`
- **ทำอะไร**: `pending` ที่สร้างเกิน 24 ชม. แต่ยังไม่ถึงเวลารับรถ (`pickup_at > NOW()`) + `plate` — **เรียกใช้โดย**: `CronService`

#### `public static unpaidExpired(string $createdBefore): array`
- **ทำอะไร**: คืนเฉพาะ `id, booking_no` ของการจอง `pending` จากเว็บ (`source='web'`) ที่ `paid_amount <= 0`, สร้างก่อน `$createdBefore` และ **ไม่มี** payment สถานะ `pending/approved` (`NOT EXISTS`) = ไม่มีสลิปรอตรวจ จึงยกเลิกอัตโนมัติได้
- **เรียกใช้โดย**: `CronService`

#### `public static cancelPending(int $id, string $reason): void`
- **ทำอะไร**: UPDATE status เป็น `cancelled` พร้อม `cancel_reason` **เฉพาะแถวที่ยัง `pending`** (กัน race: ถ้าเปลี่ยนสถานะไปแล้วจะไม่แตะ)
- **เรียกใช้โดย**: `CronService`

#### `public static confirmedPickupBetween(string $a, string $b): array`
- **ทำอะไร**: `confirmed` ที่ `DATE(pickup_at)` อยู่ระหว่าง a–b และยังไม่ถึงเวลา — ใช้เตือนลูกค้าก่อนรับรถ (คืน `b.*` ไม่ join) — **เรียกใช้โดย**: `CronService`

#### `public static pickedUpReturnBetween(string $a, string $b): array`
- **ทำอะไร**: `picked_up` ที่ `DATE(return_at)` ระหว่าง a–b และ `return_at > NOW()` — เตือนก่อนคืนรถ — **เรียกใช้โดย**: `CronService`

#### `public static countByStatus(string $status): int`
- **ทำอะไร**: นับการจองตาม status — **เรียกใช้โดย**: `DashboardController` (2), `AdminNavService`

#### `public static bookedValue(string $from, string $to): float`
- **ทำอะไร**: ผลรวม `grand_total + extra_charge` ของการจองที่ไม่ใช่ `cancelled` ซึ่ง `DATE(pickup_at)` อยู่ในช่วง (มูลค่างานตามวันรับรถ ไม่ใช่เงินที่รับจริง) — **เรียกใช้โดย**: `DashboardController`

#### `public static forCalendar(string $first, string $last): array`
- **ทำอะไร**: การจอง status `pending/confirmed/picked_up/returned` ที่ช่วงเวลาทับกับ `first..last` + `plate` เรียงด้วย `FIELD(status, ...)` (MySQL) ให้ลำดับการวาด
- **เรียกใช้โดย**: `Admin/CalendarController`

#### `public static setLineUser(int $id, ?string $uid): void`
- **ทำอะไร**: เซ็ต `line_user_id` ของการจอง (`null` = ล้าง) — **เรียกใช้โดย**: `LineService`

#### `public static lineUserOf(int $id): ?string`
- **ทำอะไร**: อ่าน `line_user_id` ของการจอง — string หรือ `null` ถ้าว่าง — **เรียกใช้โดย**: `LineService`

#### `public static unlinkLine(string $uid): void`
- **ทำอะไร**: ล้าง `line_user_id` ในทุกการจองที่ผูกกับ LINE uid นี้ — **เรียกใช้โดย**: `LineService`

#### `public static isLineLinked(string $uid): bool`
- **ทำอะไร**: true ถ้า uid ไม่ว่างและมีอย่างน้อย 1 การจองผูกอยู่ — **เรียกใช้โดย**: `LiffController` (2)

#### `public static linkedLineAccounts(): int`
- **ทำอะไร**: `COUNT(DISTINCT line_user_id)` ที่ไม่ใช่ NULL/ว่าง — **เรียกใช้โดย**: `Admin/LineController`, `MonthlyReportService`

#### `public static forLineUser(string $uid, int $limit = 12): array`
- **ทำอะไร**: การจองของ LINE uid นี้ + ข้อมูลรถ (`cover_image` ด้วย) เรียงให้ที่ยัง active (`pending/confirmed/picked_up`) ขึ้นก่อน แล้วตาม `pickup_at DESC`; `$limit` clamp 1–50
- **เรียกใช้โดย**: `LiffController`

#### `public static findOfLineUser(int $id, string $uid): ?array`
- **ทำอะไร**: ดึงการจอง id นี้ **เฉพาะเมื่อเป็นของ uid นี้** (กันดูของคนอื่น) — **เรียกใช้โดย**: `LiffController`

#### `public static signToken(int $id): string`
- **ทำอะไร**: คืน `sign_token` ของการจอง ถ้ายังไม่มีสร้างใหม่ด้วย `bin2hex(random_bytes(16))` (128 บิต) UPDATE เฉพาะเมื่อยังว่าง แล้วอ่านกลับจาก DB (ถ้ามีสองคำขอแย่งกันสร้าง จะได้ค่าเดียวกัน)
- **คืนค่า**: string 32 hex — **เรียกใช้โดย**: `SignatureService`

#### `public static extraLines(array $b, bool $chargedOnly = false): array`
- **ทำอะไร**: แปลง JSON `extra_breakdown` ของแถวการจองเป็นรายการค่าใช้จ่ายเพิ่มตอนคืนรถ (ข้ามรายการที่ไม่มี `label`); ถ้า `$chargedOnly` ตัดรายการ `waived` หรือ amount ≤ 0; ถ้าไม่มี breakdown แต่ `extra_charge > 0` (ข้อมูลก่อนเวอร์ชัน 5) สร้างบรรทัดเดียว "ค่าใช้จ่ายเพิ่มเติม" จาก `extra_note`
- **รับเข้า**: `$b` แถว booking ทั้งแถว (ไม่ query DB)
- **คืนค่า**: array ของ `{key,label,detail,amount,suggested,waived}`
- **เรียกใช้โดย**: `BookingController`/`ContractController`/`DocumentController` (admin), `LiffController`, `SignController`, `TrackController`, `SecurityDepositService`, `SignatureService` (3)

#### `public static placeType(?string $place): string`
- **ทำอะไร**: คืน `'airport'` ถ้าสถานที่ขึ้นต้น (หลัง trim) ด้วย `[สนามบิน]` ไม่งั้น `'shop'` — **เรียกใช้โดย**: `ExtendService`

#### `public static amountDue(array $b): float`
- **ทำอะไร**: `grand_total + extra_charge` — **เรียกใช้โดย**: `Booking::balance()` ภายในคลาสเท่านั้น (ไม่พบผู้เรียกภายนอก)

#### `public static balance(array $b): float`
- **ทำอะไร**: `amountDue - paid_amount` (ติดลบได้ถ้าจ่ายเกิน) — **เรียกใช้โดย**: `Views/public/track.php`

## app/Models/Car.php
- **หน้าที่ของไฟล์**: ตาราง `cars` (ราคา `price_day/week/month`, `deposit`, `deposit_booking`, `status` available/maintenance/inactive, `mileage`, `car_type` FK→`car_types.code`, `cover_image`, `sort_order`) และ VIEW `v_car_compliance` (วันหมดประกัน/พ.ร.บ./ภาษี/เช็กระยะ จาก `maintenances` ที่ `pending`)

### class Car extends Model

#### `public static findPublic(int $id): ?array`
- **ทำอะไร**: รถ 1 คันที่ `status <> 'inactive'` (หน้าเว็บลูกค้า) — **เรียกใช้โดย**: `ApiController`, `BookingController`, `CarController` (public)

#### `public static allPublic(?string $type = null): array`
- **ทำอะไร**: รถทั้งหมดที่ไม่ inactive, กรอง `car_type` ถ้าส่ง `$type`, เรียง `sort_order, price_day` — **เรียกใช้โดย**: `CarController`, `HomeController`

#### `public static all(): array`
- **ทำอะไร**: รถทุกคัน (หลังบ้าน) เรียง `sort_order, id` — **เรียกใช้โดย**: `Admin/CarController`

#### `public static active(): array`
- **ทำอะไร**: รถที่ไม่ inactive เรียง `sort_order` ใช้ทำ dropdown — **เรียกใช้โดย**: `Admin/BookingController`, `CalendarController`, `MaintenanceController`

#### `public static options(): array`
- **ทำอะไร**: เลือกเฉพาะ `id, brand, model, plate` ของ **ทุกคัน (รวม inactive)** ไว้ทำตัวกรอง — **เรียกใช้โดย**: `Admin/BookingController`

#### `public static complianceMap(): array`
- **ทำอะไร**: อ่านทุกแถวของ VIEW `v_car_compliance` แล้วทำ map ตาม `car_id`
- **คืนค่า**: `[car_id => row]` (row มี `insurance_expire, act_expire, tax_expire, next_service_at, next_service_km`) — **เรียกใช้โดย**: `Admin/CarController`

#### `public static compliance(int $id): array`
- **ทำอะไร**: เหมือนข้างบนแต่คันเดียว คืน `[]` ถ้าไม่พบ — **เรียกใช้โดย**: `Admin/CarController`

#### `public static activeCount(): int`
- **ทำอะไร**: นับรถที่ไม่ inactive — **เรียกใช้โดย**: `DashboardController`, `MonthlyReportService`

#### `public static minAvailablePrice(): float`
- **ทำอะไร**: `MIN(price_day)` ของรถ `available` (ใช้โชว์ "เริ่มต้น ฿xxx") ; ถ้าไม่มีรถ ได้ 0.0 — **เรียกใช้โดย**: `HomeController`

#### `public static hero(): ?array`
- **ทำอะไร**: เลือกรถภาพเด่นหน้าแรก: `available` และมี `cover_image` เรียง `sort_order` แล้วข้าม 2 คัน (`OFFSET 2` = คันที่ 3)
- **คืนค่า**: แถวหรือ `null` — **เรียกใช้โดย**: `HomeController`
- ข้อสังเกต: คอมเมนต์เขียนว่า "ถ้าไม่มีใช้คันแรก" แต่โค้ดไม่มี fallback — ถ้ามีรถที่เข้าเงื่อนไขน้อยกว่า 3 คัน จะได้ `null` (ผู้เรียกต้องจัดการ)

#### `public static status(int $id): ?string`
- **ทำอะไร**: อ่าน status ของรถ คืน `null` ถ้าไม่พบ — **เรียกใช้โดย**: `AvailabilityService`

#### `public static mileage(int $id): int`
- **ทำอะไร**: อ่านเลขไมล์ปัจจุบัน (ไม่พบ = 0) — **เรียกใช้โดย**: `Admin/InspectionController`

#### `public static setMileage(int $id, int $km): void`
- **ทำอะไร**: `updateById` ตั้ง `mileage` — **เรียกใช้โดย**: `BookingActionService`, `ReturnService`

#### `public static setStatus(int $id, string $status): void`
- **ทำอะไร**: `updateById` ตั้ง `status` (ไม่ตรวจค่า enum ใน Model) — **เรียกใช้โดย**: `Admin/CarController`

#### `public static lock(int $id): void`
- **ทำอะไร**: `SELECT ... FOR UPDATE` บนแถวรถ เพื่อให้คนที่จองรถคันเดียวกันพร้อมกันต้องรอคิว (ใช้ก่อนตรวจรถว่างภายใน transaction)
- **เรียกใช้โดย**: `ExtendService`, `WebBookingService`

#### `public static openBookingCount(int $id): int`
- **ทำอะไร**: นับการจอง `pending/confirmed/picked_up` ของรถ — กันปิดใช้งานรถที่ยังมีงานค้าง — **เรียกใช้โดย**: `Admin/CarController`

#### `public static activeRental(int $id): ?array`
- **ทำอะไร**: การจอง `picked_up` ของรถ (เรียงตาม `return_at` เอาอันแรก) คืนเฉพาะ `booking_no, return_at` — **เรียกใช้โดย**: `Admin/CarController`

#### `public static statusCounts(): array`
- **ทำอะไร**: `GROUP BY status` นับรถต่อสถานะ → array ของ `{status, n}` — **เรียกใช้โดย**: `DashboardController`

#### `public static sitemapRows(): array`
- **ทำอะไร**: `id, updated_at` ของรถที่ไม่ inactive สำหรับ sitemap.xml — **เรียกใช้โดย**: `SitemapController`

## app/Models/CarBlock.php
- **หน้าที่ของไฟล์**: ช่วงปิดรถ (ซ่อม/เจ้าของใช้เอง) ตาราง `car_blocks` (`car_id`, `start_at`, `end_at`, `reason`, CHECK `end_at > start_at`)

### class CarBlock extends Model

#### `public static inRange(string $firstDay, string $lastDay): array`
- **ทำอะไร**: ช่วงปิดรถที่ทับกับช่วงวันที่ (เทียบ `DATE(start_at) <= last AND DATE(end_at) >= first`) — **เรียกใช้โดย**: `Admin/CalendarController`

#### `public static upcoming(int $limit = 20): array`
- **ทำอะไร**: ช่วงปิดที่ `end_at >= NOW()` (ยังไม่สิ้นสุด) JOIN `cars` เอา `plate, brand, model` เรียง `start_at`; limit clamp 1–200 — **เรียกใช้โดย**: `CalendarController`

#### `public static overlapCount(int $carId, string $from, string $to): int`
- **ทำอะไร**: นับช่วงปิดของรถนี้ที่ทับกับ `from..to` — **เรียกใช้โดย**: `AvailabilityService`

## app/Models/CarType.php
- **หน้าที่ของไฟล์**: ประเภทรถ ตาราง `car_types` (`code` เป็น PK, `label_th`, `sort_order`, `is_active`) จัดการที่หลังบ้าน → ประเภทรถ

### class CarType extends Model
- `$primaryKey = 'code'` (override); `private static ?array $labels` แคช code→label ใน request เดียว
- **ข้อควรระวัง**: `find(int $id)`/`updateById(int ...)`/`deleteById(int ...)` จากคลาสแม่รับ `int` จึงใช้กับ PK ที่เป็น string ไม่ได้ — คลาสนี้เลยเขียนเมธอดของตัวเอง (`create/change/remove`)

#### `public static allWithUsage(): array`
- **ทำอะไร**: ทุกประเภท + `car_count` (LEFT JOIN cars, GROUP BY) เรียง `sort_order, code` — **เรียกใช้โดย**: `Admin/CarTypeController` (2)

#### `public static options(?string $includeCode = null): array`
- **ทำอะไร**: ทำ dropdown `[code => label_th]` เฉพาะ `is_active=1` แต่รวม `$includeCode` ด้วยแม้ปิดใช้งานแล้ว (เพื่อให้ฟอร์มแก้ไขรถที่ยังใช้ประเภทนั้นไม่หาย)
- **เรียกใช้โดย**: `Helpers/functions.php`

#### `public static label(string $code): string`
- **ทำอะไร**: คืนป้ายชื่อภาษาไทย โหลดทั้งตารางครั้งเดียวต่อ request (แคช static); ไม่พบ → คืน `$code` เดิม — **เรียกใช้โดย**: `Helpers/functions.php`

#### `public static create(string $code, string $label, int $sortOrder): void`
- **ทำอะไร**: INSERT ประเภทใหม่ (`is_active=1`) — **เรียกใช้โดย**: `Admin/CarTypeController`

#### `public static change(string $code, string $label, int $sortOrder, bool $active): void`
- **ทำอะไร**: UPDATE ชื่อ ลำดับ สถานะ (ไม่แก้ `code` เพราะรถอ้างถึงอยู่) — **เรียกใช้โดย**: `Admin/CarTypeController`

#### `public static remove(string $code): void`
- **ทำอะไร**: DELETE ตาม code (FK จาก cars ไม่มี CASCADE delete จึงลบประเภทที่ถูกใช้อยู่ไม่ได้ — Controller ควรเช็ก `usage()` ก่อน) — **เรียกใช้โดย**: `Admin/CarTypeController`

#### `public static exists(string $code): bool`
- **ทำอะไร**: ตรวจว่ามี code นี้ — **เรียกใช้โดย**: `Admin/CarController`, `CarTypeController`, `CarController` (public)

#### `public static usage(string $code): int`
- **ทำอะไร**: นับรถที่ใช้ประเภทนี้ — **เรียกใช้โดย**: `Admin/CarTypeController`

## app/Models/Customer.php
- **หน้าที่ของไฟล์**: ตาราง `customers` (`code` CUS-00001, `phone`, `is_blacklist`, `line_user_id`, ไฟล์เก่า `id_card_file`/`license_file`) และ `customer_documents` (เอกสารแนบในที่เก็บแบบปิด)

### class Customer extends Model

#### `public static byPhone(string $phone): ?array`
- **ทำอะไร**: หาลูกค้าเดิมจากเบอร์ — ถ้าตัวเลขน้อยกว่า 9 หลักคืน `null` ; คัดใน SQL ด้วย 7 ตัวท้าย (ตัดขีด/ช่องว่าง/วงเล็บด้วย `phone_sql`) เรียง `is_blacklist DESC, id` แล้วเทียบเต็มเบอร์ใน PHP ด้วย `phone_match` เรียก blacklist ขึ้นก่อนเพื่อให้ระเบียนซ้ำเจอตัวที่ถูกห้ามจองเสมอ
- **คืนค่า**: แถวลูกค้าหรือ `null` — **เรียกใช้โดย**: `AdminBookingService`, `WebBookingService`

#### `public static nextCode(): string`
- **ทำอะไร**: สร้างรหัส `CUS-` + เลข 5 หลักจาก `Sequence::next('customer')` ถ้ารหัสนั้นถูกใช้แล้ว (พิมพ์เองหรือนำเข้า) วนข้ามไปเลขถัดไป — **เรียกใช้โดย**: `Admin/CustomerController`, `Customer::create`

#### `public static create(array $data): int`
- **ทำอะไร**: INSERT ลูกค้า ถ้าไม่ระบุ `code` เติมจาก `nextCode()` — **คืนค่า**: id ใหม่ — **เรียกใช้โดย**: `AdminBookingService`, `WebBookingService`

#### `public static search(string $q, bool $onlyBlacklist, int $limit = 200): array`
- **ทำอะไร**: ค้นด้วย LIKE บน `full_name, phone, code, email, tax_id`; กรอง blacklist; แนบสถิติผ่าน subquery: `jobs` (จองไม่ยกเลิก), `spend`, `last_rent`, `docs` เรียง blacklist ก่อน → เช่าล่าสุด → id; limit clamp 1–1000
- **เรียกใช้โดย**: `Admin/CustomerController`

#### `public static totals(): array`
- **ทำอะไร**: ตัวเลขหัวหน้า: `total` (ใช้ `self::count()`), `blacklist`, `repeat` (ลูกค้าที่มีการจองไม่ยกเลิก > 1 ครั้ง)
- **คืนค่า**: `['total'=>int,'blacklist'=>int,'repeat'=>int]` — **เรียกใช้โดย**: `CustomerController`

#### `public static history(int $customerId, int $limit = 50): array`
- **ทำอะไร**: การจองของลูกค้า + `brand, model, plate` เรียงล่าสุดก่อน; limit 1–500 — **เรียกใช้โดย**: `CustomerController`

#### `public static spend(int $customerId): float`
- **ทำอะไร**: ผลรวม `grand_total+extra_charge` ที่ไม่ยกเลิก — **เรียกใช้โดย**: `CustomerController`

#### `public static documents(int $customerId): array`
- **ทำอะไร**: เอกสารแนบ `customer_documents` + `uploader` (ชื่อผู้ใช้) เรียงใหม่สุดก่อน — **เรียกใช้โดย**: `CustomerController`

#### `public static addDocument(array $data): int`
- **ทำอะไร**: INSERT เข้า `customer_documents` คืน id — **เรียกใช้โดย**: `CustomerController`, `PrivateFileService`

#### `public static findDocument(int $id): ?array`
- **ทำอะไร**: เอกสารแนบ 1 แถวตาม id — **เรียกใช้โดย**: `CustomerController`

#### `public static legacyFile(int $customerId, string $column): ?string`
- **ทำอะไร**: อ่านชื่อไฟล์เก่า ก่อนเวอร์ชัน 5 จากคอลัมน์ `id_card_file` หรือ `license_file` (whitelist กัน SQL injection เพราะชื่อคอลัมน์ใช้ placeholder ไม่ได้; ค่าอื่น → `null`)
- **เรียกใช้โดย**: `CustomerController`

#### `public static rememberLine(int $customerId, string $uid): void`
- **ทำอะไร**: เก็บ `line_user_id` ให้ลูกค้า **เฉพาะเมื่อยังว่าง** (ไม่เขียนทับ) — **เรียกใช้โดย**: `LineService`

#### `public static unlinkLine(string $uid): void`
- **ทำอะไร**: ล้าง `line_user_id` ของลูกค้าทุกคนที่มี uid นี้ — **เรียกใช้โดย**: `LineService`

#### `public static legacyDocCount(): int`
- **ทำอะไร**: นับจำนวนไฟล์เก่าที่ยังค้าง (บวกค่า boolean ของ 2 คอลัมน์ต่อแถว) — **เรียกใช้โดย**: `Admin/HealthController`, `HealthService`

#### `public static withLegacyFiles(): array`
- **ทำอะไร**: `id, id_card_file, license_file` ของลูกค้าที่มีไฟล์เก่าอย่างน้อยหนึ่งไฟล์ — **เรียกใช้โดย**: `PrivateFileService` (ย้ายเข้าที่เก็บปิด)

#### `public static clearLegacyFile(int $customerId, string $column): void`
- **ทำอะไร**: ตั้งคอลัมน์ไฟล์เก่าเป็น NULL (whitelist 2 คอลัมน์) — **เรียกใช้โดย**: `PrivateFileService`

## app/Models/Document.php
- **หน้าที่ของไฟล์**: ตาราง `documents` (`doc_type`, `doc_no` UNIQUE, `booking_id`, `customer_id`, `items` JSON, ยอดเงิน/VAT/หัก ณ ที่จ่าย, `status`)

### class Document extends Model
- `TYPES = quotation/invoice/receipt/contract`; `STATUSES = draft/sent/accepted/paid/void`

#### `public static nextNumber(string $type): string`
- **ทำอะไร**: สร้างเลข `<ตัวย่อ><ymm>-<รัน 4 หลัก>` (QT/IV/RC/CT; ชนิดที่ไม่รู้จักใช้ `DC`) เลขรันรีเซ็ตรายเดือนผ่าน `Sequence::next($type, 'ym')`
- **คืนค่า**: เช่น `QT2609-0001` — **เรียกใช้โดย**: `Admin/DocumentController`

#### `public static listByType(string $type, int $limit = 200): array`
- **ทำอะไร**: รายการเอกสาร (LEFT JOIN bookings/customers เอา `booking_no`, `full_name`) กรองชนิดถ้าอยู่ใน `TYPES` (ไม่งั้นทั้งหมด) เรียง id ใหม่สุด; limit 1–1000 — **เรียกใช้โดย**: `DocumentController`

#### `public static forBooking(int $bookingId): array`
- **ทำอะไร**: เอกสารทั้งหมดของการจอง — **เรียกใช้โดย**: `Admin/BookingController`

#### `public static pendingQuotationSum(): float`
- **ทำอะไร**: ผลรวม `grand_total` ของใบเสนอราคาสถานะ `sent`/`accepted` — **เรียกใช้โดย**: `DocumentController`

#### `public static setStatus(int $id, string $status): void`
- **ทำอะไร**: `updateById` ตั้ง status (ไม่ validate ค่าใน Model) — **เรียกใช้โดย**: `DocumentController`

## app/Models/Inspection.php
- **หน้าที่ของไฟล์**: ใบตรวจสภาพรถ `inspections` (`kind` out/in, UNIQUE `booking_id+kind`, `mileage`, `fuel_level` 0–8, `locked_at/locked_by`) และรูป `inspection_photos` (`position`, `superseded_at`)

### class Inspection extends Model

#### `public static get(int $bookingId, string $kind): ?array`
- **ทำอะไร**: ดึงใบตรวจ (join users เอาชื่อ `inspector`, `locker`) แล้วโหลดรูปทั้งหมด แบ่งเป็น `photos` (ยังไม่ถูกถ่ายแทน) กับ `history` (`superseded_at` ไม่ว่าง) — รูปเก่าไม่ลบเพื่อเป็นหลักฐาน
- **คืนค่า**: แถว inspection + คีย์ `photos`,`history` หรือ `null` — **เรียกใช้โดย**: `Admin/BookingController`, `ContractController`, `InspectionController`, `SignController`, `InspectionService`, `SignatureService`

#### `public static ensure(int $bookingId, string $kind, ?int $userId): int`
- **ทำอะไร**: คืน id ใบตรวจที่มีอยู่ ถ้ายังไม่มี INSERT ใหม่ (`inspected_at`=ตอนนี้) — **เรียกใช้โดย**: `InspectionController`, `BookingActionService`, `ReturnService`
- หมายเหตุ: select-then-insert ไม่อยู่ใน lock ของเมธอดนี้เอง ความปลอดภัยพึ่ง UNIQUE `uq_booking_kind` และการเรียกใน transaction ของ Service

#### `public static fillMissing(int $bookingId, string $kind, ?int $mileage, int $fuel): void`
- **ทำอะไร**: UPDATE `mileage`/`fuel_level` ด้วย `COALESCE(เดิม, ใหม่)` = เติมเฉพาะช่องว่าง และเฉพาะใบที่ยังไม่ล็อก — **เรียกใช้โดย**: `BookingActionService`, `ReturnService`

#### `public static lock(int $bookingId, string $kind, ?int $userId): bool`
- **ทำอะไร**: ล็อกใบตรวจ (ตั้ง `locked_at`,`locked_by`) ถ้ายังไม่เคยล็อก; ล็อกซ้ำไม่เปลี่ยนเวลาเดิม (เวลาแรกคือหลักฐาน)
- **คืนค่า**: `true` ถ้าเพิ่งล็อกครั้งนี้, `false` ถ้าไม่มีใบหรือล็อกแล้ว — **เรียกใช้โดย**: `InspectionService`

#### `public static isLockedRow(int $inspectionId): bool`
- **ทำอะไร**: ตรวจว่า `locked_at` มีค่าหรือไม่ — **เรียกใช้โดย**: `InspectionService`

#### `public static addPhoto(int $inspectionId, string $position, string $path, ?string $caption, ?int $userId): void`
- **ทำอะไร**: ทำเครื่องหมาย `superseded_at=NOW()` ให้รูปปัจจุบันของมุมเดียวกัน แล้ว INSERT รูปใหม่ (ไม่ลบรูปเก่า) — ไม่ได้ตรวจล็อกเอง (ผู้เรียกต้องเช็ก `isLockedRow`) และ 2 คำสั่งไม่ได้ห่อ transaction ในเมธอด
- **เรียกใช้โดย**: `InspectionService`

#### `public static stats(string $from, string $to): array`
- **ทำอะไร**: นับใบตรวจที่ `DATE(inspected_at)` ในช่วง: ทั้งหมด และที่ล็อกแล้ว
- **คืนค่า**: `[total, locked]` — **เรียกใช้โดย**: `MonthlyReportService`

## app/Models/LineEvent.php
- **หน้าที่ของไฟล์**: เหตุการณ์จาก LINE webhook ตาราง `line_events` (`event_type`, `line_user_id`, `display_name`, `text`)

### class LineEvent extends Model

#### `public static latestPeople(int $limit = 12): array`
- **ทำอะไร**: แถวล่าสุดของ **แต่ละคน** (join subquery `MAX(id)` ต่อ `line_user_id`) เรียงใหม่สุดก่อน; limit 1–100 — ใช้เลือกผู้รับแจ้งเตือนร้าน — **เรียกใช้โดย**: `Admin/LineController`

#### `public static purgeOld(): void`
- **ทำอะไร**: ลบแถวที่เก่ากว่า 30 วัน — **เรียกใช้โดย**: `CronService`

## app/Models/Maintenance.php
- **หน้าที่ของไฟล์**: งานประจำรถ ตาราง `maintenances` (`task_type`, `due_date`, `due_mileage`, `status` pending/done/skipped, `cost`, `notified_at`) — ข้อมูลนี้ป้อน VIEW `v_car_compliance`

### class Maintenance extends Model
- `TYPES = service, insurance, act, tax, tyre, repair, other`

#### `public static listByStatus(string $status, int $limit = 200): array`
- **ทำอะไร**: งาน + `plate, brand, model, mileage` ของรถ กรอง status (`all` = ไม่กรอง) เรียง pending ก่อน, ที่มี due_date ก่อน (NULL ไว้ท้าย) แล้วตาม due_date; limit 1–1000 — **เรียกใช้โดย**: `Admin/MaintenanceController`

#### `public static setPending(int $carId, string $type, string $title, ?string $dueDate, ?int $dueMileage): void`
- **ทำอะไร**: ตั้งกำหนดงานประเภทหนึ่งให้รถ: (1) ถ้า dueDate และ dueMileage เป็น null ทั้งคู่ → `skipped` งาน pending ของประเภทนั้นแล้วจบ; (2) มีงาน pending อยู่แล้ว → แก้ `due_date`/`due_mileage` ของอันแรก และถ้าวันที่เปลี่ยนจะล้าง `notified_at` ให้แจ้งเตือนใหม่ (`IF(due_date <=> :d1, notified_at, NULL)`); (3) ไม่มี → INSERT ใหม่ status pending
- **เรียกใช้โดย**: `Admin/CarController` (4 จุด: ประกัน/พ.ร.บ./ภาษี/เช็กระยะ)

#### `public static dueUntil(string $until, int $limit = 10): array`
- **ทำอะไร**: งาน pending ที่มี due_date ≤ `$until` + ข้อมูลรถ เรียง due_date; limit 1–200 — **เรียกใช้โดย**: `DashboardController`

#### `public static dueCount(string $until): int`
- **ทำอะไร**: นับงาน pending ที่ due_date ≤ until — **เรียกใช้โดย**: `AdminNavService`

#### `public static overdueCount(string $today): int`
- **ทำอะไร**: นับ pending ที่ `due_date < $today` — **เรียกใช้โดย**: `MaintenanceController`

#### `public static dueBetweenCount(string $from, string $to): int`
- **ทำอะไร**: นับ pending ที่ due_date อยู่ระหว่าง from–to — **เรียกใช้โดย**: `MaintenanceController`

#### `public static costForYear(string $year): float`
- **ทำอะไร**: ผลรวม `cost` ของงาน `done` ที่ `YEAR(done_date)=$year` — **เรียกใช้โดย**: `MaintenanceController`

#### `public static pendingExists(int $carId, string $type, string $due): bool`
- **ทำอะไร**: ตรวจว่ามีงาน pending ประเภท+วันครบกำหนดเดียวกันอยู่แล้วหรือไม่ (กันสร้างซ้ำ)
- **เรียกใช้โดย**: ไม่พบผู้เรียกใน `app/` หรือ `cron/` (อาจเป็นโค้ดที่ไม่ได้ใช้)

#### `public static needingReminder(string $until, string $today): array`
- **ทำอะไร**: งาน pending ที่ due_date ≤ until และยังไม่เคยแจ้งหรือแจ้งครั้งล่าสุดก่อนวันนี้ + ข้อมูลรถ — **เรียกใช้โดย**: `CronService`

#### `public static markNotified(int $id): void`
- **ทำอะไร**: ตั้ง `notified_at` = ตอนนี้ — **เรียกใช้โดย**: `CronService`

#### `public static skip(int $id): void`
- **ทำอะไร**: ตั้ง status `skipped` — **เรียกใช้โดย**: `MaintenanceController`

#### `public static costByCar(string $from, string $to): array`
- **ทำอะไร**: รวม `cost` ของงาน `done` ที่ `done_date` อยู่ในช่วง จัดกลุ่มตามรถ
- **คืนค่า**: `[car_id => float]` — **เรียกใช้โดย**: `Admin/ReportController`, `MonthlyReportService`
- หมายเหตุ: docblock ในโค้ดเขียนว่า "ผลตรวจสภาพรถ-ค่าบำรุงรักษา" ซึ่งไม่ตรงกับสิ่งที่ทำ (เป็นเพียงค่าบำรุงรักษา)

## app/Models/Notification.php
- **หน้าที่ของไฟล์**: คิวแจ้งเตือน (LINE/อีเมล) ตาราง `notifications` (`channel`, `tpl_key`, `recipient`, `message`, `ref_table/ref_id`, `status` queued/sent/failed, `error`, `sent_at`) Service ส่งจริง (`NotificationService`) ดึงจากคิวนี้

### class Notification extends Model

#### `public static queue(string $channel, ?string $recipient, string $message, ?string $subject = null, ?string $refTable = null, ?int $refId = null, ?string $tplKey = null): int`
- **ทำอะไร**: INSERT แถว status `queued`; ถ้า `$recipient` ยาวเกิน 190 ไบต์ (ผู้รับร้านหลายคนรวมกัน) เก็บเป็น NULL แล้วตอนส่งระบบใช้รายชื่อจากตั้งค่าเอง
- **คืนค่า**: id — **เรียกใช้โดย**: `LineService`, `NotificationService`

#### `public static queued(int $limit = 20): array`
- **ทำอะไร**: แถว `queued` เรียง id เก่าสุดก่อน; limit 1–500 — **เรียกใช้โดย**: `NotificationService`

#### `public static claim(int $id): bool`
- **ทำอะไร**: จองแถวก่อนส่งแบบ atomic: `UPDATE ... SET status='failed', error='กำลังส่ง' WHERE id AND status='queued'` — ถ้า cron กับหน้าเว็บส่งพร้อมกัน มีเพียงฝั่งเดียวที่ได้ (rowCount ≥ 1) ; ถ้าระบบล่มกลางทางแถวจะค้างเป็น failed "กำลังส่ง" ให้เห็นแทนที่จะหายเงียบ
- **คืนค่า**: `true` = เราได้สิทธิ์ส่ง — **เรียกใช้โดย**: `NotificationService`

#### `public static markResult(int $id, bool $ok, ?string $error): void`
- **ทำอะไร**: บันทึกผล: `sent` + `sent_at` หรือ `failed` + `error` (ตัด 250 ตัวอักษร) — **เรียกใช้โดย**: `NotificationService`

#### `public static alreadySent(string $tplKey, int $bookingId): bool`
- **ทำอะไร**: ตรวจว่าข้อความ template นี้ของการจองนี้ (`ref_table='bookings'`) อยู่ใน `queued` หรือ `sent` แล้วหรือไม่ — กันเตือนซ้ำ — **เรียกใช้โดย**: `LineService`

#### `public static releaseReminder(string $tplKey, int $bookingId): void`
- **ทำอะไร**: เปลี่ยน `tpl_key` เป็น `<key>_old` เพื่อให้ `alreadySent` เป็นเท็จและระบบเตือนใหม่ได้ (เช่น วันนัดเปลี่ยน) — **เรียกใช้โดย**: `AdminBookingService` (2), `ExtendService`

#### `public static latestFor(string $tplKey, int $bookingId): ?array`
- **ทำอะไร**: แถวล่าสุด (`status`,`error`) ของ template+การจองนี้ ใช้ตรวจผลส่งทันที — **เรียกใช้โดย**: `BookingActionService`

#### `public static lastFailedError(): ?string`
- **ทำอะไร**: ข้อความ error ของแถว failed ล่าสุด หรือ `null` — **เรียกใช้โดย**: `Admin/SettingController`

#### `public static retryRecentFailures(string $createdAfter): int`
- **ทำอะไร**: เปลี่ยน failed → queued เฉพาะที่สร้างหลัง `$createdAfter`, มี error, และไม่ใช่ "กำลังส่ง" / ขึ้นต้น "ปิดการใช้งาน" / "ไม่มี" (สาเหตุถาวร)
- **คืนค่า**: จำนวนแถวที่กลับเข้าคิว — **เรียกใช้โดย**: `CronService`

#### `public static recentLine(int $limit = 12): array`
- **ทำอะไร**: ข้อความช่อง `line` ล่าสุด limit 1–100 — **เรียกใช้โดย**: `Admin/LineController`

#### `public static lineSentSince(string $since): int`
- **ทำอะไร**: นับ LINE `sent` ตั้งแต่ `sent_at >= $since` — **เรียกใช้โดย**: `LineController`

#### `public static lineSentBetween(string $from, string $to): int`
- **ทำอะไร**: นับ LINE `sent` ที่ `DATE(sent_at)` ในช่วง — **เรียกใช้โดย**: `MonthlyReportService`

#### `public static lineFailedLast7Days(): int`
- **ทำอะไร**: นับ LINE `failed` ที่สร้างใน 7 วัน — **เรียกใช้โดย**: `LineController`

#### `public static failedLast7Days(): int`
- **ทำอะไร**: นับ failed ทุกช่องใน 7 วัน — **เรียกใช้โดย**: `HealthService`

#### `public static stuckQueued(): int`
- **ทำอะไร**: นับ `queued` ที่สร้างเกิน 1 ชม. (คิวค้าง) — **เรียกใช้โดย**: `HealthService`

## app/Models/Payment.php
- **หน้าที่ของไฟล์**: ตาราง `payments` (`kind` deposit/balance/security/extra/refund/security_refund, `method` transfer/cash/card/promptpay/deduct, `amount`, `status` pending/approved/rejected, `slip_file`, `approved_by/at`)

### class Payment extends Model
- `REVENUE_SQL` — นิพจน์ SQL กลางที่นับ "เงินที่เป็นรายได้": `refund` ติดลบ, `security`/`security_refund` = 0 (เงินประกันไม่ใช่รายได้), ที่เหลือบวก (รวมรายการหักจากเงินประกัน `method='deduct'`) **ใช้ที่เดียวกันทุกหน้า** เพื่อให้ตัวเลขตรงกัน

#### `public static forBooking(int $bookingId): array`
- **ทำอะไร**: payments ของการจอง + `approver` (ชื่อผู้อนุมัติ) เรียงใหม่สุด — **เรียกใช้โดย**: `Admin/BookingController`, `TrackController`

#### `public static pendingCountForBooking(int $bookingId): int`
- **ทำอะไร**: นับสลิป `pending` ของการจอง — **เรียกใช้โดย**: `LiffController`, `TrackController`

#### `public static recentForBooking(int $bookingId, int $limit = 8): array`
- **ทำอะไร**: `amount,status,paid_at,reject_reason,kind` ล่าสุด (หน้า LIFF); limit 1–50 — **เรียกใช้โดย**: `LiffController`

#### `public static listByStatus(string $status, int $limit = 200): array`
- **ทำอะไร**: รายการสลิป + ข้อมูลการจอง/รถ/ผู้อนุมัติ กรอง status (`all` = ไม่กรอง) เรียง pending ก่อนแล้วตาม id ใหม่สุด; limit 1–1000 — **เรียกใช้โดย**: `Admin/PaymentController`

#### `public static pendingCount(): int`
- **ทำอะไร**: นับสลิป pending ทั้งระบบ — **เรียกใช้โดย**: `DashboardController`, `PaymentController`, `AdminNavService`

#### `public static pendingSum(): float`
- **ทำอะไร**: ผลรวมยอด pending — **เรียกใช้โดย**: `PaymentController`

#### `public static approve(int $id, int $userId): void`
- **ทำอะไร**: ตั้ง `approved`, `approved_by`, `approved_at`=ตอนนี้ (ไม่อัปเดต `bookings.paid_amount` เอง — เป็นงานของ `PaymentService`) — **เรียกใช้โดย**: `PaymentService`

#### `public static reject(int $id, int $userId, string $reason): void`
- **ทำอะไร**: ตั้ง `rejected` พร้อมผู้ตัดสิน เวลา และ `reject_reason` — **เรียกใช้โดย**: `PaymentService`

#### `public static netPaid(int $bookingId): float`
- **ทำอะไร**: ผลรวม `REVENUE_SQL` ของ payments `approved` ของการจอง (ไม่รวมเงินประกัน หักคืนเงิน) — **เรียกใช้โดย**: `PaymentService`

#### `public static approvedRefundCount(int $bookingId): int`
- **ทำอะไร**: นับ payments `approved` ที่ `kind='refund'` — **เรียกใช้โดย**: `PaymentService`

#### `public static securityHeld(int $bookingId): float`
- **ทำอะไร**: เงินประกันที่ร้านยังถืออยู่ = `security` (+) − `security_refund` − รายการ `method='deduct'` (นับเฉพาะ approved) ปัดทศนิยม 2 ตำแหน่ง
- **เรียกใช้โดย**: `Admin/BookingController`, `PaymentService`, `SecurityDepositService`, `Views/public/sign.php`

#### `public static revenueCollected(string $from, string $to): float`
- **ทำอะไร**: รายได้รับจริงในช่วงวันที่ (ตาม `DATE(approved_at)` ไม่ใช่ `paid_at`) ใช้ `REVENUE_SQL` — **เรียกใช้โดย**: `DashboardController` (2), `PaymentController`, `ReportController`, `MonthlyReportService`

#### `public static dailyRevenue(string $from, string $to): array`
- **ทำอะไร**: รายได้รับจริงรายวัน `GROUP BY DATE(approved_at)` → array ของ `{d, amt}` — **เรียกใช้โดย**: `Admin/ReportController`

#### `public static averageApprovalHours(string $from, string $to): ?float`
- **ทำอะไร**: เฉลี่ยชั่วโมงจากสร้างถึงอนุมัติ (เฉพาะที่มี `slip_file`, approved ในช่วงวันที่); `null` ถ้าไม่มีข้อมูล — **เรียกใช้โดย**: `MonthlyReportService`

## app/Models/Report.php
- **หน้าที่ของไฟล์**: คำสั่งค้นอ่านอย่างเดียวสำหรับรายงาน (ภาพรวม/รายช่วง/รายเดือน) จาก `bookings` และ `cars` — **ไม่ extends Model**; "มูลค่า" ในที่นี้ = `grand_total + extra_charge` ของการจองที่ไม่ยกเลิก

### class Report (final)

#### `public static daysSoldByCar(string $from, string $to): array`
- **ทำอะไร**: ดึงการจอง status `confirmed/picked_up/returned/completed` ที่ทับช่วง แล้วคำนวณใน PHP: เอาส่วนที่ทับจริง (`max(start)..min(end)`) หารวินาทีด้วย 86400 = จำนวนวันแบบเศษส่วน (10:00 วันที่ 5 ถึง 10:00 วันที่ 8 = 3 วัน ไม่ใช่ 4) รวมต่อรถ
- **คืนค่า**: `[car_id => float วัน]` — **เรียกใช้โดย**: `DashboardController`, `ReportController`, `MonthlyReportService`

#### `public static revenuePerCar(string $from, string $to): array`
- **ทำอะไร**: รถที่ไม่ inactive LEFT JOIN การจองไม่ยกเลิกที่ `DATE(pickup_at)` ในช่วง รวม `revenue`, `jobs`, `days` (SUM `rental_days`) เรียง revenue มาก→น้อย — **เรียกใช้โดย**: `DashboardController`

#### `public static performancePerCar(string $from, string $to): array`
- **ทำอะไร**: คล้ายข้างบน เพิ่ม `mileage` และตั้งชื่อ `rent_days` แทน `days` — **เรียกใช้โดย**: `Admin/ReportController`

#### `public static byCarType(string $from, string $to): array`
- **ทำอะไร**: จำนวนงาน/รายได้ แยกตาม `cars.car_type` — **เรียกใช้โดย**: `ReportController`

#### `public static bySourcePickup(string $from, string $to): array`
- **ทำอะไร**: แยกตามช่องทาง `source` นับตามวันรับรถ คืน `{source,n,revenue}` — **เรียกใช้โดย**: `ReportController`

#### `public static bookingCreatedCounts(string $from, string $to): array`
- **ทำอะไร**: นับการจองที่สร้างในช่วง (`DATE(created_at)`) ทั้งหมด และที่ `cancelled`
- **คืนค่า**: `[ทั้งหมด, ยกเลิก]` — **เรียกใช้โดย**: `MonthlyReportService`

#### `public static jobsSummary(string $from, string $to): array`
- **ทำอะไร**: งานที่รับรถในช่วง ไม่ยกเลิก: `n` จำนวน, `v` มูลค่า, `x` ค่าใช้จ่ายเพิ่ม; ถ้าไม่มีแถวคืนค่า 0 ทั้งหมด — **เรียกใช้โดย**: `MonthlyReportService`

#### `public static monthlyPerCar(string $from, string $to): array`
- **ทำอะไร**: ต่อรถ: `jobs`, `revenue` (เพิ่ม `car_type`, `price_day`) ของรถที่ไม่ inactive — **เรียกใช้โดย**: `MonthlyReportService`

#### `public static bySourceCreated(string $from, string $to): array`
- **ทำอะไร**: นับตาม `source` ตามวันสร้างการจอง (ไม่ยกเลิก) — **เรียกใช้โดย**: `MonthlyReportService`

#### `public static newCustomers(string $from, string $to): int`
- **ทำอะไร**: จำนวนลูกค้า (DISTINCT) ที่มีการจองในช่วง และ **ไม่เคยมีการจองไม่ยกเลิกก่อนวันเริ่มช่วง** (`NOT EXISTS` ... `created_at < from 00:00:00`) — **เรียกใช้โดย**: `MonthlyReportService`

#### `public static allCustomers(string $from, string $to): int`
- **ทำอะไร**: จำนวนลูกค้า DISTINCT ที่จองในช่วง (ไม่ยกเลิก, มี customer_id) — **เรียกใช้โดย**: `MonthlyReportService`

#### `public static returnStats(string $from, string $to): array`
- **ทำอะไร**: นับการจองที่มี `returned_at` ในช่วง และที่คืนช้าเกิน 1 ชม. จาก `return_at`
- **คืนค่า**: `[คืนทั้งหมด, ช้า]` — **เรียกใช้โดย**: `MonthlyReportService`

#### `public static busiestPickupWeekday(string $from, string $to): ?array`
- **ทำอะไร**: วันในสัปดาห์ที่มีรับรถมากสุด (`DAYOFWEEK`: 1=อาทิตย์) → `{d, n}` หรือ `null` — **เรียกใช้โดย**: `MonthlyReportService`

## app/Models/Sequence.php
- **หน้าที่ของไฟล์**: ตัวนับเลขที่ ตาราง `sequences` (PK `name`+`period`, `last_no`) ออกแบบให้ atomic ไม่ซ้ำแม้คำขอพร้อมกัน — **ไม่ extends Model**

### class Sequence (final)

#### `public static next(string $name, string $period = ''): int`
- **ทำอะไร**: รัน `INSERT ... ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)` (ครั้งแรกได้ 1) แล้ว `SELECT LAST_INSERT_ID()` — `LAST_INSERT_ID(expr)` เก็บค่าไว้ใน connection นี้ จึงปลอดภัยต่อการแข่งกัน ไม่ต้องอ่านแล้วบวกเอง
- **รับเข้า**: `$name` เช่น `booking`/`customer`/`quotation`; `$period` ช่วงที่รีเซ็ตเลข (`260919` รายวัน, `2609` รายเดือน, `''` ไม่รีเซ็ต)
- **คืนค่า**: int เลขถัดไป — **เรียกใช้โดย**: `Booking::nextNumber`, `Customer::nextCode`, `Document::nextNumber`

## app/Models/Setting.php
- **หน้าที่ของไฟล์**: ตั้งค่าระบบ key/value ตาราง `settings` (`skey`,`svalue`) พร้อมแคช static ต่อ 1 request — **ไม่ extends Model** ผู้ใช้หลักผ่านฟังก์ชัน helper ใน `Helpers/functions.php`

### class Setting (final)
- `private static ?array $cache`

#### `public static all(): array`
- **ทำอะไร**: โหลดทั้งตารางครั้งแรก เก็บ `[skey => svalue]` ในแคช แล้วคืนจากแคช — **เรียกใช้โดย**: `Helpers/functions.php`

#### `public static get(string $key, $default = '')`
- **ทำอะไร**: อ่านค่า; ถ้าไม่มีคีย์ เป็น NULL หรือสตริงว่าง → คืน `$default` (สังเกต: ค่า `'0'` ถือว่ามีค่าและถูกคืน) — **เรียกใช้โดย**: `Helpers/functions.php`

#### `public static set(string $key, $value): void`
- **ทำอะไร**: UPSERT (`ON DUPLICATE KEY UPDATE`) เก็บเป็น string และอัปเดตแคชถ้าโหลดไว้แล้ว — **เรียกใช้โดย**: `Helpers/functions.php`
- หมายเหตุ: ถ้าแคชยังไม่ถูกโหลด (`null`) จะไม่เติมแคช — การ `get` ครั้งถัดไปค่อยโหลดค่าล่าสุดจาก DB

#### `public static flush(): void`
- **ทำอะไร**: ล้างแคช — ไม่พบผู้เรียกภายนอกในโค้ดปัจจุบัน

## app/Models/Signature.php
- **หน้าที่ของไฟล์**: ลายเซ็นอิเล็กทรอนิกส์ ตาราง `signatures` (`kind` contract/return, `signer_name`, `snapshot` + `snapshot_hash`, `ip`, `voided_at/by`, `void_reason`) — ไม่ลบ ขอเซ็นใหม่ = ทำเครื่องหมายยกเลิก (void) ฉบับเดิม

### class Signature extends Model

#### `public static active(int $bookingId, string $kind): ?array`
- **ทำอะไร**: ลายเซ็นล่าสุดที่ยังไม่ void (`voided_at IS NULL`) ของการจอง+ชนิด — **เรียกใช้โดย**: `BookingController` (2), `ContractController` (2), `SignController` (2) , `BookingActionService` (2), `SignatureService`

#### `public static voided(int $bookingId): array`
- **ทำอะไร**: ประวัติลายเซ็นที่ถูก void (+ `voider` ชื่อผู้ยกเลิก) ใหม่สุดก่อน — **เรียกใช้โดย**: `Admin/BookingController`

#### `public static void(int $id, int $userId, string $reason): bool`
- **ทำอะไร**: UPDATE ตั้ง `voided_at=NOW()`, `voided_by`, `void_reason` (ตัด 250 ตัวอักษร ด้วย `mb_substr`) เฉพาะที่ยังไม่ void
- **คืนค่า**: `true` ถ้ามีแถวถูกแก้ — **เรียกใช้โดย**: `BookingActionService`

## app/Models/User.php
- **หน้าที่ของไฟล์**: ผู้ใช้หลังบ้าน ตาราง `users` (`email` UNIQUE, `password_hash`, `role` admin/staff, `is_active`, `last_login_at`) — ผู้เรียกหลัก `AuthService`, `Admin/UserController`

### class User extends Model

#### `public static findActiveByEmail(string $email): ?array`
- **ทำอะไร**: หาผู้ใช้ตามอีเมลที่ `is_active=1` (คืนทั้งแถว รวม `password_hash` ให้ AuthService ตรวจรหัส) — **เรียกใช้โดย**: `AuthService`

#### `public static authState(int $id): ?array`
- **ทำอะไร**: อ่าน `name, role, is_active` ล่าสุดจาก DB ทุกครั้งที่ตรวจล็อกอิน เพื่อให้การปิดบัญชี/ลดสิทธิ์มีผลทันที ไม่ต้องรอ session หมดอายุ — **เรียกใช้โดย**: `AuthService`

#### `public static touchLogin(int $id): void`
- **ทำอะไร**: ตั้ง `last_login_at` = ตอนนี้ — **เรียกใช้โดย**: `AuthService`

#### `public static all(): array`
- **ทำอะไร**: ผู้ใช้ทั้งหมด (`SELECT *` รวม `password_hash`) เรียง role, name — **เรียกใช้โดย**: `Admin/UserController` (ควรระวังไม่ส่ง hash ไปที่ view)

#### `public static emailTaken(string $email, int $exceptId = 0): bool`
- **ทำอะไร**: true ถ้ามีผู้ใช้อื่น (id ≠ `$exceptId`) ใช้อีเมลนี้แล้ว — **เรียกใช้โดย**: `UserController`

#### `public static activeAdminCount(int $exceptId = 0): int`
- **ทำอะไร**: นับ admin ที่ active ไม่รวม `$exceptId` — กันการลด/ปิดบัญชี admin คนสุดท้าย — **เรียกใช้โดย**: `UserController` (2)

#### `public static roleAndStatus(int $id): ?array`
- **ทำอะไร**: อ่าน `role, is_active` ของผู้ใช้ — **เรียกใช้โดย**: `UserController` (2)
