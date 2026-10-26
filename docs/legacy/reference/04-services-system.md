# 04 — Services ระบบ (LINE / แจ้งเตือน / ลายเซ็น / ความปลอดภัย / ปฏิบัติการ)

เอกสารอ้างอิงสำหรับ junior developer ที่เพิ่งเริ่มกับ PHP MVC ครอบคลุมไฟล์ใน `app/Services/` จำนวน 13 ไฟล์ (159 method)

## ภาพรวม

ในโปรเจกต์นี้ **Service** คือคลาสที่เก็บ "ตรรกะธุรกิจ" (business logic) แยกออกจาก Controller (รับ request/ตอบ response) และ Model (คุยกับฐานข้อมูล) ทุกคลาสเป็น `final class` และแทบทั้งหมดเป็น `static method` (เรียกด้วย `ClassName::method()` ไม่ต้อง `new`) ไม่มี state ภายในคลาส ค่าตั้งต้นอ่านจาก `setting('key')` (ตาราง settings) และค่าคงที่ใน `config/config.php`

แบ่งเป็น 3 กลุ่ม

| กลุ่ม | ไฟล์ | หน้าที่โดยย่อ |
|---|---|---|
| **ติดต่อลูกค้า** | `LineService`, `NotificationService`, `SignatureService`, `InspectionService` | ส่ง LINE/อีเมล, เซ็นสัญญาออนไลน์, ถ่ายรูปตรวจสภาพรถ |
| **ความปลอดภัยและไฟล์** | `AuthService`, `AdminNavService`, `UploadService`, `PrivateFileService` | ล็อกอินหลังบ้าน + เมนูตามสิทธิ์, อัปโหลดไฟล์สาธารณะ, ที่เก็บไฟล์แบบปิด |
| **ปฏิบัติการ** | `BackupService`, `HealthService`, `CronService`, `MonthlyReportService`, `AbTrackService` | สำรอง DB, ตรวจสุขภาพระบบ, งานอัตโนมัติรายวัน, รายงานรายเดือน, สถิติ A/B |

### ความสัมพันธ์ระหว่างกัน (ภาพรวม)

```
Controller / Service อื่น (WebBookingService, PaymentService, ReturnService ...)
        │  NotificationService::newBooking / bookingConfirmed / slipResult ...
        ▼
NotificationService ──queue──▶ ตาราง notifications (Model Notification)
        │  flush()  ──▶ deliver()
        ├── channel=line  ──▶ LineService::pushTo ──▶ LINE Messaging API (cURL)
        └── channel=email ──▶ smtpSend ──▶ mail() หรือ socket SMTP
LineService::notifyCustomer = เติมตัวแปรลง template แล้ว "เข้าคิว" (ยังไม่ส่ง) ต้อง flush ตามหลัง

CronService::run  ──▶ NotificationService (queue/flush), LineService::notifyCustomer,
                      BackupService (create/prune), LateReturnService, Model ต่าง ๆ
HealthService     ──▶ UploadService::dirUsage/limitBytes, PrivateFileService::dir, BackupService::list
SignatureService  ──▶ PrivateFileService::put (เก็บ PNG ลายเซ็น), Inspection (รูปสภาพรถ)
InspectionService ──▶ UploadService::save/shrink, AuthService::id
AdminNavService   ──▶ AuthService::isAdmin
```

หลักคิดสำคัญที่เจอซ้ำในทุกไฟล์
1. **ไม่เชื่อข้อมูลจากผู้ใช้** — ตรวจชนิดไฟล์จริงด้วย `finfo` ไม่ดูนามสกุล, ตรวจ path ด้วย `realpath`, ตรวจลายเซ็น webhook ด้วย HMAC
2. **ล้มเหลวอย่างนุ่มนวล** — ฟีเจอร์เสริม (สถิติ, LINE, GD) พังแล้วต้องไม่ทำให้ฟังก์ชันหลัก (จอง/ล็อกอิน) พังตาม จึงมี `try/catch` เงียบ ๆ หลายจุด
3. **คิวก่อนส่ง** — ทุกข้อความแจ้งเตือนลงตาราง `notifications` ก่อน แล้วค่อยส่ง จึงมีประวัติและ retry ได้

---

## app/Services/LineService.php

- **หน้าที่ของไฟล์**: เชื่อม LINE Official Account — เรียก Messaging API (push / multicast / reply / rich menu / quota), ตรวจลายเซ็น webhook, ตรวจ ID token จาก LIFF, เก็บ "แบบข้อความ" (template) ที่แก้ได้จากหลังบ้าน และผูก LINE user กับการจอง
- **ระบบภายนอก**: LINE Messaging API (`https://api.line.me`), LINE Data API (`https://api-data.line.me` ใช้อัปโหลดรูป rich menu), LINE Login verify endpoint (`/oauth2/v2.1/verify`) — เรียกผ่าน **cURL** ตรง ๆ ไม่ใช้ไลบรารี
- **ใครเรียก**: `LineWebhookController` (verifySignature, profile, reply, fill/template, unlinkUser), `LiffController` (verifyIdToken, linkBooking, unlinkUser, pushTo), `Admin/LineController` (templates, api, pushTo, installRichMenu), `NotificationService` (pushTo, notifyCustomer), `CronService`, `BookingActionService`, `ExtendService`, `SecurityDepositService` (notifyCustomer)
- **พึ่งพา**: Model `Booking`, `Car`, `Customer`, `Notification`; helper `setting()`, `set_setting()`, `site_url()`, `thai_date()`, `money0()`; ค่าคงที่ `APP_ROOT`; settings: `line_channel_token`, `line_channel_secret`, `line_liff_id`, `line_notify_customer`, `tpl_<key>`, `tpl_off`, `line_richmenu_id`, `site_name`, `phone`

### class LineService
- **บทบาท**: ชุดฟังก์ชัน static ล้วน ไม่มี state. ค่าคงที่ `LINE_API_BASE` / `LINE_DATA_BASE` (ถ้า `define` ไว้) ใช้ทับ URL เพื่อชี้ไปเซิร์ฟเวอร์จำลองตอนทดสอบ

#### `public static apiBase(): string`
- **ทำอะไร**: ถ้ามีค่าคงที่ `LINE_API_BASE` ใช้ค่านั้น (ตัด `/` ท้ายออก) ไม่งั้นใช้ `https://api.line.me`
- **รับเข้า**: ไม่มี
- **คืนค่า**: URL ฐานของ Messaging API
- **เรียกใช้โดย / เรียกต่อ**: `api()`, `verifyIdToken()`

#### `public static dataBase(): string`
- **ทำอะไร**: เหมือน `apiBase()` แต่สำหรับโดเมนอัปโหลดไฟล์ (`LINE_DATA_BASE` → ค่าตั้งต้น `https://api-data.line.me`)
- **รับเข้า**: ไม่มี
- **คืนค่า**: URL ฐานของ data API
- **เรียกใช้โดย / เรียกต่อ**: `uploadRichMenuImage()`

#### `public static liffUrl(array $query = []): string`
- **ทำอะไร**: สร้างลิงก์เปิดหน้า "การจองของฉัน" ในแอป LINE. ถ้าตั้ง `line_liff_id` แล้ว → `https://liff.line.me/<LIFF_ID>?query` ถ้ายังไม่ตั้ง → fallback ไปหน้า `site_url('track')` (หน้าตรวจสอบการจองบนเว็บ)
- **รับเข้า**: `$query` array คีย์→ค่า แปลงด้วย `http_build_query` (เช่น `['no' => 'BK-1001']`)
- **คืนค่า**: URL เต็ม
- **เรียกใช้โดย / เรียกต่อ**: `bookingVars()` (ตัวแปร `{ลิงก์}`), `richMenuAreas()`, `LineWebhookController`

#### `public static loginChannelId(): string`
- **ทำอะไร**: LIFF ID รูปแบบ `เลขแชนแนล-รหัส` — ตัดเอาเฉพาะส่วนก่อนเครื่องหมาย `-` ซึ่งคือ Channel ID ของ LINE Login channel ที่ต้องใช้เป็น `client_id` ตอนตรวจ ID token. คืน `''` ถ้ายังไม่ตั้ง LIFF
- **รับเข้า**: ไม่มี
- **คืนค่า**: string
- **เรียกใช้โดย / เรียกต่อ**: `verifyIdToken()`

#### `public static ready(): bool`
- **ทำอะไร**: คืน true ถ้า `line_channel_token` ไม่ว่าง (พร้อมเรียก API ได้)
- **รับเข้า**: ไม่มี
- **คืนค่า**: bool
- **เรียกใช้โดย / เรียกต่อ**: grep แล้ว **ไม่พบผู้เรียกใน `app/` หรือ `cron/`** (น่าจะเป็นโค้ดที่ไม่ได้ใช้)

#### `public static http(string $method, string $url, array $headers = [], $body = null, int $timeout = 15): array`
- **ทำอะไร**: ตัวห่อ cURL ระดับล่างสุด. 1) ถ้าเซิร์ฟเวอร์ไม่มี `curl_init` คืน `[0, '', 'เซิร์ฟเวอร์นี้ไม่ได้เปิด cURL']` 2) ตั้งค่า `CUSTOMREQUEST`, `RETURNTRANSFER`, timeout (ค่าตั้งต้น 15 วิ), connect timeout 8 วิ, headers 3) ถ้า `$body !== null` ใส่ `POSTFIELDS` 4) `curl_exec` แล้วอ่าน HTTP code + error 5) ปิด handle. ไม่ปิด SSL verify (ใช้ค่ามาตรฐาน cURL)
- **รับเข้า**: `$method` (GET/POST/DELETE ...), `$url`, `$headers` (array ของ string แบบ `"Name: value"`), `$body` (string หรือ array/ไบนารี), `$timeout` วินาที
- **คืนค่า**: `[int httpCode, string body, string curlError]` — ล้มเหลวระดับเครือข่าย code = 0
- **เรียกใช้โดย / เรียกต่อ**: `api()`, `verifyIdToken()`, `uploadRichMenuImage()`

#### `public static api(string $method, string $path, ?array $json = null): array`
- **ทำอะไร**: เรียก Messaging API แบบ JSON. 1) อ่าน `line_channel_token`; ว่างคืน `[false, null, 'ยังไม่ได้ตั้งค่า ...']` 2) ใส่ header `Authorization: Bearer <token>` 3) ถ้ามี `$json` เพิ่ม `Content-Type: application/json` และ `json_encode(..., JSON_UNESCAPED_UNICODE)` 4) เรียก `http()` ที่ `apiBase().$path` 5) HTTP 2xx → สำเร็จ (คืน `[]` ถ้า body ไม่ใช่ JSON) 6) ไม่สำเร็จ → ประกอบข้อความจาก `curl error` หรือ `message` ของ LINE + `details[0].message` แล้วส่งผ่าน `errorHint()`
- **รับเข้า**: method, path (เริ่มด้วย `/v2/...`), `$json` array ที่จะส่ง
- **คืนค่า**: `[bool ok, ?array data, ?string error]` (error ขึ้นต้น `LINE API <code>: ...`)
- **เรียกใช้โดย / เรียกต่อ**: `pushTo`, `reply`, `profile`, `createRichMenu`, `installRichMenu`, `deleteOldRichMenu`, `Admin/LineController` (`/v2/bot/info`, `/v2/bot/message/quota`, `/quota/consumption`); เรียกต่อ `http()`, `errorHint()`

#### `private static errorHint(int $code, string $msg): string`
- **ทำอะไร**: แปลงข้อผิดพลาดที่พบบ่อยเป็นภาษาไทยที่แอดมินแก้เองได้: code 0 = เชื่อมต่อไม่ได้, 401 = token ผิด/หมดอายุ, 429 = เกินโควตาแพ็กเกจ, 400 + มีคำว่า `not found` = ผู้ใช้ยังไม่ได้เพิ่มเพื่อน OA. อื่น ๆ คืนข้อความเดิม
- **รับเข้า**: HTTP code, ข้อความดิบ
- **คืนค่า**: string
- **เรียกใช้โดย / เรียกต่อ**: `api()`

#### `private static text(string $text): array`
- **ทำอะไร**: ห่อข้อความให้เป็น message object ของ LINE `['type'=>'text','text'=>...]` และตัดที่ 4,900 ตัวอักษร (`mb_substr`) เผื่อขีดจำกัด 5,000 ของ LINE
- **รับเข้า**: ข้อความ
- **คืนค่า**: message object
- **เรียกใช้โดย / เรียกต่อ**: `pushTo()`, `reply()`

#### `public static pushTo($to, string $message): array`
- **ทำอะไร**: ส่งข้อความหาผู้ใช้. 1) `$to` เป็น array หรือ string ที่คั่นด้วยช่องว่าง/จุลภาค/semicolon → แยกเป็นรายการ, trim, ตัดค่าว่างและซ้ำ 2) ไม่มีผู้รับ → `[false,'ไม่มี LINE user id ปลายทาง']` 3) คนเดียวใช้ `/v2/bot/message/push`, หลายคนใช้ `/v2/bot/message/multicast` (ส่งครั้งเดียว ประหยัด request) ใช้กับเจ้าของร้าน+พนักงานหลายคน
- **รับเข้า**: `$to` string|array ของ LINE user id, `$message` ข้อความล้วน
- **คืนค่า**: `[bool ok, ?string error]`
- **เรียกใช้โดย / เรียกต่อ**: `NotificationService::deliver`, `LiffController` (ข้อความ linked), `Admin/LineController` (ปุ่มทดสอบ); เรียกต่อ `api()`, `text()`

#### `public static reply(string $replyToken, string $message): array`
- **ทำอะไร**: ตอบกลับข้อความที่ลูกค้าส่งมาด้วย reply token (`/v2/bot/message/reply`) — **ไม่นับโควตาข้อความของแพ็กเกจ** reply token ใช้ได้ครั้งเดียวและหมดอายุเร็ว
- **รับเข้า**: reply token จาก webhook, ข้อความ
- **คืนค่า**: `[bool, ?string]`
- **เรียกใช้โดย / เรียกต่อ**: `LineWebhookController` (ข้อความต้อนรับ, ตอบ myid, ตอบเมื่อพบเลขจอง)

#### `public static profile(string $userId): ?array`
- **ทำอะไร**: ดึงโปรไฟล์ผู้ใช้ (`GET /v2/bot/profile/{id}`, `rawurlencode` กัน path แปลก)
- **รับเข้า**: LINE user id
- **คืนค่า**: array (มี `displayName` ฯลฯ) หรือ `null` ถ้าล้มเหลว
- **เรียกใช้โดย / เรียกต่อ**: `LineWebhookController` ตอนบันทึก `line_events`

#### `public static verifySignature(string $rawBody, string $signature): bool`
- **ทำอะไร**: ยืนยันว่า webhook มาจาก LINE จริง. คำนวณ `base64(HMAC-SHA256(rawBody, channel_secret))` แล้วเทียบกับ header `X-Line-Signature` ด้วย `hash_equals` (เทียบแบบ constant-time กัน timing attack). ถ้า secret หรือ signature ว่าง → false ทันที **เหตุผล**: URL webhook เป็นสาธารณะ ใครก็ยิงมาได้ ถ้าไม่ตรวจ คนอื่นจะปลอมเหตุการณ์ (เช่น unfollow) เพื่อทำให้ผูกบัญชีหลุดได้. ต้องใช้ **raw body** ก่อน `json_decode` เพราะเปลี่ยนหนึ่งไบต์ลายเซ็นก็ไม่ตรง
- **รับเข้า**: raw body, ค่า header
- **คืนค่า**: bool
- **เรียกใช้โดย / เรียกต่อ**: `LineWebhookController` (line 33)

#### `public static verifyIdToken(string $idToken): ?array`
- **ทำอะไร**: ตรวจ ID token ที่ได้จาก LIFF กับเซิร์ฟเวอร์ LINE. 1) ต้องมี `loginChannelId()` และ token 2) POST `/oauth2/v2.1/verify` (form-urlencoded: `id_token`, `client_id`) 3) ต้อง HTTP 200 และมี `sub` 4) `aud` ต้องเท่ากับ client id ของเรา (กัน token ของแอปอื่น) 5) `exp` ต้องยังไม่หมดอายุ. **เหตุผล**: ห้ามเชื่อ user id ที่ JavaScript ฝั่งหน้าเว็บส่งมาเอง เพราะแก้ปลอมได้ — ต้องให้ LINE ยืนยัน
- **รับเข้า**: ID token string
- **คืนค่า**: `['sub'=>LINE user id,'name'=>..,'picture'=>..]` หรือ `null` ถ้าไม่ผ่านทุกกรณี
- **เรียกใช้โดย / เรียกต่อ**: `LiffController` (line 120); เรียกต่อ `loginChannelId()`, `http()`

#### `public static templates(): array`
- **ทำอะไร**: คืนแคตตาล็อกแบบข้อความทั้งหมด key → `[ชื่อ, ส่งเมื่อไหร่, ข้อความตั้งต้น]` (ดูตารางด้านล่าง). ตัวแปรเขียนเป็น `{ชื่อตัวแปร}`
- **รับเข้า**: ไม่มี
- **คืนค่า**: `array<string, array{0:string,1:string,2:string}>`
- **เรียกใช้โดย / เรียกต่อ**: `template()`, `Admin/LineController` (หน้าแก้ข้อความ, ปุ่มรีเซ็ต)

**ตารางแบบข้อความ (template keys) — ยืนยันผู้เรียกด้วย grep**

| key | ชื่อ | ส่งเมื่อไหร่ | ผู้เรียก (ไฟล์) | ตัวแปรเด่น | หมายเหตุ |
|---|---|---|---|---|---|
| `welcome` | ข้อความต้อนรับ | ลูกค้าเพิ่มเพื่อน OA (event `follow`) | `LineWebhookController` ใช้ `reply()` โดยตรง (ไม่ผ่านคิว/notifyCustomer) | `{ร้าน}` `{ลิงก์}` `{โทร}` | ไม่นับโควตา (reply) |
| `linked` | ผูกบัญชีสำเร็จ | ลูกค้ายืนยันเลขจอง+เบอร์ในหน้าการจองของฉัน | `LiffController` ~บรรทัด 156-159 ใช้ `pushTo()` ตรง (เช็ค `notify_line_enabled`) | `{ชื่อ}` `{ลิงก์}` | ไม่ผ่านคิว `notifications` |
| `booking_received` | รับการจองแล้ว | มีการจองใหม่สำเร็จ | `NotificationService::newBooking` (เรียกจาก `WebBookingService`) | `{เลขจอง}` `{รถ}` `{วันรับ}` `{วันคืน}` `{มัดจำ}` `{ลิงก์}` | ส่งได้เฉพาะถ้าการจองมี `line_user_id` ผูกอยู่แล้ว. คำอธิบายใน template พูดว่า "จองจากในแอป LINE" |
| `confirmed` | ยืนยันการจอง | ร้านกดยืนยัน หรืออนุมัติสลิปมัดจำ | `NotificationService::bookingConfirmed` (จาก `BookingActionService`, `PaymentService`) | `{เลขจอง}` `{รถ}` `{ทะเบียน}` `{วันรับ}` `{จุดรับ}` `{วันคืน}` `{คงเหลือ}` `{เงินประกัน}` | `$once=true` ส่งครั้งเดียวต่อการจอง |
| `slip_approved` | ได้รับเงินแล้ว | ร้านอนุมัติสลิป | `NotificationService::slipResult(approved=true)` (จาก `PaymentService`) | `{จำนวน}` `{เลขจอง}` `{คงเหลือ}` | ไม่ใช้ `$once` |
| `slip_rejected` | สลิปไม่ผ่าน | ร้านปฏิเสธสลิป | `NotificationService::slipResult(approved=false)` | `{เลขจอง}` `{เหตุผล}` `{ลิงก์}` `{โทร}` | เหตุผลว่างจะใส่ `-` |
| `pickup_reminder` | เตือนวันรับรถ | cron: การจองที่ยืนยันแล้วซึ่งรับรถ "วันนี้–พรุ่งนี้" | `CronService::remindCustomers` | `{รถ}` `{ทะเบียน}` `{วันรับ}` `{จุดรับ}` `{คงเหลือ}` `{เงินประกัน}` `{โทร}` | `$once=true` |
| `return_reminder` | เตือนวันคืนรถ | cron: รถที่รับไปแล้วและครบกำหนดคืน "วันนี้–พรุ่งนี้" | `CronService::remindCustomers` | `{รถ}` `{ทะเบียน}` `{วันคืน}` `{จุดคืน}` `{โทร}` | `$once=true` |
| `sign_request` | ขอให้เซ็นเอกสาร | พนักงานกดปุ่ม "ส่งลิงก์ทาง LINE" ในกล่องลายเซ็น | `BookingActionService::sendSignLink` (ส่ง `ลิงก์เซ็น`, `เอกสาร`) | `{เอกสาร}` `{ลิงก์เซ็น}` `{เลขจอง}` `{รถ}` `{ทะเบียน}` | หลังเข้าคิวจะ `flush()` ทันทีแล้วตรวจสถานะ `sent` |
| `extended` | เช่าต่อ / เลื่อนวันคืน | ร้านบันทึกการเช่าต่อ | `ExtendService` (~บรรทัด 204) ส่ง `จำนวน` = ค่าเช่าเพิ่ม | `{วันคืน}` `{จำนวน}` `{คงเหลือ}` | |
| `returned` | รับคืนรถแล้ว | ร้านบันทึกรับคืนรถ | `NotificationService::bookingReturned` (จาก `ReturnService`) ส่ง `รายการ` (ค่าใช้จ่ายเพิ่มแยกบรรทัด) | `{รายการ}` `{คงเหลือ}` `{ร้าน}` | `$once=true` |
| `deposit_refunded` | คืนเงินประกัน | ร้านกดปิดเงินประกัน | `SecurityDepositService` (~บรรทัด 156) ส่ง `คืนเงิน`, `หัก` | `{คืนเงิน}` `{หัก}` `{ร้าน}` | |

#### `public static template(string $key): string`
- **ทำอะไร**: ข้อความที่ "ใช้จริง" — อ่าน `setting('tpl_'.$key)` ถ้าเคยแก้ในหลังบ้านใช้ค่านั้น ไม่เคยแก้ใช้ข้อความตั้งต้นจาก `templates()`. key ที่ไม่มีในแคตตาล็อกคืน `''`
- **รับเข้า**: template key
- **คืนค่า**: string
- **เรียกใช้โดย / เรียกต่อ**: `notifyCustomer`, `LineWebhookController`, `LiffController`

#### `public static templateOff(string $key): bool`
- **ทำอะไร**: อ่าน setting `tpl_off` (รายการ key คั่นด้วยช่องว่างหรือจุลภาค) แล้วเช็คว่า key นี้ถูกติ๊กปิดไว้หรือไม่ (`in_array` strict)
- **รับเข้า**: template key
- **คืนค่า**: bool
- **เรียกใช้โดย / เรียกต่อ**: `notifyCustomer`, webhook, LIFF, `Admin/LineController`

#### `public static fill(string $template, array $vars): string`
- **ทำอะไร**: แทนค่า `{ตัวแปร}` ด้วย `strtr` (แทนทีเดียว ไม่วนซ้ำ ตัวแปรที่ไม่มีค่าจะคงรูป `{...}` ไว้ในข้อความ)
- **รับเข้า**: template string, `$vars` คีย์ภาษาไทย (ไม่มี `{}`) → ค่า
- **คืนค่า**: ข้อความที่แทนแล้ว
- **เรียกใช้โดย / เรียกต่อ**: `notifyCustomer`, webhook, `LiffController`, `Admin/LineController` (preview)

#### `public static templateVars(): array`
- **ทำอะไร**: คืนรายการตัวแปรที่ใช้ได้พร้อมคำอธิบาย (ชื่อ, เลขจอง, รถ, ทะเบียน, วันรับ, วันคืน, จุดรับ, จุดคืน, ยอดรวม, มัดจำ, คงเหลือ, เงินประกัน, จำนวน, เหตุผล, รายการ, คืนเงิน, หัก, เอกสาร, ลิงก์เซ็น, ร้าน, โทร, ลิงก์) ใช้แสดงในหน้าแก้ข้อความ
- **รับเข้า**: ไม่มี
- **คืนค่า**: map ชื่อตัวแปร → คำอธิบาย
- **เรียกใช้โดย / เรียกต่อ**: `Admin/LineController` (line 86)

#### `public static bookingVars(array $b, ?array $car = null, array $extra = []): array`
- **ทำอะไร**: เตรียมค่าตัวแปรทั้งหมดจากแถวการจอง. 1) ถ้าไม่ส่ง `$car` แต่มี `car_id` → `Car::find` 2) `$due = grand_total + extra_charge` 3) ประกอบ map: ชื่อ=`contact_name`, เลขจอง=`booking_no`, รถ=`brand model`, วันที่ผ่าน `thai_date(...,true)`, จุดรับ/คืน (ว่างใช้ "ที่ร้าน"; จุดคืนว่างใช้จุดรับ), ยอดเงินผ่าน `money0`, **คงเหลือ = max(0, due − paid_amount)**, ร้าน/โทร/ลิงก์ จาก settings 4) ใช้ `$extra + [...]` — **ค่าใน `$extra` ชนะค่าเริ่มต้น** (ตัวดำเนินการ `+` ของ array เก็บคีย์ฝั่งซ้ายก่อน). ค่า `จำนวน`/`เหตุผล`/`รายการ`/`ลิงก์เซ็น` เริ่มต้นเป็นว่าง, `คืนเงิน`/`หัก` = '0', `เอกสาร` = 'สัญญาเช่ารถ'
- **รับเข้า**: แถวการจอง, แถวรถ (ไม่ส่งก็ได้), ตัวแปรเสริม
- **คืนค่า**: array คีย์ภาษาไทย → string
- **เรียกใช้โดย / เรียกต่อ**: `notifyCustomer`, `LiffController`, `Admin/LineController` (ตัวอย่าง preview); เรียกต่อ `Car::find`, `liffUrl()`

#### `public static userForBooking(array $b): ?string`
- **ทำอะไร**: หา LINE user id ที่ผูกกับ "การจองนี้". ถ้าแถวมีคีย์ `line_user_id` ใช้ค่านั้น (ว่าง = null) ถ้าไม่มีคีย์ แต่มี `id` → `Booking::lineUserOf(id)`. **ตั้งใจดูเฉพาะตราบนการจอง ไม่ย้อนไปดูระเบียนลูกค้า** (เหตุผลดู `linkBooking`)
- **รับเข้า**: แถวการจอง
- **คืนค่า**: string|null
- **เรียกใช้โดย / เรียกต่อ**: `notifyCustomer`, `Admin/BookingController` (`hasLine`), `BookingActionService`

#### `public static notifyCustomer(string $tplKey, array $b, ?array $car = null, array $extra = [], bool $once = false): bool`
- **ทำอะไร**: เข้าคิวแจ้งเตือนถึงลูกค้าทาง LINE (**ยังไม่ส่งจริง** ต้องมีคนเรียก `NotificationService::flush()` ต่อ). ขั้นตอน: 1) ถ้า `line_notify_customer` ไม่ใช่ `'1'` (ค่าตั้งต้น '1') หรือ template ถูกปิด → return false 2) ไม่มี LINE user id (`userForBooking`) → false 3) ตั้ง `$key = 'cust_'.$tplKey` 4) ถ้า `$once` และ `Notification::alreadySent($key, bookingId)` (มีแถว queued/sent แล้ว) → false กันส่งซ้ำ 5) `fill(template, bookingVars)` 6) `Notification::queue('line', uid, text, null, 'bookings', bookingId, key)`
- **รับเข้า**: key ของ template, แถว booking (ต้องมี `id`), แถวรถ (ไม่ส่งก็ได้), ตัวแปรเสริม, `$once`
- **คืนค่า**: true = เข้าคิวแล้ว
- **เรียกใช้โดย / เรียกต่อ**: ดูตาราง template ด้านบน; เรียกต่อ `Notification::alreadySent/queue`

#### `public static linkBooking(string $uid, array $b): void`
- **ทำอะไร**: ผูก LINE กับ "การจองที่พิสูจน์แล้ว" 1 รายการ: `Booking::setLineUser(id, uid)`; ถ้ามี `customer_id` เรียก `Customer::rememberLine` (บันทึกไว้แสดงผลในหลังบ้านเท่านั้น ไม่เขียนทับของเดิม). **เหตุผลด้านความปลอดภัย (ตามคอมเมนต์)**: เบอร์โทรไม่ใช่ความลับ ถ้าผูกทั้งระเบียนลูกค้า คนที่รู้เบอร์ผู้อื่นสามารถจองปลอมด้วยเบอร์นั้นแล้วเห็นประวัติและรับแจ้งเตือนของเจ้าของเบอร์ได้ จึงผูกทีละการจอง
- **รับเข้า**: LINE user id (ที่ผ่าน `verifyIdToken` แล้ว), แถวการจอง
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `LiffController` (line 152)

#### `public static unlinkUser(string $uid): void`
- **ทำอะไร**: ยกเลิกการผูกทั้งในระเบียนลูกค้า (`Customer::unlinkLine`) และการจอง (`Booking::unlinkLine`)
- **รับเข้า**: LINE user id
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: webhook เมื่อ event `unfollow`, `LiffController` (line 169 ปุ่มยกเลิกผูก)

#### `public static installRichMenu(): array`
- **ทำอะไร**: ติดตั้งเมนู 3 ปุ่มด้านล่างห้องแชต. ลำดับ: 1) ต้องมีไฟล์ `APP_ROOT/assets/img/richmenu.png` (2500×843) 2) `createRichMenu()` ได้ id 3) `uploadRichMenuImage()` — ถ้าล้มเหลว **ลบเมนูที่เพิ่งสร้าง** ทิ้ง (ไม่ทิ้งขยะบน LINE) 4) `POST /v2/bot/user/all/richmenu/{id}` ตั้งเป็นเมนูหลักของทุกคน 5) `deleteOldRichMenu()` ลบเมนูเก่า 6) `set_setting('line_richmenu_id', id)`
- **รับเข้า**: ไม่มี
- **คืนค่า**: `[bool, string ข้อความผลลัพธ์/ผิดพลาด]`
- **เรียกใช้โดย / เรียกต่อ**: `Admin/LineController` (ปุ่มติดตั้งเมนู); เรียกต่อ `createRichMenu`, `uploadRichMenuImage`, `api`, `deleteOldRichMenu`

#### `private static createRichMenu(): array`
- **ทำอะไร**: `POST /v2/bot/richmenu` ขนาด 2500×843, `selected=true`, ชื่อ `thonglorcarrent-main`, `chatBarText='เมนู'`, `areas` จาก `richMenuAreas()` (ยังไม่มีรูป)
- **รับเข้า**: ไม่มี
- **คืนค่า**: `[richMenuId, errorText]` — สำเร็จ error = `''`; ล้มเหลว id = `''`
- **เรียกใช้โดย / เรียกต่อ**: `installRichMenu()`

#### `private static richMenuAreas(int $width, int $height): array`
- **ทำอะไร**: แบ่งความกว้าง 3 ช่อง: ซ้าย "จองรถ" → `site_url('cars')`, กลาง "การจองของฉัน" → `liffUrl()`, ขวา → `tel:<เบอร์>` ถ้ามีเบอร์ใน settings (กรองเหลือเฉพาะ `0-9` และ `+`) ไม่งั้น "ติดต่อร้าน" → `site_url('contact')`. ช่องขวาสุดใช้ความกว้างที่เหลือเพื่อไม่ให้เหลือเศษพิกเซล
- **รับเข้า**: ความกว้าง, ความสูงของเมนู
- **คืนค่า**: array ของ area (bounds + action uri)
- **เรียกใช้โดย / เรียกต่อ**: `createRichMenu()`

#### `private static uploadRichMenuImage(string $id, string $imagePath): string`
- **ทำอะไร**: POST ไฟล์ PNG ดิบ (`file_get_contents`) ไปที่ `dataBase()/v2/bot/richmenu/{id}/content` ด้วย header Bearer + `Content-Type: image/png`, timeout 30 วิ
- **รับเข้า**: richMenuId, path ไฟล์รูป
- **คืนค่า**: `''` = สำเร็จ, ไม่งั้นข้อความ error
- **เรียกใช้โดย / เรียกต่อ**: `installRichMenu()`; เรียกต่อ `http()`

#### `private static deleteOldRichMenu(string $newId): void`
- **ทำอะไร**: ลบเมนูเก่าที่ระบบเคยสร้าง (id จาก setting `line_richmenu_id`) ถ้าไม่ว่างและไม่ใช่ id ใหม่ — กันค้างเกินโควตา 1,000 เมนู. ไม่ตรวจผลลัพธ์การลบ
- **รับเข้า**: id เมนูใหม่
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `installRichMenu()`

---

## app/Services/NotificationService.php

- **หน้าที่ของไฟล์**: ศูนย์กลางการแจ้งเตือน LINE + อีเมล. ทุกข้อความถูก "ใส่คิว" ลงตาราง `notifications` ก่อน แล้ว `flush()` ส่ง ถ้าส่งไม่ผ่านจะถูก cron ลองใหม่ นอกจากนี้ยังมี SMTP client เขียนเองด้วย socket
- **ระบบภายนอก**: LINE (ผ่าน `LineService`), อีเมลผ่านฟังก์ชัน `mail()` หรือ SMTP server (socket `stream_socket_client`, STARTTLS/SSL, `AUTH LOGIN`)
- **ใครเรียก**: `WebBookingService`, `BookingActionService`, `PaymentService`, `ReturnService`, `ExtendService`, `SecurityDepositService`, `SignController`, `Admin/SettingController` (ทดสอบ), `CronService`
- **พึ่งพา**: Model `Notification`, `LineService`, helper `setting()`, `thai_date()`, `money()`, `money0()`, `e()`; settings: `smtp_host/port/user/pass/secure/from_name`, `email`, `line_admin_user_id`, `notify_admin_email`, `notify_line_enabled`, `notify_email_enabled`, `bank_*`, `promptpay_id`, `phone`, `line_id`

### class NotificationService
- **บทบาท**: ส่วนหน้า (facade) ของระบบคิวแจ้งเตือน. สถานะแถวใน `notifications`: `queued` → (claim) `failed` + error "กำลังส่ง" → `sent` หรือ `failed` + ข้อความ error

#### `public static queue(string $channel, ?string $recipient, string $message, ?string $subject = null, ?string $refTable = null, ?int $refId = null, ?string $tplKey = null): int`
- **ทำอะไร**: ส่งต่อให้ `Notification::queue` (บันทึกแถวสถานะ queued). Model จะตั้ง recipient เป็น null ถ้ายาวเกิน 190 ตัวอักษร (รายชื่อผู้รับหลายคน) แล้วตอนส่งจะใช้ค่าจาก settings แทน
- **รับเข้า**: `$channel` `'line'|'email'`, `$recipient` (LINE user id/อีเมล; null = ใช้ผู้รับของร้านตอนส่ง), `$message`, `$subject` (เฉพาะอีเมล), `$refTable/$refId` (อ้างอิงเรคคอร์ด), `$tplKey` (ใช้กันส่งซ้ำ)
- **คืนค่า**: id ของแถวที่เพิ่ม
- **เรียกใช้โดย / เรียกต่อ**: เมธอดสำเร็จรูปในคลาสนี้, `CronService` หลายจุด, `SignController`, `Admin/SettingController`; เรียกต่อ `Notification::queue`

#### `public static smtpSend(string $to, string $subject, string $htmlBody): array`
- **ทำอะไร**: ตัวเลือกวิธีส่ง — ถ้า setting `smtp_host` ว่าง ใช้ `sendWithMail()` (mail() ของโฮสต์) ไม่งั้น `sendWithSmtp()`
- **รับเข้า**: อีเมลผู้รับ, หัวเรื่อง, เนื้อหา HTML
- **คืนค่า**: `[bool ok, ?string error]`
- **เรียกใช้โดย / เรียกต่อ**: `deliver()`

#### `private static sendWithMail(string $to, string $subject, string $htmlBody): array`
- **ทำอะไร**: ประกอบ header (MIME 1.0, HTML UTF-8, `From: ชื่อ <setting email>`) เข้ารหัสหัวเรื่องเป็น `=?UTF-8?B?base64?=` แล้วเรียก `@mail()` (ปิด warning ด้วย `@`)
- **รับเข้า**: เหมือน `smtpSend`
- **คืนค่า**: `[ok, 'mail() ส่งไม่สำเร็จ' หรือ null]`
- **เรียกใช้โดย / เรียกต่อ**: `smtpSend()`

#### `private static sendWithSmtp(string $to, string $subject, string $htmlBody): array`
- **ทำอะไร**: คุย SMTP ด้วย socket: 1) เปิด `stream_socket_client` (`ssl://` ถ้า secure=ssl) timeout 20 วิ ล้มเหลว → error "เชื่อมต่อ SMTP ไม่ได้" 2) อ่านคำทักทาย, ส่ง `EHLO` 3) ถ้า secure=`tls` → `STARTTLS` + `stream_socket_enable_crypto` (ล้มเหลวปิด socket) แล้ว EHLO ใหม่ 4) ถ้ามี `smtp_user` → `AUTH LOGIN` (user/pass base64) ต้องได้รหัส `235` 5) `MAIL FROM` (ใช้ user หรือ setting email), `RCPT TO` ต้องได้ `25x` 6) `DATA` แล้วเขียนเนื้อหา + `\r\n.\r\n` 7) อ่านผล ต้องขึ้นต้น `250` แล้ว `QUIT`
- **รับเข้า**: เหมือน `smtpSend`
- **คืนค่า**: `[bool, ?string]` (error คือบรรทัดตอบกลับของเซิร์ฟเวอร์)
- **เรียกใช้โดย / เรียกต่อ**: `smtpSend()`; เรียกต่อ `smtpRead`, `smtpCommand`, `mailContent`
- **ข้อสังเกต**: `$to`/`$subject` ไม่ถูกตรวจ CRLF ก่อนนำไปใส่ `RCPT TO` ในไฟล์นี้ และไม่ตรวจรหัสตอบกลับของ `MAIL FROM`/`DATA`

#### `private static smtpRead($fp): string`
- **ทำอะไร**: อ่านคำตอบ SMTP ทีละบรรทัด (`fgets` สูงสุด 515 ไบต์) จนเจอบรรทัดที่อักขระตัวที่ 4 เป็นช่องว่าง (`250 OK` = จบ; `250-...` = ยังมีต่อ)
- **รับเข้า**: stream resource
- **คืนค่า**: ข้อความตอบกลับทั้งหมด
- **เรียกใช้โดย / เรียกต่อ**: `sendWithSmtp`, `smtpCommand`

#### `private static smtpCommand($fp, string $command): string`
- **ทำอะไร**: เขียนคำสั่ง + `\r\n` แล้วเรียก `smtpRead`
- **รับเข้า**: stream, คำสั่ง
- **คืนค่า**: คำตอบของเซิร์ฟเวอร์
- **เรียกใช้โดย / เรียกต่อ**: `sendWithSmtp`

#### `private static mailContent(string $from, string $to, string $subject, string $htmlBody): string`
- **ทำอะไร**: สร้างหัวจดหมาย (From/To/Subject แบบ encoded-word, MIME, `Content-Transfer-Encoding: base64`) ต่อด้วยเนื้อหา `chunk_split(base64_encode(...))` — base64 ทำให้ไม่ต้องกังวลบรรทัดที่เป็น `.` เดี่ยว ๆ (dot-stuffing)
- **รับเข้า**: from, to, subject, HTML
- **คืนค่า**: ข้อความจดหมายทั้งฉบับ (ยังไม่รวม `\r\n.\r\n`)
- **เรียกใช้โดย / เรียกต่อ**: `sendWithSmtp`

#### `public static flush(int $limit = 20): int`
- **ทำอะไร**: ประมวลผลคิว: 1) `Notification::queued($limit)` 2) แต่ละแถว `Notification::claim(id)` — UPDATE แบบ atomic เปลี่ยน queued→failed("กำลังส่ง") ถ้าได้ 0 แถวแปลว่ามี request อื่นจองไปแล้ว ให้ข้าม (กันส่งซ้ำเมื่อ cron กับหน้าเว็บ flush พร้อมกัน; ถ้าระบบล่มกลางทางจะเห็นเป็น "ส่งไม่สำเร็จ" ไม่หายเงียบ) 3) `deliver()` 4) `Notification::markResult(id, ok, err)` 5) นับจำนวนที่สำเร็จ
- **รับเข้า**: `$limit` จำนวนสูงสุดต่อรอบ (Model บังคับ 1–500)
- **คืนค่า**: จำนวนที่ส่งสำเร็จ
- **เรียกใช้โดย / เรียกต่อ**: เมธอดสำเร็จรูปด้านล่างหลังเข้าคิว, `CronService::flushAndClean` (50), `BookingActionService::sendSignLink`, `ExtendService`, `SecurityDepositService`, `SignController`, `Admin/SettingController`

#### `private static deliver(array $n): array`
- **ทำอะไร**: ส่งแถวเดียวตามช่องทาง. `line` และ `notify_line_enabled === '1'` → ผู้รับ = `recipient` หรือ fallback `line_admin_user_id` (ไม่มี → error) → `LineService::pushTo`. `email` และ `notify_email_enabled === '1'` → ผู้รับ = `recipient` หรือ `notify_admin_email` → `smtpSend` เนื้อหา `nl2br(e($message))` (escape HTML ก่อน กัน HTML injection) หัวเรื่องตั้งต้น `thonglorcarrent`. ช่องทางที่ปิดอยู่คืน `[false,'ปิดการใช้งานช่องทางนี้']` (cron จะไม่ retry error ที่ขึ้นต้น "ปิดการใช้งาน" หรือ "ไม่มี")
- **รับเข้า**: แถว notification (`channel`, `recipient`, `subject`, `message`)
- **คืนค่า**: `[bool, ?string]`
- **เรียกใช้โดย / เรียกต่อ**: `flush()`; เรียกต่อ `LineService::pushTo`, `smtpSend`

#### `public static newBooking(array $b, array $car): void`
- **ทำอะไร**: แจ้งเมื่อมีการจองใหม่ — ข้อความสรุป (เลขจอง รถ ลูกค้า วันรับ/คืน ยอดรวม มัดจำ) → เข้าคิว LINE หาแอดมิน + อีเมลหาเจ้าของ; ถ้าลูกค้ากรอกอีเมล ส่งอีเมลขอบคุณพร้อมข้อมูลบัญชีธนาคาร/PromptPay/เบอร์โทร/LINE ID; เรียก `LineService::notifyCustomer('booking_received')`; `flush()`
- **รับเข้า**: `$b` แถวการจอง (ต้องมี `id`, `booking_no`, `contact_name`, `contact_phone`, `pickup_at`, `return_at`, `grand_total`, `deposit_amount`), `$car` (`brand`,`model`,`plate`)
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `WebBookingService::create` (line 248)

#### `public static bookingConfirmed(array $b, array $car): void`
- **ทำอะไร**: แจ้งยืนยันการจอง — อีเมลลูกค้า (ถ้ามีอีเมล), LINE แอดมิน, LINE ลูกค้า template `confirmed` แบบ `$once=true`; แล้ว `flush()`. ยอดคงเหลือในข้อความแอดมิน/อีเมลคำนวณ `grand_total − paid_amount` (**ไม่รวม `extra_charge`** ต่างจาก `bookingVars` ที่รวม — ดูข้อสังเกตท้ายเอกสาร)
- **รับเข้า**: แถวการจอง, แถวรถ
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `BookingActionService` (line 85), `PaymentService` (line 105)

#### `public static bookingReturned(array $b, array $car, array $lines): void`
- **ทำอะไร**: แจ้งเมื่อรับคืนรถ. 1) แยก `$lines` เป็น `$charged` (ไม่ waived และ amount>0, รวมยอดเป็น `$extra`) กับ `$waived` 2) สร้างข้อความรายการสำหรับลูกค้า ("ค่าใช้จ่ายเพิ่ม X บาท • รายการ...") หรือ "ไม่มีค่าใช้จ่ายเพิ่ม" แล้ว `notifyCustomer('returned', ..., once=true)` 3) **แจ้งร้าน (LINE แอดมิน) เฉพาะเมื่อมีค่าใช้จ่ายเพิ่มหรือมีรายการที่พนักงานยกเว้น** — เพื่อให้เจ้าของเห็นทุกการยกเว้นค่าปรับโดยไม่ต้องเปิดดูทีละใบ (แสดงยอดที่ระบบคิดไว้ `suggested`) พร้อมยอดค้างชำระ 4) `flush()` ถ้ามีอะไรเข้าคิว
- **รับเข้า**: แถวการจอง, รถ, `$lines` array ของ `['label','amount','waived','suggested'?]`
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `ReturnService` (line 188)

#### `public static slipResult(array $b, float $amount, bool $approved, string $reason = ''): void`
- **ทำอะไร**: แจ้งผลตรวจสลิปให้ลูกค้าทาง LINE: `approved` → template `slip_approved` ไม่งั้น `slip_rejected` (ส่ง `จำนวน`, `เหตุผล` ถ้าว่างใส่ `-`) แล้ว flush ถ้าเข้าคิว
- **รับเข้า**: การจอง, ยอดในสลิป, อนุมัติหรือไม่, เหตุผลปฏิเสธ
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `PaymentService` (lines 112, 132)

#### `public static slipUploaded(array $b, float $amount): void`
- **ทำอะไร**: แจ้งแอดมิน (LINE + อีเมล) ว่าลูกค้าแจ้งโอนเงิน ให้เข้าไปตรวจและยืนยัน แล้ว flush
- **รับเข้า**: การจอง, ยอดเงิน
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `PaymentService` (line 78)

---

## app/Services/SignatureService.php

- **หน้าที่ของไฟล์**: ลายเซ็นอิเล็กทรอนิกส์ของผู้เช่า (สัญญาเช่า `contract` และใบรับคืน `return`). ลูกค้าเซ็นผ่านลิงก์เฉพาะ (token สุ่ม ไม่ต้องล็อกอิน). ทุกครั้งที่เซ็นเก็บ **snapshot** ของข้อมูล+ข้อสัญญา พร้อม hash SHA-256, เวลา, IP, user agent เพื่อใช้ตรวจว่ามีการแก้ข้อมูลหลังลงนามหรือไม่. ลายเซ็นไม่ถูกลบ (ขอเซ็นใหม่ = void ฉบับเดิม). ไฟล์ภาพเก็บในที่เก็บแบบปิด
- **ระบบภายนอก**: ไฟล์ระบบ (ผ่าน `PrivateFileService`), PHP GD (ประมวลผลภาพ), ฐานข้อมูล (transaction)
- **ใครเรียก**: `SignController` (หน้าเซ็นสาธารณะ), `BookingActionService`, view `admin/bookings/show.php`, `admin/contract.php`, `public/sign.php`
- **พึ่งพา**: Model `Booking`, `Inspection`, `Signature`, `ActivityLog`; `Database`, `Request`, `View`; `PrivateFileService::put`

### class SignatureService
- **บทบาท**: ตรรกะลายเซ็น. ค่าคงที่ `private const INVALID_SIGNATURE` (ข้อความ error กลาง "ลายเซ็นไม่ถูกต้อง กรุณาเซ็นใหม่อีกครั้ง") ประกาศกลางไฟล์แต่ใช้ได้ทั่วคลาส

#### `public static kinds(): array`
- **ทำอะไร**: นิยามชนิดเอกสาร → `[ชื่อ, สถานะการจองที่เซ็นได้]`: `contract` = สัญญาเช่ารถ เซ็นได้ตอน `pending/confirmed/picked_up`; `return` = ใบรับคืนรถ เซ็นได้ตอน `returned/completed`
- **รับเข้า**: ไม่มี
- **คืนค่า**: map kind → `[string, string[]]`
- **เรียกใช้โดย / เรียกต่อ**: `blocker`, `SignController`, `BookingActionService`, views

#### `public static url(array $b, string $kind = 'contract'): string`
- **ทำอะไร**: ลิงก์เซ็นเต็ม `site_url('sign')?no=<booking_no>&k=<kind>&t=<token>` (`rawurlencode` เลขจอง) ใช้ส่งทาง LINE ได้
- **รับเข้า**: แถวการจอง, kind
- **คืนค่า**: URL
- **เรียกใช้โดย / เรียกต่อ**: `BookingActionService::sendSignLink`, view `bookings/show.php`; เรียกต่อ `token()`

#### `public static token(array $b): string`
- **ทำอะไร**: คืน `sign_token` ของการจอง; ถ้ายังไม่มีเรียก `Booking::signToken(id)` ซึ่งสร้างรหัสสุ่ม (ตามเอกสารคลาส 128 บิต) แล้วบันทึกไว้ → ใช้รหัสเดิมตลอด
- **รับเข้า**: แถวการจอง
- **คืนค่า**: token string
- **เรียกใช้โดย / เรียกต่อ**: `url()`

#### `public static blocker(array $b, string $kind): ?string`
- **ทำอะไร**: เช็คว่าเซ็นได้ตอนนี้หรือไม่ ลำดับ: kind ไม่รู้จัก → "ไม่พบเอกสาร"; สถานะ `cancelled` → "ถูกยกเลิกแล้ว"; สถานะไม่อยู่ในรายการของ kind → ข้อความอธิบาย (return: ยังไม่ถึงขั้นคืนรถ / contract: ผ่านขั้นรับรถไปแล้ว ไม่เซ็นย้อนหลัง)
- **รับเข้า**: การจอง, kind
- **คืนค่า**: null = เซ็นได้, string = เหตุผลที่เซ็นไม่ได้
- **เรียกใช้โดย / เรียกต่อ**: `save()`, `SignController`, `BookingActionService`, views

#### `public static fields(array $b, array $car, string $kind): array`
- **ทำอะไร**: ข้อมูลสำคัญที่ผู้เช่ารับรองด้วยลายเซ็น `key => [ป้าย, ค่าแสดง]`: renter, car, pickup, return, total, deposit และถ้า `kind=return` เพิ่ม returned, km_in, fuel_in (n/8), extra, damage. ใช้ทั้งแสดงหน้าเซ็น, เก็บเป็น snapshot, และเทียบหาการแก้ไข
- **รับเข้า**: การจอง, รถ, kind
- **คืนค่า**: map key → `[label, value]`
- **เรียกใช้โดย / เรียกต่อ**: `changedFields`, `viewHash`, `buildSnapshot`, `SignController`, view `sign.php`

#### `public static changes(array $sig, array $b, array $car): array`
- **ทำอะไร**: รายการสิ่งที่ถูกแก้ **หลังลงนาม**. decode snapshot แล้วรวมผลจาก `changedFields` + `changedPhotos` + (เฉพาะ return) `changedExtras`
- **รับเข้า**: แถวลายเซ็น, การจองปัจจุบัน, รถ
- **คืนค่า**: array ของ `['label','signed','now']`
- **เรียกใช้โดย / เรียกต่อ**: view `admin/contract.php`, `bookings/show.php`

#### `private static changedFields(array $snap, array $sig, array $b, array $car): array`
- **ทำอะไร**: วนช่องปัจจุบัน เทียบกับ `snapshot['fields']` เฉพาะคีย์ที่มีในสำเนาเดิม ถ้าค่า (แปลงเป็น string) ไม่ตรงกัน → ใส่ในรายการ
- **รับเข้า**: snapshot ที่ decode แล้ว, ลายเซ็น, การจอง, รถ
- **คืนค่า**: list ของ `['label','signed','now']`
- **เรียกใช้โดย / เรียกต่อ**: `changes()`

#### `private static changedPhotos(array $snap, array $sig, array $b): ?array`
- **ทำอะไร**: เทียบชุด path รูปสภาพรถ (หลัง `sort`) ระหว่าง snapshot กับปัจจุบัน (`Inspection::get` รอบ `in` สำหรับ return, `out` สำหรับ contract). **ไม่เทียบถ้าตอนเซ็นไม่มีรูป** (ลายเซ็นไม่ได้รับรองรูป)
- **รับเข้า**: snapshot, ลายเซ็น, การจอง
- **คืนค่า**: `['label'=>'รูปสภาพรถ','signed'=>'N รูป','now'=>'M รูป ...']` หรือ null
- **เรียกใช้โดย / เรียกต่อ**: `changes()`

#### `private static changedExtras(array $snap, array $b): ?array`
- **ทำอะไร**: เทียบรายการค่าใช้จ่ายเพิ่มตอนคืนรถ (`Booking::extraLines`) กับ `snapshot['extras']` ผ่าน `extrasKey`. คืนจำนวนรายการเก่า/ใหม่ หรือ null ถ้าเหมือนกัน
- **รับเข้า**: snapshot, การจอง
- **คืนค่า**: `['label','signed','now']` หรือ null
- **เรียกใช้โดย / เรียกต่อ**: `changes()`

#### `private static extrasKey(array $lines): string`
- **ทำอะไร**: ย่อแต่ละรายการเป็น `[label, round(amount,2), waived]` แล้ว `json_encode` เป็น string เดียวเพื่อเทียบเท่ากัน
- **รับเข้า**: รายการค่าใช้จ่าย
- **คืนค่า**: JSON string
- **เรียกใช้โดย / เรียกต่อ**: `changedExtras()`

#### `public static intact(array $sig): bool`
- **ทำอะไร**: ตรวจความสมบูรณ์ — `hash_equals(snapshot_hash, sha256(snapshot))`. false แปลว่าข้อความ snapshot ในฐานข้อมูลถูกแก้ตรง ๆ
- **รับเข้า**: แถวลายเซ็น
- **คืนค่า**: bool
- **เรียกใช้โดย / เรียกต่อ**: view `bookings/show.php` (แสดงคำเตือน)

#### `public static viewHash(array $b, array $car, string $kind, string $termsText): string`
- **ทำอะไร**: ลายนิ้วมือของ "สิ่งที่ลูกค้าเห็น" บนหน้าเซ็น = sha256 ของ JSON [ค่าช่องต่าง ๆ, ข้อความสัญญา, path รูป, รายการค่าใช้จ่าย (เฉพาะ return), นโยบาย PDPA]. หน้าเซ็นฝังค่านี้ แล้วเทียบอีกครั้งตอนกดยืนยัน: ถ้าร้านแก้ข้อมูลระหว่างที่ลูกค้าเปิดหน้าค้างไว้ ค่าไม่ตรง → ให้ลูกค้าตรวจใหม่ ลายเซ็นจึงรับรองเฉพาะสิ่งที่เห็นจริง (ส่วนเทียบค่าอยู่ใน `SignController` ไม่ได้อยู่ในไฟล์นี้)
- **รับเข้า**: การจอง, รถ, kind, ข้อความสัญญาล้วน
- **คืนค่า**: hex sha256
- **เรียกใช้โดย / เรียกต่อ**: `SignController` (line 72)

#### `public static ref(array $sig): string`
- **ทำอะไร**: รหัสอ้างอิงสั้น = 12 ตัวแรกของ `snapshot_hash` ตัวพิมพ์ใหญ่ พิมพ์ลงสัญญา
- **รับเข้า**: แถวลายเซ็น
- **คืนค่า**: string 12 ตัว
- **เรียกใช้โดย / เรียกต่อ**: view `contract.php`, `bookings/show.php`, `sign.php`

#### `public static contractTermsHtml(array $b, string $blank = '<span ...>'): string`
- **ทำอะไร**: เรนเดอร์ partial `partials/contract_terms` ผ่าน `View::make` เป็น HTML — ใช้ร่วมกันระหว่างหน้าพิมพ์สัญญาและหน้าเซ็นมือถือ ลูกค้าจึงเห็นข้อความชุดเดียวกับฉบับพิมพ์. `$blank` คือ HTML ช่องว่างสำหรับกรอกมือ
- **รับเข้า**: การจอง, HTML ของช่องว่าง
- **คืนค่า**: HTML string
- **เรียกใช้โดย / เรียกต่อ**: `SignController` (line 51); เรียกต่อ `View::make`

#### `public static plainText(string $html): string`
- **ทำอะไร**: แปลง HTML ข้อสัญญาเป็นข้อความล้วนเพื่อเก็บเป็นหลักฐาน: แทน `</li>`/`</p>` ด้วย newline, `strip_tags`, `html_entity_decode`, ยุบช่องว่าง (`\s+` → ช่องว่างเดียว รวม newline ด้วย) แล้ว `trim`
- **รับเข้า**: HTML
- **คืนค่า**: ข้อความล้วน
- **เรียกใช้โดย / เรียกต่อ**: `SignController` (line 57)

#### `public static toPng(string $dataUrl): string`
- **ทำอะไร**: แปลงรูปลายเซ็น (data URL จาก canvas) เป็น PNG ที่ปลอดภัย: 1) `decodeDataUrl` 2) `checkPngSize` 3) ถ้าเซิร์ฟเวอร์ไม่มี GD คืนไบนารีเดิม (ผ่านการตรวจว่าเป็น PNG แล้ว) 4) `imagecreatefromstring` ล้มเหลว → exception 5) วาดใหม่บนพื้นขาว `onWhiteBackground` **การเขียนภาพใหม่ทำให้ข้อมูลแปลกปลอมที่อาจแฝงมาในไฟล์ (metadata, payload) หลุดทิ้งทั้งหมด** 6) `countInk` ต้อง ≥ 60 จุด ไม่งั้น "ลายเซ็นสั้นเกินไป" (กันกดส่งกระดาษเปล่า/จุดเดียว) 7) `imagepng` (บีบ level 7) ผ่าน output buffer
- **รับเข้า**: `data:image/png;base64,...`
- **คืนค่า**: ไบนารี PNG; **throws** `\RuntimeException` (ข้อความไทยแสดงให้ลูกค้าได้)
- **เรียกใช้โดย / เรียกต่อ**: `save()`

#### `private static decodeDataUrl(string $dataUrl): string`
- **ทำอะไร**: regex ต้องเป็น `data:image/png;base64,` ตามด้วยอักขระ base64 เท่านั้น ไม่ตรง → "กรุณาเซ็นชื่อในกรอบก่อนกดยืนยัน". `base64_decode(strict)` ผิดพลาด หรือขนาด <200 หรือ >600 KB → INVALID_SIGNATURE
- **รับเข้า**: data URL
- **คืนค่า**: ไบนารี; throws `\RuntimeException`
- **เรียกใช้โดย / เรียกต่อ**: `toPng()`

#### `private static checkPngSize(string $bin): void`
- **ทำอะไร**: `getimagesizefromstring` ต้องได้ชนิด `IMAGETYPE_PNG` จริง (ไม่เชื่อ header ของ data URL) และขนาดกว้าง 120–2400, สูง 60–1600 ไม่งั้น INVALID_SIGNATURE (กันภาพเล็ก/ใหญ่ผิดปกติ)
- **รับเข้า**: ไบนารีภาพ
- **คืนค่า**: void; throws
- **เรียกใช้โดย / เรียกต่อ**: `toPng()`

#### `private static onWhiteBackground($src)`
- **ทำอะไร**: สร้าง truecolor image ใหม่ขนาดเดิม เติมสีขาว เปิด alpha blending แล้ว `imagecopy` ภาพต้นฉบับลงไป (พื้นโปร่งใสกลายเป็นขาว)
- **รับเข้า**: GD image ต้นฉบับ
- **คืนค่า**: GD image ใหม่
- **เรียกใช้โดย / เรียกต่อ**: `toPng()`

#### `private static countInk($image): int`
- **ทำอะไร**: สุ่มตัวอย่างพิกเซลทุก 3 พิกเซลในแต่ละแกน นับจุดที่ผลรวม R+G+B < 500 เป็น "หมึก" (ลายเซ็นจริงมีหลายร้อยจุด)
- **รับเข้า**: GD image
- **คืนค่า**: จำนวนจุดหมึกที่สุ่มนับ
- **เรียกใช้โดย / เรียกต่อ**: `toPng()`

#### `public static save(array $b, array $car, string $kind, string $dataUrl, string $name, bool $acceptTerms, bool $consent, string $termsText): int`
- **ทำอะไร**: บันทึกลายเซ็น ลำดับ: 1) `blocker` ไม่ผ่าน → throw 2) `checkSignerName` 3) ต้องติ๊กยอมรับเงื่อนไข 4) ต้องติ๊กยินยอม PDPA 5) `toPng` 6) `buildSnapshot` 7) `insertSignature` (transaction) 8) `ActivityLog::log('signature_signed', ...)`
- **รับเข้า**: การจอง, รถ, kind, data URL, ชื่อผู้ลงนาม, ติ๊กยอมรับ, ติ๊ก PDPA, ข้อความสัญญาล้วนที่ลูกค้าเห็น
- **คืนค่า**: id ลายเซ็น; **throws** `\RuntimeException` ทุกกรณีที่เซ็นไม่ได้
- **เรียกใช้โดย / เรียกต่อ**: `SignController` (line 108)

#### `private static checkSignerName(string $name): string`
- **ทำอะไร**: ยุบช่องว่างซ้ำ + trim; ความยาว (`mb_strlen`) ต้อง 2–150 ไม่งั้น throw
- **รับเข้า**: ชื่อดิบ
- **คืนค่า**: ชื่อที่ทำความสะอาดแล้ว
- **เรียกใช้โดย / เรียกต่อ**: `save()`

#### `private static buildSnapshot(array $b, array $car, string $kind, string $name, string $termsText): string`
- **ทำอะไร**: สร้าง JSON: `booking_no`, `kind`, `fields` (key→ค่า), `extras` (เฉพาะ return), `inspection` (จำนวนรูป, `locked_at`, รายการ `files` — null ถ้าไม่มีการตรวจ), `pdpa_text`, `terms`, `signed_by`
- **รับเข้า**: การจอง, รถ, kind, ชื่อผู้ลงนาม, ข้อความสัญญา
- **คืนค่า**: JSON string (`JSON_UNESCAPED_UNICODE`)
- **เรียกใช้โดย / เรียกต่อ**: `save()`

#### `private static insertSignature(array $b, string $kind, string $name, string $png, string $snapshot): int`
- **ทำอะไร**: ใน transaction (`Database::begin`): `Booking::lock(id)` ล็อกแถวการจองก่อนตรวจ **กันกดยืนยันสองครั้งพร้อมกันแล้วได้ลายเซ็นซ้ำ**; ถ้ามีลายเซ็น active ของ kind นี้แล้ว → throw "ลงนามเรียบร้อยแล้ว"; `PrivateFileService::put('signatures', $png, 'png')` เขียนไฟล์; `Signature::insert` (เก็บ `snapshot_hash = sha256(snapshot)`, IP ตัด 45 ตัว, UA ตัด 250 ตัว, `consent_pdpa=1`, `signed_at`); commit. ผิดพลาด → rollBack แล้ว throw ต่อ
- **ข้อสังเกต**: ถ้า insert DB ล้มหลังเขียนไฟล์ ไฟล์ PNG จะเหลือในที่เก็บ (ไม่มีโค้ดลบ — `PrivateFileService` ไม่มีคำสั่งลบโดยเจตนา)
- **รับเข้า**: การจอง, kind, ชื่อ, PNG, snapshot JSON
- **คืนค่า**: id ลายเซ็น
- **เรียกใช้โดย / เรียกต่อ**: `save()`; เรียกต่อ `Request::current()`, `PrivateFileService::put`, `Signature::insert`

---

## app/Services/InspectionService.php

- **หน้าที่ของไฟล์**: ตรวจสภาพรถก่อน–หลังเช่า (ถ่ายรูป 8 มุม) เป็นหลักฐานเมื่อมีข้อพิพาท. รอบตรวจ: `out` = ตอนส่งมอบ, `in` = ตอนรับคืน. ผลที่ถูก "ล็อก" แล้วแก้ไม่ได้
- **ระบบภายนอก**: ไฟล์ระบบ (uploads/inspections)
- **ใครเรียก**: `Admin/InspectionController`, `Admin/BookingController`, `Admin/ContractController`, `SignController`, `ReturnService` (lock รอบ in), `BookingActionService` (lock รอบ out), views
- **พึ่งพา**: Model `Inspection`, `ActivityLog`; `UploadService`, `AuthService`

### class InspectionService
- **บทบาท**: ตรรกะรอบตรวจสภาพรถ ไม่มี state

#### `public static positions(): array`
- **ทำอะไร**: 8 มุมมาตรฐานเรียงตามการเดินรอบรถ: front, right, rear, left, dashboard, interior, tire, damage → `[ชื่อ, คำแนะนำการถ่าย]`
- **รับเข้า**: ไม่มี
- **คืนค่า**: map position → `[string, string]`
- **เรียกใช้โดย / เรียกต่อ**: `progress`, `Admin/InspectionController` (validate ตำแหน่ง), views

#### `public static kindLabel(string $kind): string`
- **ทำอะไร**: `out` → "ตอนส่งมอบรถ", อื่น ๆ → "ตอนรับคืนรถ"
- **รับเข้า**: `'out'|'in'`
- **คืนค่า**: ป้ายชื่อไทย
- **เรียกใช้โดย / เรียกต่อ**: `Admin/InspectionController`, views

#### `public static both(int $bookingId): array`
- **ทำอะไร**: ดึงทั้ง 2 รอบ `['out'=>Inspection::get(..,'out'), 'in'=>Inspection::get(..,'in')]` ใช้หน้าเทียบก่อน–หลัง
- **รับเข้า**: booking id
- **คืนค่า**: `['out'=>?array,'in'=>?array]`
- **เรียกใช้โดย / เรียกต่อ**: grep แล้ว **ไม่พบผู้เรียกในโปรเจกต์** (น่าจะไม่ได้ใช้)

#### `public static isLocked(?array $inspection): bool`
- **ทำอะไร**: true ถ้ามีแถวและ `locked_at` ไม่ว่าง
- **รับเข้า**: แถว inspection หรือ null
- **คืนค่า**: bool
- **เรียกใช้โดย / เรียกต่อ**: `Admin/InspectionController`, views

#### `public static phaseBlock(array $booking, string $kind): ?string`
- **ทำอะไร**: ตรวจว่ารอบนี้ยังแก้ได้ตามขั้นตอนการจองหรือไม่. รอบ `out` แก้ได้เมื่อสถานะ `pending/confirmed` เท่านั้น; รอบ `in` แก้ได้เมื่อ `picked_up` เท่านั้น. ไม่ผ่าน: รอบ in ที่ยังไม่ส่งมอบ → "ยังไม่ได้ส่งมอบรถ ...", นอกนั้น "ผลตรวจรอบ... ปิดแล้ว" **กันกรณีส่งมอบรถโดยไม่ถ่ายรูปแล้วย้อนมาถ่ายภายหลังแล้วอ้างว่าเป็นสภาพวันส่งมอบ**
- **รับเข้า**: แถวการจอง (ใช้ `status`), kind
- **คืนค่า**: null = แก้ได้, string = เหตุผล
- **เรียกใช้โดย / เรียกต่อ**: `Admin/InspectionController` (หลายจุด)

#### `public static lock(int $bookingId, string $kind): bool`
- **ทำอะไร**: เรียก `Inspection::lock(bookingId, kind, AuthService::id())` ถ้าเพิ่งล็อกจริงบันทึก `ActivityLog 'inspection_locked'`
- **รับเข้า**: booking id, kind
- **คืนค่า**: true ถ้าเพิ่งถูกล็อก
- **เรียกใช้โดย / เรียกต่อ**: `Admin/InspectionController` (line 80), `ReturnService` (line 182 รอบ in), `BookingActionService` (line 112 รอบ out)

#### `public static savePhoto(int $inspectionId, string $position, array $file, ?string $caption = null): ?string`
- **ทำอะไร**: บันทึกรูป 1 ใบ: 1) ถ้าแถวตรวจถูกล็อก (`Inspection::isLockedRow`) → throw 2) `UploadService::save($file,'inspections',8)` (ตรวจ MIME จริง ฯลฯ) ไม่แนบไฟล์ → return null 3) `UploadService::shrink` ย่อซ้ำฝั่งเซิร์ฟเวอร์ 4) `Inspection::addPhoto(...)` — ถ้ามุมนั้นมีรูปเดิมจะถูกเก็บเป็นประวัติ (คอลัมน์ `superseded_at`) ไม่ลบ
- **รับเข้า**: id แถว inspection, key ตำแหน่ง, element ของ `$_FILES`, คำบรรยาย (ถ้ามี)
- **คืนค่า**: path แบบสัมพัทธ์จาก uploads/ หรือ null; **throws** `\RuntimeException`
- **เรียกใช้โดย / เรียกต่อ**: `Admin/InspectionController` (line 103); เรียกต่อ `UploadService::save/shrink`, `Inspection::addPhoto`, `AuthService::id`

#### `public static progress(?array $inspection): array`
- **ทำอะไร**: นับจำนวนมุมที่ถ่ายแล้ว (ไม่นับ `damage` เพราะข้ามได้) เทียบกับ `required = 8 − 1 = 7`; `complete = done ≥ required`. ไม่มีแถว → done 0
- **รับเข้า**: แถว inspection (มี `photos`) หรือ null
- **คืนค่า**: `['done','required','complete']`
- **เรียกใช้โดย / เรียกต่อ**: `Admin/BookingController`, `Admin/InspectionController`

#### `public static photoMap(?array $inspection): array`
- **ทำอะไร**: แปลงรายการรูปเป็น map `position => แถวรูป` (ถ้าซ้ำตำแหน่ง ตัวหลังทับตัวแรก — ขึ้นกับลำดับที่ Model คืนมา)
- **รับเข้า**: แถว inspection หรือ null
- **คืนค่า**: map
- **เรียกใช้โดย / เรียกต่อ**: `Admin/BookingController`, `Admin/ContractController`, `Admin/InspectionController`, `SignController`

---

## app/Services/AuthService.php

- **หน้าที่ของไฟล์**: ล็อกอิน/ออกจากระบบหลังบ้าน (role `admin` และ `staff`) ด้วย PHP session + ป้องกัน brute force + ตรวจสิทธิ์สดจาก DB ทุก request
- **ระบบภายนอก**: PHP session, ฐานข้อมูล (`users`, `activity_logs`)
- **ใครเรียก**: `Admin/AuthController`, `AuthMiddleware`, `AdminMiddleware`, `ApiController`, helper `functions.php` (`auth_user()`, `is_admin()`), หลาย Service (`AuthService::id()`)
- **พึ่งพา**: `Flash`, `Request`, `Session`, Model `User`, `ActivityLog`; ค่าคงที่ `MAX_LOGIN_ATTEMPTS`=5, `LOGIN_LOCK_MINUTES`=15, `SESSION_LIFETIME`=7200 วินาที (จาก `config/config.php`)

### class AuthService

#### `public static attempt(string $email, string $password): bool`
- **ทำอะไร**: 1) นับความล้มเหลวล่าสุดใน 15 นาที (`recentFailures`) ถ้า ≥ 5 → flash "รออีก 15 นาที" และ return false **ก่อนตรวจรหัสผ่าน** (ล็อกจริง แม้ใส่รหัสถูกก็ไม่ผ่าน) 2) `User::findActiveByEmail` (เฉพาะบัญชีที่ active) 3) `password_verify` กับ `password_hash` — ถ้าไม่พบผู้ใช้หรือรหัสผิด บันทึก `ActivityLog 'login_failed'` (detail = อีเมล) และ return false (ผลเหมือนกันทั้งสองกรณี Controller จึงแสดงข้อความทั่วไปได้โดยไม่เปิดเผยว่าอีเมลมีอยู่) 4) สำเร็จ → ล้างประวัติ fail ของอีเมลนี้ (try/catch เงียบ) 5) `Session::regenerate()` **กัน session fixation** 6) เก็บ `$_SESSION['user']` (id, name, email, role) และ `last_activity` 7) `User::touchLogin`, log `'login'`
- **รับเข้า**: อีเมลและรหัสผ่านดิบจากฟอร์ม
- **คืนค่า**: true = ล็อกอินสำเร็จ
- **เรียกใช้โดย / เรียกต่อ**: `Admin/AuthController` (line 26); เรียกต่อ `recentFailures`, `User`, `ActivityLog`, `Session`
- **หมายเหตุ**: การนับล็อกใช้ "อีเมลเดียวกัน **หรือ** IP เดียวกัน" (ดู `ActivityLog::loginFailures`) ส่วน `clearLoginFailures` ล้างตามอีเมลเท่านั้น

#### `private static recentFailures(string $email, string $ip): int`
- **ทำอะไร**: คำนวณเวลา `now − 15 นาที` แล้วเรียก `ActivityLog::loginFailures`. ถ้าอ่านตารางไม่ได้ (exception) คืน 0 — **fail-open** เพื่อไม่ให้ล็อกผู้ใช้จนเข้าระบบไม่ได้
- **รับเข้า**: อีเมล, IP
- **คืนค่า**: จำนวนครั้งที่ล้มเหลว
- **เรียกใช้โดย / เรียกต่อ**: `attempt()`

#### `public static user(): ?array`
- **ทำอะไร**: คืน `$_SESSION['user']` หรือ null
- **รับเข้า**: ไม่มี
- **คืนค่า**: `['id','name','email','role']` หรือ null
- **เรียกใช้โดย / เรียกต่อ**: `isAdmin()`, helper `auth_user()`

#### `public static id(): ?int`
- **ทำอะไร**: id ผู้ใช้ที่ล็อกอิน (int) หรือ null — ใช้ลงใน `created_by`, `approved_by`, ล็อกประวัติ
- **รับเข้า**: ไม่มี
- **คืนค่า**: int|null
- **เรียกใช้โดย / เรียกต่อ**: `InspectionService`, `BookingActionService`, `ReturnService`, `AdminBookingService`, `SecurityDepositService`

#### `public static check(): bool`
- **ทำอะไร**: ตรวจว่าล็อกอินอยู่ไหม: 1) ไม่มี session user → false 2) ไม่ใช้งานเกิน `SESSION_LIFETIME` (2 ชม.) → `logout()` แล้ว false 3) ต่ออายุ `last_activity` 4) **อ่านสิทธิ์สดจาก DB ครั้งเดียวต่อ request** (ตัวแปร `static $checked`) ผ่าน `User::authState`: ถ้าผู้ใช้หายไปหรือ `is_active=0` → logout, false; ไม่งั้นอัปเดต role/name ใน session. **เหตุผล**: เจ้าของปิด/ลบ/ลดสิทธิ์พนักงานต้องมีผลทันที ไม่ใช่รอให้ออกจากระบบเอง. ถ้า DB อ่านไม่ได้ชั่วคราว (exception) → ถือว่าผ่าน (fail-open) ไม่เตะผู้ใช้ออกกลางงาน
- **รับเข้า**: ไม่มี (อ่านจาก session)
- **คืนค่า**: bool
- **เรียกใช้โดย / เรียกต่อ**: `AuthMiddleware`, `Admin/AuthController`, `ApiController`; เรียกต่อ `logout()`, `User::authState`

#### `public static isAdmin(): bool`
- **ทำอะไร**: `role` ใน session เท่ากับ `'admin'` (ไม่มี = false). ไม่เรียก `check()` เอง จึงควรเรียกหลังผ่าน middleware
- **รับเข้า**: ไม่มี
- **คืนค่า**: bool
- **เรียกใช้โดย / เรียกต่อ**: `AdminMiddleware`, `AdminNavService`, `BookingActionService`, helper `is_admin()`

#### `public static logout(): void`
- **ทำอะไร**: ถ้ามีผู้ใช้ใน session บันทึก log `'logout'` แล้ว `Session::destroy()`
- **รับเข้า**: ไม่มี
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `Admin/AuthController`, `check()`

---

## app/Services/AdminNavService.php

- **หน้าที่ของไฟล์**: สร้างเมนูด้านข้างของหลังบ้าน พร้อมตัวเลข badge แจ้งเตือน และซ่อนเมนูที่เป็นของเจ้าของร้านจากพนักงาน
- **ใครเรียก**: `app/Views/layouts/admin.php` (line 11)
- **พึ่งพา**: Model `Booking`, `Payment`, `Maintenance`; `AuthService::isAdmin`

### class AdminNavService

#### `public static menu(): array`
- **ทำอะไร**: 1) นับตัวเลข: จองรอยืนยัน (`Booking::countByStatus('pending')`), สลิปรอตรวจ (`Payment::pendingCount()`), งานประจำรถใกล้ครบกำหนด (`Maintenance::dueCount` ภายใน `reminder_days_before` วัน ค่าตั้งต้น 30) 2) นิยามเมนู 4 กลุ่ม (งานประจำวัน / ข้อมูลหลัก / เอกสาร & รายงาน / ระบบ) เป็น tuple `[path, key, icon, label, count, ownerOnly]` 3) ถ้าไม่ใช่ admin ข้ามรายการ `ownerOnly=true` 4) กลุ่มที่ไม่เหลือรายการไม่ถูกใส่ในผลลัพธ์. **หมายเหตุสำคัญ**: นี่เป็นเพียงการซ่อนเมนู ไม่ใช่การควบคุมสิทธิ์จริง สิทธิ์จริงอยู่ที่ `AdminMiddleware` ตอน route
- **รับเข้า**: ไม่มี
- **คืนค่า**: `array<groupName, list<['path','key','icon','label','count']>>`
- **เรียกใช้โดย / เรียกต่อ**: `layouts/admin.php`; เรียกต่อ `Booking::countByStatus`, `Payment::pendingCount`, `Maintenance::dueCount`, `AuthService::isAdmin`

---

## app/Services/UploadService.php

- **หน้าที่ของไฟล์**: อัปโหลดไฟล์เข้าโฟลเดอร์ `uploads/` (สาธารณะ — รูปรถ, สลิป, รูปตรวจสภาพ). ตรวจชนิดจริงด้วย `finfo` ไม่เชื่อนามสกุล. ไฟล์อ่อนไหว (บัตรประชาชน/ลายเซ็น) ต้องใช้ `PrivateFileService`
- **ระบบภายนอก**: ไฟล์ระบบ, PHP GD, `php.ini` (upload_max_filesize/post_max_size)
- **ใครเรียก**: `Admin/CarController`, `PaymentService` (สลิป), `InspectionService`, `PrivateFileService` (errorMessage), `HealthService`, `Router`, หลาย Controller (limit แสดงใน UI)
- **พึ่งพา**: ค่าคงที่ `UPLOAD_DIR`

### class UploadService
- **บทบาท**: ค่าคงที่ `private const ALLOWED` = jpeg→jpg, png→png, webp→webp, pdf→pdf (whitelist ชนิด MIME → นามสกุลที่ใช้เซฟ)

#### `public static limitBytes(): int`
- **ทำอะไร**: ขนาดอัปโหลดสูงสุดที่ใช้ได้จริง = ค่าน้อยกว่าระหว่าง `upload_max_filesize` กับ `post_max_size` (แปลงด้วย `iniToBytes`; กรองค่า 0 ทิ้ง) ถ้าอ่านไม่ได้ใช้ 2 MB
- **รับเข้า**: ไม่มี
- **คืนค่า**: ไบต์
- **เรียกใช้โดย / เรียกต่อ**: Controller ต่าง ๆ (แสดงขีดจำกัด), `Router` (line 98), `HealthService`

#### `private static iniToBytes(string $value): int`
- **ทำอะไร**: แปลงรูปแบบ php.ini (`8M`, `2G`, `512K`) เป็นไบต์จากตัวอักษรท้าย (ไม่มีหน่วย = ไบต์). ใช้ `(int)$value` หาตัวเลข
- **รับเข้า**: string จาก `ini_get`
- **คืนค่า**: ไบต์
- **เรียกใช้โดย / เรียกต่อ**: `limitBytes()`

#### `public static errorMessage(int $code): string`
- **ทำอะไร**: แปลงรหัส `UPLOAD_ERR_*` เป็นภาษาไทย: INI/FORM_SIZE (ใหญ่เกิน แสดงขีดจำกัดจาก ini), PARTIAL (สัญญาณหลุด), NO_TMP_DIR/CANT_WRITE (เขียนไม่ได้ ติดต่อผู้ดูแล), อื่น ๆ แสดง code
- **รับเข้า**: รหัส error
- **คืนค่า**: ข้อความไทย
- **เรียกใช้โดย / เรียกต่อ**: `save()`, `PrivateFileService::storeUpload`

#### `public static save(array $file, string $subdir, int $maxMB = 8): ?string`
- **ทำอะไร**: บันทึกไฟล์อัปโหลด: 1) ไม่มี `error` หรือ `UPLOAD_ERR_NO_FILE` → null 2) error อื่น → throw ข้อความไทย 3) `size` เกิน `maxMB` → throw 4) **ตรวจ MIME จริงด้วย `finfo(FILEINFO_MIME_TYPE)` จากเนื้อไฟล์** ไม่อยู่ใน whitelist → throw (เหตุผล: นามสกุล/ชนิดที่เบราว์เซอร์แจ้งปลอมได้ เช่นอัปโหลด .php ปลอมเป็น .jpg) 5) สร้างโฟลเดอร์ย่อย (0755) 6) **ตั้งชื่อใหม่เอง** `YYYYmmdd_HHMMSS_<hex 12 ตัวสุ่ม>.<นามสกุลจาก whitelist>` (ไม่ใช้ชื่อที่ผู้ใช้ส่งมา กัน path traversal/ทับไฟล์/นามสกุลอันตราย) 7) `is_uploaded_file` + `move_uploaded_file` (ยืนยันว่าเป็นไฟล์จาก HTTP upload จริง)
- **รับเข้า**: element ของ `$_FILES`, ชื่อโฟลเดอร์ย่อย (เช่น `cars`, `slips`, `inspections`), ขนาดสูงสุด MB
- **คืนค่า**: path สัมพัทธ์จาก uploads เช่น `slips/20261021_103000_ab12....jpg` หรือ null; **throws** `\RuntimeException`
- **เรียกใช้โดย / เรียกต่อ**: `Admin/CarController` (ปก/แกลเลอรี), `PaymentService` (line 62), `InspectionService::savePhoto`
- **ข้อสังเกต**: `$subdir` ไม่ถูกกรองอักขระ (ใช้เพียง `trim('/')`) — ผู้เรียกในโค้ดส่งค่าคงที่เท่านั้น จึงไม่เป็นปัญหาตอนนี้

#### `public static shrink(string $fullPath, int $maxSide = 1600, int $quality = 82): void`
- **ทำอะไร**: ย่อรูปฝั่งเซิร์ฟเวอร์ (กันเบราว์เซอร์ย่อไม่สำเร็จ / JS ปิด / อัปจากคอม ที่รูปดิบ 4–5 MB กินพื้นที่โฮสต์). ไม่ทำอะไรถ้าไม่มี GD, ไม่มีไฟล์, `getimagesize` อ่านไม่ได้ (เช่น PDF) หรือด้านยาวสุด ≤ `maxSide`. มิฉะนั้น `openImage` → คำนวณสัดส่วน → `imagecopyresampled` → **เขียนทับไฟล์เดิมเป็น JPEG** (`imagejpeg`) → free memory
- **รับเข้า**: path เต็มของไฟล์, ด้านยาวสูงสุด (px), คุณภาพ JPEG
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `InspectionService::savePhoto` เท่านั้น (ตาม grep)
- **ข้อสังเกต/ความไม่สอดคล้อง**: ผลเป็น JPEG เสมอ แต่ไฟล์เดิมอาจชื่อ `.png`/`.webp` (นามสกุลมาจาก `save`) ทำให้เนื้อไฟล์กับนามสกุลไม่ตรง (และสูญเสียความโปร่งใส) — ที่แสดงผลถูกต้องเพราะระบบเสิร์ฟไฟล์ดูจาก MIME ของเนื้อไฟล์

#### `private static openImage(string $path, int $type)`
- **ทำอะไร**: เปิดรูปตามชนิด (JPEG/PNG/WEBP — WEBP ต้องมี `imagecreatefromwebp`) ใช้ `@` ปิด warning คืน GD image หรือ false
- **รับเข้า**: path, ค่า `IMAGETYPE_*`
- **คืนค่า**: GD image|false
- **เรียกใช้โดย / เรียกต่อ**: `shrink()`

#### `public static dirUsage(string $dir): array`
- **ทำอะไร**: นับขนาดรวมและจำนวนไฟล์ในโฟลเดอร์แบบ recursive (`RecursiveDirectoryIterator`, SKIP_DOTS) หยุดที่ 20,000 ไฟล์กันช้า; exception → คืนเท่าที่นับได้; ไม่มีโฟลเดอร์ → `[0,0]`
- **รับเข้า**: path โฟลเดอร์
- **คืนค่า**: `[int bytes, int files]`
- **เรียกใช้โดย / เรียกต่อ**: `HealthService`, `Admin/BackupController`

---

## app/Services/PrivateFileService.php

- **หน้าที่ของไฟล์**: ที่เก็บไฟล์แบบปิด `storage/private/` สำหรับไฟล์ที่ห้ามเปิดผ่านลิงก์ตรง (สำเนาบัตรประชาชน, ใบขับขี่, ลายเซ็น). ป้องกัน 3 ชั้น: (1) `storage/` ถูก `.htaccess` ปิดทั้งโฟลเดอร์ (2) ชื่อไฟล์สุ่ม 32 hex เดาไม่ได้ (3) เปิดดูได้ผ่านหน้า PHP ที่ตรวจสิทธิ์และบันทึกประวัติเท่านั้น. **คลาสนี้ไม่มีคำสั่งลบไฟล์**
- **ระบบภายนอก**: ไฟล์ระบบ, Apache `.htaccess`
- **ใครเรียก**: `SignatureService::insertSignature` (put), `Admin/CustomerController` (storeUpload, path, legacyPath, response), `Admin/HealthController` (protectLegacyDocs, migrateLegacyDocs), `HealthService` (dir), views (dataUri)
- **พึ่งพา**: `Database` (transaction), `Response`, Model `Customer`, `UploadService::errorMessage`; `APP_ROOT`, `UPLOAD_DIR`

### class PrivateFileService

#### `public static customerDocKinds(): array`
- **ทำอะไร**: ชนิดเอกสารลูกค้า → ชื่อแสดง: id_card, driver_license, passport, other
- **รับเข้า**: ไม่มี
- **คืนค่า**: map
- **เรียกใช้โดย / เรียกต่อ**: `Admin/CustomerController`

#### `public static allowedMimes(): array`
- **ทำอะไร**: whitelist MIME → นามสกุล (jpeg, png, webp, pdf) — เหมือน `UploadService::ALLOWED`
- **รับเข้า**: ไม่มี
- **คืนค่า**: map
- **เรียกใช้โดย / เรียกต่อ**: `storeUpload`, `response`, `dataUri`, `migrateOne`

#### `public static root(): string`
- **ทำอะไร**: `APP_ROOT/storage/private`
- **รับเข้า**: ไม่มี
- **คืนค่า**: path
- **เรียกใช้โดย / เรียกต่อ**: `dir`, `path`

#### `private static denyRules(): string`
- **ทำอะไร**: ข้อความ `.htaccess` ปิดการเข้าถึงทั้งหมด รองรับ Apache 2.4 (`Require all denied`) และ 2.2 (`Order allow,deny / Deny from all`)
- **รับเข้า**: ไม่มี
- **คืนค่า**: เนื้อไฟล์ `.htaccess`
- **เรียกใช้โดย / เรียกต่อ**: `protectFolder`, `protectLegacyDocs`

#### `public static dir(string $sub): string`
- **ทำอะไร**: คืน path โฟลเดอร์ย่อยใน private (สร้างถ้ายังไม่มี, mode 0750). **ทำความสะอาด `$sub`**: ตัวพิมพ์เล็ก, เหลือเฉพาะ `a-z 0-9 _ / -` (ตัด `.` ทิ้ง จึงใส่ `..` ไม่ได้), trim `/`. สร้างไม่สำเร็จ → throw. แล้ววาง `.htaccess` + `index.html` ใน `storage/` และ `storage/private/` (`protectFolder`)
- **รับเข้า**: ชื่อโฟลเดอร์ย่อย ('' = ราก)
- **คืนค่า**: path เต็ม; throws `\RuntimeException`
- **เรียกใช้โดย / เรียกต่อ**: `put`, `storeUpload`, `migrateOne`, `HealthService::privateStorageCheck` (`dir('')`)

#### `private static protectFolder(string $folder): void`
- **ทำอะไร**: ถ้าโฟลเดอร์มีอยู่ และยังไม่มี `.htaccess` เขียนกฎปิด; ยังไม่มี `index.html` สร้างไฟล์ว่าง (กัน directory listing). ใช้ `@` ปิด warning ถ้าเขียนไม่ได้
- **รับเข้า**: path โฟลเดอร์
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `dir()`

#### `public static put(string $sub, string $bytes, string $ext): string`
- **ทำอะไร**: เขียนข้อมูลดิบลง private: ล้าง `$ext` เหลือ `a-z0-9`, โฟลเดอร์ `<sub>/YYYYmm`, ชื่อ `bin2hex(random_bytes(16)).ext`, `file_put_contents(LOCK_EX)` (ล้มเหลว → throw), `chmod 0640`
- **รับเข้า**: โฟลเดอร์ย่อย, ข้อมูลไบนารี, นามสกุล
- **คืนค่า**: path สัมพัทธ์ เช่น `signatures/202610/ab12….png`
- **เรียกใช้โดย / เรียกต่อ**: `SignatureService::insertSignature`

#### `public static storeUpload(array $file, string $sub, int $maxMB = 8): ?array`
- **ทำอะไร**: รับไฟล์อัปโหลดเข้า private (ลำดับเหมือน `UploadService::save`): ไม่แนบ → null; error code → throw; ขนาดเกิน → throw; **`finfo` ตรวจ MIME จริง** ต้องอยู่ใน whitelist; ตั้งชื่อสุ่ม + นามสกุลจาก whitelist; `move_uploaded_file`; `chmod 0640`
- **รับเข้า**: element ของ `$_FILES`, โฟลเดอร์ย่อย, ขนาดสูงสุด MB
- **คืนค่า**: `['path','mime','size']` หรือ null; **throws** `\RuntimeException`
- **เรียกใช้โดย / เรียกต่อ**: `Admin/CustomerController` (line 128)
- **ข้อสังเกต**: ต่างจาก `UploadService::save` ตรงที่ไม่เรียก `is_uploaded_file()` แยก (แต่ `move_uploaded_file` ตรวจให้อยู่แล้ว)

#### `public static path(?string $rel): ?string`
- **ทำอะไร**: แปลง path สัมพัทธ์เป็น path จริงใน private ผ่าน `insideFolder`
- **รับเข้า**: path สัมพัทธ์จากฐานข้อมูล
- **คืนค่า**: path เต็ม หรือ null ถ้าไม่มีไฟล์/พยายามออกนอกโฟลเดอร์
- **เรียกใช้โดย / เรียกต่อ**: `Admin/CustomerController`, `dataUri`

#### `public static legacyPath(?string $rel): ?string`
- **ทำอะไร**: เหมือน `path` แต่ฐานคือ `UPLOAD_DIR` — สำหรับเอกสารเก่า (ก่อนเวอร์ชัน 5) ใน `uploads/docs`
- **รับเข้า**: path สัมพัทธ์
- **คืนค่า**: path เต็ม|null
- **เรียกใช้โดย / เรียกต่อ**: `Admin/CustomerController` (line 68), `migrateOne`

#### `private static insideFolder(string $folder, ?string $rel): ?string`
- **ทำอะไร**: **ป้องกัน path traversal**: ว่างหรือมี NUL byte → null; `realpath` ของโฟลเดอร์ราก และของ `folder/rel` (realpath แก้ `../` และ symlink ให้เป็น path จริง และคืน false ถ้าไฟล์ไม่มี); ต้องเป็นไฟล์; และ path จริงต้องขึ้นต้นด้วย `root + DIRECTORY_SEPARATOR` (ใส่ตัวคั่นกันกรณีโฟลเดอร์ชื่อขึ้นต้นเหมือนกัน เช่น `private2`)
- **รับเข้า**: โฟลเดอร์ฐาน, path สัมพัทธ์
- **คืนค่า**: path เต็ม|null
- **เรียกใช้โดย / เรียกต่อ**: `path`, `legacyPath`

#### `public static response(string $fullPath, string $downloadName = 'file'): Response`
- **ทำอะไร**: สร้าง HTTP response ส่งไฟล์ (**ผู้เรียกต้องตรวจสิทธิ์มาก่อน**). ตรวจ MIME จากเนื้อไฟล์ ไม่อยู่ใน whitelist → 415. ชื่อไฟล์ดาวน์โหลดถูกล้างเหลือ `A-Za-z0-9_.-` + นามสกุลจริง. Header: `Content-Type`, `Content-Disposition: inline`, **`X-Content-Type-Options: nosniff`** (ไม่ให้เบราว์เซอร์เดาชนิดเอง กันไฟล์ปลอมเป็น HTML/script), `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`
- **รับเข้า**: path เต็ม (ที่ผ่าน `path()` แล้ว), ชื่อสำหรับดาวน์โหลด
- **คืนค่า**: `Response`
- **เรียกใช้โดย / เรียกต่อ**: `Admin/CustomerController` (lines 59, 73)

#### `public static dataUri(?string $rel): string`
- **ทำอะไร**: อ่านไฟล์เล็กใน private (≤ 600 KB) แล้วคืน `data:<mime>;base64,...` สำหรับฝังในหน้า (ใช้แสดงลายเซ็นโดยไม่ต้องมี URL ตรง). ไม่พบ/ใหญ่เกิน/MIME ไม่อยู่ใน whitelist → `''`
- **รับเข้า**: path สัมพัทธ์
- **คืนค่า**: data URI หรือ `''`
- **เรียกใช้โดย / เรียกต่อ**: views `contract.php`, `bookings/show.php`, `sign.php`

#### `public static protectLegacyDocs(): void`
- **ทำอะไร**: ถ้ามี `uploads/docs` แต่ยังไม่มี `.htaccess` วางกฎปิดการเข้าถึงทางเว็บ
- **รับเข้า**: ไม่มี
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `Admin/HealthController`, `Admin/CustomerController`

#### `public static migrateLegacyDocs(?int $userId = null): array`
- **ทำอะไร**: ย้ายเอกสารลูกค้าเดิม (คอลัมน์ `id_card_file` → ชนิด id_card, `license_file` → driver_license) จาก uploads/docs เข้า private: วนลูกค้าจาก `Customer::withLegacyFiles()` แต่ละคอลัมน์ที่ไม่ว่างเรียก `migrateOne` แล้วนับผล. เป็นการ **ย้าย (rename) ไม่ลบ**
- **รับเข้า**: id ผู้ใช้ที่กดย้าย (บันทึกเป็น `uploaded_by`)
- **คืนค่า**: `['moved','missing','failed']` (จำนวน)
- **เรียกใช้โดย / เรียกต่อ**: `Admin/HealthController` (line 50 ปุ่ม "ย้ายเข้าที่เก็บแบบปิด")

#### `private static migrateOne(array $customer, string $column, string $kind, ?int $userId): string`
- **ทำอะไร**: 1) `legacyPath` ไม่พบไฟล์จริง → `'missing'` (คงข้อมูลไว้ให้ตรวจ) 2) MIME ไม่อยู่ใน whitelist → `'failed'` 3) ปลายทาง `docs/YYYYmm/<hex สุ่ม>.<ext>` 4) `rename` ล้มเหลว → `'failed'` 5) ใน transaction: `Customer::addDocument` แล้ว `Customer::clearLegacyFile` 6) ถ้า DB ผิดพลาด → rollBack และ **rename ไฟล์กลับที่เดิม** (ไม่ทิ้งไฟล์ที่ไม่มีข้อมูลชี้ถึง) → `'failed'`
- **รับเข้า**: แถวลูกค้า, ชื่อคอลัมน์เดิม, ชนิดเอกสาร, id ผู้ใช้
- **คืนค่า**: `'moved' | 'missing' | 'failed'`
- **เรียกใช้โดย / เรียกต่อ**: `migrateLegacyDocs()`

---

## app/Services/BackupService.php

- **หน้าที่ของไฟล์**: สำรองฐานข้อมูลด้วย PHP ล้วน (ไม่ใช้ `mysqldump` เพราะโฮสต์แชร์หลายเจ้าปิด `exec()`) เป็นไฟล์ `.sql` (บีบ `.gz` ถ้ามี zlib) ใน `storage/backups/` สำรองเฉพาะฐานข้อมูล ไม่รวมรูปใน uploads
- **ระบบภายนอก**: MySQL (ผ่าน PDO), ไฟล์ระบบ, zlib
- **ใครเรียก**: `CronService::backupDatabase` (create, prune), `Admin/BackupController` (list, find, create, ดาวน์โหลด), `HealthService::cronChecks` (list)
- **พึ่งพา**: `Database::pdo()`, `set_setting()`, ค่าคงที่ `DB_NAME`, `APP_ROOT`

### class BackupService

#### `public static dir(): string`
- **ทำอะไร**: คืน `storage/backups` (สร้างถ้ายังไม่มี 0750) และวาง `.htaccess` (deny) + `index.html` ว่างไว้ที่ `storage/` ถ้ายังไม่มี
- **รับเข้า**: ไม่มี
- **คืนค่า**: path
- **เรียกใช้โดย / เรียกต่อ**: `list`, `create`

#### `private static denyRules(): string`
- **ทำอะไร**: ข้อความ `.htaccess` ปิดการเข้าถึง (เหมือน `PrivateFileService::denyRules` — โค้ดซ้ำกัน)
- **รับเข้า**: ไม่มี
- **คืนค่า**: string
- **เรียกใช้โดย / เรียกต่อ**: `dir()`

#### `public static list(): array`
- **ทำอะไร**: `glob` ไฟล์ `db_*.sql*` ในโฟลเดอร์ สร้างรายการ `name,path,size,time` แล้วเรียงใหม่สุดก่อน (`usort` ด้วย `newestFirst`)
- **รับเข้า**: ไม่มี
- **คืนค่า**: array ของ `['name','path','size','time']`
- **เรียกใช้โดย / เรียกต่อ**: `Admin/BackupController`, `HealthService`, `prune`, `find`

#### `private static newestFirst(array $a, array $b): int`
- **ทำอะไร**: ตัวเทียบ `usort` — `$b['time'] <=> $a['time']`
- **รับเข้า**: สองแถวไฟล์
- **คืนค่า**: -1/0/1
- **เรียกใช้โดย / เรียกต่อ**: `list()`

#### `public static create(): array`
- **ทำอะไร**: สร้างไฟล์สำรอง: 1) ตรวจโฟลเดอร์เขียนได้ ไม่ได้ → throw 2) เก็บกวาด `part_*.tmp` ค้างเก่ากว่า 1 ชม. 3) ตั้งชื่อ `db_YYYYmmdd_HHMMSS_<hex12>.sql[.gz]` (รหัสสุ่มกัน URL เดา) 4) **เขียนลงไฟล์ชั่วคราว `part_*.tmp` ก่อน** เสร็จสมบูรณ์จึง `rename` เป็นชื่อจริง — ถ้าพื้นที่เต็มกลางทางจะไม่มีไฟล์ครึ่งๆ กลางๆ ปนกับไฟล์ที่ใช้กู้ได้ 5) `dumpAll`, `closeHandle` (ตรวจ return เพื่อจับดิสก์เต็ม) 6) error → ปิด handle, ลบ tmp, throw ต่อ 7) `chmod 0640`, `set_setting('backup_last_at', now)`
- **รับเข้า**: ไม่มี
- **คืนค่า**: `['name','size','tables','rows']`; **throws** `\RuntimeException`
- **เรียกใช้โดย / เรียกต่อ**: `CronService::backupDatabase`, `Admin/BackupController` (line 51 ปุ่ม "สำรองตอนนี้"); เรียกต่อ `dumpAll`, `closeHandle`, `removeStaleTempFiles`

#### `private static removeStaleTempFiles(string $dir): void`
- **ทำอะไร**: ลบ `part_*.tmp` ที่เก่ากว่า 1 ชั่วโมง (ซากจากสคริปต์ที่ถูกตัดกลางทาง) — ไม่แตะไฟล์สำรองจริง
- **รับเข้า**: path โฟลเดอร์
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `create()`

#### `private static closeHandle($handle, bool $gz): void`
- **ทำอะไร**: `gzclose`/`fclose` และ throw ถ้า return ล้มเหลว (สัญญาณพื้นที่เต็ม)
- **รับเข้า**: handle, ใช้ gzip หรือไม่
- **คืนค่า**: void; throws
- **เรียกใช้โดย / เรียกต่อ**: `create()`

#### `private static write($handle, bool $gz, string $text): void`
- **ทำอะไร**: เขียนข้อความ (`gzwrite`/`fwrite`) ถ้าได้ false หรือเขียนได้น้อยกว่าความยาว → throw "เขียนไม่ครบ พื้นที่อาจเต็ม"
- **รับเข้า**: handle, gz flag, ข้อความ
- **คืนค่า**: void; throws
- **เรียกใช้โดย / เรียกต่อ**: `dumpAll`, `dumpTable`

#### `private static dumpAll($handle, bool $gz): array`
- **ทำอะไร**: เขียน header (ชื่อ DB, เวลา), `SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS = 0; SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'`; วน `SHOW FULL TABLES` เฉพาะ `BASE TABLE` (ข้าม view) เรียก `dumpTable`; ท้ายไฟล์ `FOREIGN_KEY_CHECKS = 1` พร้อมสรุปจำนวน. ปิด FK check เพื่อให้กู้ตารางตามลำดับใดก็ได้
- **รับเข้า**: handle, gz flag
- **คืนค่า**: `['tables','rows']`
- **เรียกใช้โดย / เรียกต่อ**: `create()`

#### `private static dumpTable($handle, bool $gz, string $table): int`
- **ทำอะไร**: ใส่ชื่อตารางใน backtick (escape backtick ซ้อน), `SHOW CREATE TABLE`, เขียน `DROP TABLE IF EXISTS` + CREATE, แล้ว `SELECT *` ไล่ทีละแถว (ไม่โหลดทั้งตารางเข้าหน่วยความจำ ถ้า driver ไม่ buffer) สะสมเป็น batch ละ 200 แถวต่อ `INSERT ... VALUES` หนึ่งคำสั่ง
- **รับเข้า**: handle, gz flag, ชื่อตาราง
- **คืนค่า**: จำนวนแถว
- **เรียกใช้โดย / เรียกต่อ**: `dumpAll()`; เรียกต่อ `columnList`, `valueList`, `write`
- **ข้อสังเกต**: PDO MySQL ตั้งต้นเป็น buffered query จึงอาจโหลดทั้งตารางเข้าหน่วยความจำ (ไม่พบการตั้ง unbuffered ในไฟล์นี้)

#### `private static columnList(array $names): string`
- **ทำอะไร**: คืน `` (`col1`,`col2`,...) `` โดย escape backtick
- **รับเข้า**: ชื่อคอลัมน์
- **คืนค่า**: string
- **เรียกใช้โดย / เรียกต่อ**: `dumpTable()`

#### `private static valueList(\PDO $pdo, array $row): string`
- **ทำอะไร**: คืน `('v1',NULL,...)` — `null` → `NULL`, ค่าอื่นแปลงเป็น string แล้ว `$pdo->quote()` (escape ตาม charset ของ connection). ทุกค่าถูก quote เป็น string รวมถึงตัวเลข (MySQL แปลงกลับให้ตอน import); ข้อมูล binary ไม่ได้ถูกจัดการแยก
- **รับเข้า**: PDO, แถวข้อมูล
- **คืนค่า**: string
- **เรียกใช้โดย / เรียกต่อ**: `dumpTable()`

#### `public static prune(): int`
- **ทำอะไร**: ลบไฟล์สำรองเก่า: `keep = max(7, backup_keep_days (ค่าตั้งต้น 90))` วัน. เรียงใหม่สุดก่อน **ข้าม 7 ไฟล์ล่าสุดเสมอ** (กัน cron หยุดนานแล้วลบจนหมด) ที่เหลือลบถ้าเก่ากว่ากำหนด
- **รับเข้า**: ไม่มี (อ่าน setting `backup_keep_days`)
- **คืนค่า**: จำนวนไฟล์ที่ลบ
- **เรียกใช้โดย / เรียกต่อ**: `CronService::backupDatabase`
- **ข้อสังเกต**: เลข 7 ใช้สองความหมาย (ขั้นต่ำ 7 วัน และเก็บ 7 ไฟล์ล่าสุด)

#### `public static find(string $name): ?array`
- **ทำอะไร**: หาไฟล์สำรองจากชื่อ โดยเทียบ (`hash_equals`) กับรายการไฟล์ที่ `list()` คืนเท่านั้น — **ไม่นำชื่อจากผู้ใช้ไปต่อ path โดยตรง** จึงกัน path traversal/ขอไฟล์นอกโฟลเดอร์
- **รับเข้า**: ชื่อไฟล์จาก request
- **คืนค่า**: แถวไฟล์หรือ null
- **เรียกใช้โดย / เรียกต่อ**: `Admin/BackupController` (line 33)

---

## app/Services/HealthService.php

- **หน้าที่ของไฟล์**: ชุดตรวจสุขภาพระบบ ใช้ทั้งหน้าหลังบ้าน "สุขภาพระบบ" และ endpoint `/health`. แต่ละข้อคืนแถว `[หัวข้อ, ระดับ ok|warn|fail, ผลที่พบ, คำแนะนำ]`
- **ระบบภายนอก**: ฐานข้อมูล (`information_schema`), ไฟล์ระบบ, `$_SERVER`
- **ใครเรียก**: `HealthController` (สาธารณะ), `Admin/HealthController`
- **พึ่งพา**: `Database`, Model `Customer`, `Notification`; `UploadService`, `PrivateFileService`, `BackupService`; ค่าคงที่ `APP_DEBUG`, `UPLOAD_DIR`, `APP_ROOT`

### class HealthService

#### `public static checks(): array`
- **ทำอะไร**: รวมผลตรวจ 6 กลุ่มตามลำดับ: php, database, file, cron, notification, account
- **รับเข้า**: ไม่มี
- **คืนค่า**: `array<int, array{0:string,1:string,2:string,3:string}>`
- **เรียกใช้โดย / เรียกต่อ**: `HealthController` (line 34), `Admin/HealthController` (line 29)

#### `private static row(string $title, string $level, string $found, string $advice = ''): array`
- **ทำอะไร**: ตัวช่วยประกอบแถวผลตรวจ `[title, level, found, advice]`
- **รับเข้า**: ตามชื่อพารามิเตอร์
- **คืนค่า**: array 4 ช่อง
- **เรียกใช้โดย / เรียกต่อ**: ทุกเมธอดตรวจ

#### `private static phpChecks(): array`
- **ทำอะไร**: 4 ข้อ: เวอร์ชัน PHP ≥ 8.1 (ไม่ถึง = warn); ส่วนขยายครบ `pdo_mysql, curl, gd, fileinfo, mbstring, zlib` (ขาด = fail); HTTPS (ไม่ใช้ = fail เพราะ LINE และข้อมูลลูกค้าต้องใช้); `APP_DEBUG` ต้องปิด (เปิด = fail เพราะผู้ใช้เห็นรายละเอียดภายใน)
- **รับเข้า**: ไม่มี
- **คืนค่า**: list ของแถว
- **เรียกใช้โดย / เรียกต่อ**: `checks()`

#### `private static usesHttps(): bool`
- **ทำอะไร**: true ถ้า `$_SERVER['HTTPS']` ไม่ว่างและไม่ใช่ `off`, หรือ `HTTP_X_FORWARDED_PROTO === 'https'` (หลัง proxy/Cloudflare), หรือ setting `site_url` ขึ้นต้น `https://`
- **รับเข้า**: ไม่มี
- **คืนค่า**: bool
- **เรียกใช้โดย / เรียกต่อ**: `phpChecks()`
- **ข้อสังเกต**: ข้อสุดท้ายหมายความว่าถ้าตั้ง site_url เป็น https แต่ผู้เข้าเว็บจริงใช้ http ก็ถือว่าผ่าน

#### `private static databaseChecks(): array`
- **ทำอะไร**: query ขนาด DB (`data_length + index_length` จาก information_schema) ถ้าสำเร็จคืนแถว "ฐานข้อมูล ok" + `schemaCheck()`; exception ใด ๆ → fail "เชื่อมต่อไม่ได้ ตรวจ config/config.php"
- **รับเข้า**: ไม่มี
- **คืนค่า**: list ของแถว
- **เรียกใช้โดย / เรียกต่อ**: `checks()`

#### `private static schemaCheck(): array`
- **ทำอะไร**: ตรวจว่ามีตารางตาม `requiredTables()` และคอลัมน์ตาม `requiredColumns()` ใน `information_schema` (ใช้ prepared params `:t`, `:c`) รวมรายการที่ขาด; ไม่ขาด = ok; ขาด = fail พร้อมคำเตือนว่า `schema.sql` **ลบตารางเดิมทั้งหมด** ห้ามรันถ้ามีข้อมูลจริง
- **รับเข้า**: ไม่มี
- **คืนค่า**: แถวผลตรวจ 1 แถว
- **เรียกใช้โดย / เรียกต่อ**: `databaseChecks()`

#### `private static requiredTables(): array`
- **ทำอะไร**: รายชื่อ: `car_types, sequences, signatures, inspections, inspection_photos, customer_documents, line_events, ab_events, v_car_compliance` (อันสุดท้ายเป็น view แต่ `information_schema.TABLES` รวม view อยู่แล้วจึงตรวจได้)
- **รับเข้า**: ไม่มี
- **คืนค่า**: string[]
- **เรียกใช้โดย / เรียกต่อ**: `schemaCheck()`

#### `private static requiredColumns(): array`
- **ทำอะไร**: คู่ `[ตาราง, คอลัมน์]`: `bookings.sign_token`, `bookings.line_user_id`, `bookings.delivery_km`, `bookings.extra_breakdown`, `notifications.tpl_key`, `car_blocks.created_by`, `cars.car_type`, `inspection_photos.superseded_at`
- **รับเข้า**: ไม่มี
- **คืนค่า**: list ของคู่
- **เรียกใช้โดย / เรียกต่อ**: `schemaCheck()`

#### `private static fileChecks(): array`
- **ทำอะไร**: รวม 5 ข้อ: โฟลเดอร์ uploads เขียนได้ + ขนาด/จำนวนไฟล์ (`dirUsage`); ที่เก็บแบบปิด (`privateStorageCheck`); เอกสารเก่า (`legacyDocsCheck` ถ้าไม่ null); พื้นที่ว่าง (`diskSpaceCheck` ถ้าไม่ null); ขนาดอัปโหลดสูงสุด (`limitBytes` < 2 MB = warn)
- **รับเข้า**: ไม่มี
- **คืนค่า**: list ของแถว
- **เรียกใช้โดย / เรียกต่อ**: `checks()`

#### `private static privateStorageCheck(): array`
- **ทำอะไร**: เรียก `PrivateFileService::dir('')` (สร้างโฟลเดอร์ให้ด้วย) ตรวจ `is_writable` + `dirUsage`; exception → fail "เขียนไฟล์ไม่ได้ ตั้งสิทธิ์ storage เป็น 755" (ถ้าเขียนไม่ได้ ลูกค้าจะเซ็นสัญญา/แนบเอกสารไม่ได้)
- **รับเข้า**: ไม่มี
- **คืนค่า**: แถวผลตรวจ
- **เรียกใช้โดย / เรียกต่อ**: `fileChecks()`

#### `private static legacyDocsCheck(): ?array`
- **ทำอะไร**: `Customer::legacyDocCount()` — มากกว่า 0 = warn ให้กดย้ายเข้าที่เก็บแบบปิด. exception (DB ยังไม่อัปเดต) → null (ข้ามข้อนี้)
- **รับเข้า**: ไม่มี
- **คืนค่า**: แถวผลตรวจ หรือ null
- **เรียกใช้โดย / เรียกต่อ**: `fileChecks()`

#### `private static diskSpaceCheck(): ?array`
- **ทำอะไร**: `disk_free_space(APP_ROOT)` — < 100 MB = fail, < 500 MB = warn, อ่านไม่ได้ = null
- **รับเข้า**: ไม่มี
- **คืนค่า**: แถวผลตรวจ หรือ null
- **เรียกใช้โดย / เรียกต่อ**: `fileChecks()`

#### `private static cronChecks(): array`
- **ทำอะไร**: 3 ข้อ: (1) cron ล่าสุดจาก `cron_last_at` — ไม่เคย หรือเกิน 36 ชม. = fail (2) `cron_key` ยังเป็นค่าตั้งต้น `CHANGE-THIS-CRON-KEY` = fail (3) สำรองฐานข้อมูลจาก `backup_last_at` — ไม่เคย = fail, เกิน 36 ชม. = warn, แสดงจำนวนไฟล์สำรอง (`BackupService::list`)
- **รับเข้า**: ไม่มี
- **คืนค่า**: list ของแถว
- **เรียกใช้โดย / เรียกต่อ**: `checks()`

#### `private static notificationChecks(): array`
- **ทำอะไร**: 2 ข้อ: การส่งแจ้งเตือน 7 วัน (`Notification::failedLast7Days` + `stuckQueued` — ค้างคิว >1 ชม.) มีปัญหา = warn; การเชื่อมต่อ LINE ตั้งครบ (token, secret, liff id, `notify_line_enabled='1'`) ไม่ครบ = warn
- **รับเข้า**: ไม่มี
- **คืนค่า**: list ของแถว
- **เรียกใช้โดย / เรียกต่อ**: `checks()`

#### `private static accountChecks(): array`
- **ทำอะไร**: 2 ข้อ: จำนวนแอดมิน active (0 = fail; 1 = ok แต่เตือนให้มีสำรอง) และรถเลยกำหนดคืน (`status='picked_up' AND return_at < NOW()` > 0 = warn). ข้อความ "คันเหล่านี้ถูกกันไม่ให้จองต่อ" เป็นพฤติกรรมของส่วนอื่น ไม่ได้ทำในไฟล์นี้
- **รับเข้า**: ไม่มี
- **คืนค่า**: list ของแถว
- **เรียกใช้โดย / เรียกต่อ**: `checks()`

---

## app/Services/CronService.php

- **หน้าที่ของไฟล์**: งานอัตโนมัติรายวัน ตั้งใน cPanel Cron Jobs (แนะนำวันละครั้ง 08:00). เรียกผ่าน `cron/daily.php` ซึ่งตรวจ `cron_key` (`hash_equals`; ถ้าเรียกผ่านเว็บและยังใช้คีย์ตั้งต้นจะปฏิเสธ 403) แล้วเรียก `CronService::run()` และพิมพ์บรรทัดสรุป
- **ระบบภายนอก**: LINE/Email (ผ่านคิว), ไฟล์สำรอง
- **ใครเรียก**: `cron/daily.php` (บรรทัด 33) เท่านั้น
- **พึ่งพา**: Model `ActivityLog`, `Booking`, `LineEvent`, `Maintenance`, `Notification`; `NotificationService`, `LineService`, `BackupService`, `LateReturnService`; helper `thai_date`, `money0`, `human_bytes`, `site_url`

### class CronService
- **บทบาท**: ตัวควบคุมลำดับงาน. แต่ละงานเป็น private method รับ `array &$lines` (อ้างอิง) เพื่อต่อบรรทัดสรุปผล

**ลำดับการทำงานจริงใน `run()`** (หมายเลขตามคอมเมนต์ในโค้ด; ตารางเรียงตามลำดับที่รันจริง)

| ลำดับรัน | งาน | เมธอด | กำหนดการ/เงื่อนไข | ผลข้างเคียง |
|---|---|---|---|---|
| 1 | งานประจำรถใกล้ครบกำหนด | `remindMaintenance` | ทุกครั้งที่ cron รัน, ภายใน `reminder_days_before` วัน (ค่าตั้งต้น 30) | คิว LINE+อีเมลหาแอดมิน, `Maintenance::markNotified` |
| 2 | สรุปงานส่งมอบ/รับคืนวันนี้ | `sendTodayJobs` | ทุกครั้ง ถ้ามีงานวันนี้ | คิว LINE+อีเมลหาแอดมิน |
| 2.5 | ยกเลิกจองที่ไม่จ่ายมัดจำ | `cancelUnpaidBookings` | **ปิดเป็นค่าตั้งต้น** (`pending_expire_hours=0`) | ยกเลิกจอง + log + คิว LINE แอดมิน |
| 3 | จองค้างยืนยัน >24 ชม. | `warnStalePending` | ทุกครั้ง | คิว LINE แอดมิน |
| 4 | รถเลยกำหนดคืน | `warnOverdue` | ทุกครั้ง | คิว LINE+อีเมลหาแอดมิน |
| (ไม่มีเลข) | retry ข้อความที่ส่งไม่สำเร็จ | inline ใน `run()` | ข้อความที่สร้างภายใน 20 ชม. ล่าสุด | `Notification::retryRecentFailures` ตั้งกลับเป็น queued |
| 5 | เตือนลูกค้า 1 วันก่อน | `remindCustomers` | ทุกครั้ง; ส่งครั้งเดียวต่อการจอง | คิว LINE ลูกค้า |
| 6 | สำรองฐานข้อมูล | `backupDatabase` (+ `noticeMonthlyBackup`) | วันละครั้ง (ข้ามถ้าสำรองไปแล้วใน 20 ชม.) | ไฟล์ `.sql.gz`, ลบไฟล์เก่า, แจ้งเดือนละครั้ง |
| 7 | ส่งคิว + เก็บกวาด | `flushAndClean` | ทุกครั้ง | ส่งข้อความจริงสูงสุด 50, ลบ `line_events` เก่ากว่า 30 วัน, บันทึก `cron_last_at` |

สุดท้าย `run()` บันทึก `ActivityLog 'cron_daily'` (detail = บรรทัดสรุปคั่นด้วย ` | `)

#### `public static run(): array`
- **ทำอะไร**: ตั้ง `$today`, `$lines = []` เรียกงานตามตารางด้านบน. **ลำดับ retry สำคัญ**: ต้อง retry ข้อความที่ค้างก่อน `remindCustomers` เพราะถ้าทำทีหลัง ข้อความเตือนลูกค้าที่เคยส่งไม่ผ่านจะถูกสร้างใหม่ แล้วถูกปลุกซ้ำจาก retry = ลูกค้าได้สองข้อความ (ตามคอมเมนต์). retry ใช้เกณฑ์ `created_at ≥ now − 20 ชม.` และ Model ยกเว้น error ที่ขึ้นต้น "ปิดการใช้งาน"/"ไม่มี"/"กำลังส่ง"
- **รับเข้า**: ไม่มี
- **คืนค่า**: `string[]` บรรทัดสรุป เช่น `maintenance reminders: 2`, `backup: 1.2 MB, 3400 rows`, `notifications sent: 5`
- **เรียกใช้โดย / เรียกต่อ**: `cron/daily.php`; เรียกต่อ private methods ทั้งหมดด้านล่าง

#### `private static remindMaintenance(string $today, array &$lines): void`
- **ทำอะไร**: (งาน 1) `Maintenance::needingReminder(วันนี้+N วัน, วันนี้)` — ไม่มีรายการ → ออก. สร้างข้อความ "งานประจำรถ" แต่ละรายการแสดงชื่อ ทะเบียน "อีก X วัน" หรือ "เลยกำหนด X วัน" + วันที่ไทย และเรียก `Maintenance::markNotified(id)` กันแจ้งซ้ำ (เรียกก่อนที่ข้อความจะเข้าคิว). เข้าคิว LINE + อีเมล (ref `maintenances`)
- **รับเข้า**: วันนี้ `Y-m-d`, อ้างอิง `$lines`
- **คืนค่า**: void; เพิ่ม `maintenance reminders: N`
- **เรียกใช้โดย / เรียกต่อ**: `run()`

#### `private static sendTodayJobs(string $today, array &$lines): void`
- **ทำอะไร**: (งาน 2) `Booking::pickupsOn` / `returnsOn` วันนี้; ไม่มีเลย → ออก. สร้างข้อความ "งานวันนี้" แยกหมวดส่งมอบ/รับคืน บรรทัดละ `• HH:mm น. ยี่ห้อรุ่น (ทะเบียน) — ชื่อ เบอร์` (`jobLines`) เข้าคิว LINE+อีเมลแอดมิน
- **รับเข้า**: วันนี้, `$lines`
- **คืนค่า**: void; เพิ่ม `today jobs: N`
- **เรียกใช้โดย / เรียกต่อ**: `run()`; เรียกต่อ `jobLines`

#### `private static jobLines(array $bookings, string $timeColumn): string`
- **ทำอะไร**: ต่อบรรทัดรายการงานด้วย `sprintf` ใช้คอลัมน์เวลา `pickup_at` หรือ `return_at` ตามที่ส่งมา ต้องมี `brand, model, plate, contact_name, contact_phone` ในแถว (จาก query ที่ join รถ)
- **รับเข้า**: รายการการจอง, ชื่อคอลัมน์เวลา
- **คืนค่า**: ข้อความหลายบรรทัด
- **เรียกใช้โดย / เรียกต่อ**: `sendTodayJobs()`

#### `private static cancelUnpaidBookings(array &$lines): void`
- **ทำอะไร**: (งาน 2.5) ถ้า `pending_expire_hours` ≤ 0 (ค่าตั้งต้น) → ไม่ทำอะไร. ไม่งั้น `Booking::unpaidExpired(now − N ชม.)` — เงื่อนไขใน Model: สถานะ `pending`, `source='web'`, `paid_amount <= 0`, สร้างก่อนเวลาที่กำหนด และไม่มี payment สถานะ pending/approved. วนเรียก `Booking::cancelPending(id, เหตุผลอัตโนมัติ)` + `ActivityLog 'booking_auto_cancelled'`. คิว LINE แอดมินสรุป (แสดงเลขจองสูงสุด 15). **เหตุผล**: การจองที่ไม่จ่ายกันรถไว้ ถ้าไม่มีใครยกเลิกรถคันนั้นจะขายไม่ได้
- **รับเข้า**: `$lines`
- **คืนค่า**: void; เพิ่ม `auto-cancelled unpaid: N`
- **เรียกใช้โดย / เรียกต่อ**: `run()`
- **ข้อสังเกต**: ไม่แจ้งลูกค้าที่ถูกยกเลิก (ไม่มีโค้ดส่งข้อความหาลูกค้าในเมธอดนี้)

#### `private static warnStalePending(array &$lines): void`
- **ทำอะไร**: (งาน 3) `Booking::stalePending()` (ตามชื่อ/คอมเมนต์: ค้างยืนยันเกิน 24 ชม.) → ข้อความรายการเลขจอง ชื่อ เบอร์ วันรับ → คิว LINE แอดมิน (ไม่ส่งอีเมล)
- **รับเข้า**: `$lines`
- **คืนค่า**: void; เพิ่ม `stale pending: N`
- **เรียกใช้โดย / เรียกต่อ**: `run()`

#### `private static warnOverdue(array &$lines): void`
- **ทำอะไร**: (งาน 4) `Booking::overdue()` → ต่อรายการคำนวณชั่วโมงที่เลยมา และ **ค่าปรับโดยประมาณ** ด้วย `LateReturnService::calculate($b, ['price_day'=>...], now, null, null)` (ใช้ `['total']`) → คิว LINE+อีเมลแอดมิน
- **รับเข้า**: `$lines`
- **คืนค่า**: void; เพิ่ม `overdue: N`
- **เรียกใช้โดย / เรียกต่อ**: `run()`; เรียกต่อ `LateReturnService::calculate` (ซิกเนเจอร์ไม่ได้ตรวจ — อยู่นอกขอบเขตไฟล์ที่ได้รับมอบหมาย)

#### `private static remindCustomers(string $today, array &$lines): void`
- **ทำอะไร**: (งาน 5) ช่วงวันที่ = วันนี้ถึงพรุ่งนี้ (เพื่อให้การเช่า 1 วันที่จองกระชั้นชิดยังได้ข้อความ). `Booking::confirmedPickupBetween` → `LineService::notifyCustomer('pickup_reminder', ..., once=true)`; `Booking::pickedUpReturnBetween` → `'return_reminder'` once=true. เฉพาะลูกค้าที่ผูก LINE; `$once` ใช้ `Notification::alreadySent` กันซ้ำ. นับเฉพาะที่เข้าคิว
- **รับเข้า**: วันนี้, `$lines`
- **คืนค่า**: void; เพิ่ม `customer reminders: N` (ถ้า > 0)
- **เรียกใช้โดย / เรียกต่อ**: `run()`; ข้อความที่เข้าคิวถูกส่งจริงใน `flushAndClean` (งาน 7)

#### `private static backupDatabase(array &$lines): void`
- **ทำอะไร**: (งาน 6) ใน try/catch: ถ้า `backup_last_at` อยู่ใน 20 ชม. → ข้าม (กัน cron ถี่สำรองซ้ำ); ไม่งั้น `BackupService::create()`, `BackupService::prune()`, ต่อบรรทัดสรุป (ขนาด แถว จำนวนที่ลบ), `noticeMonthlyBackup`. ล้มเหลว → ต่อบรรทัด `backup FAILED: ...` และคิว LINE+อีเมลแจ้งเจ้าของ
- **รับเข้า**: `$lines`
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `run()`; เรียกต่อ `BackupService`, `noticeMonthlyBackup`

#### `private static noticeMonthlyBackup(array $backup): void`
- **ทำอะไร**: ถ้า setting `backup_notice_month` ยังไม่ใช่เดือนปัจจุบัน (`Y-m`) → คิว LINE+อีเมลบอกเจ้าของให้เข้าหลังบ้าน (ลิงก์ `admin/backups`) ดาวน์โหลดสำเนาไปเก็บนอกเซิร์ฟเวอร์ แล้วบันทึกเดือนไว้. **ไม่แนบไฟล์ไปกับอีเมล** เพราะมีข้อมูลส่วนบุคคลของลูกค้า
- **รับเข้า**: ผลจาก `BackupService::create()` (`size`, `rows`)
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `backupDatabase()`

#### `private static flushAndClean(array &$lines): void`
- **ทำอะไร**: (งาน 7) `NotificationService::flush(50)` ส่งข้อความที่ค้างจริง (ต่อบรรทัด `notifications sent: N`); `LineEvent::purgeOld()` ลบ `line_events` ที่เก่ากว่า 30 วัน ใน try/catch เงียบ (DB ยังไม่อัปเดต); `set_setting('cron_last_at', now)` ซึ่ง `HealthService` ใช้ตรวจว่า cron ยังทำงาน
- **รับเข้า**: `$lines`
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `run()`
- **ข้อสังเกต**: `cron_last_at` ถูกบันทึกท้ายสุด ถ้างานก่อนหน้า throw exception ที่ไม่ถูกดักจับ (นอก backup/purge) ค่านี้จะไม่ถูกอัปเดต

---

## app/Services/MonthlyReportService.php

- **หน้าที่ของไฟล์**: รวบรวมตัวเลขรายงานผลประกอบการรายเดือน เทียบเดือนก่อน และสร้าง "ข้อสังเกต" อัตโนมัติจากตัวเลขล้วน ๆ (ไม่เดา)
- **ใครเรียก**: `Admin/MonthlyReportController` (`build`), view `admin/monthly_report.php`
- **พึ่งพา**: Model `Booking`, `Car`, `Inspection`, `Maintenance`, `Notification`, `Payment`, `Report`; ค่าคงที่ `TH_MONTHS_FULL`; `money0`

### class MonthlyReportService

#### `public static metrics(string $from, string $to, int $dim): array`
- **ทำอะไร**: ตัวเลขหลักของช่วงเวลาเดียว (ใช้กับเดือนนี้และเดือนก่อนด้วยสูตรเดียวกัน): จำนวนรถ active, วันที่ขายได้รายคัน (`Report::daysSoldByCar`) และรวม, **อัตราการใช้งาน (util) = วันขายรวม ÷ (จำนวนรถ × จำนวนวันในเดือน) × 100**, รายได้ที่เก็บได้ (`Payment::revenueCollected`), การจองใหม่/ยกเลิก (`Report::bookingCreatedCounts`) และอัตรายกเลิก, สรุปงาน (`jobs`, `booked` = มูลค่า, `extra` = ค่าใช้จ่ายเพิ่ม), ticket เฉลี่ยต่องาน. มีการป้องกันหารศูนย์ทุกจุด
- **รับเข้า**: วันแรก `Y-m-d`, วันสุดท้าย, จำนวนวันในเดือน
- **คืนค่า**: array คีย์: `cars, days_map, sold, util, collected, new, cancelled, cancel_rt, jobs, booked, extra, ticket`
- **เรียกใช้โดย / เรียกต่อ**: `build()`

#### `public static build(string $m): array`
- **ทำอะไร**: ประกอบข้อมูลทั้งหมดเดือน `$m` (`YYYY-MM`): คำนวณช่วงวันที่ เดือนก่อน/เดือนถัดไป ชื่อเดือนไทย + ปี พ.ศ. (`+543`); `metrics` เดือนนี้และเดือนก่อน; ตารางรายคัน (`carRows`) พร้อมค่าซ่อม (`Maintenance::costByCar`), best/worst; แหล่งที่มาการจอง (`bySourceCreated`); ลูกค้าใหม่ vs กลับมา (`max(0, all − new)`); สถิติคืนรถ (`returnStats` → คืนแล้ว/ช้า), เวลาอนุมัติสลิปเฉลี่ย, ค่าซ่อมรวม; ใน try/catch (ฐานข้อมูลเก่ากว่าเวอร์ชัน 4 ให้ข้ามส่วนนี้): จำนวนบัญชี LINE ที่ผูก, ข้อความ LINE ที่ส่ง, สถิติการล็อกผลตรวจ; แล้ว `buildNotes`; คืนทุกอย่างด้วย `compact()`
- **รับเข้า**: เดือนรูปแบบ `YYYY-MM` (ไม่ validate ในนี้ ผู้เรียกต้องตรวจ)
- **คืนค่า**: array ใหญ่ (คีย์เด่น: `m, from, to, dim, pm, nm, title, cur, prev, carRows, best, worst, bySource, srcTotal, custNew, custBack, late, returned, slipHrs, maintCost, lineLinked, lineSent, inspLocked, inspTotal, notes`)
- **เรียกใช้โดย / เรียกต่อ**: `Admin/MonthlyReportController` (line 20); เรียกต่อ `metrics`, `carRows`, `webShare`, `buildNotes`

#### `private static carRows(string $from, string $to, int $dim, array $daysMap, array $costMap): array`
- **ทำอะไร**: ขยายแถวรายคันจาก `Report::monthlyPerCar` เติม `days`, `util = days/dim*100`, `cost`; เรียงจากใช้งานมากไปน้อย (`usort` + `mostUsedFirst`)
- **รับเข้า**: ช่วงวัน, จำนวนวัน, map รถ→วันขาย, map รถ→ค่าซ่อม
- **คืนค่า**: list ของแถวรถ
- **เรียกใช้โดย / เรียกต่อ**: `build()`

#### `private static mostUsedFirst(array $a, array $b): int`
- **ทำอะไร**: ตัวเทียบ `usort`: `$b['util'] <=> $a['util']`
- **รับเข้า**: สองแถว
- **คืนค่า**: -1/0/1
- **เรียกใช้โดย / เรียกต่อ**: `carRows()`

#### `private static webShare(array $bySource, $total): float`
- **ทำอะไร**: หาแถว `source === 'web'` ใน `bySource` คืน `n/total*100`; ไม่มี หรือ `$total` เป็น 0 → 0
- **รับเข้า**: แหล่งที่มา, ยอดรวม
- **คืนค่า**: เปอร์เซ็นต์
- **เรียกใช้โดย / เรียกต่อ**: `build()`

#### `private static buildNotes(array $d): array`
- **ทำอะไร**: ข้อสังเกตอัตโนมัติ (`[ระดับ ok|warn|muted, ข้อความ]`): ถ้าไม่มีทั้งงานและการจองใหม่ → ข้อความ muted เดียว. มิฉะนั้นรวม: รถใช้งานสูง (`busyCarNotes`), รถใช้งานต่ำ (`idleCarNotes`); ยกเลิกสูง (จองใหม่ ≥ 5 และอัตรา ≥ 20%); สัดส่วนจองผ่านเว็บ (เมื่อมีที่มา ≥ 5; ok ถ้า ≥ 50%, แนะนำถ้า < 30%); คืนรถช้า >1 ชม.; เวลาตรวจสลิปเฉลี่ย > 6 ชม.; วันรับรถที่คนมากที่สุด (≥ 3 ครั้ง); ผลตรวจสภาพรถที่ยังไม่ล็อก. ถ้าไม่มีเลย → ok "ไม่พบจุดที่ต้องจับตา"
- **รับเข้า**: array ย่อยจาก `build()` (`cur, carRows, srcTotal, webShare, returned, late, slipHrs, busyDay, inspTotal, inspLocked`)
- **คืนค่า**: list ของ `[level, text]`
- **เรียกใช้โดย / เรียกต่อ**: `build()`
- **ข้อสังเกต**: `$dayNames` ใช้ดัชนีเริ่มที่ 1 = อาทิตย์ (ตรงกับ MySQL `DAYOFWEEK`); นิยาม "คืนรถช้า" อยู่ที่ `Report::returnStats` (ไม่ได้ตรวจในไฟล์นี้)

#### `private static busyCarNotes(array $carRows): array`
- **ทำอะไร**: ทุกคันที่ util ≥ 75% → `ok` แนะนำปรับราคาขึ้นหรือเพิ่มรถประเภทเดียวกัน
- **รับเข้า**: แถวรถ
- **คืนค่า**: list ของ note
- **เรียกใช้โดย / เรียกต่อ**: `buildNotes()`

#### `private static idleCarNotes(array $carRows): array`
- **ทำอะไร**: รถที่ util < 20%: ถ้า 1–2 คัน ทำข้อความรายคัน (พร้อมจำนวนวัน) แนะนำโปรโมชัน; ถ้ามากกว่า 2 คัน รวมเป็นข้อความเดียว (กันรายงานยาวซ้ำ ๆ) แนะนำหาลูกค้าเพิ่มก่อนปรับราคา
- **รับเข้า**: แถวรถ
- **คืนค่า**: list ของ note
- **เรียกใช้โดย / เรียกต่อ**: `buildNotes()`

---

## app/Services/AbTrackService.php

- **หน้าที่ของไฟล์**: เก็บสถิติเทียบ UX สองเวอร์ชัน (A = ดีไซน์เดิม, B = ดีไซน์ใหม่) ที่ 5 จุดของกรวยการจอง: `view_home → view_car → start_form → submit → booked` ไม่เก็บข้อมูลส่วนบุคคล ใช้ visitor id สุ่มต่อ session. แต่ละเวอร์ชันของเว็บเขียนลงตาราง `ab_events` ของฐานข้อมูลตัวเอง แล้วหน้า "เทียบผล UX" ดึงมารวมกัน
- **ใครเรียก**: `HomeController` (`view_home`), `CarController` (`view_car`), `BookingController` (`submit`, `booked`), `ApiController` (ขั้นอื่นเช่น `start_form` ผ่าน `$step` ที่ client ส่ง), view `admin/ab_report.php` (`stepLabel`)
- **พึ่งพา**: Model `AbEvent`; ค่าคงที่ `SITE_VARIANT` (ใน `config/config.php` ตั้งเป็น `'A'`), `$_SESSION`, `$_SERVER['HTTP_USER_AGENT']`

### class AbTrackService

#### `public static visitor(): string`
- **ทำอะไร**: คืน id ผู้เยี่ยมชมแบบไม่ระบุตัวตน `$_SESSION['ab_vid']`; ไม่มีก็สร้างใหม่ `bin2hex(random_bytes(8))` (16 อักขระ) — สุ่ม ไม่ผูกกับตัวบุคคล และอยู่ได้เท่าอายุ session
- **รับเข้า**: ไม่มี
- **คืนค่า**: string
- **เรียกใช้โดย / เรียกต่อ**: `track()`

#### `public static track(string $step, ?int $carId = null): void`
- **ทำอะไร**: บันทึก 1 เหตุการณ์ — ไม่ทำอะไรถ้าไม่ได้ `define('SITE_VARIANT')`; จัดประเภทอุปกรณ์จาก User-Agent (regex `Mobile|Android|iPhone|iPad` → `mobile` ไม่งั้น `desktop`); `AbEvent::insert([variant, step, visitor_id, car_id, device])`; **จับทุก `Throwable` แล้วเงียบ** เพราะสถิติพังต้องไม่ทำให้ลูกค้าจองไม่ได้
- **รับเข้า**: ชื่อขั้นตอน, id รถ (ถ้ามี)
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `trackOnce()`, `BookingController` (line 77 `booked`)
- **ข้อสังเกต**: `$step` ไม่ถูก validate ในเมธอดนี้ (ผ่าน `ApiController` ค่ามาจาก client — การจำกัดค่าต้องทำที่ผู้เรียก ไม่ได้ตรวจในไฟล์นี้)

#### `public static trackOnce(string $step, ?int $carId = null): void`
- **ทำอะไร**: กันนับซ้ำ 1 คน 1 ขั้น ต่อ session โดยใช้ key `ab_done_<step>[_<carId>]` ใน session; ถ้ามีแล้วข้าม ไม่งั้นตั้งค่าแล้วเรียก `track`. (ตั้ง flag ก่อน track — ถ้า track ล้มเหลวก็จะไม่ลองใหม่)
- **รับเข้า**: ชื่อขั้นตอน, id รถ
- **คืนค่า**: void
- **เรียกใช้โดย / เรียกต่อ**: `HomeController`, `CarController`, `BookingController` (line 19), `ApiController` (line 94)

#### `public static stepLabel(string $step): string`
- **ทำอะไร**: แปลง key ขั้นตอนเป็นชื่อไทย (เข้าหน้าแรก / เปิดดูรถ / เริ่มกรอกฟอร์ม / กดยืนยัน / จองสำเร็จ) key ที่ไม่รู้จักคืนค่าเดิม
- **รับเข้า**: key ขั้นตอน
- **คืนค่า**: ชื่อไทย
- **เรียกใช้โดย / เรียกต่อ**: view `admin/ab_report.php`

---

## ข้อสังเกตเพิ่มเติมสำหรับผู้พัฒนา (suspected bugs / inconsistencies)

1. `UploadService::shrink` เขียน JPEG ทับไฟล์เสมอ แม้ไฟล์ชื่อ `.png`/`.webp` (เนื้อไฟล์กับนามสกุลไม่ตรง, สูญเสียความโปร่งใส) — ปัจจุบันเรียกจาก `InspectionService::savePhoto` เท่านั้น
2. `NotificationService::bookingConfirmed` คำนวณ "ยอดคงเหลือ" เป็น `grand_total − paid_amount` ไม่รวม `extra_charge` ขณะที่ `LineService::bookingVars` (`{คงเหลือ}`) รวม `extra_charge` — ตัวเลขอาจต่างกันถ้ามีค่าใช้จ่ายเพิ่มก่อนยืนยัน
3. `LineService::ready()` และ `InspectionService::both()` ไม่มีผู้เรียกในโปรเจกต์ (grep ใน `app/`, `cron/`) — อาจเป็นโค้ดที่ไม่ได้ใช้
4. คำอธิบาย template `booking_received` ว่า "จองจากในแอป LINE" แต่ผู้เรียกจริงคือ `NotificationService::newBooking` (หลังจองผ่าน `WebBookingService`) — ส่งได้เมื่อการจองมี LINE user ผูกอยู่เท่านั้น ซึ่งตอนสร้างการจองใหม่ผ่านเว็บมักยังไม่ผูก
5. `CronService::remindMaintenance` เรียก `Maintenance::markNotified` ก่อนเข้าคิวข้อความ; ถ้าเข้าคิวล้มเหลว รายการจะถูกมาร์กว่าแจ้งแล้วโดยไม่ได้แจ้งจริง
6. ล็อกอิน: ตรวจ brute force ด้วย "อีเมลเดียวกัน หรือ IP เดียวกัน" แต่ `clearLoginFailures` ล้างตามอีเมลเท่านั้น; ผู้ใช้หลายคนหลัง NAT เดียวกันอาจถูกล็อกร่วมกัน
7. `AuthService::recentFailures` และ `check()` เป็น fail-open เมื่อ DB อ่านไม่ได้ (ตั้งใจ แต่ตอน DB มีปัญหาการป้องกัน brute force/การเพิกถอนสิทธิ์จะไม่ทำงานชั่วคราว)
8. `BackupService::prune` ใช้เลข 7 สองความหมาย (ขั้นต่ำ 7 วัน และเก็บ 7 ไฟล์ล่าสุด) และ `denyRules()` ซ้ำกับ `PrivateFileService::denyRules()`
9. `NotificationService::sendWithSmtp` ไม่ตรวจ CR/LF ใน `$to` ก่อนใส่คำสั่ง SMTP และไม่ตรวจรหัสตอบ `MAIL FROM`/`DATA`
10. `CronService::cancelUnpaidBookings` ยกเลิกการจองโดยไม่แจ้งลูกค้า (แจ้งเฉพาะแอดมิน)
