# thonglorcarrent — ระบบเช่ารถ (PHP MVC + PDO + Tailwind CSS)

ระบบเช่ารถออนไลน์พร้อมหลังบ้าน เขียนด้วย **PHP 8.1+ แบบ MVC**, ติดต่อฐานข้อมูลด้วย **PDO (prepared statements ทั้งหมด)**
และจัดหน้าด้วย **Tailwind CSS** — รันบน shared hosting (cPanel) ได้โดยไม่ต้องมี Composer หรือ Node.js บนเซิร์ฟเวอร์

## โครงสร้างโปรเจกต์

```
index.php                 Front controller — ทุกคำขอเข้าที่นี่ผ่าน .htaccess
.htaccess                 rewrite ทุก URL ที่ไม่ใช่ไฟล์จริง → index.php, ปิดโฟลเดอร์ภายใน
config/config.php         ค่าตั้งต้นที่ต้องแก้ตอนติดตั้ง (ฐานข้อมูล, BASE_URL, เขตเวลา)
cron/daily.php            งานรายวัน (บางมาก: ตรวจรหัสแล้วเรียก CronService)
database/                 schema.sql + update-v2..v5.sql + seed.sql
assets/                   css (app.css ที่ build แล้ว), js, img
uploads/                  รูปรถ สลิป รูปตรวจสภาพรถ (เปิดผ่านเว็บได้ แต่ห้ามรันสคริปต์)
storage/                  ไฟล์สำรองและเอกสารลูกค้า (ปิดไม่ให้เปิดผ่านเว็บ)

app/
├─ bootstrap.php          autoloader (namespace App\) + helpers + ErrorHandler + Session
├─ routes.php             ตาราง URL → [Controller, method] ทั้งระบบ
├─ Core/                  ตัวขับเคลื่อน: Router, Request, Response, View, Controller, Model,
│                         Database (PDO), Session, Csrf, Flash, ErrorHandler
├─ Models/                เข้าถึงข้อมูล (static, PDO) — Booking, Car, Customer, Payment, ...
├─ Services/              กฎธุรกิจ — PricingService, AvailabilityService, BookingService,
│                         PaymentService, LineService, SignatureService, BackupService, ...
├─ Middleware/            AuthMiddleware (ต้องล็อกอิน), AdminMiddleware (เฉพาะเจ้าของร้าน)
├─ Controllers/           หน้าเว็บลูกค้า, API, LINE (LIFF/webhook)
│  └─ Admin/              ตัวควบคุมหลังบ้านทั้งหมด
├─ Helpers/functions.php  ฟังก์ชันช่วยใน view (e(), url(), money(), thai_date(), icon(), ...)
└─ Views/                 เทมเพลต PHP + คลาส Tailwind
   ├─ layouts/            public, admin, admin_print, admin_login, focus (หน้ามือถือสั้นๆ)
   ├─ partials/           ชิ้นส่วนที่ใช้ซ้ำ (flash, pagination, quote_box, contract_terms, ...)
   ├─ public/             หน้าลูกค้า (หน้าแรก รถ จอง ตรวจสอบ เซ็นเอกสาร LIFF)
   └─ admin/              หน้าหลังบ้าน
```

### การไหลของคำขอ

`index.php` → `Application::run()` → `Router` จับคู่ `routes.php` → (Middleware) → **Controller** →
เรียก **Model / Service** → ส่งข้อมูลให้ **View** → `Response`

- Controller บาง: รับค่า, เรียก Service, เลือก View/redirect เท่านั้น
- กฎธุรกิจ (คิดราคา, ตรวจรถว่าง, ชำระเงิน, ค่าปรับ, ลายเซ็น) อยู่ใน `Services/`
- SQL ทั้งหมดอยู่ใน `Models/` ผ่าน `App\Core\Database` (named placeholders, `ATTR_EMULATE_PREPARES=false`)
- ทุกฟอร์ม POST ตรวจ CSRF อัตโนมัติโดย Router (ยกเว้น webhook ที่ตรวจลายเซ็นของ LINE เอง)

## ติดตั้ง

1. สร้างฐานข้อมูล MySQL/MariaDB (utf8mb4) แล้ว import ตามลำดับ:
   `database/schema.sql` → `update-v2.sql` → `update-v3.sql` → `update-v4.sql` → `update-v5.sql` → `ab-events.sql` → `update-rules.sql` → `seed.sql`
   (ถ้า import ด้วยบรรทัดคำสั่งให้ใส่ `--default-character-set=utf8mb4`)
2. แก้ `config/config.php` — `DB_HOST / DB_NAME / DB_USER / DB_PASS` (ระบบตรวจ `BASE_URL` เองจากตำแหน่งไฟล์)
3. อัปโหลดทั้งโปรเจกต์ (ไม่ต้องอัป `node_modules/`) ให้ Apache/LiteSpeed เปิด `mod_rewrite` ได้
4. เปิด `https://โดเมน/check` ตรวจการติดตั้ง แล้วเข้า `https://โดเมน/admin/login`
   ผู้ใช้เริ่มต้น `admin@thonglorcarrent.com` / `Thonglor@2026` — **เปลี่ยนรหัสผ่านทันที**
5. ตั้ง cron วันละครั้งตามคำสั่งที่หน้า หลังบ้าน → ตั้งค่า → Cron

## Tailwind CSS

`assets/css/app.css` ถูก build แล้วและ commit ไว้ (โฮสต์ไม่ต้องมี Node.js) แก้หน้าตา/เพิ่มคลาสแล้วค่อย build ใหม่:

```bash
npm install
npm run build      # หรือ npm run watch ระหว่างพัฒนา
```

- ต้นฉบับ: `assets/css/src/input.css` (base + component ที่ใช้ซ้ำ เช่น `.btn .field .panel .table .badge-* .alert-*`)
- ตั้งค่าธีม: `tailwind.config.js` — สีแบรนด์อ่านจากตัวแปร CSS (`--c-brand` ฯลฯ) ที่ `brand_style()` ฉีดจากหน้า
  หลังบ้าน → ตั้งค่า จึงเปลี่ยนสีทั้งเว็บได้โดยไม่ต้อง build ใหม่
- คลาสที่ประกอบชื่อจากตัวแปร PHP (เช่น `'badge-' . $tone`) ต้องเขียนเต็มใน view หรืออยู่ใน `safelist`

## แผนที่ URL

| ส่วน | URL |
|------|-----|
| หน้าลูกค้า | `/` · `/cars` · `/cars/{id}` · `/booking` (POST) · `/booking/success` · `/track` · `/how-to` · `/faq` · `/contact` · `/terms` |
| เซ็นเอกสาร / LINE | `/sign` · `/liff` · `/liff/api` · `/line/webhook` |
| ระบบ | `/sitemap.xml` · `/health` · `/check` · `/api/quote` · `/api/track` |
| หลังบ้าน | `/admin/login` · `/admin/dashboard` · `/admin/bookings[/{id}]` · `/admin/calendar` · `/admin/payments` · `/admin/cars` · `/admin/customers` · `/admin/maintenance` · `/admin/documents` · `/admin/reports` · `/admin/monthly-report` · `/admin/ab-report` · `/admin/line` · `/admin/settings` · `/admin/users` · `/admin/backups` · `/admin/health` |

ที่อยู่แบบเดิม (`/cars.php`, `/admin/booking-view.php?id=1`, ...) ยังใช้ได้ — ระบบตอบ 301 ไปที่อยู่ใหม่ให้
(ส่วน `/line/webhook.php` และ `/liff/api.php` คงรับ POST ไว้ เพราะระบบภายนอกอาจยังเรียกที่อยู่เดิม)

## เพิ่มหน้าใหม่

1. เพิ่มบรรทัดใน `app/routes.php` — `$r->get('/path', [XController::class, 'method']);`
2. เขียนเมธอดใน Controller (`return $this->view('folder/name', [...])`)
3. สร้างไฟล์ `app/Views/folder/name.php` — เริ่มด้วย `$this->layout('layouts/public', ['title' => '...'])`
4. `npm run build` ถ้าใช้คลาส Tailwind ใหม่
