# 01 — Core (จุดเข้าระบบ, bootstrap, config, cron และ app/Core)

พื้นที่นี้คือ "เครื่องยนต์" ของ MVC ที่เขียนเอง (ไม่ใช้ framework): `index.php` เป็นจุดเข้าเดียวของเว็บ, `bootstrap.php` เตรียมระบบ (config, autoloader, error handler, session), แล้ว `app/Core/*` ทำหน้าที่ Request → Router → Controller → Response/View ส่วน `cron/daily.php` เป็นจุดเข้าที่สองสำหรับงานอัตโนมัติ
โค้ดทั้งหมดอยู่ใน namespace `App\` ซึ่ง autoloader แมปไปที่โฟลเดอร์ `app/` (เช่น `App\Core\Router` = `app/Core/Router.php`)

```
Browser -> .htaccess -> index.php -> bootstrap.php (config, autoload, helpers, ErrorHandler, Session)
        -> Application::run -> Request::capture -> routes.php (ลงทะเบียน Route ใน Router)
        -> Router::dispatch -> [postTooLarge? -> CSRF -> middleware ทีละตัว] -> Controller::method
        -> Response (หรือ HttpException ที่พก Response) -> Response::send
cron:  cron/daily.php -> bootstrap.php -> ตรวจ key -> CronService::run()
```

---

## index.php
- **หน้าที่ของไฟล์**: Front controller — ทุกคำขอเว็บเข้ามาที่ไฟล์นี้ (ผ่าน .htaccess) ไฟล์นี้บางมาก ไม่มี class/function
- อ่านจากบนลงล่าง:
  1. คอมเมนต์ใหญ่อธิบายโครงสร้าง MVC (Core / Models / Services / Controllers / Views)
  2. `require_once app/bootstrap.php` — เตรียมระบบทุกอย่างก่อน
  3. `\App\Core\Application::run()` — เริ่มรับคำขอและส่งคำตอบ
- **เรียกโดย**: web server. **เรียกต่อ**: `bootstrap.php`, `Application::run`

---

## app/bootstrap.php
- **หน้าที่ของไฟล์**: เริ่มระบบ ทุกจุดเข้า (`index.php`, `cron/daily.php`) ต้อง require ก่อนทำอย่างอื่น
- อ่านจากบนลงล่าง:
  1. `require_once config/config.php` — โหลดค่าคงที่ทั้งหมด (DB, BASE_URL ฯลฯ)
  2. `define('APP_PATH', __DIR__)` = โฟลเดอร์ `app/` (ใช้ใน `Application::run` เพื่อหา `routes.php`); `define('VIEW_PATH', __DIR__.'/Views')` = โฟลเดอร์ view (ใช้ใน `View::capture`)
  3. **autoloader** (closure ผ่าน `spl_autoload_register`): รับชื่อคลาสเต็ม ถ้าไม่ขึ้นต้นด้วย `App\` ก็ไม่ทำอะไร, ถ้าใช่ก็ตัด prefix `App\` ออก แทน `\` ด้วย `/` ต่อ `.php` ถ้ามีไฟล์จริง (`is_file`) จึง `require` — ทำให้ไม่ต้องเขียน `require` ทีละคลาส (นี่คือ PSR-4 แบบย่อ)
  4. `require_once Helpers/functions.php` — ฟังก์ชันช่วยแบบ global เช่น `e()`, `url()`, `asset()`, `csrf_field()`, `setting()` (ไฟล์นี้อยู่นอกขอบเขตเอกสารนี้)
  5. **กันข้อมูลผิดรูป**: วนทุกค่าใน `$_GET` ถ้าเป็น array (เช่นมีคนส่ง `?no[]=x`) ให้เปลี่ยนเป็น `''` และถ้า `$_POST` ไม่มี key เดียวกันก็แก้ `$_REQUEST` ด้วย เหตุผล: ไม่งั้น `trim()` ฯลฯ จะโยน `TypeError` เกิดหน้าขาว 500 แล้ว `unset($__k,$__v)` ล้างตัวแปรชั่วคราว (หมายเหตุ: ทำเฉพาะ `$_GET` ไม่แตะ `$_POST` เพราะฟอร์มบางหน้าใช้ array จริง เช่น `Request::arr`)
  6. `ErrorHandler::register()` — ติดตั้งตัวจัดการ exception ที่ไม่มีใครดัก
  7. `Session::start()` — เปิด session ตั้งแต่ต้น ก่อนมี output ใด ๆ (ถ้าเริ่มหลังพ่น HTML cookie จะส่งไม่ได้ ทำให้ CSRF token และ flash message หาย)
- **เรียกโดย**: `index.php`, `cron/daily.php`

---

## config/config.php
- **หน้าที่ของไฟล์**: ค่าคอนฟิกหลัก (แก้ไฟล์นี้ไฟล์เดียวตอนติดตั้งบน cPanel) ใช้ `define()` ล้วน ๆ ถูก require โดย `bootstrap.php`
- อ่านจากบนลงล่าง:
  - **ฐานข้อมูล**: `DB_HOST`='localhost', `DB_NAME`='carrent', `DB_USER`='carrent', `DB_PASS`='CHANGE_ME' (ต้องเปลี่ยนตอนติดตั้ง), `DB_CHARSET`='utf8mb4' — ใช้โดย `Database::pdo()` / `Database::connect()`
  - **BASE_URL_MANUAL** = `null` (null = ให้ระบบตรวจเอง; จะตั้งเองก็ใส่เช่น `'/carrent'`)
  - **BASE_URL** (ถ้ายังไม่ถูก define ไว้): ถ้า `BASE_URL_MANUAL` เป็น string ใช้ค่านั้นตัด `/` ท้ายออก; ไม่งั้นคำนวณเองโดย:
    1. หา `$__docRoot` (DOCUMENT_ROOT ปรับ `\`→`/` ตัด `/` ท้าย) และ `$__appRoot` (โฟลเดอร์ระบบ = `dirname(__DIR__)`)
    2. หา `$__docReal`/`$__appReal` ด้วย `realpath()` เผื่อโฮสต์ที่มี symlink
    3. ถ้า appRoot ขึ้นต้นด้วย docRoot → BASE_URL = ส่วนที่เกิน; ถ้าไม่ตรงลองแบบ realpath; ถ้ายังไม่ได้ ใช้ทางสำรอง `dirname(SCRIPT_NAME)` และตัด `/cron` ท้ายออก (เพราะ cron อยู่ในโฟลเดอร์ย่อย)
    4. จัดรูปให้ไม่มี `/` ท้าย และถ้าได้ `/` เดี่ยว ให้เป็น `''`
    ตัวอย่าง: ติดตั้งที่ `example.com/carrent` → `'/carrent'`; ที่ root → `''`
  - **Path**: `APP_ROOT` (รากโปรเจกต์), `UPLOAD_DIR` (`APP_ROOT/uploads`), `UPLOAD_URL` (`BASE_URL/uploads`)
  - **ความปลอดภัย/เซสชัน**: `SESSION_NAME`='THONGLORCARRENT', `SESSION_LIFETIME`=7200 วินาที (2 ชม.), `MAX_LOGIN_ATTEMPTS`=5, `LOGIN_LOCK_MINUTES`=15 (ล็อกอินผิดเกินแล้วล็อก — ใช้ใน AuthService), `SITE_VARIANT`='A' และ `SITE_VARIANT_LABEL`='ดีไซน์เดิม' (เกี่ยวกับการเทียบดีไซน์ ไม่ได้ใช้ใน Core)
  - **ระบบ**: `APP_TZ`='Asia/Bangkok', `APP_DEBUG`=false (production ต้อง false), `APP_VERSION`='2.0.0'
  - `date_default_timezone_set(APP_TZ)` ตั้งเวลา PHP; ถ้า `APP_DEBUG` เปิด `display_errors` + `E_ALL`, ไม่งั้นปิด `display_errors` และ report `E_ALL & ~E_NOTICE & ~E_DEPRECATED` (ไม่โชว์ error ให้ผู้ใช้เห็น)
- **ข้อสังเกต**: ตัวแปรชั่วคราว `$__docRoot` ฯลฯ ไม่ได้ `unset` จึงค้างเป็น global (ไม่เป็นอันตราย)

---

## cron/daily.php
- **หน้าที่ของไฟล์**: จุดเข้าสำหรับงานอัตโนมัติรายวัน (ตั้งใน cPanel Cron Jobs) เรียกได้ 2 แบบ: CLI `php cron/daily.php "KEY"` (แนะนำ) หรือผ่านเว็บ `daily.php?key=KEY` (สำรอง) ตรวจรหัสแล้วเรียก `App\Services\CronService::run()` ที่มีรายละเอียดงานทั้งหมด
- อ่านจากบนลงล่าง:
  1. `require_once ../app/bootstrap.php` (ใน CLI `Session::start()` จะไม่เปิดคุกกี้)
  2. `$isCli = PHP_SAPI === 'cli'`; `$key` = argv[1] ถ้า CLI ไม่งั้น `$_GET['key']`; `$real` = `setting('cron_key', 'CHANGE-THIS-CRON-KEY')` (อ่านจาก DB ผ่าน helper)
  3. ถ้า `!hash_equals($real, $key)` → HTTP 403 พิมพ์ `forbidden` แล้ว exit (ใช้ `hash_equals` เพื่อกัน timing attack)
  4. ถ้าไม่ใช่ CLI และ key ยังเป็นค่าตั้งต้น `CHANGE-THIS-CRON-KEY` → 403 พร้อมข้อความให้เปลี่ยน key (รหัสตั้งต้นใครก็รู้ จึงยอมเฉพาะ CLI)
  5. ถ้าเป็นเว็บ ส่ง header `text/plain; charset=utf-8`
  6. `$lines = CronService::run()` แล้วพิมพ์ `thonglorcarrent cron <เวลา>` ตามด้วยบรรทัดผลลัพธ์
- **เรียกต่อ**: `setting()`, `CronService::run()` (ไม่ได้อ่านโค้ดของ CronService ในเอกสารนี้ — อยู่นอกขอบเขต)

---

## app/Core/Application.php
- **หน้าที่ของไฟล์**: ตัวขับเคลื่อนระบบ: รับคำขอ → ส่งให้ Router → ส่งผลลัพธ์กลับ เรียกจาก `index.php` ที่เดียว

### class Application (final)
- ไม่มี property; มีเมธอด static เดียว

#### `public static run(): void`
- **ทำอะไร**:
  1. `Request::capture()` สร้างอ็อบเจ็กต์ Request ของคำขอนี้ (และเก็บเป็น current)
  2. `new Router()`
  3. `require APP_PATH.'/routes.php'` — ไฟล์นี้ return ฟังก์ชัน (closure) รับ Router; เรียก `$routes($router)` เพื่อลงทะเบียนเส้นทางทั้งหมด
  4. `$router->dispatch($request)` ในบล็อก try; ถ้ามี `HttpException` หลุดมา (เช่นโยนจากนอก `Router::run`) ใช้ `$e->response()` เป็นคำตอบ
  5. `$response->send()` ส่งออกจริง
- **รับเข้า**: ไม่มี. **คืนค่า**: void
- **เรียกใช้โดย**: `index.php`. **เรียกต่อ**: `Request::capture`, `Router::dispatch`, `HttpException::response`, `Response::send`
- หมายเหตุ: exception อื่น ๆ ไม่ถูกดักที่นี่ แต่ไปถึง `ErrorHandler::handle`

---

## app/Core/Controller.php
- **หน้าที่ของไฟล์**: คลาสแม่ของ controller ทุกตัว ช่วยแสดง view / redirect / ตอบ JSON; controller มีหน้าที่แค่รับคำขอ เรียก model/service แล้วเลือก view — ไม่เขียน SQL ที่นี่ ถูก extend โดย controller ใน `app/Controllers/*` (Router สร้างด้วย `new $class($request)`)

### class Controller (abstract)
- property: `protected Request $request` (promoted จาก constructor)

#### `public __construct(protected Request $request)`
- **ทำอะไร**: เก็บ Request ไว้ใน `$this->request` ให้เมธอดลูกใช้
- **รับเข้า**: `$request` — คำขอปัจจุบัน. **เรียกใช้โดย**: `Router::call`, `Router::dispatch` (สร้าง `ErrorController`), `Controller::notFound`

#### `protected view(string $name, array $data = [], int $status = 200): Response`
- **ทำอะไร**: เรนเดอร์ view ด้วย `View::make($name,$data)` ได้ string HTML แล้วห่อเป็น `Response::html(..., $status)`
- **รับเข้า**: `$name` ชื่อไฟล์ใต้ `app/Views` ไม่ต้องมี `.php` (เช่น `'public/faq'`), `$data` ตัวแปรที่ส่งให้ view, `$status` HTTP status
- **คืนค่า**: `Response`
- **ตัวอย่าง**: `return $this->view('public/faq', ['faqs' => $faqs]);`

#### `protected redirect(string $path, int $status = 302): Response`
- **ทำอะไร**: ส่งต่อไป `Response::redirect` ($path เป็น path ภายในระบบ เช่น `'cars/3'`, `'admin/bookings'` ไม่ต้องใส่ BASE_URL)
- **รับเข้า**: `$path`, `$status` (302 ปกติ). **คืนค่า**: `Response`
- **ตัวอย่าง**: `return $this->redirect('admin/login');`

#### `protected json($data, int $status = 200): Response`
- **ทำอะไร**: ตอบ JSON ผ่าน `Response::json`. **รับเข้า**: ข้อมูลที่ `json_encode` ได้, status. **คืนค่า**: `Response`

#### `protected back(string $fallback = ''): Response`
- **ทำอะไร**: redirect กลับหน้าที่มา โดยใช้ `Request::safeRefererPath($fallback)` (รับเฉพาะ referer ภายในเว็บนี้ กัน open redirect) ไม่มี referer ก็ไป `$fallback`
- **รับเข้า**: `$fallback` path สำรอง. **คืนค่า**: `Response` (redirect)
- **หมายเหตุ**: grep ไม่พบ `->back(` ถูกเรียกที่ไหนนอก Core (ดูข้อสังเกตท้ายเอกสาร)

#### `protected flash(string $type, string $message): void`
- **ทำอะไร**: ทางลัดเรียก `Flash::add` เก็บข้อความแจ้งผลครั้งเดียวไว้ใน session
- **รับเข้า**: `$type` (`ok|warn|danger|info`), `$message`
- **ตัวอย่าง**: `$this->flash('ok', 'บันทึกแล้ว');`

#### `protected notFound(string $title = 'ไม่พบหน้าที่ต้องการ'): Response`
- **ทำอะไร**: สร้าง `\App\Controllers\ErrorController` ด้วย request เดิมแล้วเรียก `->notFound($title)` ให้หน้า 404 (ErrorController เลือก JSON หรือ HTML ตาม `wantsJson()`)
- **เรียกใช้โดย**: `Admin\AdminController` (บรรทัด ~24 ใช้คู่กับ `halt`), `CarController`

#### `protected halt(Response $response): never`
- **ทำอะไร**: โยน `HttpException($response)` เพื่อหยุดงานทันทีแล้วตอบ Response นี้ (ใช้ได้จากชั้นลึก เช่น helper ที่ต้อง return Response ไม่ได้) ถูกดักใน `Router::run` (และสำรองที่ `Application::run`)
- **ตัวอย่าง**: `$this->halt($this->redirect('admin/bookings'));` — ใช้ใน Admin\BookingController, CarController, CustomerController, DocumentController, AdminController

---

## app/Core/Csrf.php
- **หน้าที่ของไฟล์**: ป้องกัน CSRF (คนอื่นหลอกให้ผู้ใช้ที่ล็อกอินอยู่ส่งฟอร์มโดยไม่รู้ตัว) โทเคนสุ่มเก็บใน `$_SESSION['csrf']` ส่งมากับฟอร์ม (`_csrf`) หรือส่วนหัว `X-CSRF-Token` เรียกจาก helper `csrf_token()/csrf_field()` (ใน view) และ `Router::run` (ตรวจ)

### class Csrf (final)

#### `public static token(): string`
- **ทำอะไร**: ถ้า `$_SESSION['csrf']` ว่าง สร้างใหม่ด้วย `bin2hex(random_bytes(32))` (64 ตัวอักษร hex, สุ่มแบบ cryptographically secure) แล้วคืนค่านั้น — ใช้โทเคนเดิมตลอดอายุ session
- **คืนค่า**: string โทเคน. **เรียกใช้โดย**: `Csrf::field`, `csrf_token()` (functions.php), `LiffController` (ส่งให้ JS)

#### `public static field(): string`
- **ทำอะไร**: คืน HTML `<input type="hidden" name="_csrf" value="...">` โดยใช้ `e()` escape ค่า (กัน XSS ในแอตทริบิวต์)
- **เรียกใช้โดย**: `csrf_field()` ที่ใส่ในฟอร์มของ view. **ตัวอย่าง**: `<?= csrf_field() ?>` ภายใน `<form method="post">`

#### `public static valid(Request $request): bool`
- **ทำอะไร**: อ่านโทเคนที่ส่งมาตามลำดับ: `POST _csrf` (ต้องเป็น string ไม่ว่าง) → ถ้าไม่ได้ใช้ header `X-CSRF-Token` → ถ้ายังว่างใช้ query string `_csrf` จากนั้นเปรียบเทียบกับ `$_SESSION['csrf']` ด้วย `hash_equals` (กัน timing attack) ต้องมีโทเคนใน session ด้วย (`!empty`)
- **รับเข้า**: `$request`. **คืนค่า**: true/false
- **เรียกใช้โดย**: `Router::run` (กับทุก method ที่ไม่ใช่ GET/HEAD/OPTIONS และ Route ที่ไม่ได้ `noCsrf()`), `LiffController` (บรรทัด ~69)

---

## app/Core/Database.php
- **หน้าที่ของไฟล์**: เชื่อมต่อฐานข้อมูลด้วย PDO แบบ prepared statement เท่านั้น (ค่าที่ผู้ใช้ส่งมาแยกจาก SQL จึงกัน SQL injection) เป็น static helper ที่ Model/Service/Controller เรียก ขึ้นกับค่าคงที่ `DB_*`
- สำคัญ: ตั้ง `PDO::ATTR_EMULATE_PREPARES = false` (ใช้ native prepare) จึงต้องใช้ **ชื่อพารามิเตอร์ไม่ซ้ำกันในคำสั่งเดียว** (`:q`, `:q2`, `:q3`) ห้ามใช้ชื่อเดิมซ้ำหลายตำแหน่ง

### class Database (final)
- property: `private static ?PDO $pdo = null` — connection เดียวที่แชร์ทั้ง request (singleton)

#### `public static pdo(): PDO`
- **ทำอะไร**: ถ้ามีการเชื่อมต่อแล้วคืนเลย (lazy singleton — เชื่อมต่อเมื่อมีการใช้ครั้งแรกเท่านั้น) ไม่งั้นเรียก `connect()` ด้วยค่า `DB_*`; ถ้า `PDOException` ห่อเป็น `DatabaseConnectionException` (เพื่อให้ ErrorHandler แสดงข้อความกลางโดยไม่เปิดเผยรหัสผ่าน/รายละเอียด) จากนั้น `exec("SET time_zone = '+07:00'")` (ใช้ `date('P')`) ให้ `NOW()` ของ MySQL ตรงกับเวลา PHP เพราะโฮสต์บางเจ้าตั้ง MySQL เป็น UTC ทำให้การเทียบเวลาเลยกำหนดคืน/ล็อกบัญชีคลาด 7 ชม. ถ้าตั้งไม่ได้ก็ข้ามเงียบ ๆ (catch Throwable)
- **คืนค่า**: `PDO`; **throws**: `DatabaseConnectionException`
- **เรียกใช้โดย**: ทุกเมธอดใน Database เอง, `BackupService`, `InstallController` ฯลฯ

#### `public static connect(string $host, string $name, string $user, string $pass, string $charset = DB_CHARSET): PDO`
- **ทำอะไร**: สร้าง PDO ใหม่ (ไม่เก็บ singleton) ด้วย DSN `mysql:host=..;dbname=..;charset=..` และ options: `ERRMODE_EXCEPTION` (error โยน exception), `FETCH_ASSOC` (ได้ array key เป็นชื่อคอลัมน์), `EMULATE_PREPARES=false`, `MYSQL_ATTR_INIT_COMMAND = SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`
- **รับเข้า**: host, ชื่อ DB, user, password, charset. **คืนค่า**: `PDO` (throws `PDOException` ถ้าต่อไม่ได้)
- **เรียกใช้โดย**: `Database::pdo` (comment บอกว่าใช้ต่อ DB อีกชุดในหน้าเทียบ UX; grep ไม่พบผู้เรียกอื่นนอก Core)

#### `public static one(string $sql, array $params = []): ?array`
- **ทำอะไร**: `prepare` → `execute($params)` → `fetch()`; ได้แถวเดียวเป็น array
- **คืนค่า**: array หรือ `null` ถ้าไม่พบ (แปลง `false` เป็น `null`)
- **ตัวอย่าง**: `Database::one('SELECT * FROM cars WHERE id = :id', [':id' => $id]);`

#### `public static all(string $sql, array $params = []): array`
- **ทำอะไร**: เหมือน `one` แต่ `fetchAll()`; คืน array ของแถว (ว่าง = `[]`)

#### `public static value(string $sql, array $params = [])`
- **ทำอะไร**: `fetchColumn()` คืนค่าคอลัมน์แรกของแถวแรก (ใช้กับ `COUNT(*)`, `SUM`, `MAX`) คืน `false` ถ้าไม่มีแถว
- **คืนค่า**: mixed (ไม่ประกาศชนิด). **เรียกใช้โดย**: `Model::count`, Model/Controller อื่นหลายแห่ง (grep พบ ~67 จุดนอก Core)

#### `public static exec(string $sql, array $params = []): int`
- **ทำอะไร**: รันคำสั่งที่ไม่ต้องการชุดผลลัพธ์ (INSERT/UPDATE/DELETE) คืน `rowCount()` จำนวนแถวที่ได้รับผลกระทบ (หมายเหตุ MySQL: UPDATE ที่ค่าเหมือนเดิมนับเป็น 0)
- **เรียกใช้โดย**: `Model::deleteById` และ Model ต่าง ๆ

#### `public static insert(string $table, array $data): int`
- **ทำอะไร**: สร้าง SQL `INSERT INTO `table` (`c1`,`c2`) VALUES (:c1,:c2)` จาก key ของ `$data` แล้ว `bindValue` ทีละค่า `execute` และคืน `lastInsertId()` เป็น int
- **รับเข้า**: `$table`, `$data` แบบ `['คอลัมน์' => ค่า]`
- **ความปลอดภัย**: ค่าถูก bind (ปลอดภัย) แต่ **ชื่อตาราง/คอลัมน์ถูกต่อ string** (ใส่ backtick ให้แต่ไม่ escape) ห้ามส่ง key ที่มาจากผู้ใช้ตรง ๆ
- **เรียกใช้โดย**: `Model::insert`, `Inspection`, `Customer`

#### `public static update(string $table, array $data, string $whereCol, $whereVal): int`
- **ทำอะไร**: สร้าง `UPDATE `t` SET `c` = :c, ... WHERE `whereCol` = :__w` bind ค่าทั้งหมด (ค่า where ใช้ชื่อ `:__w`) คืน `rowCount()`
- **รับเข้า**: ตาราง, ข้อมูลที่จะแก้, ชื่อคอลัมน์เงื่อนไข (ส่วนใหญ่ `id`), ค่าเงื่อนไข. ข้อควรระวังเรื่องชื่อคอลัมน์เหมือน `insert`; `$data` ว่างจะได้ SQL ผิด
- **เรียกใช้โดย**: `Model::updateById`

#### `public static begin(): void`
- **ทำอะไร**: `beginTransaction()` เริ่ม transaction (ชุดคำสั่งที่สำเร็จทั้งหมดหรือยกเลิกทั้งหมด). **เรียกใช้โดย**: SecurityDepositService, ExtendService, PrivateFileService, SignatureService ฯลฯ

#### `public static commit(): void`
- **ทำอะไร**: `commit()` ยืนยัน transaction

#### `public static rollBack(): void`
- **ทำอะไร**: ย้อนกลับ **เฉพาะเมื่อ `inTransaction()` ยังเป็นจริง** เหตุผล: หลัง deadlock ฐานข้อมูลอาจปิด transaction ให้เองแล้ว การเรียก rollBack ตรง ๆ จะเกิด error ซ้อน
- **รูปแบบใช้ปกติ**: `begin(); try { ...; commit(); } catch (\Throwable $e) { rollBack(); throw $e; }`

---

## app/Core/DatabaseConnectionException.php
- **หน้าที่ของไฟล์**: ชนิด exception เฉพาะ "เชื่อมต่อฐานข้อมูลไม่ได้" แยกออกมาเพื่อให้จัดการต่างจากข้อผิดพลาดทั่วไป

### class DatabaseConnectionException extends \Exception
- ไม่มี property/method (ตัวเปล่า สืบทอดทุกอย่างจาก `\Exception`)
- โยนโดย `Database::pdo()` (ส่ง message/previous ของ PDOException ต่อไป); ถูกตรวจใน `ErrorHandler::handle` (`instanceof`) เพื่อแสดงข้อความ "ไม่สามารถเชื่อมต่อฐานข้อมูลได้"
- หมายเหตุ: comment ในไฟล์พูดถึงหน้า /health ตอบ 503 — ดูข้อสังเกตท้ายเอกสาร

---

## app/Core/ErrorHandler.php
- **หน้าที่ของไฟล์**: exception ที่ไม่มีใครดัก → แสดงหน้าข้อความสั้น ๆ แทนหน้าขาว โดยไม่เผยรายละเอียดภายใน (path, SQL, รหัสผ่าน) ลงทะเบียนใน `bootstrap.php`

### class ErrorHandler (final)

#### `public static register(): void`
- **ทำอะไร**: `set_exception_handler([self::class,'handle'])` — PHP จะเรียก `handle` เมื่อมี exception หลุดออกมาจนสุด. **เรียกใช้โดย**: `bootstrap.php`

#### `public static handle(\Throwable $e): void`
- **ทำอะไร**:
  1. `error_log(...)` บันทึกคลาส, ข้อความ, ไฟล์:บรรทัด (ลง log ของเซิร์ฟเวอร์ ผู้ใช้ไม่เห็น)
  2. จัดประเภท: `$dbDown` (DatabaseConnectionException) → ข้อความเชื่อมต่อ DB ไม่ได้ status 500; `$bad` (`TypeError`/`ValueError` = ข้อมูลที่ส่งมารูปแบบผิด) → 400 ข้อความ "ข้อมูลที่ส่งมาไม่ถูกรูปแบบ"; อื่น ๆ → 500 ข้อความทั่วไป
  3. `$wantsJson` = ไม่ใช่ CLI และ `Request::current()->wantsJson()`
  4. ตั้ง `http_response_code` ถ้ายังไม่ส่ง header
  5. ถ้า wantsJson → ตอบ JSON `{ok:false,error:msg}` (+ `debug` เมื่อ `APP_DEBUG`) แล้ว return
  6. ถ้า CLI → เขียน `$msg` ลง STDERR (+ stack trace เมื่อ debug) แล้ว return
  7. มิฉะนั้นพิมพ์หน้า HTML สั้นแบบ inline ไม่พึ่ง View/DB (เพราะข้อผิดพลาดอาจมาจากสองอย่างนั้นเอง) ข้อความถูก `htmlspecialchars` เสมอ (กัน XSS); โชว์ stack trace เฉพาะ `APP_DEBUG`
- **รับเข้า**: `$e` exception/error. **คืนค่า**: void
- **เรียกใช้โดย**: PHP runtime (ผ่าน register). **เรียกต่อ**: `Request::current()->wantsJson()`

---

## app/Core/Flash.php
- **หน้าที่ของไฟล์**: ข้อความแจ้งผลครั้งเดียว (แสดงในหน้าถัดไปแล้วหาย — รูปแบบ flash-after-redirect) เก็บใน `$_SESSION['flash']` เป็น list ของ `['type'=>..,'msg'=>..]` ชนิด: `ok | warn | danger | info` ต้องมี session เปิดอยู่แล้ว (bootstrap เปิดให้)

### class Flash (final)

#### `public static add(string $type, string $message): void`
- **ทำอะไร**: ต่อท้ายรายการใน `$_SESSION['flash']`. **เรียกใช้โดย**: `Controller::flash`, `Router::run` (กรณีอัปโหลดไฟล์ใหญ่), `AuthMiddleware`, `AdminMiddleware`

#### `public static has(): bool`
- **ทำอะไร**: `!empty($_SESSION['flash'])` — มีข้อความรออยู่ไหม (ไม่ล้าง). **เรียกใช้โดย**: `Admin\AuthController` (บรรทัด ~29)

#### `public static pull(): array`
- **ทำอะไร**: อ่านทั้งหมดแล้ว `unset` ทิ้งทันที (ดึงแล้วหาย) คืน `[]` ถ้าไม่มี. **เรียกใช้โดย**: view `partials/flash.php` (`Flash::pull()` ในลูป foreach)

---

## app/Core/HttpException.php
- **หน้าที่ของไฟล์**: exception ที่พก `Response` ไว้ โยนจากที่ไหนก็ได้ (middleware, controller, service) เพื่อหยุดงานแล้วตอบ Response นี้ทันที (ใช้เป็นการ "return ข้ามชั้น")

### class HttpException extends \RuntimeException
- property: `private Response $response` (promoted)

#### `public __construct(private Response $response)`
- **ทำอะไร**: เก็บ Response และเรียก `parent::__construct('HTTP '.$response->statusCode())` ให้ message เป็น "HTTP 302" ฯลฯ
- **เรียกใช้โดย**: `Controller::halt`

#### `public response(): Response`
- **ทำอะไร**: คืน Response ที่เก็บไว้. **เรียกใช้โดย**: `Router::run`, `Application::run`

---

## app/Core/Model.php
- **หน้าที่ของไฟล์**: คลาสแม่ของ model — งานพื้นฐานของตาราง (หาตาม id, เพิ่ม, แก้, ลบ, นับ) model ลูก (15 คลาสใน `app/Models`) เพิ่มคำสั่งค้นหาเฉพาะของตัวเองโดยเรียก `Database::one/all/value` ด้วย prepared statement

### class Model (abstract)
- `protected static string $table = ''` — ชื่อตาราง ลูกต้อง override (เช่น `'maintenances'`)
- `protected static string $primaryKey = 'id'` — ชื่อคอลัมน์ primary key
- เมธอดทั้งหมดเป็น static และใช้ `static::` (late static binding) เพื่ออ่านค่า `$table` ของคลาสลูก ชื่อตาราง/คีย์ต่อเข้า SQL ตรง ๆ จึงต้องเป็นค่าคงที่ที่นักพัฒนากำหนดเท่านั้น (ไม่ใช่ input ผู้ใช้); ค่า id ถูก bind

#### `public static find(int $id): ?array`
- **ทำอะไร**: `SELECT * FROM table WHERE pk = :id` ผ่าน `Database::one`. **คืนค่า**: แถว array หรือ `null`
- **ตัวอย่าง**: `$doc = Document::find($id);`

#### `public static insert(array $data): int`
- **ทำอะไร**: ส่งต่อ `Database::insert(static::$table, $data)` คืน id ใหม่

#### `public static updateById(int $id, array $data): int`
- **ทำอะไร**: ส่งต่อ `Database::update(table, data, primaryKey, id)` คืนจำนวนแถวที่แก้
- **ตัวอย่าง**: `Car::updateById($id, ['status' => 'ready']);`

#### `public static deleteById(int $id): int`
- **ทำอะไร**: `DELETE FROM table WHERE pk = :id` ผ่าน `Database::exec` คืนจำนวนแถวที่ลบ (ลบจริง ไม่ใช่ soft delete)

#### `public static count(): int`
- **ทำอะไร**: `SELECT COUNT(*) FROM table` ผ่าน `Database::value` แล้ว cast เป็น int. **เรียกใช้โดย**: `Customer` (`self::count()`)

---

## app/Core/Request.php
- **หน้าที่ของไฟล์**: ห่อข้อมูลคำขอ HTTP (`$_GET`, `$_POST`, `$_FILES`, `$_SERVER`) ไว้ที่เดียว ตัวอ่านค่าแบบมีชนิด (`str/int/float/bool/arr`) ตัดค่าที่ผิดรูปแบบให้เองโดยไม่โยน TypeError (กันหน้าขาว) ใช้โดย Router, Controller, Csrf, ErrorHandler, Service

### class Request (final)
- `private static ?Request $current` — คำขอปัจจุบัน (singleton ต่อ request)
- `private ?string $rawBody` — cache เนื้อหาดิบ

#### `public static capture(): self`
- **ทำอะไร**: สร้าง `new self()` เก็บเป็น `$current` แล้วคืน. **เรียกใช้โดย**: `Application::run`, `Request::current`

#### `public static current(): self`
- **ทำอะไร**: คืนคำขอปัจจุบัน ถ้ายังไม่มีก็ `capture()` ให้ — ใช้ในที่ที่ไม่ได้รับ Request ตรง ๆ. **เรียกใช้โดย**: `ErrorHandler::handle`, `AuthService`, `SignatureService`

#### `public method(): string`
- **ทำอะไร**: `REQUEST_METHOD` เป็นตัวพิมพ์ใหญ่ ค่าเริ่มต้น `'GET'`. **เรียกใช้โดย**: `Router::dispatch/run`, `isPost`, `postTooLarge`

#### `public isPost(): bool`
- **ทำอะไร**: `method() === 'POST'`. **เรียกใช้โดย**: `LiffController`

#### `public path(): string`
- **ทำอะไร**: ได้ path ที่ Router ใช้จับคู่: (1) เอา `REQUEST_URI` (ค่าเริ่มต้น `/`) (2) `parse_url` เอาเฉพาะ path (ตัด query) (3) `rawurldecode` (4) ตัด `BASE_URL` ออกถ้าขึ้นต้นด้วยมัน (5) ถ้าขึ้นต้น `/index.php` ให้ตัดออก (รองรับ `/index.php/xxx`) (6) จัดรูปให้ขึ้นต้น `/` ไม่มี `/` ท้าย (ยกเว้นหน้าแรก = `/`)
- **คืนค่า**: เช่น `/cars/3`. **เรียกใช้โดย**: `Router::dispatch`, `wantsJson`, `Router::run`

#### `public queryString(): string`
- **ทำอะไร**: คืน `QUERY_STRING` ดิบ (ไม่มี `?`) ใช้ใน `Router::legacy` เพื่อพ่วง query เดิมไปกับ redirect

#### `public query(string $key, $default = null)`
- **ทำอะไร**: `$_GET[$key] ?? $default` (ไม่แปลงชนิด). **ใช้โดย**: `Csrf::valid`, `LiffController`

#### `public post(string $key, $default = null)`
- **ทำอะไร**: `$_POST[$key] ?? $default` (ไม่แปลงชนิด อาจเป็น array). **ใช้โดย**: `Csrf::valid`, `DocumentController`

#### `public input(string $key, $default = null)`
- **ทำอะไร**: อ่าน POST ก่อน แล้วค่อย GET แล้วค่อย default. ตัวอ่านแบบมีชนิดทั้งหมดอ่านผ่านเมธอดนี้

#### `public has(string $key): bool`
- **ทำอะไร**: `isset` ใน POST หรือ GET (ค่า `null` ถือว่าไม่มี)

#### `public str(string $key, int $max = 0, string $default = ''): string`
- **ทำอะไร**: อ่านด้วย `input`; ถ้าไม่ใช่ string (เช่น array) คืน `$default`; `trim` แล้วถ้า `$max > 0` ตัดด้วย `mb_substr` (นับเป็นตัวอักษร ไม่ใช่ byte — เหมาะกับภาษาไทย) ไม่ escape HTML — ต้อง `e()` ตอนแสดงผล
- **รับเข้า**: key, ความยาวสูงสุด (0 = ไม่จำกัด), ค่าเริ่มต้น
- **ตัวอย่าง**: `$reason = $this->request->str('reason', 250);`

#### `public int(string $key, int $default = 0): int`
- **ทำอะไร**: ถ้าค่าเป็น scalar cast `(int)` ไม่งั้นคืน default (array → default). ข้อความที่ไม่ใช่ตัวเลขจะกลายเป็น 0 โดยไม่เตือน

#### `public float(string $key, float $default = 0.0): float`
- **ทำอะไร**: เหมือน `int` แต่ cast `(float)`

#### `public bool(string $key): bool`
- **ทำอะไร**: จริงเมื่อ `!empty($v) && $v !== '0'` (ช่อง checkbox ที่ติ๊ก); array ที่ไม่ว่างก็นับเป็นจริง

#### `public arr(string $key): array`
- **ทำอะไร**: คืนค่าถ้าเป็น array (เช่น `addons[delivery]=1`) ไม่ใช่ก็ `[]`

#### `public all(): array`
- **ทำอะไร**: `$_POST + $_GET` (POST มีความสำคัญกว่าเมื่อ key ซ้ำ)

#### `public file(string $key): array`
- **ทำอะไร**: คืน `$_FILES[$key]` ถ้าเป็น array ไม่งั้น `[]` ใช้ส่งต่อ `UploadService::save` / `PrivateFileService::storeUpload`

#### `public fileList(string $key): array`
- **ทำอะไร**: จัดรูป `$_FILES` ของ input หลายไฟล์ (`name="images[]"`) จากรูปแบบ "คอลัมน์ของ array" เป็น list ที่แต่ละ element คือไฟล์เดียว (`name,type,tmp_name,error,size`; ถ้าขาดใช้ default เช่น `error=UPLOAD_ERR_NO_FILE`) คืน `[]` ถ้า key ไม่ใช่ไฟล์หลายไฟล์ (name ต้องเป็น array)
- **เรียกใช้โดย**: `Admin\CarController`

#### `public header(string $name): string`
- **ทำอะไร**: อ่าน request header ผ่าน `$_SERVER['HTTP_'.ชื่อตัวพิมพ์ใหญ่ ขีด→underscore]` คืน `''` ถ้าไม่มี (ไม่ใช่ null)
- **ตัวอย่าง**: `$request->header('X-Line-Signature')` (ใน LineWebhookController); `header('Referer')`

#### `public ip(): string`
- **ทำอะไร**: `REMOTE_ADDR` (ไม่เชื่อ `X-Forwarded-For` — หลัง proxy/Cloudflare จะได้ IP ของ proxy). **ใช้โดย**: `AuthService` (นับล็อกอินผิด), `BookingController`, `LiffController`, `SignatureService`

#### `public userAgent(): string`
- **ทำอะไร**: `HTTP_USER_AGENT`. **ใช้โดย**: `SignatureService`

#### `public host(): string`
- **ทำอะไร**: `HTTP_HOST` (อาจมีพอร์ต). **ใช้โดย**: `safeRefererPath`, `LiffController`, `Admin\LineController`

#### `public isSecure(): bool`
- **ทำอะไร**: จริงเมื่อ `HTTPS` ไม่ว่างและไม่ใช่ `off` หรือ header `X-Forwarded-Proto` เป็น `https` (หลัง proxy). **ใช้โดย**: `InstallController`
- หมายเหตุ: `Session::start` ตรวจ HTTPS ด้วยตัวเอง (ไม่ใช้เมธอดนี้ และไม่ดู X-Forwarded-Proto)

#### `public wantsJson(): bool`
- **ทำอะไร**: คืนจริงถ้า header `Accept` มี `application/json`, หรือ `X-Requested-With` = `xmlhttprequest` (AJAX), หรือ path ขึ้นต้น `/api/`, หรือเท่ากับ `/liff/api`
- **ใช้โดย**: `ErrorHandler::handle`, `Router::run` (เลือกตอบ JSON/ข้อความ), `ErrorController`

#### `public rawBody(): string`
- **ทำอะไร**: อ่าน `php://input` ทั้งก้อนครั้งแรกแล้ว cache (`??=`) จำเป็นต่อการตรวจลายเซ็น (HMAC) ของ webhook LINE ที่ต้องใช้ body ดิบไม่ผ่านการแปลง
- **ใช้โดย**: `LineWebhookController`, `LineService`

#### `public postTooLarge(): bool`
- **ทำอะไร**: ตรวจว่า PHP ทิ้งคำขอทั้งก้อนเพราะเกิน `post_max_size` ไหม: ต้องเป็น POST, `CONTENT_TYPE` เป็น `multipart/form-data` หรือ `application/x-www-form-urlencoded` (JSON/webhook ไม่นับ), `CONTENT_LENGTH > 0` แต่ `$_POST` และ `$_FILES` ว่างทั้งคู่ — กรณีนี้ร้ายสุดเพราะไม่มี CSRF token ให้ตรวจเลย
- **ใช้โดย**: `Router::run` (ตรวจก่อน CSRF), `LiffController`

#### `public safeRefererPath(string $fallback = ''): string`
- **ทำอะไร**: แปลง header Referer เป็น path ภายในเว็บนี้เท่านั้น (กัน **open redirect** เพราะ Referer ปลอมได้): ไม่มี referer/`parse_url` ล้มเหลว → fallback; ถ้ามี host ต้องตรงกับ `host()` (เทียบแบบไม่สนตัวพิมพ์ ลองแบบรวมพอร์ตด้วย) ไม่งั้น fallback; ตัด `BASE_URL` ออกจาก path, ตัด `/` นำหน้า; ถ้าว่าง, มี `..`, หรือขึ้นต้น `/` → fallback; ถ้ามี query ต่อ `?query` ท้าย
- **คืนค่า**: path แบบไม่มี BASE_URL และไม่ขึ้นต้น `/` (เช่น `admin/bookings?page=2`) พร้อมส่งให้ `redirect()` (ซึ่งผ่าน `url()`)
- **ใช้โดย**: `Controller::back`, `Router::run`

---

## app/Core/Response.php
- **หน้าที่ของไฟล์**: ผลลัพธ์ที่ controller ส่งกลับ — เป็นอ็อบเจ็กต์เก็บ body/status/headers ยังไม่ส่งออกจนกว่า `Application` เรียก `send()` (ทำให้ทดสอบและโยนข้ามชั้นด้วย HttpException ได้)

### class Response (final)
- properties (private, promoted): `string $body`, `int $status = 200`, `array<string,string> $headers`, `?string $file` (path ไฟล์ที่จะส่งตรง)

#### `public __construct(string $body = '', int $status = 200, array $headers = [], ?string $file = null)`
- **ทำอะไร**: เก็บค่าตามพารามิเตอร์ ปกติไม่เรียกตรง แต่ใช้ factory ด้านล่าง

#### `public static html(string $html, int $status = 200): self`
- **ทำอะไร**: Response ที่มี `Content-Type: text/html; charset=utf-8`. **ใช้โดย**: `Controller::view`, `Router::run` (แปลง string เป็น Response)

#### `public static text(string $text, int $status = 200, string $type = 'text/plain'): self`
- **ทำอะไร**: ข้อความล้วน Content-Type = `$type; charset=utf-8`. **ใช้โดย**: `Router::dispatch` (405), `Router::run` (419), `HealthController`, `CustomerController`

#### `public static json($data, int $status = 200): self`
- **ทำอะไร**: `json_encode($data, JSON_UNESCAPED_UNICODE)` (ภาษาไทยไม่ถูกแปลงเป็น \uXXXX) cast เป็น string (ถ้า encode ล้มเหลวจะได้ `''`) Content-Type `application/json; charset=utf-8`. **ใช้โดย**: `Controller::json`, `Router::run`

#### `public static redirect(string $path, int $status = 302): self`
- **ทำอะไร**: ถ้า `$path` ขึ้นต้น `http://`/`https://` ใช้ตรง ๆ ไม่งั้นผ่าน `url($path)` (helper ที่เติม BASE_URL) แล้วตั้ง header `Location` body ว่าง
- **ข้อควรระวัง**: ถ้าส่งค่าจากผู้ใช้เข้ามาเป็น `$path` แบบ URL เต็ม จะกลายเป็น open redirect — ต้องกรองก่อน (ดู `safeRefererPath`)
- **ใช้โดย**: `Controller::redirect`, `Router::legacy/run`, middleware

#### `public static file(string $fullPath, array $headers = [], int $status = 200): self`
- **ทำอะไร**: ตั้ง `Content-Length` = `filesize($fullPath)` แล้วสร้าง Response ที่ body ว่างแต่มี `$file` ให้ `send()` อ่านออก (เหมาะกับไฟล์สำรอง/เอกสารลูกค้า ไม่โหลดทั้งไฟล์เข้าหน่วยความจำ) ถ้าไฟล์ไม่มี `filesize` คืน false → header เป็นค่าว่าง (ผู้เรียกควรตรวจก่อน)
- **ใช้โดย**: `Admin\BackupController`, `PrivateFileService`

#### `public header(string $name, string $value): self`
- **ทำอะไร**: ตั้ง/ทับ header แล้ว return `$this` (fluent) เช่น `->header('Cache-Control','no-store')`. **ใช้โดย**: `HealthController`

#### `public status(int $code): self`
- **ทำอะไร**: เปลี่ยน status code แบบ fluent

#### `public statusCode(): int`
- **ทำอะไร**: อ่าน status ปัจจุบัน. **ใช้โดย**: `HttpException::__construct`

#### `public body(): string`
- **ทำอะไร**: อ่าน body (grep ไม่พบผู้เรียกนอก Core)

#### `public send(): void`
- **ทำอะไร**: ถ้ายังไม่ส่ง header → `http_response_code(status)` และ `header()` ทุกตัว; ถ้ามี `$file` → ล้าง output buffer ทุกชั้น (`ob_end_clean` วนจน level 0) แล้ว `readfile($file)` และ return; ไม่งั้น `echo $body`
- **เรียกใช้โดย**: `Application::run`
- หมายเหตุ: ไม่มีการเว้น body สำหรับ HEAD (ปล่อยให้ web server จัดการ)

---

## app/Core/Route.php
- **หน้าที่ของไฟล์**: เส้นทาง 1 รายการ: method(s) + pattern → handler (`[Controller::class,'method']` หรือ callable) + middleware สร้างโดย `Router::add`

### class Route (final)
- `private string $regex` — regex ที่คอมไพล์จาก pattern
- `private array $paramNames` — ชื่อพารามิเตอร์ใน URL ตามลำดับ
- `private bool $csrf = true` — ต้องตรวจ CSRF ไหม (ค่าเริ่มต้นใช่)
- `private array $middleware` — รายชื่อคลาส middleware
- `private array $methods`, `private $handler` (promoted)

#### `public __construct(private array $methods, string $pattern, private $handler, array $middleware = [])`
- **ทำอะไร**: เก็บ middleware แล้ว `compile($pattern)` เก็บ regex
- **รับเข้า**: methods เช่น `['GET','HEAD']`, pattern เช่น `/cars/{id}`, handler, รายชื่อคลาส middleware

#### `private compile(string $pattern): string`
- **ทำอะไร**: แปลง `{name}` เป็น named group `(?P<name>[^/]+)` และ `{name:regex}` เป็น `(?P<name>regex)` (เช่น `{id:\d+}` รับเฉพาะตัวเลข) พร้อมเก็บชื่อลง `$paramNames` แล้วห่อเป็น `#^...$#u` (จับคู่ทั้งสตริง รองรับ UTF-8)
- **ข้อสังเกต**: ส่วนที่เป็นตัวอักษรตายตัวของ pattern ไม่ถูก `preg_quote` ดังนั้น `.` ใน `/cars.php` จะเป็น wildcard ตัวอักษรเดียว (ผลข้างเคียงเล็กน้อย) และ pattern ห้ามมีตัว `#`

#### `public noCsrf(): self`
- **ทำอะไร**: ปิดการตรวจ CSRF เฉพาะเส้นทางนี้ return `$this` (fluent) ใช้กับ request จากภายนอกที่ไม่มี session เช่น `/line/webhook`, `/liff/api`, `/api/track` (ใน `routes.php`) ซึ่งต้องมีวิธียืนยันตัวตนอื่นแทน

#### `public needsCsrf(): bool`
- **ทำอะไร**: อ่านค่า `$csrf`. **ใช้โดย**: `Router::run`

#### `public handler()`
- **ทำอะไร**: คืน handler (array หรือ callable). **ใช้โดย**: `Router::run` → `call`

#### `public middleware(): array`
- **ทำอะไร**: คืนรายชื่อคลาส middleware. **ใช้โดย**: `Router::run`

#### `public allows(string $method): bool`
- **ทำอะไร**: `in_array($method, $methods, true)`. **ใช้โดย**: `Router::dispatch`

#### `public match(string $path): ?array`
- **ทำอะไร**: `preg_match` กับ path; ไม่ตรง → `null`; ตรง → array `[ชื่อ => ค่า]` ตามลำดับ `$paramNames` (route ไม่มีพารามิเตอร์ได้ `[]`)
- **ตัวอย่าง**: pattern `/cars/{id:\d+}` กับ `/cars/3` → `['id'=>'3']` (เป็น string เสมอ)
- **ใช้โดย**: `Router::dispatch`

---

## app/Core/Router.php
- **หน้าที่ของไฟล์**: ตัวกำหนดเส้นทาง — เก็บรายการ Route, จับคู่ method + path, ตรวจ CSRF, รัน middleware ตามลำดับ แล้วเรียก controller ใช้โดย `Application::run` และ `app/routes.php`

### class Router (final)
- `private array $routes` — รายการ `Route` ตามลำดับที่ลงทะเบียน (ตัวที่ลงก่อนชนะ)
- `private array $groups` — stack ของกลุ่มปัจจุบัน แต่ละอัน `{prefix, middleware}`

#### `public get(string $pattern, $handler): Route`
- **ทำอะไร**: ลงทะเบียน GET (รวม HEAD ด้วย) ผ่าน `add`. **คืนค่า**: `Route` เพื่อต่อ `->noCsrf()` ได้

#### `public post(string $pattern, $handler): Route`
- **ทำอะไร**: ลงทะเบียน POST. **ตัวอย่าง**: `$r->post('/line/webhook', [LineWebhookController::class,'handle'])->noCsrf();`

#### `public any(string $pattern, $handler): Route`
- **ทำอะไร**: ตอบทั้ง GET, HEAD, POST. (grep ไม่พบการเรียกใช้ใน routes.php หรือที่อื่น)

#### `public group(array $attrs, callable $routes): void`
- **ทำอะไร**: push `{prefix, middleware}` (ค่า `prefix`/`middleware` เริ่มต้น `''`/`[]`) ลง `$groups`, เรียก `$routes($this)` ให้ลงทะเบียนเส้นทางลูก แล้ว `array_pop` ออก — กลุ่มซ้อนกันได้ (prefix ต่อกัน, middleware รวมกัน)
- **ตัวอย่าง**: `$r->group(['prefix'=>'/admin','middleware'=>[AuthMiddleware::class]], function($r){ ... });`

#### `public legacy(string $oldPath, string $newPath): void`
- **ทำอะไร**: ลงทะเบียน GET ที่ redirect แบบ **301** (ถาวร) จาก URL เก่า (`.php`) ไปใหม่ โดยพ่วง query string เดิม (ใช้ `&` ถ้า `$newPath` มี `?` อยู่แล้ว ไม่งั้น `?`) handler เป็น closure static
- **ใช้โดย**: `routes.php` (เช่น `/cars.php` → `/cars`)

#### `private add(array $methods, string $pattern, $handler): Route`
- **ทำอะไร**: รวม prefix และ middleware จากทุกกลุ่มที่เปิดอยู่ใน `$groups`, สร้าง path เต็มด้วย `'/'.trim($prefix.'/'.ltrim($pattern,'/'),'/')`, สร้าง `Route`, เก็บเข้า `$routes`, คืน Route
- **เรียกใช้โดย**: `get`, `post`, `any`

#### `public dispatch(Request $request): Response`
- **ทำอะไร**: วนทุก Route ตามลำดับ: `match(path)` ไม่ตรง → ข้าม; ตรงแต่ method ไม่อนุญาต → จำไว้ว่า `$allowedElsewhere` แล้วหาต่อ; ตรงทั้งคู่ → `run(...)` คืน Response ทันที ถ้าหมดแล้ว: เคยเจอ path ตรงแต่ method ผิด → `Response::text('Method Not Allowed', 405)` (ไม่ส่ง header `Allow`); ไม่งั้น → `ErrorController::notFound()` (404)
- **รับเข้า**: `$request`. **คืนค่า**: `Response`. **เรียกใช้โดย**: `Application::run`

#### `private run(Route $route, array $params, Request $request): Response`
- **ทำอะไร** (ตามลำดับ):
  1. **อัปโหลดใหญ่เกิน**: ถ้า `postTooLarge()` → คำนวณขีดจำกัด MB จาก `UploadService::limitBytes()`; ถ้า `wantsJson()` ตอบ JSON 413; ไม่งั้น `Flash::add('danger', ...)` แล้ว redirect กลับด้วย `safeRefererPath` (fallback `admin` ถ้า path ขึ้นต้น `/admin` ไม่งั้นหน้าแรก) — ต้องดักก่อน CSRF เพราะตอนนั้น `$_POST` ว่าง ผู้ใช้จะเห็นแค่ "เซสชันหมดอายุ" โดยไม่รู้สาเหตุ
  2. **CSRF**: ถ้า method ไม่ใช่ GET/HEAD/OPTIONS และ Route ต้องการ CSRF และ `!Csrf::valid()` → ตอบ 419 (JSON ถ้า `wantsJson()` ไม่งั้นข้อความธรรมดา) "เซสชันหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่"
  3. ใน try: วน `$route->middleware()` — แต่ละตัว `new $class()` แล้ว `->handle($request)`; ถ้าคืน `Response` ให้ return ทันที (ตัดจบ เช่นไปหน้า login) ไม่งั้นไปตัวถัดไป; ถ้าผ่านหมด → `call(handler, params, request)`
  4. ดัก `HttpException` → คืน `$e->response()`
  5. ผลลัพธ์ถ้าเป็น `Response` คืนตรง ๆ ไม่งั้น `Response::html((string) $result)` (ให้ controller/closure คืน string HTML ได้)
- **คืนค่า**: `Response`. **เรียกใช้โดย**: `dispatch`

#### `private call($handler, array $params, Request $request)`
- **ทำอะไร**: ถ้า handler เป็น array `[Class, 'method']` → `new $class($request)` แล้วเรียก `$controller->$method(...array_values($params))` (พารามิเตอร์ URL ส่งแบบ **ตามลำดับตำแหน่ง** ไม่ใช่ตามชื่อ จึงต้องเรียงอาร์กิวเมนต์เมธอดให้ตรงกับลำดับ `{}` ใน pattern; ค่าเป็น string เสมอ เมธอดที่ประกาศ `int` จะถูก coerce เพราะไม่ได้ใช้ strict_types — ยืนยันจากโค้ด: ไม่มี declare(strict_types) ในไฟล์ Core ที่อ่าน) ถ้าเป็น callable → `$handler($request, ...params)`
- **เรียกใช้โดย**: `run`

---

## app/Core/Session.php
- **หน้าที่ของไฟล์**: จัดการ session — ตั้งคุกกี้ให้ปลอดภัยและจำกัดไว้เฉพาะโฟลเดอร์ที่ติดตั้ง คลาสนี้ห่อ `$_SESSION` (แต่ `Csrf`/`Flash` เข้าถึง `$_SESSION` ตรง ๆ)

### class Session (final)

#### `public static start(): void`
- **ทำอะไร**: ถ้า session active อยู่แล้วคืนเลย; ถ้า CLI ไม่เปิดคุกกี้ แค่ทำให้ `$_SESSION` เป็น array แล้ว return (cron/ทดสอบ); มิฉะนั้น `session_name(SESSION_NAME)`, คำนวณ cookie path = `/` หรือ `BASE_URL/` (ลงหลายชุดบนโดเมนเดียวกันไม่ตีกัน และ CSRF token ไม่ข้ามชุด), `session_set_cookie_params` ด้วย lifetime = `SESSION_LIFETIME`, `httponly=true` (JS อ่านคุกกี้ไม่ได้ กัน XSS ขโมย session), `samesite='Lax'` (ลด CSRF ข้ามเว็บ), `secure` ถ้าเป็น HTTPS แล้ว `session_start()`
- **เรียกใช้โดย**: `bootstrap.php`, `Admin\AuthController::logout` (เปิดใหม่หลัง destroy เพื่อให้ flash ใช้ได้)

#### `public static get(string $key, $default = null)`
- **ทำอะไร**: `$_SESSION[$key] ?? $default` (grep ไม่พบผู้เรียกนอก Core)

#### `public static put(string $key, $value): void`
- **ทำอะไร**: `$_SESSION[$key] = $value`

#### `public static has(string $key): bool`
- **ทำอะไร**: `isset($_SESSION[$key])` (ค่า null ถือว่าไม่มี)

#### `public static forget(string $key): void`
- **ทำอะไร**: `unset($_SESSION[$key])`

#### `public static regenerate(): void`
- **ทำอะไร**: ถ้า session active เรียก `session_regenerate_id(true)` (เปลี่ยน session id และลบไฟล์เก่า) — ใช้หลังล็อกอินสำเร็จเพื่อกัน **session fixation**. **ใช้โดย**: `AuthService` (บรรทัด ~39), `LiffController` (~124)

#### `public static destroy(): void`
- **ทำอะไร**: ล้าง `$_SESSION = []`; ถ้า session active และเปิด `session.use_cookies` ส่ง `setcookie` ชื่อเดียวกัน ค่าว่าง หมดอายุในอดีต ด้วยพารามิเตอร์คุกกี้เดิม (path/domain/secure/httponly) เพื่อลบคุกกี้ แล้ว `session_destroy()`. ใช้ตอนออกจากระบบ (เรียกจาก `AuthService::logout`)

---

## app/Core/View.php
- **หน้าที่ของไฟล์**: ตัวแสดงผลเทมเพลต PHP ธรรมดา (ไม่มีภาษา template ใหม่) ใช้ output buffering (`ob_start`) เก็บผลของไฟล์ view เป็น string รองรับ layout ซ้อน, partial และ section ไฟล์ view อยู่ที่ `VIEW_PATH/<name>.php`
- วิธีใช้ในไฟล์ view: `$this->layout('layouts/public', ['title'=>..])` เลือก layout; `<?= $this->partial('partials/x', [...]) ?>` แทรกชิ้นย่อย; `$this->start('name')`…`$this->end()` เก็บเนื้อหาเป็น section; `$this->section('name')` ใน layout แสดง section; `$content` ใน layout คือเนื้อหาหน้า
- **ความปลอดภัย**: View **ไม่ escape ให้อัตโนมัติ** ต้องเรียก `e()` ทุกครั้งที่แสดงข้อมูลจากผู้ใช้ (กัน XSS)

### class View (final)
- `?string $layoutName` ชื่อ layout ที่เลือก, `array $layoutData` ข้อมูลที่ส่งให้ layout, `array $sections` เนื้อหา section ที่เก็บไว้, `array $sectionStack` stack ชื่อ section ที่กำลังเปิด, `array $shared` ข้อมูลของหน้าหลักที่ส่งต่อให้ layout

#### `public static make(string $name, array $data = []): string`
- **ทำอะไร**: ทางลัด `(new self())->render($name,$data)` — สร้าง View ใหม่ต่อการเรนเดอร์ (state ไม่ปนกัน). **ใช้โดย**: `Controller::view`, `ApiController`, `SignatureService`

#### `public render(string $name, array $data = []): string`
- **ทำอะไร**: เก็บ `$data` เป็น `$shared`; เรนเดอร์หน้า (`capture`) ได้ `$content`; แล้ววนตราบที่มี layout: เก็บชื่อ/ข้อมูล layout ปัจจุบันไว้ในตัวแปรท้องถิ่น เคลียร์ค่าใน object (เพื่อให้ layout เรียก `layout()` ซ้อนได้) แล้ว `capture(layout, $layoutData + $shared + ['content'=>$content])` ผลลัพธ์กลายเป็น `$content` ใหม่ เมื่อไม่มี layout เหลือ คืน `$content`
- **ข้อสังเกต**: ใช้ตัวดำเนินการ `+` (array union) ซ้าย-ชนะ: ค่าจาก `layout()` มาก่อน แล้ว `$shared` ถ้าข้อมูลหน้ามี key ชื่อ `content` จะทับ `content` ที่ระบบสร้าง (ควรเลี่ยงชื่อนี้)

#### `public layout(string $name, array $data = []): void`
- **ทำอะไร**: บันทึกว่าหน้านี้ต้องห่อด้วย layout ใด + ข้อมูลที่ส่งให้ layout (เช่น `title`) — ทำงานจริงตอน `render` วนห่อ. เรียกในไฟล์ view

#### `public partial(string $name, array $data = []): string`
- **ทำอะไร**: เรนเดอร์ชิ้นส่วนย่อยแล้วคืน string — ส่งตัวแปรเฉพาะที่ระบุใน `$data` (ไม่ส่ง `$shared` ให้)
- **ตัวอย่าง**: `<?= $this->partial('partials/car_card', ['c' => $car]) ?>`

#### `public start(string $name): void`
- **ทำอะไร**: push ชื่อลง `$sectionStack` แล้ว `ob_start()` เริ่มเก็บ output

#### `public end(): void`
- **ทำอะไร**: pop ชื่อล่าสุดจาก stack, `ob_get_clean()` เก็บเป็น `$sections[$name]` ต้องจับคู่กับ `start()` เสมอ (ถ้าเรียก `end()` โดยไม่มี `start()` โค้ดไม่ได้ตรวจ ชื่อจะเป็น null)

#### `public section(string $name, string $default = ''): string`
- **ทำอะไร**: คืนเนื้อหา section ที่เก็บไว้ หรือ `$default` ถ้าไม่มี. **ใช้โดย**: layouts/* (`public`, `admin`, `focus`, `admin_print`)

#### `public hasSection(string $name): bool`
- **ทำอะไร**: จริงเมื่อมี section และไม่ใช่ข้อความว่าง/ช่องว่างล้วน (`trim`). **ใช้โดย**: `layouts/public.php`

#### `private capture(string $name, array $data): string`
- **ทำอะไร**: หาไฟล์ `VIEW_PATH/$name.php`; ไม่มี (`!is_file`) → `RuntimeException('ไม่พบไฟล์ view: ...')` (ไปจบที่ ErrorHandler → หน้า 500) ถ้ามีเรียก `includeFile`. **เรียกใช้โดย**: `render`, `partial`

#### `private includeFile(string $__file, array $__data): string`
- **ทำอะไร**: `extract($__data, EXTR_SKIP)` เปลี่ยน key เป็นตัวแปรท้องถิ่น (SKIP = ไม่ทับตัวแปรที่มีอยู่ เช่น `$__file`), `ob_start()`, `include $__file` (ใน view ใช้ `$this` เป็น View ได้) ถ้าเกิด Throwable → `ob_end_clean()` ทิ้ง buffer ที่ค้าง แล้วโยนต่อ ไม่งั้นคืน `ob_get_clean()`
- ชื่อพารามิเตอร์ขึ้นต้น `__` เพื่อไม่ชนชื่อตัวแปรของ view. **เรียกใช้โดย**: `capture`

---

## ข้อสังเกต (สิ่งที่ดูเป็นบั๊ก/ไม่สอดคล้อง)
- `Controller::back()`, `Router::any()`, `Response::body()`, `Session::get/put/has/forget` ไม่มีผู้เรียกนอก Core (โค้ดสำรอง/ยังไม่ได้ใช้); `Database::connect()` ถูกใช้ผ่าน `pdo()` เท่านั้น
- comment ใน `DatabaseConnectionException` บอกว่า /health จะตอบ 503 แต่ `ErrorHandler` ตอบ 500 สำหรับ DB ล่ม (HealthController ดัก Throwable เอง)
- `Database::insert/update` ต่อชื่อตาราง/คอลัมน์เป็น string (ไม่ whitelist) — ปลอดภัยเฉพาะเมื่อ key ไม่มาจากผู้ใช้
- `Route::compile` ไม่ `preg_quote` ส่วนคงที่ของ pattern (`.` ใน `/cars.php` เป็น wildcard)
- `Request::path` ตัด `BASE_URL` ด้วย `str_starts_with` โดยไม่เช็กขอบเขต `/` (เช่น BASE_URL `/car` กับ path `/carrent` จะตัดผิด)
- `Csrf::valid` ยอมรับโทเคนจาก query string `_csrf` ด้วย (โทเคนอาจรั่วทาง URL/log)
- `Request::ip()` ไม่ดู `X-Forwarded-For` ส่วน `Session::start` ตรวจ HTTPS เองโดยไม่ดู `X-Forwarded-Proto` ต่างจาก `Request::isSecure()` (หลัง proxy cookie อาจไม่ติด secure)
- `View::render` ใช้ `+` ทำให้ key `content` ในข้อมูลหน้าหลักทับเนื้อหาจริงได้; `View::end()` ไม่ตรวจ stack ว่าง
- `bootstrap.php` กัน array เฉพาะ `$_GET` ส่วน `$_POST` ที่ส่ง array มาที่ field ที่คาดว่าเป็น string จะถูก `Request::str()` แปลงเป็นค่า default เท่านั้น
