# 05 — Routes, Helpers และ Controller ฝั่งสาธารณะ

เอกสารนี้อธิบาย `app/routes.php`, `app/Helpers/functions.php` และ controller ฝั่งลูกค้า/ระบบภายนอกทั้ง 13 ตัวใน `app/Controllers/*.php` (ไม่รวมโฟลเดอร์ `Admin/`) สำหรับ junior developer ที่เพิ่งเริ่มกับ PHP MVC

## 0. คำขอ (request) เดินทางถึง controller ได้อย่างไร

```
Browser ──► index.php ──► Request::capture()
                           │
                           ▼
              app/routes.php (return function (Router $r) {...})   ← ลงทะเบียนเส้นทางทั้งหมด
                           │
                           ▼
              Router::dispatch($request)
                1) วน $routes หา Route ที่ path ตรง (match) และ method อนุญาต (allows)
                   - path ตรงแต่ method ไม่ตรง → 405 "Method Not Allowed"
                   - ไม่เจอเลย → ErrorController::notFound() (404)
                2) Router::run():
                   a) postTooLarge()? ไฟล์ใหญ่เกิน post_max_size → ตอบ 413 (JSON) หรือ flash + redirect
                   b) ถ้า method ไม่ใช่ GET/HEAD/OPTIONS และ route ไม่ได้ noCsrf() → Csrf::valid()
                      ไม่ผ่าน = 419 "เซสชันหมดอายุ..."
                   c) รัน middleware ตามลำดับ (AuthMiddleware / AdminMiddleware) — ถ้าคืน Response ก็หยุดทันที
                   d) Router::call(): new Controller($request) แล้วเรียก method โดยส่งพารามิเตอร์จาก URL
                      เช่น /cars/{id:\d+} → CarController::show(int $id)
                   e) ถ้า controller โยน HttpException → ใช้ response ในนั้น
                3) ผลลัพธ์ที่เป็น Response ถูกส่งออก (ถ้าไม่ใช่ Response จะห่อเป็น HTML)
                           │
Controller ──► $this->view('public/xxx', $data)  → View::make() → app/Views/public/xxx.php → Response::html
          ├──► $this->redirect('path')            → Response::redirect (เติม BASE_URL ให้)
          └──► $this->json([...])                 → Response::json
```

ข้อควรรู้จากคลาสแม่ `App\Core\Controller`:

| method | หน้าที่ |
|---|---|
| `__construct(protected Request $request)` | ทุก controller ได้ `$this->request` อัตโนมัติ |
| `view($name, $data, $status)` | render ไฟล์ใต้ `app/Views/` แล้วห่อเป็น `Response::html` |
| `redirect($path, $status=302)` | path ภายในระบบ เช่น `cars/3` (ไม่ต้องใส่ BASE_URL) |
| `json($data, $status)` | ตอบ JSON (UTF-8 ไม่ escape ภาษาไทย) |
| `back($fallback)` | กลับหน้าเดิมโดยใช้ `Request::safeRefererPath()` (กัน open redirect) |
| `flash($type,$msg)` | เก็บข้อความแจ้งเตือนข้ามคำขอ (แสดงใน `partials/flash.php`) |
| `notFound($title)` | เรียก `ErrorController::notFound` |
| `halt(Response)` | โยน `HttpException` เพื่อหยุดงานจากชั้นลึก |

หมายเหตุสำหรับ `Request`: `str($key, $max=0, $default='')` ดึงค่าแบบ string + `trim` + ตัดความยาว (`mb_substr`) ถ้า `$max>0`; `int()/float()/bool()/arr()` แปลงชนิด; `post()/query()` อ่านจาก POST/GET ตรง ๆ; `ip()`, `header()`, `host()`, `rawBody()` ฯลฯ

### ตารางเส้นทางทั้งหมดของฝั่งสาธารณะ (จาก routes.php)

Middleware: "-" = ไม่มี; CSRF: "ตรวจ" = Router ตรวจ `_csrf` ให้เองเมื่อเป็น POST, "ข้าม" = `->noCsrf()`

| URL | Method | Controller@method | Middleware | CSRF | วัตถุประสงค์ |
|---|---|---|---|---|---|
| `/` | GET | `HomeController@index` | - | - | หน้าแรก |
| `/cars` | GET | `CarController@index` | - | - | รายการรถ / ค้นหารถว่างตามช่วงวัน |
| `/cars/{id:\d+}` | GET | `CarController@show` | - | - | รายละเอียดรถ + ฟอร์มจอง |
| `/booking` | POST | `BookingController@store` | - | ตรวจ | รับฟอร์มจอง |
| `/booking/success` | GET | `BookingController@success` | - | - | หน้าจองสำเร็จ (เฉพาะคนที่เพิ่งจอง) |
| `/track` | GET | `TrackController@index` | - | - | ตรวจสถานะการจอง (เลขที่จอง + เบอร์) |
| `/track` | POST | `TrackController@slip` | - | ตรวจ | แจ้งโอนเงิน/อัปสลิป |
| `/how-to` | GET | `PageController@howTo` | - | - | วิธีการเช่า |
| `/faq` | GET | `PageController@faq` | - | - | คำถามที่พบบ่อย |
| `/contact` | GET | `PageController@contact` | - | - | ติดต่อเรา |
| `/terms` | GET | `PageController@terms` | - | - | เงื่อนไขการเช่า |
| `/sign` | GET | `SignController@show` | - | - | หน้าเซ็นเอกสารบนมือถือ (ต้องมีลิงก์+โทเคน) |
| `/sign` | POST | `SignController@store` | - | ตรวจ | บันทึกลายเซ็น |
| `/sitemap.xml` | GET | `SitemapController@index` | - | - | sitemap XML |
| `/health` | GET | `HealthController@index` | - | - | จุดตรวจ uptime (OK/FAIL หรือ JSON เมื่อมี key) |
| `/check` | GET | `InstallController@index` | - | - | ตรวจระบบหลังติดตั้ง (ล็อกเมื่อใช้งานแล้ว) |
| `/liff` | GET | `LiffController@index` | - | - | หน้า "การจองของฉัน" ใน LINE (LIFF) |
| `/liff/api` | POST | `LiffController@api` | - | **ข้าม** | JSON API ของ LIFF (ตรวจ CSRF/origin เอง) |
| `/line/webhook` | POST | `LineWebhookController@handle` | - | **ข้าม** | รับ event จาก LINE (ตรวจลายเซ็น) |
| `/line/webhook` | GET | `LineWebhookController@notPost` | - | - | ตอบ 405 "POST only" |
| `/api/quote` | GET | `ApiController@quote` | - | - | คิดราคาสด (JSON + HTML กล่องราคา) |
| `/api/track` | POST | `ApiController@track` | - | **ข้าม** | รับสัญญาณสถิติ "start_form" |
| `/line/webhook.php` | POST | `LineWebhookController@handle` | - | ข้าม | ที่อยู่เดิมของ webhook (คงไว้) |
| `/liff/api.php` | POST | `LiffController@api` | - | ข้าม | ที่อยู่เดิมของ LIFF API (คงไว้) |
| `*.php` เดิมต่าง ๆ | GET | closure redirect 301 | - | - | ดูหัวข้อ legacy ด้านล่าง |
| `/admin/...` | * | `Admin\*Controller` | Auth / Admin | ตรวจ | หลังบ้าน — อธิบายในเอกสารของ Admin controllers |

> เส้นทาง GET ทุกเส้นที่ลงทะเบียนด้วย `$r->get()` ตอบ HEAD ด้วย (Router::get ใช้ `['GET','HEAD']`)

---

## app/routes.php

**หน้าที่ของไฟล์:** ไฟล์นี้ `return` closure `static function (Router $r): void` ซึ่งถูกเรียกครั้งเดียวตอนบูตระบบ เพื่อลงทะเบียนเส้นทางทั้งหมดเข้า `Router` (ไม่ได้รัน controller ในตอนนั้น) การเพิ่มหน้าใหม่ = เพิ่มบรรทัดที่นี่ + เมธอดใน controller + ไฟล์ view

เครื่องมือของ Router ที่ใช้:

- `$r->get($pattern, $handler)` / `$r->post(...)` — คืน `Route` ให้เชื่อม `->noCsrf()` ได้
- `{id:\d+}` — พารามิเตอร์ใน URL พร้อม regex (ค่าที่จับได้ถูกส่งเป็น argument ของ method ตามลำดับ)
- `$r->group(['prefix'=>..., 'middleware'=>[...]], fn)` — จัดกลุ่ม prefix/middleware (ซ้อนกันได้ prefix ต่อกัน middleware สะสม)
- `$r->legacy($old, $new)` — GET เก่า → redirect **301** ไป path ใหม่ พร้อมต่อ query string เดิม (ถ้า `$new` มี `?` อยู่แล้วจะใช้ `&`)
- handler เป็น `[Controller::class, 'method']` หรือ closure `(Request $req, ...params)` ก็ได้

### ส่วนที่ 1 — หน้าเว็บลูกค้า
บรรทัด 30–45: หน้าแรก, รายการรถ, รายละเอียดรถ (`{id:\d+}` รับเฉพาะตัวเลข), ฟอร์มจอง (POST), หน้าจองสำเร็จ, ตรวจสถานะ/แจ้งโอน (GET+POST บน URL เดียวกัน), หน้าข้อมูลทั่วไป 4 หน้า, เซ็นเอกสาร, sitemap, health, check

### ส่วนที่ 2 — LINE
- `GET /liff` แสดงหน้า LIFF
- `POST /liff/api` **noCsrf** เหตุผล (ตามคอมเมนต์ในไฟล์): คำขอ `act=session` มาก่อนที่จะมี CSRF token (token ออกให้หลังยืนยันตัวตนสำเร็จ) จึงตรวจ **ที่มาของคำขอ (Origin / Sec-Fetch-Site)** แทน ส่วน act อื่น ๆ `LiffController::api()` เรียก `Csrf::valid()` ด้วยตัวเอง
- `POST /line/webhook` **noCsrf** เหตุผล: ผู้เรียกคือเซิร์ฟเวอร์ LINE ไม่มี session/ฟอร์ม จึงใช้การตรวจลายเซ็น `X-Line-Signature` (HMAC) ใน controller แทน
- `GET /line/webhook` ตอบ 405 JSON ให้คนที่เปิดลิงก์ด้วยเบราว์เซอร์

### ส่วนที่ 3 — API ภายในหน้าเว็บ
- `GET /api/quote` — คิดราคา (GET ไม่เปลี่ยนข้อมูล จึงไม่ต้อง CSRF)
- `POST /api/track` **noCsrf** เหตุผล: `navigator.sendBeacon` แนบ header/โทเคนไม่ได้ และ endpoint รับเฉพาะ step ที่อยู่ใน whitelist (`start_form`) ไม่รับข้อมูลอื่นจากผู้ใช้ จึงความเสี่ยงต่ำ

### ส่วนที่ 4 — ที่อยู่เดิมแบบ .php (legacy)
ระบบเดิมเป็นไฟล์ `.php` ลูกค้าอาจบันทึกไว้หรืออยู่ในข้อความ LINE ที่เคยส่งไป จึงมี redirect 301:

| เก่า | ใหม่ |
|---|---|
| `/index.php` `/cars.php` `/booking-success.php` `/track.php` `/how-to.php` `/faq.php` `/contact.php` `/terms.php` `/sign.php` `/liff/index.php` `/sitemap.php` `/health.php` `/check.php` | `/` `/cars` `/booking/success` `/track` `/how-to` `/faq` `/contact` `/terms` `/sign` `/liff` `/sitemap.xml` `/health` `/check` (ต่อ query string เดิมให้) |
| `/car.php?id=N` (closure) | `cars/N` (ถ้า `id>0`) มิฉะนั้น `cars` |
| `/line/webhook.php` (POST) `/liff/api.php` (POST) | **ไม่ redirect** เพราะ redirect ไม่ได้กับ POST จากระบบภายนอก (LINE console / LIFF เดิม) — ผูกกับ controller ตัวเดียวกับเส้นทางใหม่ และ noCsrf เหมือนกัน |

### ส่วนที่ 5 — หลังบ้าน (`/admin`)
- `group(['prefix'=>'/admin'])` ครอบทั้งหมด
- `/admin/login` (GET/POST) และ `/admin/logout` (GET) อยู่นอก middleware (ต้องเข้าได้ตอนยังไม่ล็อกอิน)
- กลุ่มที่สองใส่ `AuthMiddleware` — ต้องล็อกอิน (เจ้าของหรือพนักงาน) ไม่เช่นนั้น flash เตือนแล้ว redirect ไป `admin/login`; `/admin/` redirect ไป `admin/dashboard`; มีหมวด bookings, calendar, payments, cars, customers, maintenance, documents
- กลุ่มซ้อนในสุดใส่ `AdminMiddleware` เพิ่ม — เฉพาะเจ้าของร้าน: reports, monthly-report, ab-report, line, settings, car-types, users, backups, health (staff จะถูก flash เตือนแล้ว redirect กลับ dashboard)
- ถูกบันทึกผ่าน `Admin\...Controller` (อธิบายในเอกสารของ Admin) ซึ่งไม่อยู่ในไฟล์นี้
- ตัวอย่าง constraint: `/bookings/{id:\d+}/preview/{type:return|extend}`, `/inspection/{kind:out|in}`, `/customers/{id:\d+}/legacy/{kind:id_card|license}`

### ส่วนที่ 6 — ที่อยู่หลังบ้านแบบเดิม
- `$adminMap` วนลูปสร้าง `legacy('/admin/<old>.php', '/admin/<new>')` สำหรับ login, logout, dashboard, bookings, calendar, payments, cars, customers, maintenance, documents, reports, monthly-report, ab-report, line, settings, users, backups, health
- closure เฉพาะที่ต้องแปลง `?id=`: `booking-view.php`, `booking-edit.php`, `car-edit.php`, `customer-edit.php`, `contract-print.php`, `document-print.php`, `document-edit.php` (ถ้าไม่มี id ส่งไป `documents/new` พร้อม `type`, `booking_id`), `inspection.php?booking=&kind=`

### ข้อสังเกตเรื่อง noCsrf (สรุป)
| Route | เหตุผลที่ข้าม CSRF | การป้องกันทดแทน |
|---|---|---|
| `POST /liff/api`, `/liff/api.php` | request แรก (`session`) ยังไม่มี token | `session`: ตรวจ Origin/Sec-Fetch-Site + ตรวจ ID token กับ LINE; act อื่น: `Csrf::valid()` + ต้องมี `$_SESSION['liff']['uid']` |
| `POST /line/webhook`, `/line/webhook.php` | เรียกจากเซิร์ฟเวอร์ LINE | `LineService::verifySignature()` |
| `POST /api/track` | `sendBeacon` ส่ง token ไม่ได้ | whitelist ค่า `step` |

---

## app/Helpers/functions.php

**หน้าที่ของไฟล์:** รวมฟังก์ชัน global (ไม่มี namespace) ที่เรียกได้ทุกที่โดยเฉพาะใน view (`e()`, `url()`, `thai_date()` ฯลฯ) และคอนสแตนต์ `TH_MONTHS`, `TH_MONTHS_FULL` (ชื่อเดือนไทยแบบย่อ/เต็ม index 1–12) กฎธุรกิจอยู่ใน `app/Services` และการเข้าถึง DB อยู่ใน `app/Models` — ที่นี่เป็นแค่ตัวช่วยบาง ๆ (หลายตัวเป็น wrapper ไปเรียก Service/Model)

### กลุ่ม Output / URL

#### `e($v): string`
- **ทำอะไร:** escape HTML ด้วย `htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` เพื่อกัน XSS
- **รับเข้า:** ค่าอะไรก็ได้ (แปลงเป็น string)
- **คืนค่า:** string ที่ escape แล้ว (`& < > " '` ถูกแปลง)
- **ใช้ที่ไหน:** ทุก view ที่พิมพ์ข้อมูลจากผู้ใช้/DB เช่น `Views/partials/car_card.php`: `<?= e(upload_url($c['cover_image'])) ?>` (ใช้มากที่สุดในระบบ ~600 จุด); `SitemapController`
- **ตัวอย่าง:** `e('<b>"x"</b>')` → `&lt;b&gt;&quot;x&quot;&lt;/b&gt;`

#### `url(string $path = ''): string`
- **ทำอะไร:** สร้าง URL ภายในระบบ = `BASE_URL . '/' . ltrim($path,'/')` (BASE_URL ตรวจจับเองตอนบูต รองรับติดตั้งในโฟลเดอร์ย่อย)
- **รับเข้า:** path เช่น `'cars/3'`
- **คืนค่า:** เช่น `/thonglorcarrent/cars/3` (ไม่มี http://โดเมน)
- **ใช้ที่ไหน:** view ทั่วไป `url('cars/' . (int)$c['id'])`, `Response::redirect()` ภายใน, `BookingController::success` (`url('track')`)

#### `asset(string $path): string`
- **ทำอะไร:** ต่อ `?v=<filemtime>` ท้าย URL ของไฟล์ CSS/JS เพื่อ cache-busting (ถ้าอ่านเวลาไฟล์ไม่ได้ใช้ `APP_VERSION`)
- **รับเข้า:** path เทียบกับ `APP_ROOT` เช่น `'assets/css/app.css'`
- **คืนค่า:** URL พร้อม query version
- **ใช้ที่ไหน:** `Views/layouts/admin_print.php`, `Views/layouts/focus.php`: `<?= e(asset('assets/css/app.css')) ?>`

#### `site_url(string $path = ''): string`
- **ทำอะไร:** สร้าง URL เต็ม (มี `https://โดเมน`) — อ่านจากตั้งค่า `site_url`; ถ้าว่างและมี `HTTP_HOST` จะประกอบเองโดยตรวจ HTTPS จาก `$_SERVER['HTTPS']` หรือ `X-Forwarded-Proto`; ตัด `/` ท้ายแล้วต่อ path
- **รับเข้า:** path (ว่างได้)
- **คืนค่า:** URL เต็ม (ถ้าไม่มีทั้ง setting และ HTTP_HOST — เช่นรันใน cron — จะได้แค่ path)
- **ใช้ที่ไหน:** `SitemapController::index`, `LineWebhookController::event` (`site_url('cars')`), `LineService` (ลิงก์ในข้อความ LINE), `LiffController::state`

#### `csrf_token(): string`
- **ทำอะไร:** wrapper ของ `Csrf::token()` (สร้างและเก็บใน `$_SESSION['csrf']`)
- **คืนค่า:** token hex 64 ตัวอักษร
- **ใช้ที่ไหน:** `Views/admin/inspection.php` (ฝังเป็นตัวแปร JS)

#### `csrf_field(): string`
- **ทำอะไร:** wrapper ของ `Csrf::field()` คืน `<input type="hidden" name="_csrf" value="...">`
- **ใช้ที่ไหน:** ทุกฟอร์ม POST เช่น `Views/admin/backups/index.php`: `<?= csrf_field() ?>` (ถ้าลืมใส่ Router จะตอบ 419)

#### `selected($cond): string`
- **ทำอะไร:** คืน `' selected'` ถ้า `$cond` เป็นจริง ไม่เช่นนั้น `''`
- **ใช้ที่ไหน:** `<option ...<?= selected($sbType === $t) ?>>` ใน `Views/partials/searchbar.php`

#### `checked($cond): string`
- **ทำอะไร:** เหมือน `selected` แต่คืน `' checked'` สำหรับ checkbox/radio
- **ใช้ที่ไหน:** `Views/admin/users/index.php`: `<?= checked(!$edit || (int)$edit['is_active']) ?>`

### กลุ่มตั้งค่าระบบ

#### `settings(): array`
- **ทำอะไร:** wrapper ของ `Setting::all()` คืนค่าตั้งค่าทั้งหมด (key→value)
- **ใช้ที่ไหน:** `Admin\SettingController`, `Admin\LineController`

#### `setting(string $key, $default = '')`
- **ทำอะไร:** wrapper ของ `Setting::get($key,$default)` อ่านตั้งค่าตาม key (ตาราง `settings`)
- **คืนค่า:** ค่าตั้งค่า (ปกติเป็น string) หรือ `$default` ถ้าไม่มี
- **ใช้ที่ไหน:** ใช้มากที่สุดรองจาก `e()` เช่น `setting('phone')` ใน `PageController::contact`, `setting('open_time','08:00')` ใน view
- **หมายเหตุ:** ค่าเป็น string เสมอ (เช่น `setting('notify_line_enabled') === '1'`) ต้อง cast เองถ้าต้องการตัวเลข

#### `set_setting(string $key, $value): void`
- **ทำอะไร:** wrapper ของ `Setting::set()` บันทึกค่าตั้งค่า
- **ใช้ที่ไหน:** `BackupService` (`set_setting('backup_last_at', ...)`), `LineService` (`line_richmenu_id`)

### กลุ่มผู้ใช้ที่ล็อกอิน

#### `auth_user(): ?array`
- **ทำอะไร:** wrapper ของ `AuthService::user()` — คืนข้อมูลผู้ใช้หลังบ้านที่ล็อกอินอยู่ หรือ `null`
- **ใช้ที่ไหน:** `Views/layouts/admin.php`: `$u = auth_user() ?? [...]`, `InstallController::guard`

#### `is_admin(): bool`
- **ทำอะไร:** wrapper ของ `AuthService::isAdmin()` — role เป็น admin (เจ้าของร้าน) หรือไม่
- **ใช้ที่ไหน:** `Views/admin/bookings/show.php`, `Admin\BookingController`, `InstallController::guard`

### กลุ่มวันที่ / เวลา / เงิน (ไทย)

#### `thai_date(?string $datetime, bool $withTime = false, bool $full = false): string`
- **ทำอะไร:** แปลงวันที่เป็นรูปแบบไทย พ.ศ. — ว่าง / ขึ้นต้น `0000` / `strtotime` ไม่ได้ → `'-'`; ปี = ค.ศ.+543; `$full=false` → `19 ก.ย. 69` (ปี 2 หลัก), `$full=true` → `19 กันยายน 2569`; `$withTime` ต่อ ` HH:MM น.`
- **รับเข้า:** string วันเวลาที่ `strtotime` อ่านได้ (เช่น `2026-09-19 10:00:00` หรือ `2026-09-19T10:00`)
- **คืนค่า:** string
- **ใช้ที่ไหน:** `CarController::index` (หัวข้อหน้า), `LiffController::bookingForScreen`, `Views/partials/contract_terms.php` (`thai_date($b['return_at'], true, true)`)

#### `money($n, bool $symbol = false): string`
- **ทำอะไร:** `number_format((float)$n, 2)`; ถ้า `$symbol` ใส่ `฿` นำหน้า
- **ตัวอย่าง:** `money(1500)` → `1,500.00`
- **ใช้ที่ไหน:** `Views/partials/quote_box.php`

#### `money0($n): string`
- **ทำอะไร:** จำนวนเงินไม่มีทศนิยม `number_format((float)$n, 0)`
- **ใช้ที่ไหน:** `Views/partials/car_card.php`, `CarController::show` (คำอธิบาย SEO), `PageController::faq`, `LiffController::bookingForScreen`

#### `human_bytes(float $b): string`
- **ทำอะไร:** แปลงไบต์เป็น B/KB/MB/GB (หาร 1024 วนจนกว่าจะ <1024 หรือถึง GB); B ไม่มีทศนิยม หน่วยอื่น 1 ตำแหน่ง
- **ตัวอย่าง:** `human_bytes(1536)` → `1.5 KB`
- **ใช้ที่ไหน:** `Views/admin/backups/index.php`
- **หมายเหตุ:** `return ''` ท้ายฟังก์ชันไม่มีทางถึง (loop คืนค่าเมื่อถึง GB เสมอ)

#### `baht_text(float $amount): string`
- **ทำอะไร:** แปลงจำนวนเงินเป็นคำอ่านไทย (ท้ายใบเสร็จ) — ปัดเป็น 2 ตำแหน่ง แยกบาท/สตางค์; 0 บาท = `ศูนย์บาท`; สตางค์ 0 → `ถ้วน` มิฉะนั้นต่อ `<คำอ่าน>สตางค์`; ใช้ `thai_number_words()` อ่านตัวเลข
- **ตัวอย่าง:** `baht_text(121.50)` → `หนึ่งร้อยยี่สิบเอ็ดบาทห้าสิบสตางค์`
- **ใช้ที่ไหน:** `Views/admin/documents/print.php` (`baht_text((float)$doc['grand_total'])`)

#### `thai_number_words(string $digits): string`
- **ทำอะไร:** อ่านเลขจำนวนเต็ม (ส่งเป็น string) เป็นคำไทย: ตัด 0 นำหน้า; วนทีละหลัก ข้ามหลักที่เป็น 0; กฎพิเศษ — หลักหน่วยเป็น 1 และเลขยาว >1 หลัก → `เอ็ด`; หลักสิบ 1 → `สิบ` (ไม่มี "หนึ่ง"); หลักสิบ 2 → `ยี่สิบ`
- **รับเข้า:** string ตัวเลข เช่น `"21"` → `ยี่สิบเอ็ด`
- **ใช้ที่ไหน:** ภายใน `baht_text()` เท่านั้น (ไม่พบการเรียกที่อื่น)
- **ข้อจำกัด:** คอมเมนต์ระบุรองรับถึงหลักล้าน (`$units` มี index 0–6) ตัวเลขตั้งแต่ 10,000,000 ขึ้นไป (index 7) จะเกิด "Undefined array key" และอ่านผิด; ไม่รองรับ "ล้านล้าน"

### กลุ่มเบอร์โทร

#### `phone_digits(string $phone): string`
- **ทำอะไร:** ลบทุกตัวที่ไม่ใช่ตัวเลข แล้วถ้ายาว ≥11 หลักและขึ้นต้น `66` แปลงเป็น `0` + ส่วนที่เหลือ (`+66812345678` → `0812345678`)
- **ใช้ที่ไหน:** `WebBookingService`, `AdminBookingService`, `LiffController::link`, `phone_match`

#### `phone_match(?string $a, ?string $b): bool`
- **ทำอะไร:** เทียบเบอร์สองเบอร์หลัง normalize ด้วย `phone_digits`; ต้องยาว ≥9 หลัก (เบอร์ว่างหรือ "-" ไม่ match กับอะไรเลย กันการดูการจองที่ไม่มีเบอร์ด้วยเบอร์สุ่ม) เทียบด้วย `hash_equals` (constant-time)
- **คืนค่า:** true/false
- **ใช้ที่ไหน:** `TrackController::lookup`, `LiffController::link`, `AdminBookingService`, `Models/Customer.php`

#### `phone_sql(string $col): string`
- **ทำอะไร:** คืนนิพจน์ SQL `REPLACE(...)` ซ้อนกัน 6 ชั้น ตัด `-` ช่องว่าง `(` `)` `.` `+` ออกจากคอลัมน์เบอร์โทร เพื่อคัดแถวใน SQL ก่อนค่อยเทียบเต็มด้วย `phone_match()`
- **รับเข้า:** ชื่อคอลัมน์ (ต้องเป็นค่าที่โค้ดกำหนดเอง **ห้ามส่งข้อมูลจากผู้ใช้** เพราะต่อเข้า SQL ตรง ๆ)
- **ใช้ที่ไหน:** `Models/Customer.php`, `Models/Booking.php`

### กลุ่มป้ายสถานะ

#### `booking_status_label(string $s): array`
- **ทำอะไร:** แปลงสถานะการจองเป็น `[ข้อความไทย, โทนสี]` — pending=`รอยืนยัน`/warn, confirmed=`ยืนยันแล้ว`/info, picked_up=`รับรถแล้ว`/primary, returned=`คืนรถแล้ว`/ok, completed=`ปิดงาน`/ok, cancelled=`ยกเลิก`/danger; ไม่รู้จัก → `[$s,'muted']`
- **ใช้ที่ไหน:** `[$l,$cl] = booking_status_label($p['status'])` ใน `Views/admin/dashboard.php`, `LiffController::bookingForScreen`

#### `payment_status_label(string $s): array`
- **ทำอะไร:** เหมือนด้านบน สำหรับ unpaid/deposit_paid/paid/refunded
- **ใช้ที่ไหน:** `Views/public/track.php`, `Views/admin/bookings/index.php`, `LiffController`

#### `car_type_label(string $t): string`
- **ทำอะไร:** wrapper ของ `CarType::label($t)` คืนชื่อประเภทรถ (ไทย) จากรหัส
- **ใช้ที่ไหน:** `Views/partials/car_card.php`, `Views/admin/cars/index.php`

#### `car_type_options(?string $keep = null): array`
- **ทำอะไร:** wrapper ของ `CarType::options($keep)` คืน `[code => label]` สำหรับ dropdown; `$keep` = ประเภทที่ปิดใช้งานแล้วแต่ยังต้องแสดง
- **ใช้ที่ไหน:** `Views/partials/searchbar.php`, `Views/admin/cars/form.php`

#### `fuel_label(?int $level): string`
- **ทำอะไร:** แปลงระดับน้ำมัน (ขีด 0–8) เป็นข้อความ — null → `-`; 0=ถังว่าง, 2=1/4, 4=ครึ่งถัง, 6=3/4, 8=เต็มถัง; ค่าอื่น → `"N/8 ขีด"`
- **ใช้ที่ไหน:** `Views/admin/contract.php`

### กลุ่มไฟล์อัปโหลด

#### `upload_url(?string $path): string`
- **ทำอะไร:** คืน `UPLOAD_URL . '/' . path` ถ้า path ไม่ว่าง ไม่เช่นนั้น `''`
- **ใช้ที่ไหน:** `Views/partials/car_card.php`, `LiffController::bookingForScreen`

#### `upload_exists(?string $path): bool`
- **ทำอะไร:** ตรวจว่าไฟล์อยู่จริงใต้ `UPLOAD_DIR` และขนาด >0 (กัน DB ชี้ไฟล์ที่ยังไม่อัปโหลดแล้วรูปแตก)
- **ใช้ที่ไหน:** `Views/partials/car_card.php`, `Views/public/home.php`, `LiffController::bookingForScreen`

### กลุ่มสีแบรนด์

#### `hex_to_rgb(string $hex, string $fallback): array`
- **ทำอะไร:** แปลง `#rgb`/`#rrggbb` เป็น `[r,g,b]`; ถ้ารูปแบบไม่ถูกต้องใช้ `$fallback` (ซึ่งสมมติว่าถูกต้อง); รองรับ 3 หลัก (ขยายซ้ำตัวอักษร)
- **ใช้ที่ไหน:** ภายใน `brand_style()` เท่านั้น

#### `mix_rgb(array $rgb, float $t): array`
- **ทำอะไร:** ผสมสีกับขาว (`$t` บวก, 0–1) หรือดำ (`$t` ลบ) ตามสัดส่วน `|t|` ปัดเป็น int
- **ใช้ที่ไหน:** ภายใน `brand_style()` สร้างเฉดอ่อน/เข้ม

#### `brand_style(string $defPrimary='#1f2d3d', string $defAccent='#e09a3c', string $defDark='#16212e'): string`
- **ทำอะไร:** อ่านสีแบรนด์จาก setting `brand_primary/brand_accent/brand_dark` (ไม่มีก็ใช้ default) สร้าง CSS variable ชุด `--c-brand`, `-700`, `-100`, `-50`, `--c-accent`(+`-600`,`-100`,`-50`), `--c-dark` ในรูป `"r g b"` ให้ Tailwind ใช้ `rgb(var(--x) / alpha)`
- **คืนค่า:** string `<style>:root{...}</style>` (พิมพ์ใน `<head>`)
- **ใช้ที่ไหน:** `Views/layouts/focus.php`, `Views/layouts/admin_print.php`

### กลุ่มไอคอน / โลโก้

#### `icon(string $name, string $cls = ''): string`
- **ทำอะไร:** คืน inline SVG (stroke ตาม `currentColor`) ตามชื่อไอคอน เช่น `car`, `calendar`, `clock`, `search`, `phone`, `user`, `menu`, `close`, `seat`, `gear`, `fuel`, `tag`, `percent`, `shield`, `lock`, `refresh`, `check`, `checkbig`, `arrow`, `back`, `doc`, `money`, `pin`, `line`, `mail`, `star`, `clipboard`, `upload`, `wrench`, `camera`, `sign`, `compare`, `whatsapp`; ชื่อที่ไม่มี → SVG ว่าง; ตาราง path เก็บใน `static $paths` สร้างครั้งแรกครั้งเดียว; `$cls` ถูก `e()` แล้ว
- **ใช้ที่ไหน:** `<?= icon('car', 'h-[34px] w-[34px]') ?>` ใน `Views/partials/car_card.php` (~50 จุด)

#### `brand_logo(string $mode = 'lockup', string $cls = ''): string`
- **ทำอะไร:** คืนแท็ก `<img>` ของโลโก้ `assets/img/logo-{lockup|full|mark}.webp?v=APP_VERSION` พร้อม width/height ตายตัว (กัน layout shift) และ alt = setting `site_name`; mode ที่ไม่รู้จักใช้ lockup
- **ใช้ที่ไหน:** `Views/admin/login.php`, `Views/admin/documents/print.php`
- **หมายเหตุ:** ขนาดของ `mark` (520x122) ดูเป็นขนาดแนวนอน แม้ชื่อบอกว่าสัญลักษณ์อย่างเดียว — เป็นแค่ข้อมูลในโค้ด ไม่ตรวจสอบกับไฟล์จริง

---

## app/Controllers/ApiController.php

**หน้าที่ของไฟล์:** API JSON ที่หน้าเว็บเรียกด้วย JavaScript — `GET /api/quote` (คิดราคาสด) และ `POST /api/track` (รับสัญญาณสถิติ)

### class ApiController extends Controller

#### `public quote(): Response`
**ทำอะไร:**
1. อ่านพารามิเตอร์จาก query: `car_id` (int), `pickup`, `ret`, `addons` (JSON string → `json_decode` → `BookingService::cleanAddons()` ล้างให้เหลือเฉพาะ add-on ที่ถูกต้อง; ถ้าไม่ใช่ array ใช้ `[]`), `km` (float จำกัด 0–2000), `pick_type`/`ret_type` (ได้เฉพาะ `airport` หรือ `shop`)
2. `Car::findPublic($carId)` — ไม่เจอ → คืน JSON `available=false`, `error`, `html` กล่อง alert (สถานะ HTTP ยัง 200)
3. แปลง `pickup`/`ret` เป็น `Y-m-d H:i:s` ด้วย `strtotime` (ค่าไม่ถูกต้องเป็น `''`)
4. **โหมดแอดมิน:** `admin=1` **และ** `AuthService::check()` (ล็อกอินจริง) — ข้ามเงื่อนไขจองล่วงหน้า/เวลาทำการ (จองย้อนหลัง/วันนี้ได้) ตรวจแค่ว่าคืนหลังรับ; พารามิเตอร์ `ignore` (เลขการจองที่ไม่ต้องนับตอนตรวจรถว่าง ใช้ตอนแก้ไขการจอง) ใช้ได้เฉพาะโหมดแอดมิน — ผู้ใช้ทั่วไปส่งมาจะถูกเมิน (กันใช้ไล่ดูว่าใครจองรถอะไรช่วงไหน)
5. หา `$periodError`: ไม่มีวัน → "กรุณาเลือกวันรับและวันคืนรถ"; แอดมิน → ตรวจลำดับวัน; ลูกค้า → `AvailabilityService::validatePeriod($pickDb,$retDb,$pickType,$retType)`; ถ้ายังไม่มี error และไม่ใช่แอดมิน และ `DeliveryFeeService::isOutOfRange($addons,$km)` → error ระยะทางเกินพื้นที่ส่งรถอัตโนมัติ (แสดงเบอร์ร้าน)
6. `AvailabilityService::isCarAvailable()` (ถ้าไม่มี error) และ `PricingService::quote(...)` หรือ `emptyQuote()` ถ้ามี error
7. render partial `partials/quote_box` ด้วย `View::make` แล้วตอบ JSON

**รับเข้า:** query string ตามข้างบน (GET) — ไม่มี CSRF (read-only)
**คืนค่า:** `Response` JSON: `available`, `error` (ข้อความหรือ null; ถ้าไม่ว่าง "รถไม่ว่างในช่วงนี้"), `days`, `total` (`grand_total`), `deposit` (`deposit_amount`), `html`
**View ที่ใช้:** `app/Views/partials/quote_box.php` (render เป็นสตริงฝังใน JSON; ไม่ได้ส่งเป็นหน้า)

#### `public track(): Response`
**ทำอะไร:** อ่าน `step` และ `car_id` จาก POST; ถ้า `step` ไม่อยู่ใน whitelist `['start_form']` → JSON `{ok:false}` 400; มิฉะนั้นเรียก `AbTrackService::trackOnce($step,$carId)` (บันทึกสถิติ A/B funnel (ชื่อ method บ่งว่าบันทึกแบบครั้งเดียว — รายละเอียดการกันซ้ำอยู่ใน AbTrackService)) แล้วตอบ `{ok:true}` ทั้งสองกรณีใส่ `Cache-Control: no-store`
**รับเข้า:** POST `step`, `car_id` (route นี้ `noCsrf` — ดูเหตุผลในส่วน routes)
**คืนค่า:** JSON
**View ที่ใช้:** ไม่มี

---

## app/Controllers/BookingController.php

**หน้าที่ของไฟล์:** รับฟอร์มจองจากลูกค้า → บันทึก → ส่งไปหน้ายืนยัน. URL: `POST /booking`, `GET /booking/success`

### class BookingController extends Controller

#### `public store(): Response`
**ทำอะไร:**
1. (CSRF ตรวจโดย Router ก่อนถึงตรงนี้) บันทึกสถิติ `AbTrackService::trackOnce('submit')`
2. `Car::findPublic($carId)` — ไม่เจอ → flash `danger` "ไม่พบรถที่เลือก" แล้ว redirect `cars`
3. รวบรวมข้อมูลลงอาร์เรย์ `$in` พร้อมตัดความยาวตามขนาดคอลัมน์ (กัน error ฐานข้อมูลถึงลูกค้า): pickup_at/return_at (30), contact_name(150)/phone(30)/email(150)/line(80), note(1000), pickup_place/return_place(230), `addons` (ผ่าน `BookingService::cleanAddons`), `delivery_km` (0–2000), ประเภทจุดรับ/คืน (`airport` หรือ `shop`), `accept` (ยอมรับเงื่อนไข), `liff_uid` จาก `$_SESSION['liff']['uid']` (ถ้าจองในแอป LINE จะผูกบัญชีอัตโนมัติ — ค่านี้มาจาก session ที่ยืนยัน ID token แล้ว ไม่ใช่จากฟอร์ม)
4. **กันกดซ้ำ:** ถ้า session มี `last_booking` และมีวันรับ/คืน → `Booking::recentDuplicate(เลขที่เดิม, carId, pick, ret, เวลา 180 วินาทีที่แล้ว)`; ถ้าเจอ (รถเดิม ช่วงเดิม ภายใน 3 นาที) → redirect ไป `booking/success?no=...` ของรายการเดิม ไม่สร้างใหม่ (กันดับเบิลคลิก/กดย้อนส่งใหม่ ที่จะเจอ "รถไม่ว่าง")
5. `WebBookingService::create($car, $in, $r->ip())` — ตรวจความถูกต้อง/ว่าง/blacklist/คิดราคา/บันทึก/แจ้งเตือน (อยู่ใน service)
6. ถ้ามี `errors` → flash `danger` ทีละข้อ; ถ้า `blacklisted` → redirect `cars/{id}` (ไม่เติม query) ไม่เช่นนั้น redirect `cars/{id}?pickup=..&ret=..#book` เพื่อเก็บวันที่ที่เลือกไว้
7. สำเร็จ: เก็บ `$_SESSION['last_booking']=booking_no`, `AbTrackService::track('booked',$carId)`, redirect `booking/success?no=...`

**รับเข้า:** POST: `car_id`, `pickup_at`, `return_at`, `contact_name`, `contact_phone`, `contact_email`, `contact_line`, `note`, `pickup_place`, `return_place`, `addons[]`, `delivery_km`, `pickup_place_type`, `return_place_type`, `accept`
**คืนค่า:** `Response` redirect เสมอ (302)
**View ที่ใช้:** ไม่มี

#### `public success(): Response`
**ทำอะไร:** อ่าน `no` จาก query; ถ้ามีเลขให้ `Booking::findByNoWithCar($no)`; **อนุญาตเฉพาะ session ที่เพิ่งจอง** (`$_SESSION['last_booking'] === $no`) เพื่อกันเดาเลขที่จองคนอื่น — ไม่เจอหรือไม่ตรง → 404 view พร้อมปุ่มไปหน้า `track`; ผ่านแล้ว render หน้าสำเร็จ (เลขที่จอง บัญชีโอน ลิงก์อัปสลิป)
**รับเข้า:** GET `no`
**คืนค่า:** `Response` HTML (404 หรือ 200)
**View ที่ใช้:** `app/Views/errors/404.php` (กรณีไม่ผ่าน, ส่ง title/message/backUrl/backLabel), `app/Views/public/booking_success.php` (ส่ง `b`, `title`)

---

## app/Controllers/CarController.php

**หน้าที่ของไฟล์:** รายการรถ/ค้นหารถว่าง และหน้ารายละเอียดรถพร้อมฟอร์มจอง. URL: `GET /cars`, `GET /cars/{id}`

### class CarController extends Controller

#### `public index(): Response`
**ทำอะไร:**
1. รับช่วงวันได้ 2 รูปแบบ — แยกช่อง (`pd`+`pt`, `rd`+`rt`; เวลา default `10:00`) หรือแบบเดิม (`pickup`, `ret`); ถ้ามี `pd` และ `rd` จะประกอบเป็น `YYYY-MM-DDTHH:MM` ทับค่าแบบเดิม
2. `type` ใช้เฉพาะถ้า `CarType::exists()` (กัน type มั่ว)
3. ถ้ามีทั้งวันรับและคืน = โหมดค้นหา: แปลงเป็นรูปแบบ DB, `AvailabilityService::validatePeriod()`; ถ้าไม่มี error → `availableCars()` + `PricingService::rentalDays()`; ถ้าไม่ค้นหา → `Car::allPublic($type)`
4. ส่งค่าเริ่มต้นแถบค้นหา (`sbPick`, `sbRet` = พรุ่งนี้ 10:00 / มะรืน 10:00, `sbType`)

**รับเข้า:** GET `pd,pt,rd,rt` หรือ `pickup,ret`, `type`
**คืนค่า:** `Response` HTML 200
**View ที่ใช้:** `app/Views/public/cars.php` (ตัวแปร title, searching, error, days, cars, pickup, ret, sbPick, sbRet, sbType) และใช้ partial `searchbar`, `car_card` ภายใน

#### `public show(int $id): Response`
**ทำอะไร:** `Car::findPublic($id)` ไม่เจอ → `$this->notFound('ไม่พบรถคันนี้', 'รถอาจถูกนำออกจากระบบแล้ว')` (ดูข้อสังเกตท้ายเอกสาร); เจอ → `AbTrackService::trackOnce('view_car', id)`; ใช้ `pickup`/`ret` จาก query หรือค่า default (พรุ่งนี้ 10:00 – มะรืน 10:00); `validatePeriod`, `isCarAvailable` (ถ้าไม่มี error), `PricingService::quote($car,$pick,$ret,[],0.0,null,0.0)` ราคาตั้งต้นไม่มี add-on; ส่ง `features` (json_decode จากคอลัมน์ features), `busy` (`AvailabilityService::busyRanges`), `addonOpts` (`PricingService::addonCatalog`) ให้ view — ฟอร์มจองในหน้านี้เรียก `/api/quote` อัปเดตราคาสด
**รับเข้า:** path `{id}` (int จาก regex `\d+`), GET `pickup`, `ret`
**คืนค่า:** `Response` HTML (404 ถ้าไม่พบรถ)
**View ที่ใช้:** `app/Views/public/car.php` (title, desc สำหรับ SEO, car, carName, features, busy, addonOpts, pickup, ret, periodError, available, q)

---

## app/Controllers/ErrorController.php

**หน้าที่ของไฟล์:** หน้า error (ตอนนี้มี 404). ถูกเรียกจาก `Router::dispatch` (ไม่พบเส้นทาง) และ `Controller::notFound()` — ไม่มี URL ของตัวเอง

### class ErrorController extends Controller

#### `public notFound(string $title = 'ไม่พบหน้าที่ต้องการ', string $message = 'หน้านี้อาจถูกย้ายหรือลบไปแล้ว'): Response`
**ทำอะไร:** ถ้า `Request::wantsJson()` (Accept JSON, XHR, path ขึ้นต้น `/api/` หรือ `/liff/api`) → JSON `{ok:false,error:$title}` 404; ไม่เช่นนั้น render view 404
**รับเข้า:** `$title`, `$message`
**คืนค่า:** `Response` 404
**View ที่ใช้:** `app/Views/errors/404.php`

---

## app/Controllers/HealthController.php

**หน้าที่ของไฟล์:** จุดตรวจสำหรับ uptime monitor. URL: `GET /health` (ไม่ใช่ `/admin/health` ซึ่งเป็นของ Admin)

### class HealthController extends Controller

#### `public index(): Response`
**ทำอะไร:**
1. `Database::value('SELECT 1')` และอ่าน setting `health_key` — ถ้า exception ใด ๆ → `plain('FAIL', 503)`
2. อ่าน `key` จาก query; ถ้า key ว่าง/ไม่มี key ในระบบ/ไม่ตรง (`hash_equals`) → `plain('OK', 200)` (ไม่เปิดเผยข้อมูล จึงเปิดสาธารณะได้)
3. ถ้า key ถูก: วน `HealthService::checks()` (แต่ละรายการ `[title, level, found, advice]`) สร้าง JSON รวม `status` = `ok` → `warn` (มี warn) → `fail` (มี fail) พร้อม `checked_at` (ISO 8601) และตอบ `Content-Type: application/json`, `Cache-Control: no-store`, `X-Robots-Tag: noindex`

**รับเข้า:** GET `key` (ไม่บังคับ)
**คืนค่า:** `Response` text "OK"/"FAIL" หรือ JSON. หมายเหตุ: status HTTP ของ JSON เป็น 200 เสมอแม้ `status=fail` (ผู้ใช้ monitor ต้องอ่านค่า `status` เอง)
**View ที่ใช้:** ไม่มี

#### `private plain(string $text, int $status): Response`
**ทำอะไร:** helper สร้าง `Response::text` พร้อม header `Cache-Control: no-store` และ `X-Robots-Tag: noindex`
**คืนค่า:** `Response` text/plain

---

## app/Controllers/HomeController.php

**หน้าที่ของไฟล์:** หน้าแรก. URL: `GET /`

### class HomeController extends Controller

#### `public index(): Response`
**ทำอะไร:** `AbTrackService::trackOnce('view_home')`; `Car::allPublic()` (รถทั้งหมดที่แสดงสาธารณะ); คำนวณ `totalCars = count`, `minPrice = Car::minAvailablePrice()`, `heroCar = Car::hero()` หรือคันแรกในรายการ (ถ้าไม่มีรถเลยเป็น null)
**รับเข้า:** ไม่มี
**คืนค่า:** `Response` HTML 200
**View ที่ใช้:** `app/Views/public/home.php` (cars, totalCars, minPrice, heroCar)

---

## app/Controllers/InstallController.php

**หน้าที่ของไฟล์:** หน้าตรวจระบบหลังติดตั้ง ใช้ได้ระหว่างติดตั้ง แล้วล็อกเมื่อระบบถูกใช้งาน (เปิดต่อได้เฉพาะเจ้าของที่ล็อกอิน). URL: `GET /check` (และ legacy `/check.php`)

### class InstallController extends Controller

Property (private): `array $rows` (ผลตรวจแต่ละข้อ), `int $fail`, `int $warn` (ตัวนับ)

#### `private chk(string $name, bool $ok, string $detail = '', bool $warnOnly = false): void`
**ทำอะไร:** เพิ่มผลตรวจ 1 แถวลง `$rows`; ถ้า `!$ok` นับ `warn++` เมื่อ `$warnOnly` มิฉะนั้น `fail++`
**คืนค่า:** ไม่มี (void)

#### `public index(): Response`
**ทำอะไร:** `guard()` — ถ้าคืน Response (ล็อก) ให้ตอบทันที; มิฉะนั้นรันชุดตรวจตามลำดับ `checkPhp`, `checkConfig`, `checkDatabase` (ได้ bool), `checkFiles`, `checkSecurity` แล้ว render view พร้อมเพิ่ม header `X-Robots-Tag: noindex, nofollow` และ `Cache-Control: no-store`
**รับเข้า:** ไม่มี
**คืนค่า:** `Response` HTML
**View ที่ใช้:** `app/Views/install/check.php` (title, heading, sub, narrow, rows, fail, warn, dbOk)

#### `private guard(): ?Response`
**ทำอะไร:** ตัดสินว่าระบบ "ถูกใช้งานแล้ว" หรือไม่: เชื่อมต่อ PDO เอง (ไม่ผ่าน `Database` class เพราะอาจต่อไม่ได้) นับ `users WHERE last_login_at IS NOT NULL` > 0 (ถ้า exception ถือว่ายังไม่ติดตั้ง/เปิดได้); ถ้าใช้งานแล้วและยังไม่มีไฟล์ `storage/.installed` จะสร้างไฟล์นั้น (บันทึกเวลา) เพื่อ "จำ" ว่าเคยใช้งานแล้ว แม้วันที่ DB ล่มก็ยังไม่เปิดเผยชื่อ DB/path; ถ้า `inUse` และผู้ใช้ไม่ได้ล็อกอินเป็น admin (`auth_user()`/`is_admin()`) → ตอบ 403 view locked + `X-Robots-Tag: noindex`
**คืนค่า:** `Response` 403 หรือ `null` (ผ่าน)
**View ที่ใช้:** `app/Views/install/locked.php`

#### `private connect(): \PDO`
**ทำอะไร:** สร้าง PDO ใหม่ด้วยค่าคงที่ `DB_HOST, DB_NAME, DB_CHARSET, DB_USER, DB_PASS` (ERRMODE_EXCEPTION, timeout 5 วินาที)

#### `private checkPhp(): void`
**ทำอะไร:** ตรวจ PHP ≥ 8.1 (fail ถ้าไม่ถึง); ตรวจ extension `pdo_mysql, mbstring, fileinfo, json` (fail ถ้าไม่มี) และ `curl, gd` (เตือนเท่านั้น); ตรวจ `upload_max_filesize` (>=2 ผ่าน, <4 แสดงคำเตือน) เป็น warning

#### `private checkConfig(): void`
**ทำอะไร:** แสดง `BASE_URL` ที่ตรวจพบ (เป็น ok เสมอ), ตรวจว่ามี `assets/css/app.css` (fail ถ้าไม่มี พร้อมแจ้งว่าอาจยังไม่ได้รัน `npm run build`), ตรวจว่าอ่าน `DOCUMENT_ROOT` ได้ (warning)

#### `private checkDatabase(): bool`
**ทำอะไร:** เชื่อมต่อ DB; ตรวจตารางที่จำเป็น 19 ตาราง (`SHOW TABLES` แล้ว `array_diff`) — ขาด → บันทึก fail และ **return false**; นับ cars/users/settings ว่ามีข้อมูลตั้งต้น; ตรวจ charset เป็น utf8*; ตรวจรูปปกรถ (คันที่ `status <> 'inactive'`) มีค่าและไฟล์อยู่จริง; ถ้า exception → fail "ต่อไม่ได้" พร้อมข้อความ error และ return false
**คืนค่า:** `true` ถ้าต่อ DB ได้และตารางครบ
**หมายเหตุด้านความปลอดภัย:** แสดงข้อความ exception และชื่อ DB/user — จึงต้องมี `guard()`

#### `private checkFiles(): void`
**ทำอะไร:** ตรวจไฟล์โลโก้ `assets/img/logo-full.webp`; นับไฟล์ `uploads/cars/*.webp` (ควร ≥ 8); ตรวจโฟลเดอร์ `uploads`, `uploads/slips|cars|docs|inspections`, `storage`, `storage/backups|private` ว่ามีและเขียนได้ (แสดง permission octal)

#### `private checkSecurity(): void`
**ทำอะไร:** `APP_DEBUG === false` (warning), ใช้ HTTPS (`$this->request->isSecure()`, warning), มี `uploads/.htaccess`, และ `config|app|storage/.htaccess` (fail ถ้าไม่มี)

---

## app/Controllers/LiffController.php

**หน้าที่ของไฟล์:** หน้า "การจองของฉัน" ภายในแอป LINE (LIFF). URL: `GET /liff` (หน้า), `POST /liff/api?act=...` (JSON API; มีเส้นทางเก่า `/liff/api.php`)

หลักความปลอดภัย (จากคอมเมนต์ + โค้ด): LINE user id มาจากการตรวจ ID token กับเซิร์ฟเวอร์ LINE เท่านั้น ไม่รับ uid จากหน้าเว็บ; ลูกค้าเห็นเฉพาะการจองที่ผูกกับ LINE ของตนเอง

| act | ต้อง session | ต้อง CSRF | method ที่เรียก |
|---|---|---|---|
| `session` | ไม่ | ไม่ (ตรวจ Origin แทน) | `session()` |
| `state` | ใช่ | ใช่ | `state()` |
| `link` | ใช่ | ใช่ | `link($uid)` |
| `unlink` | ใช่ | ใช่ | `unlink($uid)` |
| `slip` | ใช่ | ใช่ | `slip($uid)` |

### class LiffController extends Controller

#### `public index(): Response`
**ทำอะไร:** render หน้า LIFF ส่ง `liffId` จาก setting `line_liff_id` (trim) ใส่ header `Cache-Control: no-store`, `X-Robots-Tag: noindex`
**คืนค่า:** `Response` HTML
**View ที่ใช้:** `app/Views/public/liff.php` (title, heading, who, narrow, liffId)

#### `public api(): Response`
**ทำอะไร (เป็น dispatcher ของ API):**
1. `postTooLarge()` → `fail(..., 413)` บอกขนาดสูงสุด
2. ไม่ใช่ POST → `fail('POST only', 405)`
3. อ่าน `act` จาก query; `session` → `session()`
4. act อื่นต้องมี `$_SESSION['liff']['uid']` (ไม่มี → 401 "เซสชันหมดอายุ") และ `Csrf::valid($r)` (ไม่ผ่าน → 419)
5. `switch(act)` → `state`/`link`/`unlink`/`slip`; ไม่รู้จัก → 404 "ไม่รู้จักคำสั่งนี้"

**รับเข้า:** POST + query `act`
**คืนค่า:** `Response` JSON เสมอ

#### `private fail(string $message, int $status = 400): Response`
**ทำอะไร:** helper คืน JSON `{ok:false,error:$message}` + `Cache-Control: no-store`

#### `private out(array $data): Response`
**ทำอะไร:** helper คืน JSON (200) + `Cache-Control: no-store`

#### `private session(): Response`
**ทำอะไร:** เข้าสู่ระบบด้วย LINE ID token
1. **ตรวจที่มาของคำขอ:** ถ้ามี header `Origin` ต้องมี host(:port) ตรงกับ `Request::host()` และถ้ามี `Sec-Fetch-Site` ต้องเป็น `same-origin` หรือ `none` มิฉะนั้น 403 (กันเว็บอื่นสั่งเบราว์เซอร์ลูกค้าให้ login ด้วย LINE ของคนอื่นแบบเงียบ ๆ ซึ่งทำให้การจองไปผูกผิดคน) — ถ้าไม่มีทั้งสอง header (client ที่ไม่ใช่เบราว์เซอร์) จะผ่านการตรวจนี้ แต่ยังต้องมี ID token ที่ LINE ยืนยัน
2. ถ้า setting `line_liff_id` ว่าง → 503 "ร้านยังไม่ได้เปิดใช้งานส่วนนี้"
3. `LineService::verifyIdToken($_POST['id_token'])` (ตรวจกับเซิร์ฟเวอร์ LINE) — ไม่ผ่าน → 401
4. `Session::regenerate()` (กัน session fixation) แล้วเก็บ `$_SESSION['liff'] = [uid(sub), name, picture]`
5. คืน `state()` (ซึ่งรวม CSRF token ใหม่ให้หน้าเว็บใช้ในคำขอถัดไป)

#### `private link(string $uid): Response`
**ทำอะไร:** ผูกบัญชี LINE กับการจอง 1 รายการ
1. **rate limit:** `ActivityLog::liffFailsByUser($uid)` ≥ 6 หรือ `liffFailsByIp($ip)` ≥ 20 (ในช่วง 15 นาที จากตาราง activity_logs action `liff_link_fail`) → 429
2. `booking_no` (upper-case) และ `phone` (`phone_digits`); ว่าง/เบอร์สั้นกว่า 9 → 400
3. `Booking::findByNo($no)` + `phone_match` — ไม่ตรง → `ActivityLog::log('liff_link_fail', null, null, $uid)` แล้ว 400
4. `LineService::linkBooking($uid,$b)` (ผูกเฉพาะรายการที่พิสูจน์แล้ว) + log `liff_linked`
5. ถ้าเปิดแจ้งเตือน LINE (`notify_line_enabled === '1'`) และเทมเพลต `linked` ไม่ถูกปิด → push ข้อความยืนยัน (ผิดพลาดแล้วถูกกลืน ไม่ทำให้การผูกล้ม)
6. คืน `state()` + `notice` "ผูกบัญชีเรียบร้อย"

**รับเข้า:** POST `booking_no`, `phone`

#### `private unlink(string $uid): Response`
**ทำอะไร:** `LineService::unlinkUser($uid)`, log `liff_unlinked`, คืน `state()` + notice "ยกเลิกการผูกบัญชีแล้ว"

#### `private slip(string $uid): Response`
**ทำอะไร:** แจ้งโอนเงิน
1. `Booking::isLineLinked($uid)` — ยังไม่ผูก → 403
2. `Booking::findOfLineUser(booking_id, $uid)` — ต้องเป็นการจองที่ LINE นี้ผูกไว้เอง (กันส่งสลิปเข้าการจองคนอื่น) ไม่พบ → 404
3. `Payment::pendingCountForBooking` ≥ 3 → ปฏิเสธ (กันส่งรัวจนพื้นที่เต็ม)
4. `PaymentService::submitSlip($b, $_FILES['slip'], round(amount,2))` (ตรวจสถานะ ไฟล์ ยอด และแจ้งร้าน อยู่ใน service) — exception → `BookingService::publicError()` แปลงเป็นข้อความที่แสดงลูกค้าได้
5. คืน `state()` + notice

**รับเข้า:** POST (multipart) `booking_id`, `amount`, ไฟล์ `slip`

#### `private state(): array`
**ทำอะไร:** ประกอบข้อมูลทั้งหมดที่หน้าจอ LIFF ต้องใช้ (ส่งเฉพาะของผู้ใช้ LINE คนนี้): `ok`, `csrf` (`Csrf::token()`), `profile`, `linked`, `shop` (ชื่อ/โทร/ลิงก์รถ), `bank` (ธนาคาร เลขบัญชี ชื่อบัญชี PromptPay), `limit_mb`, `bookings`; ถ้าผูกแล้ว ดึง `Booking::forLineUser($uid, 12)` ใส่ `customer.name` และแปลงแต่ละรายการด้วย `bookingForScreen()`
**คืนค่า:** array (ใช้ห่อด้วย `out()` หรือ `json()`)

#### `private bookingForScreen(array $b): array`
**ทำอะไร:** แปลงแถวการจองเป็นรูปแบบพร้อมแสดง: ป้ายสถานะ (`booking_status_label`, `payment_status_label`), ยอดรวม = `grand_total + extra_charge`, คงเหลือ = max(0, ยอดรวม − paid_amount), `isOpen` = ยังไม่ cancelled/completed, **ยอดที่แนะนำให้โอน** (`suggest`): ถ้ายังไม่เคยจ่ายเลย = min(คงเหลือ, มัดจำ) มิฉะนั้น = คงเหลือ; ตัวเลขเงินจัดรูปแบบด้วย `money0` (ยกเว้น `balance_raw`, `suggest` เป็นตัวเลข); `can_pay = isOpen && balance>0`; รวมรูปรถ (`upload_exists`), วันที่ไทย, จุดรับ/คืน (ว่างแสดง "ที่ร้าน"), `extras` และ `payments`

#### `private extraChargesForScreen(array $booking): array`
**ทำอะไร:** วน `Booking::extraLines($booking, true)` แปลงเป็น `[label, amount(money0)]`

#### `private paymentsForScreen(int $bookingId): array`
**ทำอะไร:** ดึง `Payment::recentForBooking($id, 8)` แปลงสถานะเป็นป้ายไทย (pending=รอตรวจสอบ, approved=ยืนยันแล้ว, rejected=ไม่ผ่าน), ยอด, วันที่, เหตุผลปฏิเสธ, ชนิด

---

## app/Controllers/LineWebhookController.php

**หน้าที่ของไฟล์:** ปลายทางที่ LINE เรียก (ตั้งใน LINE Developers → Webhook URL): เพิ่มเพื่อน (ส่งข้อความต้อนรับ), เลิกติดตาม (ยกเลิกการผูกบัญชี), ข้อความ (ตอบเฉพาะคำที่รู้จัก). URL: `POST /line/webhook` (และ `/line/webhook.php`), `GET /line/webhook`

### class LineWebhookController extends Controller

#### `public handle(): Response`
**ทำอะไร:**
1. อ่าน raw body และ header `X-Line-Signature`
2. `LineService::verifySignature($raw,$sig)` — ไม่ผ่าน → JSON 401 "bad signature" (นี่คือการป้องกันแทน CSRF)
3. `json_decode` แล้ววน `events[]` เรียก `event($ev)` ทีละรายการใน `try/catch` — event ที่พังบันทึก `ActivityLog::log('line_webhook_error', ..., ข้อความ error ตัด 200 ตัว)` และไปต่อ (เพราะถ้าไม่ตอบ 200 LINE จะส่งซ้ำทั้งชุด)
4. ตอบ `{ok:true}` 200 เสมอเมื่อลายเซ็นถูก (ปุ่ม Verify ของ LINE ส่ง events ว่างมา)

**คืนค่า:** JSON

#### `public notPost(): Response`
**ทำอะไร:** JSON `{ok:false,error:'POST only'}` สถานะ 405 (route GET)

#### `private event(array $ev): void`
**ทำอะไร:** ประมวลผล event เดียว
1. ดึง `type`, `source.userId`, `replyToken`, `deliveryContext.isRedelivery`
2. ข้ามถ้าไม่มี userId หรือ type ไม่ใช่ `follow|unfollow|message`; ถ้าเป็น `message` ที่ไม่ใช่ text (รูป/สติกเกอร์) ข้าม ไม่บันทึก
3. ถ้าไม่ใช่ `unfollow` เรียก `LineService::profile($uid)` ดึง displayName
4. `LineEvent::insert([...])` บันทึกลงตาราง `line_events` (display_name ตัด 120, text ตัด 500) — เพื่อให้เจ้าของร้านหยิบ user id ไปตั้งเป็นผู้รับแจ้งเตือน
5. `unfollow` → `LineService::unlinkUser($uid)` แล้วจบ
6. ถ้าเป็นการส่งซ้ำ (redelivery) หรือไม่มี replyToken → จบ (บันทึกแล้วแต่ไม่ตอบซ้ำ)
7. เตรียมตัวแปรเทมเพลต `ร้าน, โทร, ลิงก์ (LIFF URL), ชื่อ`
8. `follow` → ถ้าเทมเพลต `welcome` ไม่ถูกปิด (`templateOff`) ตอบด้วย `LineService::fill(template('welcome'), $base)`
9. `message` (ตามลำดับ): (ก) คำว่า `myid, my id, ไอดี, userid, user id` → ตอบ LINE user id; (ข) regex หาเลขที่จอง (`/\b([A-Z]{1,4}-?\d{4,}(?:-?\d+)?)\b/i`) แล้ว `Booking::findByNo(strtoupper)` ถ้าเจอตอบลิงก์ LIFF พร้อมเลขที่ (เหลือแค่ยืนยันเบอร์ — ไม่เปิดเผยรายละเอียดการจองจนกว่าจะยืนยัน); (ค) มีคำสำคัญ `จอง, สถานะ, สลิป, โอน, เช็ค, เช็ก, ตรวจสอบ` → ตอบลิงก์ LIFF + ลิงก์ `site_url('cars')`; (ง) ข้อความอื่นไม่ตอบ (ให้พนักงานตอบในแอป LINE OA)

**หมายเหตุ:** ไม่ใช้ view; คืน void

---

## app/Controllers/PageController.php

**หน้าที่ของไฟล์:** หน้าข้อมูลทั่วไปแบบ static. URL: `GET /how-to`, `/faq`, `/contact`, `/terms`

### class PageController extends Controller

#### `public howTo(): Response`
**ทำอะไร:** ส่ง `late` = `LateReturnService::policyText()` (ข้อความนโยบายคืนรถช้าที่ดึงจากตั้งค่าจริง)
**View ที่ใช้:** `app/Views/public/how_to.php`

#### `public faq(): Response`
**ทำอะไร:** สร้างอาร์เรย์ `$faqs` เป็นคู่ `[คำถาม, คำตอบ]` 14 ข้อ โดยตัวเลขที่เกี่ยวกับเงื่อนไขดึงจากการตั้งค่า/Service จริง (เพื่อไม่ให้หน้าเว็บบอกต่างจากที่ระบบคิดเงิน): `booking_lead_hours` (default 3), `LateReturnService::policyText()` (grace/fee), `open_time/close_time`, `airport_open_time/airport_close_time`, `booking_deposit`, `DeliveryFeeService::tiersText()`, `free_km_per_day`, `over_km_fee` — แต่บางข้อเป็นข้อความตายตัวใน controller (เงินประกัน 3,000/5,000/10,000 บาท, นโยบายยกเลิก 3 วัน/50%) ถ้าธุรกิจเปลี่ยนต้องแก้โค้ดตรงนี้
**View ที่ใช้:** `app/Views/public/faq.php` (faqs)

#### `public contact(): Response`
**ทำอะไร:** ส่ง `phoneRaw` = เบอร์จาก setting `phone` ที่ตัดอักขระที่ไม่ใช่ตัวเลข (ใช้ทำลิงก์ `tel:`)
**View ที่ใช้:** `app/Views/public/contact.php`

#### `public terms(): Response`
**ทำอะไร:** ส่ง `late` (นโยบายคืนช้า), `fuelFee` (`LateReturnService::fuelFeePerLevel()` ค่าน้ำมันต่อขีด), `tiers` (`DeliveryFeeService::tiersText()` ขั้นค่าส่งรถ)
**View ที่ใช้:** `app/Views/public/terms.php`

ทุก method: รับเข้า = ไม่มี, คืนค่า = `Response` HTML 200

---

## app/Controllers/SignController.php

**หน้าที่ของไฟล์:** หน้าเซ็นเอกสารบนมือถือสำหรับลูกค้า ไม่ต้องล็อกอิน ใช้ลิงก์ที่มีโทเคนสุ่ม 128 บิต (hex 32 ตัว) ของแต่ละการจอง. URL: `GET /sign?no=<เลขที่จอง>&k=<contract|return>&t=<token>` และ `POST /sign` (ตรวจ CSRF โดย Router). ปุ่มสร้างลิงก์อยู่ในหน้าการจองหลังบ้าน (เปิดบนเครื่องพนักงาน/คัดลอก/ส่ง LINE)

### class SignController extends Controller

#### `private context(): array`
**ทำอะไร:** เตรียมข้อมูลที่หน้าเซ็นต้องใช้
1. อ่าน `no`, `t` (token), `k` (default `contract`) และรายการชนิดเอกสารจาก `SignatureService::kinds()`
2. ถ้า `no` ไม่ว่าง **และ** token เป็น hex 32 ตัว (regex) จึง `Booking::findByNoForContract($no)`
3. `valid` = พบการจอง มี `sign_token` และ `hash_equals` ตรงกับ token (constant-time) และ `kind` อยู่ในรายการ — ไม่ valid → คืน context ว่าง ๆ (`valid=false`) ทันที
4. ถ้า valid: `docName`, ข้อมูลรถ, `Inspection::get(bookingId, kind==='return' ? 'in' : 'out')`; ข้อความที่ลูกค้ารับรอง (`termsHtml`): สัญญา → `SignatureService::contractTermsHtml()` (เติมเลขไมล์จากการตรวจสภาพถ้ายังไม่ส่งมอบ) / ใบรับคืน → ข้อความคงที่; `termsText = plainText(termsHtml)`; `sig = Signature::active(bookingId, kind)` (ลายเซ็นที่มีอยู่แล้ว)
5. คืนอาร์เรย์เต็ม รวม `photos` (`InspectionService::photoMap`), `extras` (`Booking::extraLines` เฉพาะ return), `fields` (`SignatureService::fields`), `blocker` (เหตุผลที่เซ็นไม่ได้ เช่น ยังไม่พร้อม — `null` ถ้าเซ็นแล้ว), `viewHash` (`SignatureService::viewHash` — แฮชของสิ่งที่ลูกค้ากำลังเห็น)

**คืนค่า:** array context

#### `private render(array $ctx): Response`
**ทำอะไร:** render view `public/sign` ด้วย `$ctx + [title, heading, sub]` สถานะ 200 ถ้า valid ไม่เช่นนั้น 404 ("ลิงก์ไม่ถูกต้อง") และใส่ header กันแคช/ดัชนี/referrer: `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer` (token อยู่ใน URL จึงต้องไม่รั่วผ่าน Referer)
**View ที่ใช้:** `app/Views/public/sign.php`

#### `public show(): Response`
**ทำอะไร:** `render(context())` — แสดงหน้าเซ็น (หรือหน้า "ลิงก์ไม่ถูกต้อง", หรือแสดงลายเซ็นเดิมถ้าเซ็นแล้ว, หรือ blocker)
**รับเข้า:** GET `no`, `k`, `t` (และ `done=1` หลังเซ็นสำเร็จ ใช้ใน view)

#### `public store(): Response`
**ทำอะไร:** บันทึกลายเซ็น
1. `context()`; ไม่ valid หรือเซ็นไปแล้ว (`sig`) → render (ไม่บันทึกซ้ำ)
2. ใน try: ตรวจ `hash_equals($ctx['viewHash'], $_POST['seen'])` — ถ้าร้านแก้ข้อมูลหลังลูกค้าเปิดหน้านี้ค้างไว้ → โยน RuntimeException "ร้านมีการปรับข้อมูล... กรุณาตรวจ...แล้วเซ็นใหม่" (ลูกค้าเซ็นสิ่งที่เห็นจริงเท่านั้น)
3. `SignatureService::save($b, $car, $kind, sig(รูปลายเซ็น), name, accept, pdpa, termsText)` (ตรวจ blocker/ความถูกต้องข้างใน)
4. แจ้งร้านทาง LINE: `NotificationService::queue('line', setting('line_admin_user_id'), ข้อความ, null, 'bookings', id)` + `flush()` ใน try/catch แยก (ล้มเหลวไม่กระทบลูกค้า)
5. redirect `sign?no=..&k=..&t=..&done=1`
6. จับ `\Throwable` ทั้งหมด: `BookingService::publicError($ex, 'บันทึกไม่สำเร็จ...')` ใส่ `ctx['error']`, โหลดลายเซ็นปัจจุบันซ้ำ (เผื่อมีคนเซ็นไปพร้อมกัน) แล้ว render หน้าเดิมพร้อมข้อความ

**รับเข้า:** query `no,k,t` + POST `_csrf`, `seen`, `sig`, `name`, `accept`, `pdpa`
**คืนค่า:** `Response` (redirect เมื่อสำเร็จ หรือ HTML)

---

## app/Controllers/SitemapController.php

**หน้าที่ของไฟล์:** สร้าง `sitemap.xml` อัตโนมัติ. URL: `GET /sitemap.xml` (legacy `/sitemap.php`)

### class SitemapController extends Controller

#### `public index(): Response`
**ทำอะไร:** สร้างรายการ URL คงที่ 6 หน้า (`/`, `cars`, `how-to`, `faq`, `contact`, `terms` พร้อม priority/changefreq) ด้วย `site_url()`; ต่อด้วยหน้ารถทุกคันจาก `Car::sitemapRows()` (priority 0.8, weekly, `lastmod` จาก `updated_at`); ประกอบ XML ด้วยการต่อสตริง โดย `e()` ทุกค่า; ตอบ `application/xml`
**รับเข้า:** ไม่มี
**คืนค่า:** `Response::text($xml, 200, 'application/xml')`
**View ที่ใช้:** ไม่มี
**หมายเหตุ:** ถ้าไม่ได้ตั้งค่า `site_url` และไม่มี `HTTP_HOST` URL จะเป็นแบบ relative

---

## app/Controllers/TrackController.php

**หน้าที่ของไฟล์:** ลูกค้าตรวจสถานะการจองและแจ้งโอนเงิน (ยืนยันตัวตนด้วย เลขที่จอง + เบอร์โทร ไม่ต้องล็อกอิน). URL: `GET /track`, `POST /track`

### class TrackController extends Controller

#### `private lookup(string $no, string $phone): array`
**ทำอะไร:** ถ้า `no` หรือ `phone` ว่าง คืน `[null, null]` (ยังไม่ค้น/ไม่แสดง error); `Booking::findByNoWithCar($no)` แล้วเทียบเบอร์ด้วย `phone_match()` (ตัวเลขล้วน รองรับ +66/ขีด/เว้นวรรค ≥9 หลัก) — ไม่ตรงถือว่าไม่พบ
**คืนค่า:** `[array การจอง|null, string ข้อความผิดพลาด|null]` — ข้อความเดียวกัน ("ไม่พบการจองที่ตรงกับเลขที่และเบอร์โทรศัพท์นี้") ไม่ว่าเลขที่ผิดหรือเบอร์ผิด (ไม่บอกใบ้ว่าส่วนไหนถูก)

#### `public index(): Response`
**ทำอะไร:** อ่าน `no`, `phone` (GET) → `lookup()`; ถ้าเจอโหลด `Payment::forBooking(id)` และ `Booking::extraLines($b, true)` (รายการค่าใช้จ่ายเพิ่ม); ส่ง `limit = UploadService::limitBytes()` ให้ view แสดงขนาดสลิปสูงสุด
**คืนค่า:** `Response` HTML
**View ที่ใช้:** `app/Views/public/track.php` (no, phone, b, err, payments, extras, limit)

#### `public slip(): Response`
**ทำอะไร:** แจ้งโอน: อ่าน `no`, `phone` แล้ว `lookup()` ซ้ำเพื่อยืนยันตัวตน (ไม่เชื่อว่าเคยผ่านหน้า index); ไม่พบ → redirect กลับ `track?no=..&phone=..` เฉย ๆ; พบ → ถ้ามีสลิปสถานะรอตรวจ ≥ 3 (`Payment::pendingCountForBooking`) ปฏิเสธด้วย exception; อ่าน `paid_at` (แปลงด้วย `strtotime` ถ้าผิดเป็น null) แล้วเรียก `PaymentService::submitSlip($b, $r->file('slip'), round(amount,2), paidAt, note(ตัด 250))` (ตรวจสถานะ ไฟล์ ยอด และแจ้งร้าน — ใช้ฟังก์ชันเดียวกับหน้า LINE); สำเร็จ flash `ok`; exception → flash `danger` ผ่าน `BookingService::publicError()`; สุดท้าย redirect กลับหน้า track
**รับเข้า:** POST (multipart) `no`, `phone`, `amount`, `paid_at`, `note`, ไฟล์ `slip` + `_csrf`
**คืนค่า:** redirect 302 เสมอ
**View ที่ใช้:** ไม่มี

---

## สิ่งที่ควรระวัง / ข้อสังเกต (จากการอ่านโค้ด)

1. `CarController::show()` เรียก `$this->notFound('ไม่พบรถคันนี้', 'รถอาจถูกนำออกจากระบบแล้ว')` ด้วย 2 อาร์กิวเมนต์ แต่ `Controller::notFound(string $title)` รับแค่ตัวเดียว (PHP ไม่ error แต่ข้อความที่สองถูกทิ้ง) หน้า 404 จะแสดงข้อความ default "หน้านี้อาจถูกย้ายหรือลบไปแล้ว"
2. `thai_number_words()` / `baht_text()` ไม่รองรับตัวเลข ≥ 10,000,000 (index `$units[7]` ไม่มี)
3. `/track` (GET และ POST) ไม่มีการจำกัดจำนวนครั้งที่ลองผิด ต่างจาก `LiffController::link` ที่จำกัด 6 ครั้ง/15 นาทีต่อ LINE user และ 20 ครั้ง/ IP — การเดาเบอร์ 9+ หลักกับเลขที่จองทำได้ยาก แต่ไม่มี throttle
4. หน้า `/track` redirect กลับด้วยเบอร์โทรใน query string (`track?no=..&phone=..`) ทำให้เบอร์อยู่ใน URL/history/log (ออกแบบมาเพื่อความสะดวก)
5. `HealthController::index` ตอบ HTTP 200 เสมอเมื่อใส่ key ถูก แม้ `status` ใน JSON เป็น `fail` (monitor ต้องอ่าน body)
6. Legacy redirect `/admin/contract-print.php` และ `/admin/document-print.php` ไม่ตรวจว่ามี `id` — ถ้าไม่ส่ง id จะไปที่ `admin/bookings/0/contract` (404)
7. `/admin/logout` เป็น GET (ไม่ผ่าน CSRF) — logout ข้ามเว็บทำได้ ความเสี่ยงต่ำแต่เป็นข้อสังเกต
8. `human_bytes()` มี `return ''` ที่ไม่มีทางทำงาน (ไม่เป็นปัญหา)
