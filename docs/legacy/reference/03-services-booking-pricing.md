# 03 – Services: การจอง ราคา และการเงิน (app/Services)

เอกสารนี้อธิบายทุกคลาสและทุกเมธอด (รวม private) ของ Service 12 ไฟล์ที่เกี่ยวกับ "การจองรถ → ราคา → การเงิน"
เหมาะสำหรับ junior developer ที่เพิ่งเริ่มกับ PHP MVC และธุรกิจรถเช่า

> แนวคิด MVC ในโปรเจกต์นี้: **Controller** รับ request แล้วเรียก **Service** (เก็บ business rules) ซึ่งเรียก **Model** (คุยกับฐานข้อมูล)
> ทุก Service ในที่นี้เป็น `final class` ที่มีแต่ `static` method (ไม่ต้อง `new`) เรียกแบบ `PricingService::quote(...)`
> ข้อความ `RuntimeException` ที่ Service โยนออกมา "ตั้งใจให้ผู้ใช้อ่าน" – Controller จะแปลงเป็น flash message ผ่าน `BookingService::publicError()`

---

## 0. ภาพรวม life-cycle ของการจอง

```
 pending ──confirm──▶ confirmed ──pickup──▶ picked_up ──return──▶ returned ──complete──▶ completed
   │  └────────────────pickup (ข้ามยืนยันได้)───────▲
   └──cancel──▶ cancelled ◀──cancel── confirmed          (ยกเลิกได้เฉพาะก่อนส่งมอบรถ)
```

| สถานะ (`bookings.status`) | ความหมายทางธุรกิจ | ใครเปลี่ยนสถานะ |
|---|---|---|
| `pending` | ลูกค้าจอง (หรือแอดมินสร้าง) รอยืนยัน – **กันรถไว้แล้ว** แม้ยังไม่จ่ายมัดจำ | `WebBookingService::create`, `AdminBookingService::create` |
| `confirmed` | ร้านยืนยันแล้ว (กดเอง หรือ auto เมื่อสลิปมัดจำอนุมัติ) | `BookingActionService::confirm`, `PaymentService::approve` |
| `picked_up` | ส่งมอบรถให้ลูกค้าแล้ว (รถออกงาน) | `BookingActionService::pickup` |
| `returned` | รับรถคืนแล้ว คิดค่าใช้จ่ายเพิ่มแล้ว | `ReturnService::run` |
| `completed` | ปิดงาน | `BookingActionService::complete` |
| `cancelled` | ยกเลิก (ทำได้เฉพาะ pending/confirmed) | `BookingActionService::cancel` |

สถานะ `picked_up, returned, completed, cancelled` ถือว่า **locked** (`BookingService::isLocked`) – แก้รถ/วันเวลา/ราคาไม่ได้ เหลือแก้เฉพาะข้อมูลผู้เช่า จุดรับ–คืน หมายเหตุ

### แนวคิดเรื่องเงิน

| คำ | คอลัมน์ / ที่มา | ความหมาย |
|---|---|---|
| ค่าเช่า (rental amount) | `rental_amount` จาก `PricingService::calcRental` | ค่าเช่ารถตามจำนวนวัน (เลือกเรตรายวัน/สัปดาห์/เดือนที่ถูกสุด) |
| บริการเสริม | `addon_amount` | ส่งรถถึงที่, คนขับ, คาร์ซีท, ประกันเสริม, GPS |
| ส่วนลด / VAT / ยอดรวม | `discount`, `vat_amount`, `grand_total` | `grand_total` = (ค่าเช่า + เสริม − ส่วนลด) + VAT |
| มัดจำการจอง (booking deposit) | `deposit_amount` | จ่ายตอนจองเพื่อล็อกรถ **หักจากค่าเช่า** (ไม่ใช่เก็บเพิ่ม) |
| เงินประกัน (security deposit) | `security_deposit` (เงื่อนไข) + `payments.kind='security'` (เงินที่ร้านถือจริง) | ประกันความเสียหาย **คืนลูกค้า** ไม่นับเป็นรายได้ |
| ค่าใช้จ่ายเพิ่ม (extra charges) | `extra_charge`, `extra_breakdown` (JSON) | คืนช้า / น้ำมันขาด / ระยะทางเกิน / อื่นๆ คิดตอนรับคืนรถ (หรือเพิ่มภายหลังตอนปิดเงินประกัน) |
| ยอดที่ชำระแล้ว | `paid_amount` | ผลรวมรายการ `payments` ที่ `approved` ตามสูตร `Payment::REVENUE_SQL` (refund เป็นลบ, security/security_refund = 0) คำนวณใหม่ด้วย `PaymentService::recalc` |
| สถานะการชำระ | `payment_status` | `unpaid` / `deposit_paid` (จ่ายบางส่วน) / `paid` / `refunded` |

ยอดที่ลูกค้าต้องจ่ายทั้งหมด = `grand_total + extra_charge`  ส่วนที่ค้าง = ยอดนั้น − `paid_amount`

### ใครทำอะไร (ตารางสรุป)

| ขั้นตอน | Service | Controller ที่เรียก |
|---|---|---|
| ลูกค้าจองหน้าเว็บ | `WebBookingService` | `BookingController` (public) |
| ตรวจรถว่าง / เวลาทำการ | `AvailabilityService` | `CarController`, `ApiController`, ทุก Service จอง |
| คิดราคา | `PricingService` (+ `DeliveryFeeService`) | `CarController`, `ApiController`, Web/AdminBookingService |
| แอดมินสร้าง/แก้จอง | `AdminBookingService` | `Admin\BookingController` |
| ปุ่มบนหน้างาน | `BookingActionService` | `Admin\BookingController` |
| รับคืนรถ | `ReturnService` (+ `LateReturnService`) | ผ่าน `BookingActionService` |
| เช่าต่อ | `ExtendService` | ผ่าน `BookingActionService` + `Admin\BookingController` (ขอราคา) |
| ปิดเงินประกัน | `SecurityDepositService` | ผ่าน `BookingActionService` |
| รับ/อนุมัติ/ปฏิเสธสลิป, คิดยอดชำระ | `PaymentService` | `TrackController`, `LiffController`, `Admin\PaymentController` |
| ฟังก์ชันกลาง | `BookingService` | หลาย Controller |

---

## app/Services/BookingService.php

- **หน้าที่ของไฟล์**: ฟังก์ชันเล็กๆ ที่การจองทุกแบบใช้ร่วมกัน (แปลงข้อความ error, ทำความสะอาด input บริการเสริม, เช็ก locked, แปลงวันเวลา, แปลงผล quote เป็นคอลัมน์ DB) ไม่ depend Model ใดๆ. เรียกโดย `Admin\BookingController`, `BookingController`, `ApiController`, `TrackController`, `LiffController`, `SignController` และ Service จองอื่นๆ

### class `BookingService`
- บทบาท: utility class
- `LOCKED_STATUSES = ['picked_up','returned','completed','cancelled']` – สถานะที่ห้ามแก้รถ วัน–เวลา ราคา (เพราะผูกกับผลตรวจสภาพรถและการเงินแล้ว)

#### `public static publicError(\Throwable $e, string $fallback = 'ทำรายการไม่สำเร็จ ...'): string`
- **ทำอะไร**: แยกว่า exception นี้ "เราโยนเองเพื่อให้ผู้ใช้อ่าน" หรือเป็น error ภายใน. ถ้าเป็น `RuntimeException` และ **ไม่ใช่** `PDOException` (ต้องเช็กแยกเพราะ `PDOException` สืบทอดจาก `RuntimeException`) → คืน `getMessage()` ตรงๆ. นอกนั้น (เช่น error ฐานข้อมูล) → `error_log` ข้อความจริงพร้อมไฟล์/บรรทัด แล้วคืนข้อความกลาง `$fallback` เพื่อไม่เปิดเผยรายละเอียดภายในให้ผู้ใช้
- **รับเข้า**: `$e` exception ที่ถูกจับ, `$fallback` ข้อความสำรอง
- **คืนค่า**: string ข้อความสำหรับแสดงผู้ใช้
- **เรียกใช้โดย**: `WebBookingService::create`, `Admin\BookingController` (บรรทัด 77, 89, 194), `TrackController`, `LiffController`, `SignController`

#### `public static cleanAddons(array $raw): array`
- **ทำอะไร**: วนทุกคู่ key/value จากฟอร์ม แปลงเป็น int (ถ้าไม่ใช่ scalar ให้เป็น 0) แล้วบีบให้อยู่ระหว่าง 0–5 ต่อรายการ ป้องกันค่าแปลก/ติดลบ/จำนวนมหาศาลที่จะทำให้ราคาเพี้ยน. **ไม่ได้กรอง key** (key ที่ไม่รู้จักจะถูกเก็บไว้ แต่ `PricingService::calcAddons` สนใจเฉพาะ key ที่อยู่ใน catalog)
- **รับเข้า**: `$raw` เช่น `['child_seat' => '2', 'gps' => '9']`
- **คืนค่า**: เช่น `['child_seat' => 2, 'gps' => 5]`
- **เรียกใช้โดย**: `BookingController`, `ApiController`, `AdminBookingService::rentalColumns`

#### `public static isLocked(?array $booking): bool`
- **ทำอะไร**: true ถ้า `$booking` ไม่เป็น null และ `status` อยู่ใน `LOCKED_STATUSES`. การจองใหม่ (null) = ไม่ locked
- **เรียกใช้โดย**: `AdminBookingService::save/update`, `Admin\BookingController` (บรรทัด 100)

#### `public static toDb(string $text): string`
- **ทำอะไร**: แปลงข้อความวันเวลาจากฟอร์มด้วย `strtotime` เป็น `Y-m-d H:i:s`. ว่างหรือ parse ไม่ได้ → คืน `''` (ผู้เรียกต้องเช็ก `''` เอง)
- **เรียกใช้โดย**: `WebBookingService::create`, `AdminBookingService::readPeriod`

#### `public static priceColumns(array $quote): array`
- **ทำอะไร**: แปลงผลของ `PricingService::quote()` เป็นชุดคอลัมน์ของตาราง `bookings`: `rental_days, rate_breakdown (JSON {rental, addons}), rental_amount, addon_amount, discount, subtotal, vat_rate, vat_amount, grand_total, deposit_amount, security_deposit`
- **รับเข้า**: `$quote` จาก `PricingService::quote`
- **คืนค่า**: array คอลัมน์ → ค่า (ใช้ `+` ต่อกับ row อื่น)
- **เรียกใช้โดย**: `WebBookingService::bookingRow`, `AdminBookingService::rentalColumns`

---

## app/Services/AvailabilityService.php

- **หน้าที่ของไฟล์**: ตัดสินว่า "รถคันนี้ว่างไหม" และ "ช่วงเวลารับ–คืนที่ลูกค้าเลือกใช้ได้ไหม". Business rules: (1) เว้นเวลาพักรถระหว่างงาน (ล้างรถ/เติมน้ำมัน/เผื่อคืนช้า) (2) รถที่เลยกำหนดคืนแต่ยังไม่กดรับคืนถือว่าไม่ว่าง (3) เวลาทำการ (สนามบินกว้างกว่า) (4) จองล่วงหน้าขั้นต่ำ/เช่าขั้นต่ำ/เช่าสูงสุด 1 ปี
- **ใช้ Model**: `Car`, `Booking`, `CarBlock`, `Database`; **Service อื่น**: `PricingService`
- **เรียกโดย**: `CarController`, `ApiController`, `WebBookingService`, `AdminBookingService`, `ExtendService`
- **Settings ที่ใช้ (seed)**: `booking_gap_hours=4`, `booking_lead_hours=3`, `min_rental_days=1`, `open_time=08:00`, `close_time=20:00`, `airport_open_time=05:00`, `airport_close_time=24:00`

### class `AvailabilityService`
- ไม่มี constant (ใช้ setting ทั้งหมด)

#### `public static gapHours(): int`
- **ทำอะไร**: อ่าน setting `booking_gap_hours` (default 4) คืนจำนวนชั่วโมงพักรถ ต่ำสุด 0
- **เรียกใช้โดย**: `paddedRange`, `ExtendService::busyMessage`

#### `private static paddedRange(string $pickup, string $return): array`
- **ทำอะไร**: ขยายช่วงเวลาหัว–ท้ายออกข้างละ `gapHours` ชั่วโมง เพื่อให้จองติดกันเป๊ะไม่ได้ ถ้า gap = 0 คืนช่วงเดิม
- **คืนค่า**: `[from, to]` รูปแบบ `Y-m-d H:i:s`
- **ตัวอย่างตัวเลข**: ขอ 10:00–18:00 gap 4 → ตรวจ 06:00–22:00

#### `public static isCarAvailable(int $carId, string $pickup, string $return, ?int $ignoreBookingId = null): bool`
- **ทำอะไร**: ตรวจตามลำดับ (ไม่ผ่านข้อใดคืน false ทันที)
  1. `Car::status($carId)` ต้องเป็น `available` (รถปิดใช้งาน/ซ่อมไม่ว่าง)
  2. ขยายช่วงด้วย `paddedRange` แล้วนับการจองที่ชน (`Booking::conflictCount`: สถานะ pending/confirmed/picked_up และ `pickup_at < to AND return_at > from`) ต้องเป็น 0
  3. `Booking::overdueOutCount` – มีการจองอื่นของรถคันนี้ที่ `picked_up` และเลย `return_at` แล้ว = รถยังอยู่กับลูกค้า → ไม่ว่าง (กันจองซ้อนกับรถที่ไม่อยู่)
  4. `CarBlock::overlapCount` – ช่วงที่ร้านปิดรถ (ซ่อม ฯลฯ) ต้องไม่ทับ
- **รับเข้า**: `$ignoreBookingId` ใช้ตอนเช่าต่อ/แอดมินแก้ใบจอง เพื่อไม่ให้ใบของตัวเองบล็อกตัวเอง
- **คืนค่า**: bool
- **เรียกใช้โดย**: `WebBookingService::checkPeriod` และ `saveOnce` (ตรวจซ้ำใน transaction หลังล็อกรถ), `AdminBookingService::rentalColumns`, `ExtendService::quote`, `CarController`, `ApiController`

#### `public static availableCars(string $pickup, string $return, ?string $carType = null): array`
- **ทำอะไร**: ดึงรายการรถที่ว่างทั้งหมดในช่วงเวลา ด้วย SQL เดียว กติกาเดียวกับ `isCarAvailable` (`status='available'`, ไม่มีจองชน, ไม่มีรถ picked_up เลยกำหนด **ของรถคันนั้นเอง**, ไม่ชน car_blocks) กรอง `car_type` ถ้าส่งมา เรียงตาม `sort_order, price_day`. จากนั้นคิดจำนวนวันด้วย `PricingService::rentalDays` และราคาเช่าของแต่ละคันด้วย `calcRental` แล้วเติมคีย์พิเศษลงแถวรถ
- **คืนค่า**: รายการรถ (แถว `cars`) + `_days`, `_total`, `_avg_day`, `_lines` (ราคาเฉพาะค่าเช่า ไม่รวมบริการเสริม)
- **เรียกใช้โดย**: `CarController` (หน้าค้นหารถ)

#### `public static busyRanges(int $carId, ?string $from = null, ?string $to = null): array`
- **ทำอะไร**: ขอช่วงวันที่รถไม่ว่าง (จอง + ปิดรถ) ไว้วาดปฏิทิน default จากวันนี้ถึง +6 เดือน โดยเรียก `Booking::busyRanges`
- **เรียกใช้โดย**: `CarController` (หน้ารายละเอียดรถ)

#### `public static serviceWindow(string $placeType = 'shop'): array`
- **ทำอะไร**: คืนเวลาเปิด–ปิดที่รับ/คืนรถได้ ถ้า `airport` ใช้ `airport_open_time/close_time` (05:00–24:00) label "สนามบิน" นอกนั้นใช้ `open_time/close_time` (08:00–20:00) label "จุดรับ–คืนทั่วไป"
- **คืนค่า**: `['open'=>'HH:MM','close'=>'HH:MM','label'=>string]`

#### `public static hhmmToMinutes(string $t): int`
- **ทำอะไร**: แปลง `"HH:MM"` เป็นนาทีนับจากเที่ยงคืน รองรับ `24:00` = 1440 (สิ้นวัน). ถ้าไม่มีนาทีถือเป็น 0
- **ตัวอย่าง**: `"08:30"` → 510, `"24:00"` → 1440

#### `public static validateServiceTime(string $datetime, string $placeType, string $what): ?string`
- **ทำอะไร**: แปลง datetime เป็นนาทีของวัน แล้วเทียบกับ `serviceWindow`. ถ้า `mins < open` หรือ `mins > close` คืนข้อความ เช่น `"รับรถได้เฉพาะเวลา 08:00–20:00 น. (จุดรับ–คืนทั่วไป)"` (เวลา = close พอดียังผ่าน)
- **รับเข้า**: `$what` คำกริยา เช่น 'รับรถ' / 'คืนรถ'
- **คืนค่า**: `null` = ผ่าน, หรือข้อความ error/warning
- **เรียกใช้โดย**: `validatePeriod`, `ExtendService::quote` (ใช้เป็น warning ไม่ใช่ error)

#### `public static validatePeriod(string $pickup, string $return, string $pickType = 'shop', string $retType = 'shop'): ?string`
- **ทำอะไร**: ตรวจช่วงเวลาที่ลูกค้าเลือกตามลำดับ: (1) parse ไม่ได้ → "รูปแบบวันที่ไม่ถูกต้อง" (2) คืนไม่หลังรับ → "วันคืนรถต้องหลังวันรับรถ" (3) เวลารับ/คืนอยู่ในเวลาทำการ (ตามประเภทจุด) (4) จองล่วงหน้าอย่างน้อย `booking_lead_hours` (default 3) ชม. (5) จำนวนวันเช่า ≥ `min_rental_days` (6) ไม่เกิน 365 วัน
- **คืนค่า**: `null` = ผ่าน; หรือข้อความผิดพลาดตัวแรกที่เจอ
- **เรียกใช้โดย**: `WebBookingService::checkPeriod`, `CarController`, `ApiController`. (แอดมิน **ไม่ใช้** เมธอดนี้ – `AdminBookingService::readPeriod` ตรวจเองแบบหลวมกว่า ไม่บังคับเวลาทำการ/ล่วงหน้า)

---

## app/Services/DeliveryFeeService.php

- **หน้าที่ของไฟล์**: คิดค่าส่ง–รับรถถึงที่ตามระยะทางจากกรุงเทพ (เที่ยวเดียว) จาก "ตารางช่วงระยะทาง" ที่เก็บเป็นข้อความใน settings เพื่อแก้ได้ไม่ต้องแตะโค้ด. ระยะที่เกินช่วงสุดท้าย = ไม่มีราคา ต้องคุยกับเจ้าหน้าที่
- **ใช้**: `setting()` helper. **เรียกโดย**: `PricingService::calcAddons`, `WebBookingService::checkDelivery`, `AdminBookingService::rentalColumns`, `ApiController`, `Admin\BookingController`, `PageController`, `SettingController`, view `car.php` / `contract_terms.php`

### class `DeliveryFeeService`
- `DEFAULT_TIERS = '30:0,50:500,80:1000,120:1500,200:2500'` – ตารางสำรองถ้าไม่มี setting `delivery_tiers` รูปแบบ `ถึงกม.:ราคา` คั่นด้วย `,`
- Settings: `free_delivery_km=30` (ระยะฟรี), `delivery_tiers`

#### `public static tiers(): array`
- **ทำอะไร**: อ่าน `delivery_tiers` แยกด้วย `,` แล้วแต่ละส่วนแยกด้วย `:` ข้ามส่วนที่ไม่ได้เป็นคู่ 2 ค่า และข้ามที่ `max_km <= 0` สุดท้ายเรียงจากใกล้ไปไกลด้วย `usort` ผ่าน `byDistance`
- **คืนค่า**: `[['max_km'=>float,'fee'=>float], ...]`

#### `private static byDistance(array $a, array $b): int`
- **ทำอะไร**: comparator สำหรับ `usort` เทียบ `max_km` ด้วย spaceship `<=>`

#### `public static freeKm(): float`
- **ทำอะไร**: คืน setting `free_delivery_km` (default 30) – ระยะที่ส่งฟรี

#### `public static feeForKm(float $km): ?float`
- **ทำอะไร**: ถ้า `km <= freeKm()` → `0.0`. มิฉะนั้นหา tier แรกที่ `km <= max_km` คืน `fee` ของ tier นั้น (เหมาเป็นช่วง ไม่ใช่คิดต่อกม.). ถ้าเกินทุก tier คืน `null` (= เกินพื้นที่บริการ)
- **คืนค่า**: ราคาต่อเที่ยว หรือ `null`
- **ตัวอย่างตัวเลข** (ค่า default): 25 กม. → 0 · 45 กม. → 500 · 80 กม. → 1,000 · 130 กม. → 2,500 · 250 กม. → null. (tier แรก `30:0` ซ้ำกับช่วงฟรี จึงไม่ถูกใช้)
- **เรียกใช้โดย**: `PricingService::calcAddons`, `isOutOfRange`

#### `public static isOutOfRange(array $addons, float $km): bool`
- **ทำอะไร**: true ก็ต่อเมื่อลูกค้า **เลือก** `delivery` หรือ `return_delivery` (จำนวน > 0) และ `feeForKm($km)` เป็น null. ถ้าไม่เลือกส่งรถ ระยะทางไม่เกี่ยว
- **เรียกใช้โดย**: `WebBookingService::checkDelivery`, `AdminBookingService::rentalColumns`, `ApiController`

#### `public static bookingKm(array $booking): float`
- **ทำอะไร**: หาระยะทางส่งรถของการจอง: ถ้ามี `delivery_km` ใช้ค่านั้น. การจองเก่าที่ไม่ได้เก็บไว้ จะย้อนหาจาก `rate_breakdown['addons']` – หาบรรทัด `delivery`/`return_delivery` ที่ราคาต่อหน่วย > 0 แล้วจับคู่กับ tier ที่ `fee` ตรงกัน (±0.01) คืน `max_km` ของ tier นั้น (เป็นค่าประมาณ ไม่ใช่ระยะจริง). ไม่เจอคืน 0.0
- **เรียกใช้โดย**: `Admin\BookingController` (เติมค่าในฟอร์มแก้ไข)

#### `public static tiersText(): string`
- **ทำอะไร**: สร้างข้อความอธิบายตารางค่าส่งสำหรับหน้าเว็บ/เงื่อนไข ข้าม tier ที่ `max_km <= freeKm`
- **ตัวอย่างผลลัพธ์ (default)**: `ไม่เกิน 30 กม. ไม่มีค่าใช้จ่าย · 31–50 กม. 500 บาท · 51–80 กม. 1,000 บาท · 81–120 กม. 1,500 บาท · 121–200 กม. 2,500 บาท · เกิน 200 กม. กรุณาติดต่อเจ้าหน้าที่`
- **เรียกใช้โดย**: `PageController`, `SettingController`, `Admin\BookingController`, views

---

## app/Services/PricingService.php

- **หน้าที่ของไฟล์**: เครื่องคิดราคาเช่า (ค่าเช่า + บริการเสริม + ส่วนลด + VAT + มัดจำ + เงินประกัน). ไม่มี Model; ใช้ `setting()` และ `DeliveryFeeService`. เรียกโดย `WebBookingService`, `AdminBookingService`, `ExtendService`, `AvailabilityService`, `CarController`, `ApiController`
- **กติกาคิดวัน**: จำนวนวัน = `ceil((ชั่วโมงรวม − 2) / 24)` ขั้นต่ำ 1 วัน (ผ่อนผัน 2 ชม.); คิดเรต เดือน(30)/สัปดาห์(7)/วัน แบบที่ **ถูกที่สุดเสมอ**; ถ้ารถไม่ได้ตั้ง `price_week`/`price_month` ใช้ `price_day × 7/30`

### class `PricingService`
- `GRACE_HOURS = 2` – ผ่อนผันชั่วโมงเมื่อนับวันเช่า (เช่า 24 ชม. 2 นาที ยังนับ 1 วัน)
- `DAYS_IN_WEEK = 7`, `DAYS_IN_MONTH = 30` – ขนาดแพ็กเกจเหมา
- ใช้ settings: `vat_enabled=0`, `vat_rate=7`, `booking_deposit=1000`, `deposit_percent=20`, `security_deposit_default=3000`
- รถตัวอย่างใน seed (Yaris Ativ): `price_day=1200, price_week=7500, price_month=26000, deposit=3000, deposit_booking=1000, driver_fee_day=1200`

#### `public static rentalDays(string $pickup, string $return): int`
- **ทำอะไร**: แปลงทั้งสองเวลาเป็น timestamp; parse ไม่ได้หรือ `end <= start` คืน 0; ไม่เช่นนั้นคิดชั่วโมง = (end−start)/3600 → `ceil(max(0, hours − 2) / 24)` และ `max(1, ...)`
- **คืนค่า**: int วันเช่า (0 = ช่วงเวลาไม่ถูกต้อง)
- **ตัวอย่างตัวเลข**: 10:00 → 10:00 วันถัดไป (24 ชม.) = 1 วัน · 24 ชม. 2 นาที... (24.03 ชม. − 2 = 22.03 → 1 วัน) · 26 ชม. = 1 วัน · 26.5 ชม. = ceil(24.5/24) = 2 วัน · 73.5 ชม. = ceil(71.5/24) = 3 วัน
- **เรียกใช้โดย**: `quote`, `AvailabilityService::availableCars/validatePeriod`, `AdminBookingService::readPeriod`, `ExtendService::quote`, `CarController`

#### `public static calcRental(array $car, int $days): array`
- **ทำอะไร**: ถ้า `days <= 0` คืนศูนย์. หา `perWeek`/`perMonth` (fallback จาก `perDay`) แล้วเรียก `cheapestCombination` ได้ `[months, weeks, singleDays]` จากนั้นสร้างบรรทัดสำหรับใบเสนอราคา ('เหมาเดือน (30 วัน)', 'เหมาสัปดาห์ (7 วัน)', 'รายวัน') เฉพาะที่ qty > 0 และรวมเป็น `total` (ปัด 2 ตำแหน่ง) พร้อมค่าเฉลี่ยต่อวัน
- **รับเข้า**: `$car` ต้องมี `price_day, price_week, price_month`
- **คืนค่า**: `['total'=>float,'lines'=>[['label','qty','unit','amount']],'avg_day'=>float]`
- **ตัวอย่างตัวเลข** (Yaris 1200/7500/26000): 3 วัน = 3,600 (รายวัน) · 8 วัน = 1 สัปดาห์ + 1 วัน = 7,500 + 1,200 = **8,700** (ถูกกว่า 8×1,200 = 9,600) · 6 วัน = 6×1,200 = 7,200 < 7,500 → ยังเป็นรายวัน
- **เรียกใช้โดย**: `quote`, `AvailabilityService::availableCars`, `ExtendService::quote`

#### `private static cheapestCombination(int $days, float $perDay, float $perWeek, float $perMonth): array`
- **ทำอะไร**: เริ่มจากสมมติรายวันล้วน (`[0,0,days]`) แล้วลองทุกชุด (เดือน 0..ceil(days/30)) × (สัปดาห์ 0..ceil(เหลือ/7)) โดยวันที่เหลือที่ไม่ครบสัปดาห์คิดรายวัน ชุดไหนถูกกว่าค่าเดิมเกิน 0.004 บาทจึงแทนที่ (กัน floating point). การอนุญาตให้ "ปัดขึ้นเป็นเหมาเต็ม" (เช่น 6 วันเหมาสัปดาห์) เกิดได้เพราะ loop ไปถึง ceil – ทำให้ราคาไม่แพงกว่าเหมาเต็ม
- **คืนค่า**: `[months, weeks, singleDays]`
- **ตัวอย่าง**: ถ้า `price_week` = 5,000 (ถูก) และ 6 วัน → เหมา 1 สัปดาห์ = 5,000 < 7,200 จึงเลือกเหมาสัปดาห์ แม้เช่าแค่ 6 วัน (ทำให้ผล `singleDays` ไม่ติดลบ ด้วย `max(0, ...)`)

#### `public static addonCatalog(array $car): array`
- **ทำอะไร**: คืนรายการบริการเสริมสำหรับแสดงในฟอร์ม key → `[ชื่อ, ราคา, หน่วย]`; ราคา `-1.0` = คิดตามระยะทาง (delivery/return_delivery). คนขับใช้ `driver_fee_day` ของรถ; คาร์ซีท 150, ประกันเสริม 300, GPS 100 บาท/วัน
- **หมายเหตุ**: ราคา hard-code ซ้ำกับใน `calcAddons` (ต้องแก้สองที่ – ดูส่วนข้อสังเกตท้ายเอกสาร)
- **เรียกใช้โดย**: `CarController` (ตัวเลือกบริการเสริมในหน้าจอง)

#### `public static calcAddons(array $car, array $addons, int $days, float $km = 0.0): array`
- **ทำอะไร**: คิดค่าบริการเสริมที่เลือก. ค่าส่ง = `DeliveryFeeService::feeForKm($km) ?? 0.0` (ใช้ราคาเท่ากันทั้งขารับและขาส่ง คิดแยกบรรทัด ไม่คูณวัน). บริการรายวัน (driver, child_seat, insurance_plus, gps) คิด `จำนวน × วัน × ราคา`. ข้ามรายการที่ qty ≤ 0; ข้ามบริการรายวันที่ราคา ≤ 0 (เช่นรถที่ไม่มีค่าคนขับ) แต่ **บริการส่งรถที่ราคา 0 ยังแสดง** (ต่อท้ายว่า "(ในระยะฟรี)") เพื่อให้ลูกค้าเห็นว่าได้บริการฟรี. ถ้า `isOutOfRange` ทำให้ fee เป็น null ที่นี่จะกลายเป็น 0 – ผู้เรียกต้องตรวจ `isOutOfRange` ก่อนเสมอ
- **รับเข้า**: `$addons` เช่น `['delivery'=>1,'return_delivery'=>1,'child_seat'=>2]`; `$days` จาก `rentalDays`; `$km` ระยะทาง
- **คืนค่า**: `['total'=>float,'lines'=>[['key','label','qty','unit','amount']]]`
- **ตัวอย่างตัวเลข**: 3 วัน, delivery=1 + return_delivery=1 ที่ 45 กม. (ค่า 500) + child_seat=1 → ส่ง 500 + รับ 500 + คาร์ซีท (1×3×150 = 450) = **1,450**
- **เรียกใช้โดย**: `quote`, `ExtendService::quote` (ไม่ส่ง `$km` → ค่าส่งเป็น 0 ซึ่งตั้งใจ เพราะเอาแค่ส่วนต่างของบริการรายวัน)

#### `private static addonLabel(string $name, bool $chargedPerDay, int $days, bool $isDelivery, float $km, float $amount): string`
- **ทำอะไร**: สร้างข้อความชื่อบรรทัด: ต่อ " (N วัน)" สำหรับบริการรายวัน, ต่อ " — X กม." สำหรับบริการส่งรถเมื่อมีระยะทาง (ตัดศูนย์ทศนิยมท้าย), ต่อ " (ในระยะฟรี)" เมื่อเป็นบริการส่งและยอด ≤ 0

#### `public static quote(array $car, string $pickup, string $return, array $addons = [], float $discount = 0.0, ?bool $vat = null, float $km = 0.0): array`
- **ทำอะไร**: ใบเสนอราคาเต็มของการจอง ขั้นตอน
  1. `days = rentalDays`, `rental = calcRental`, `addon = calcAddons`
  2. `subtotal = max(0, rental + addon − discount)` (ส่วนลดทำให้ติดลบไม่ได้)
  3. VAT: `$vat === null` → ตาม setting `vat_enabled` ('1' = เปิด); true/false = บังคับ. `vat_rate` จาก setting (default 7) ถ้าเปิด
  4. `grand_total = subtotal + vat`
  5. มัดจำการจอง `bookingDeposit()`, เงินประกัน `securityDeposit()`
  6. `balance_on_pickup = max(0, grand_total − มัดจำ)`; `due_on_pickup = balance_on_pickup + security_deposit` (ยอดที่ต้องจ่ายวันรับรถ)
- **รับเข้า**: `$discount` เป็นบาท, `$vat` null/true/false, `$km` ระยะส่งรถ
- **คืนค่า**: `days, rental_lines, rental_amount, avg_day, addon_lines, addon_amount, discount, subtotal, vat_rate, vat_amount, grand_total, deposit_amount, security_deposit, balance_on_pickup, due_on_pickup, delivery_km`
- **ตัวอย่างตัวเลข** (Yaris, VAT ปิดตาม default): เช่า 3 วัน + คาร์ซีท 1 ชิ้น + ส่งรถ 45 กม. ขาเดียว: rental 3,600 + addon (450 + 500) = 4,550 → grand_total **4,550**; มัดจำ 1,000; ประกัน 3,000; ค้างวันรับ = 3,550; **due_on_pickup = 6,550**. ถ้าเปิด VAT 7%: vat = 318.50, grand_total = 4,868.50
- **เรียกใช้โดย**: `WebBookingService::create`, `AdminBookingService::rentalColumns`, `CarController`, `ApiController`

#### `private static bookingDeposit(array $car, float $grandTotal): float`
- **ทำอะไร**: เลือกมัดจำการจองตามลำดับความสำคัญ: `cars.deposit_booking` (ถ้า > 0) → setting `booking_deposit` (default 1000) → (ถ้ายัง ≤ 0) `grand_total × deposit_percent% ` ปัดเต็มบาท. แล้วบีบไม่ให้เกิน `grand_total`. มัดจำนี้ **หักจากค่าเช่า ไม่ได้เก็บเพิ่ม**
- **ตัวอย่าง**: รถ deposit_booking=1000, grand_total 800 → มัดจำ 800 (ไม่เกินยอดรวม)

#### `private static securityDeposit(array $car): float`
- **ทำอะไร**: เงินประกันความเสียหาย: `cars.deposit` ถ้า > 0 ไม่เช่นนั้น setting `security_deposit_default` (3000)

#### `public static emptyQuote(): array`
- **ทำอะไร**: คืนใบเสนอราคาเป็นศูนย์ (days = 0 ฯลฯ) ให้กล่องสรุปราคาใน view แสดงได้โดยไม่ error เมื่อช่วงเวลาไม่ผ่านการตรวจ (หมายเหตุ: ไม่มีคีย์ `balance_on_pickup`, `due_on_pickup` – view ต้องไม่อ่านคีย์เหล่านี้เมื่อ days = 0)
- **เรียกใช้โดย**: `ApiController`

---

## app/Services/LateReturnService.php

- **หน้าที่ของไฟล์**: นโยบายและการคิดค่าใช้จ่ายเพิ่มตอนรับคืนรถ 3 แบบ: คืนช้า, น้ำมันขาด, ระยะทางเกิน. ผลคือ "ยอดแนะนำ" ที่พนักงานปรับ/ยกเว้นได้ในฟอร์มรับคืน. ข้อความนโยบายที่แสดงบนเว็บ/สัญญามาจากค่าเดียวกันกับที่ใช้คิดจริง
- **เรียกโดย**: `ReturnService::chargeLines`, `Admin\BookingController` (ขอยอดแนะนำแบบ AJAX บรรทัด 219), `CronService` (ประมาณค่าปรับรถเลยกำหนดคืน – ส่ง car แค่ `price_day`), `PageController`, views (`contract_terms`, `how_to`)
- **Settings (seed)**: `late_grace_hours=2`, `late_full_day_hours=6`, `late_fee_per_hour=200`, `fuel_fee_per_level=300`, `free_km_per_day=0` (0 = ไม่จำกัดระยะ), `over_km_fee=4`

### class `LateReturnService`
- `MINUTES_PER_DAY = 1440` – ใช้แยกคืนช้าเป็น "วันเต็ม" กับ "เศษของวัน"

#### `public static policy(): array`
- **ทำอะไร**: อ่าน setting มาเป็นนโยบาย: `grace` (ชม. ผ่อนผัน, default = `PricingService::GRACE_HOURS` = 2, ต่ำสุด 0), `full_day` (เศษของวันเกินกี่ชม. ให้คิดเป็น 1 วันเต็ม, ต่ำสุด 1, default 6), `per_hour` (ค่าปรับ/ชม., default 200)
- **คืนค่า**: `['grace'=>int,'full_day'=>int,'per_hour'=>float]`

#### `public static policyText(): array`
- **ทำอะไร**: สร้างข้อความภาษาไทยอธิบายนโยบายจาก `policy()` – `grace` ("ผ่อนผันคืนช้าได้ไม่เกิน 2 ชั่วโมง") และ `fee` ("เกิน 2 ชั่วโมง คิดค่าปรับชั่วโมงละ 200 บาท เกิน 6 ชั่วโมงคิดเป็นค่าเช่าเพิ่ม 1 วันเต็ม")
- **เรียกใช้โดย**: `PageController` (เงื่อนไข/วิธีเช่า/FAQ)

#### `public static fuelFeePerLevel(): float`
- **ทำอะไร**: ค่าน้ำมันขาดต่อ 1 ขีด (1/8 ถัง) จาก `fuel_fee_per_level` (default 300, ต่ำสุด 0)

#### `public static calculate(array $booking, array $car, string $returnedAt, ?int $mileageIn, ?int $fuelIn): array`
- **ทำอะไร**: รวมผลของ `lateLine`, `fuelLine`, `mileageLine` (ตัดรายการที่เป็น null) แล้วรวมยอด
- **รับเข้า**: `$booking` (ต้องมี `return_at, fuel_out, mileage_out, rental_days`), `$car` (ต้องมี `price_day`), `$returnedAt` `Y-m-d H:i:s`, `$mileageIn` หรือ null, `$fuelIn` 0–8 หรือ null
- **คืนค่า**: `['lines'=>[['key'=>'late|fuel|km','label','detail','amount']], 'total'=>float]`
- **เรียกใช้โดย**: `ReturnService::chargeLines`, `Admin\BookingController`, `CronService`

#### `private static lateLine(array $booking, array $car, string $returnedAt): ?array`
- **ทำอะไร**: คิดค่าปรับคืนช้า
  1. `minutesLate = floor((returnedAt − return_at)/60)`; ถ้า ≤ grace×60 → null (ไม่คิด)
  2. แยก `fullDays = minutesLate ÷ 1440`, `remaining = minutesLate mod 1440`
  3. ถ้า `remaining > full_day×60` → `fullDays++` (เศษเกิน 6 ชม. = เพิ่ม 1 วันเต็ม); ถ้า `remaining > grace×60` → `extraHours = ceil(remaining/60) − grace` (ปรับรายชั่วโมงเฉพาะส่วนที่เกินผ่อนผัน)
  4. ค่าปรับรายชั่วโมงถูกจำกัดไม่เกิน `price_day` (กันกรณีช้า 5 ชม. แพงกว่าช้า 7 ชม. ที่คิดเต็มวัน)
  5. `amount = fullDays × price_day + hourlyFee` (ใช้ **เรตรายวัน** เสมอ ไม่ใช้เรตสัปดาห์/เดือน); ถ้า ≤ 0 → null
- **ตัวอย่างตัวเลข** (price_day 1,200; grace 2, full_day 6, 200/ชม.):
  - ช้า 1 ชม. 30 นาที (90 นาที) → ไม่คิด
  - ช้า 5 ชม. 20 นาที (320 นาที): remaining 320 ≤ 360, > 120 → extraHours = ceil(5.33)=6 − 2 = 4 → 4×200 = 800 (< 1,200) → **800 บาท**
  - ช้า 7 ชม. (420 นาที) → เกิน 6 ชม. → 1 วันเต็ม = **1,200 บาท**
  - ช้า 26 ชม. (1,560 นาที): fullDays 1, remaining 120 (อยู่ในผ่อนผัน) → **1,200 บาท** (ไม่ใช่ 2 วัน)
  - ช้า 30 ชม. (1,800): fullDays 1, remaining 360 → extraHours = 6−2 = 4 → 1,200 + min(800, 1,200) = **2,000 บาท**
- **คืนค่า**: `['key'=>'late','label'=>'คืนรถช้า X','detail'=>...,'amount'=>float]` หรือ null

#### `private static lateTimeText(int $minutesLate): string`
- **ทำอะไร**: แปลงนาทีเป็นข้อความ เช่น "3 ชม. 20 นาที" หรือ "1 วัน 2 ชม." (ถ้านาทีเศษ > 0 ต่อท้าย "N นาที")

#### `private static lateDetailText(int $fullDays, int $extraHours, float $pricePerDay, array $policy): string`
- **ทำอะไร**: สร้างคำอธิบายวิธีคิด เช่น "ค่าเช่าเพิ่ม 1 วัน × 1,200 บาท + เศษ 4 ชม. × 200 บาท" ถ้าไม่มีวันเต็มจะขึ้นต้นด้วย "ผ่อนผัน 2 ชม. คิด 4 ชม. × 200 บาท"

#### `private static fuelLine(array $booking, ?int $fuelIn): ?array`
- **ทำอะไร**: คืน null ถ้าไม่มีค่า `fuel_out` ของการจอง, หรือ `$fuelIn` เป็น null, หรือค่าน้ำมันต่อขีด ≤ 0, หรือ `fuelIn >= fuelOut`. มิฉะนั้นคิด `(fuelOut − fuelIn) × fee`. ตัวเลขน้ำมัน 0–8 ขีด
- **ตัวอย่างตัวเลข**: รับ 8/8 คืน 6/8 → ขาด 2 ขีด × 300 = **600 บาท**
- ข้อสังเกต: `CronService` ส่ง `$fuelIn = null` จึงไม่มีบรรทัดน้ำมันในการประเมินรถเลยกำหนด

#### `private static mileageLine(array $booking, ?int $mileageIn): ?array`
- **ทำอะไร**: เปิดใช้เฉพาะเมื่อ setting `free_km_per_day > 0` (default 0 = ปิด) และมี `mileage_out` > 0, `mileageIn > mileageOut`. `allowed = free_km_per_day × max(1, rental_days)`, `used = in − out`, `over = used − allowed`; ถ้า `over > 0` และ `over_km_fee > 0` คิด `over × over_km_fee`
- **ตัวอย่างตัวเลข**: ตั้ง free_km_per_day = 300, เช่า 3 วัน → ฟรี 900 กม.; วิ่ง 1,000 กม. → เกิน 100 × 4 = **400 บาท**

---

## app/Services/PaymentService.php

- **หน้าที่ของไฟล์**: (1) คำนวณ `paid_amount` และ `payment_status` ของการจองใหม่จากรายการ `payments` ที่ approved (2) รับแจ้งโอนจากลูกค้า (สลิป) (3) แอดมินอนุมัติ/ปฏิเสธสลิป
- **ใช้ Model**: `Payment`, `Booking`, `Car`, `ActivityLog`; **Service อื่น**: `UploadService`, `NotificationService`
- **เรียกโดย**: `recalc` ← Admin/Return/Extend/SecurityDeposit/BookingAction Service; `submitSlip` ← `TrackController`, `LiffController`; `approve/reject` ← `Admin\PaymentController`

### class `PaymentService`
- ไม่มี constant (มีค่าจำกัดฝังในโค้ด: สลิปไม่เกิน 2,000,000 บาท, ไฟล์ไม่เกิน 8 MB)

#### `public static recalc(int $bookingId): void`
- **ทำอะไร**: `paid = Payment::netPaid(bookingId)` (รวมเฉพาะ approved ตาม `REVENUE_SQL`: refund = ลบ, security/security_refund = 0, ที่เหลือรวม **รวมรายการ method=deduct** ซึ่งคือการหักเงินประกันไปชำระยอดค้าง). โหลดการจอง (ไม่พบ → return เงียบๆ). `due = grand_total + extra_charge`. ตัดสินสถานะ:
  - `paid < 0` หรือ (มี refund approved และ `paid ≤ 0.001`) → `refunded` (เช่น ยกเลิกแล้วคืนมัดจำครบ)
  - `paid ≤ 0.001` → `unpaid`
  - `paid ≥ due − 0.01` → `paid`
  - นอกนั้น → `deposit_paid` (จ่ายบางส่วน)
  แล้วเขียน `paid_amount` (ปัด 2 ตำแหน่ง) และ `payment_status` ผ่าน `Booking::updateById`
- **ทำไมต้องเรียกบ่อย**: ทุกครั้งที่ `grand_total` หรือ `extra_charge` เปลี่ยน (แก้จอง, เช่าต่อ, รับคืน, ปิดเงินประกัน) ยอดที่ต้องจ่ายเปลี่ยน ไม่งั้นจะขึ้น "ชำระครบ" ทั้งที่ยังค้าง
- ไม่แตะ `bookings.security_deposit` (นั่นคือเงื่อนไข) – เงินประกันที่ร้านถือจริงดูที่ `Payment::securityHeld()`
- **เรียกใช้โดย**: `AdminBookingService::update`, `BookingActionService::addPayment`, `ReturnService::run`, `ExtendService::run`, `SecurityDepositService::run`, `approve`, `reject`

#### `public static submitSlip(array $b, array $file, float $amount, ?string $paidAt = null, ?string $note = null): int`
- **ทำอะไร**: ลูกค้าแจ้งโอน. ตรวจตามลำดับ: การจอง `cancelled`/`completed` → "การจองนี้ปิดแล้ว ไม่สามารถแจ้งโอนได้"; `amount <= 0` → "กรุณาระบุจำนวนเงินที่โอน"; `amount > 2,000,000` → "จำนวนเงินไม่ถูกต้อง"; อัปโหลดไฟล์ผ่าน `UploadService::save($file,'slips',8)` ไม่สำเร็จ → "กรุณาแนบไฟล์สลิป". จากนั้น insert payments สถานะ **`pending`** (รอแอดมินตรวจ) method `transfer`, kind = `balance` ถ้าเคยจ่ายแล้ว (`paid_amount > 0`) ไม่เช่นนั้น `deposit`; `paid_at` ใช้ค่าที่ส่งมาหรือเวลาปัจจุบัน. บันทึก ActivityLog `slip_uploaded` และแจ้งร้านด้วย `NotificationService::slipUploaded` (ถ้า error ถูกกลืนเงียบ ไม่ทำให้การแจ้งโอนหาย). ยังไม่เรียก `recalc` เพราะ pending ไม่นับยอด
- **รับเข้า**: `$b` แถว booking, `$file` ไฟล์จาก `$_FILES`/`Request::file`, `$amount` บาท, `$paidAt` ข้อความวันเวลาที่ลูกค้าบอก (optional), `$note`
- **คืนค่า**: id ของ payment ที่สร้าง; โยน `RuntimeException` ตามข้อความข้างบน
- **เรียกใช้โดย**: `TrackController` (บรรทัด 66), `LiffController` (บรรทัด 191)

#### `public static approve(int $paymentId, int $userId): bool`
- **ทำอะไร**: โหลด payment; ถ้าไม่พบหรือสถานะไม่ใช่ `pending` → return false (ตรวจไปแล้ว). มิฉะนั้น `Payment::approve` → `recalc`. แล้วโหลดการจองใหม่: ถ้ายัง `pending` และ `paid_amount >= deposit_amount` → **ยืนยันการจองอัตโนมัติ** (`status='confirmed'`) และส่ง `NotificationService::bookingConfirmed` (ข้อความยืนยัน); ถ้าไม่เข้าเงื่อนไขนี้ ส่ง `NotificationService::slipResult(..., true)` แจ้งว่าได้รับเงินแล้ว (ไม่ส่งซ้ำสองข้อความ). การแจ้งเตือนล้มเหลวไม่ทำให้อนุมัติพัง. บันทึก ActivityLog `payment_approved`
- **คืนค่า**: true เมื่ออนุมัติรายการนี้ได้
- **เรียกใช้โดย**: `Admin\PaymentController` (บรรทัด 30)

#### `public static reject(int $paymentId, int $userId, string $reason): bool`
- **ทำอะไร**: เช่นเดียวกัน ถ้าไม่พบ/ไม่ pending → false. เหตุผลว่างใช้ "ข้อมูลไม่ถูกต้อง". `Payment::reject` → `recalc` → แจ้งลูกค้า `slipResult(..., false, $reason)` → ActivityLog `payment_rejected`
- **เรียกใช้โดย**: `Admin\PaymentController` (บรรทัด 39)

---

## app/Services/WebBookingService.php

- **หน้าที่ของไฟล์**: ลูกค้าจองรถจากหน้าเว็บ (รวมเปิดจากใน LINE/LIFF). ลำดับ: ตรวจข้อมูล → ตรวจรถว่าง → กันจองถี่เกินไป → ตรวจ blacklist → คิดราคา → บันทึก (transaction + ล็อกรถ) → log + แจ้งเตือนร้าน. สร้างการจองสถานะ `pending`, `payment_status='unpaid'`, `source='web'`
- **ใช้**: Models `Booking, Car, Customer, ActivityLog`, `Database`; Services `BookingService, AvailabilityService, DeliveryFeeService, PricingService, NotificationService`
- **เรียกโดย**: `BookingController::` (public) บรรทัด 63 `WebBookingService::create($car, $in, $r->ip())`

### class `WebBookingService`
- `MAX_UNPAID_PER_PHONE = 3` – เบอร์เดียวมีการจอง pending ที่ยังไม่จ่ายมัดจำได้สูงสุด 3 รายการ (เพราะ pending กันรถไว้ ถ้าไม่จำกัดคนเดียวจองรัวๆ ทำรถทั้งร้านดู "ไม่ว่าง")
- `MAX_PER_IP_PER_HOUR = 8` – จองได้ไม่เกิน 8 ครั้ง/ชม. ต่อ IP (นับจาก activity log)

#### `public static create(array $car, array $in, string $ip): array`
- **ทำอะไร**:
  1. แปลง `pickup`/`ret` ด้วย `BookingService::toDb`
  2. รวม error จาก `checkContact`, `checkPeriod`, `checkDelivery`; ถ้ายังไม่มี error ค่อยเรียก `checkBookingLimits` (ประหยัด query)
  3. มี error → `failure($errors)`
  4. `Customer::byPhone`; ถ้าเป็น `is_blacklist = 1` → error "ไม่สามารถจองออนไลน์ได้ กรุณาติดต่อเจ้าหน้าที่ <เบอร์ร้าน>" และ `blacklisted = true`
  5. `PricingService::quote($car, $pickup, $return, $addons, 0.0, null, $km)` (ส่วนลด 0, VAT ตาม setting)
  6. `saveWithRetry`; ถ้า exception ใดๆ → `BookingService::publicError` (ข้อความ fallback "บันทึกการจองไม่สำเร็จ ...")
  7. โหลดการจอง, `ActivityLog::log('booking_created', ...)`, `notifyShop`
- **รับเข้า**: `$car` แถวรถ; `$in` keys: `pickup, ret` (ข้อความวันเวลา), `name, phone, email, line, note`, `place_pick, place_ret` (ข้อความจุดรับ/คืน), `addons` (array จาก `cleanAddons`), `km` (float), `pick_type, ret_type` (`shop|airport`), `accept` (ยอมรับเงื่อนไข), `liff_uid` (LINE userId ถ้าจองผ่าน LIFF); `$ip` ของผู้จอง
- **คืนค่า**: `['errors'=>string[], 'booking'=>?array, 'blacklisted'=>bool]` (สำเร็จ: errors ว่างและ booking เป็นแถวที่เพิ่งสร้าง)
- **เรียกใช้โดย**: `BookingController` | **เรียกต่อ**: `Booking::find/insert`, ทุก private ด้านล่าง

#### `private static checkContact(array $in): array`
- **ทำอะไร**: ตรวจ (1) ชื่อ ≥ 2 ตัวอักษร ("กรุณากรอกชื่อ–นามสกุล") (2) เบอร์: regex `[0-9-+ ]{9,20}` และมีตัวเลข ≥ 9 หลัก ("เบอร์โทรศัพท์ไม่ถูกต้อง") (3) อีเมล (ถ้ากรอก) ผ่าน `FILTER_VALIDATE_EMAIL` ("อีเมลไม่ถูกต้อง") (4) ต้องติ๊กยอมรับเงื่อนไข ("กรุณายอมรับเงื่อนไขการเช่า")
- **คืนค่า**: array ข้อความ error (ว่าง = ผ่าน)

#### `private static checkPeriod(int $carId, string $pickup, string $return, array $in): array`
- **ทำอะไร**: วัน–เวลาว่าง → "กรุณาเลือกวัน–เวลารับและคืนรถ"; `AvailabilityService::validatePeriod` ไม่ผ่านคืนข้อความนั้น; `isCarAvailable` ไม่ผ่าน → "ขออภัย รถคันนี้เพิ่งถูกจองในช่วงเวลาดังกล่าว กรุณาเลือกวันใหม่". นี่เป็นการตรวจ "รอบแรก" แบบไม่ล็อก (เร็ว) ส่วนการตรวจที่กันชนกันจริงอยู่ใน `saveOnce`

#### `private static checkDelivery(array $in): array`
- **ทำอะไร**: ถ้าเลือกส่ง/รับรถถึงที่แต่ระยะทางเกินตารางค่าส่ง (`DeliveryFeeService::isOutOfRange`) → error ระบุระยะและเบอร์ร้านให้ขอราคา; ไม่เช่นนั้นว่าง

#### `private static checkBookingLimits(string $phone, string $ip): array`
- **ทำอะไร**: กันการจองถี่ผิดปกติ: (1) `Booking::openUnpaidCountByPhone` ≥ 3 → ให้ชำระมัดจำรายการเดิมก่อน (2) `ActivityLog::bookingsFromIpSince` (ย้อนหลัง 1 ชม.) ≥ 8 → ให้ลองใหม่ภายหลัง

#### `private static saveWithRetry(int $carId, array $in, ?array $customer, string $pickup, string $return, array $quote): int`
- **ทำอะไร**: เรียก `saveOnce` สูงสุด 3 รอบ. retry เฉพาะเมื่อเป็น `PDOException` รหัส `23000` (unique constraint ซ้ำ เช่น เลขที่จองซ้ำ) และยังไม่ครบรอบ โดยหน่วงสุ่ม 20–120 ms ก่อนลองใหม่; error อื่นโยนต่อทันที. (บรรทัดท้ายที่ throw `RuntimeException('บันทึกการจองไม่สำเร็จ')` เป็นด่านสำรองที่ปกติไปไม่ถึง)
- **คืนค่า**: booking id

#### `private static saveOnce(int $carId, array $in, ?array $customer, string $pickup, string $return, array $quote): int`
- **ทำอะไร**: ทำใน transaction เดียว: `Database::begin()` → **`Car::lock($carId)`** (`SELECT ... FOR UPDATE` แถวรถ) ทำให้คนที่จองรถคันเดียวกันพร้อมกันต้องต่อคิว → ตรวจ `isCarAvailable` ซ้ำ (ถ้าไม่ว่าง throw "รถคันนี้เพิ่งถูกจองไปแล้ว กรุณาเลือกวันใหม่") → ใช้ลูกค้าเดิมหรือ `createCustomer` → `bookingRow` → `Booking::insert` → `commit`. เกิด error ใดๆ → `rollBack` แล้ว throw ต่อ. นี่คือการป้องกัน **double-booking race condition**
- **คืนค่า**: booking id

#### `private static createCustomer(array $in): int`
- **ทำอะไร**: สร้างลูกค้าใหม่ `type='individual'` จากชื่อ/เบอร์/อีเมล/LINE (ค่าว่างเป็น null) ผ่าน `Customer::create`

#### `private static bookingRow(int $carId, int $customerId, array $in, string $pickup, string $return, array $quote): array`
- **ทำอะไร**: ประกอบแถวสำหรับ insert: `booking_no` จาก `Booking::nextNumber()`, ข้อมูลติดต่อ, `pickup_at/return_at`, จุดรับ–คืน (`placeText`), `delivery_km` (null ถ้า 0), `line_user_id` (`lineUserFromSession`), `addons` (JSON), `status='pending'`, `payment_status='unpaid'`, `source='web'`, `note`; ต่อด้วยคอลัมน์ราคา `BookingService::priceColumns($quote)`

#### `private static placeText(string $type, string $place): ?string`
- **ทำอะไร**: ถ้าจุดเป็น `airport` เติมคำนำหน้า `[สนามบิน] ` แล้ว trim; ว่างคืน null. คำนำหน้านี้ถูกใช้ภายหลังโดย `Booking::placeType()` เพื่อรู้ว่าเป็นสนามบิน (เช่น `ExtendService`)

#### `private static lineUserFromSession(string $uid): ?string`
- **ทำอะไร**: รับ `liff_uid` เฉพาะที่รูปแบบเป็น LINE userId จริง (`U` + hex 32 ตัว) ไม่งั้น null เพื่อผูกการจองกับ LINE ทันที ลูกค้าจะได้ข้อความยืนยันโดยไม่ต้องกรอกเพิ่ม

#### `private static notifyShop(array $booking, array $car): void`
- **ทำอะไร**: `NotificationService::newBooking` ครอบ try/catch กลืน error – แจ้งเตือนล้มเหลวไม่ควรทำให้การจองพัง

#### `private static failure(array $errors, bool $blacklisted = false): array`
- **ทำอะไร**: helper สร้างผลลัพธ์ล้มเหลว `['errors'=>..., 'booking'=>null, 'blacklisted'=>...]`

---

## app/Services/AdminBookingService.php

- **หน้าที่ของไฟล์**: พนักงานสร้าง/แก้ไขการจองจากหลังบ้าน (รับงานทางโทรศัพท์/LINE/หน้าร้าน). กติกา: การจองที่ locked (ส่งมอบแล้วขึ้นไป) แก้ได้เฉพาะข้อมูลผู้เช่า จุดรับ–คืน และหมายเหตุ; ก่อนส่งมอบแก้ได้ทั้งรถ วันเวลา บริการเสริม ส่วนลด VAT มัดจำ ประกัน. มีกลไกกันบันทึกทับ (optimistic concurrency ด้วย `updated_at`)
- **ใช้**: `Request`, Models `Booking, Car, Customer, Notification, ActivityLog`; Services `BookingService, PricingService, AvailabilityService, DeliveryFeeService, PaymentService, AuthService`
- **เรียกโดย**: `Admin\BookingController` บรรทัด 74 (สร้าง: `save(null, $request)`) และ 86 (แก้: `save($b, $request)`)

### class `AdminBookingService`
- ไม่มี constant

#### `public static save(?array $existing, Request $request): array`
- **ทำอะไร**: จุดเข้าหลัก. (1) `checkNotEditedElsewhere` (2) `contactColumns` (3) ถ้าการจองไม่ locked เพิ่ม `rentalColumns` (4) มี `$existing` → `update` ไม่มี → `create`
- **รับเข้า**: `$existing` แถวการจองเดิม หรือ null = สร้างใหม่; `$request` ข้อมูลฟอร์ม
- **คืนค่า**: `['id'=>int,'message'=>string]`; โยน `RuntimeException` เมื่อข้อมูลไม่ถูกต้อง
- **เรียกใช้โดย**: `Admin\BookingController`

#### `private static checkNotEditedElsewhere(?array $existing, Request $request): void`
- **ทำอะไร**: หน้าฟอร์มส่งค่า `rev` (= `updated_at` ตอนเปิดหน้า) มาด้วย ถ้าไม่ตรงกับ `updated_at` ปัจจุบันของการจอง → throw "การจองนี้ถูกแก้ไขจากหน้าอื่นหลังจากที่เปิดหน้านี้ ..." กันบันทึกทับการเปลี่ยนแปลง (เช่น เช่าต่อ รับเงิน) ที่เกิดระหว่างเปิดหน้าค้าง. สร้างใหม่ไม่ตรวจ

#### `private static contactColumns(?array $existing, Request $request): array`
- **ทำอะไร**: อ่านชื่อ (≤150) เบอร์ (≤30) ว่างอย่างใดอย่างหนึ่ง → "กรุณากรอกชื่อและเบอร์โทร"; เบอร์มีตัวเลข < 9 หลัก → "เบอร์โทรต้องมีตัวเลขอย่างน้อย 9 หลัก (ใช้ยืนยันตัวตนตอนลูกค้าตรวจสอบการจอง)". สร้างคอลัมน์ `customer_id` (จาก `findOrCreateCustomerId`), ชื่อ/เบอร์/อีเมล/LINE, `pickup_place`, `return_place`, `note`. ถ้าแก้การจองเดิมแล้วเบอร์ไม่ตรงเดิม (`phone_match`) → ตั้ง `line_user_id = null` (อาจเป็นคนละคน จึงเลิกส่งแจ้งเตือน LINE ไปหาคนเดิม; ลูกค้าผูกใหม่ได้จากหน้า "การจองของฉัน")

#### `private static findOrCreateCustomerId(string $name, string $phone, Request $request): int`
- **ทำอะไร**: หาลูกค้าจากเบอร์ (`Customer::byPhone`) ถ้าเจอใช้ id เดิม (ไม่อัปเดตชื่อ/อีเมล ที่ตารางลูกค้า) ไม่เจอสร้างใหม่ `type='individual'`. (ไม่ตรวจ blacklist ฝั่งแอดมิน)

#### `private static rentalColumns(?array $existing, Request $request): array`
- **ทำอะไร**: ส่วนรถ/วันเวลา/ราคา (ใช้เฉพาะเมื่อไม่ locked)
  1. `Car::find(car_id)` ไม่พบ → "กรุณาเลือกรถ"
  2. `readPeriod` ได้ pickup/return
  3. `isCarAvailable($carId,$pickup,$return,$existingId ?: null)` – ส่ง id ตัวเองเพื่อไม่ให้ใบเดิมบล็อกตัวเอง; ไม่ว่าง → "รถคันนี้ไม่ว่างในช่วงเวลาที่เลือก"
  4. `cleanAddons`; `delivery_km` จำกัด 0–2000; ถ้า `isOutOfRange` → error อธิบายให้ไปเพิ่มช่วงระยะทางในตั้งค่า
  5. `discount = max(0, round(...))`; `quote(... $discount, $request->has('vat'), $km)` – **VAT ถูกกำหนดด้วย checkbox `vat`** (ติ๊ก = คิด VAT ตามอัตราใน setting, ไม่ติ๊ก = ไม่คิด ไม่ว่า setting `vat_enabled` เป็นอะไร)
  6. รวมคอลัมน์ `car_id, pickup_at, return_at, delivery_km, addons` + `priceColumns`
  7. ทับ `deposit_amount` ด้วย `depositAmount()` และ `security_deposit` ด้วย `securityDeposit()`
  - ไม่ทำใน transaction/ล็อกรถ (ต่างจาก `WebBookingService`) – ความเสี่ยง race กรณีแอดมินสองคนจองรถคันเดียวกันพร้อมกัน
- **คืนค่า**: array คอลัมน์ที่จะเขียน

#### `private static readPeriod(Request $request): array`
- **ทำอะไร**: อ่าน `pickup_at`, `return_at` ผ่าน `BookingService::toDb`; ว่าง → "กรุณาเลือกวัน–เวลารับและคืนรถ"; คืน ≤ รับ → "วันคืนรถต้องหลังวันรับรถ"; `rentalDays > 365` → "ระยะเวลาเช่าเกิน 1 ปี ...". ไม่ตรวจเวลาทำการ/ล่วงหน้า (แอดมินทำได้ยืดหยุ่นกว่าลูกค้า)
- **คืนค่า**: `[pickup, return]` รูปแบบ DB

#### `private static depositAmount(Request $request, array $quote): float`
- **ทำอะไร**: ช่อง `deposit_amount` ว่าง = ใช้ค่ามัดจำที่ระบบคิด; มีค่า = `max(0, min(grand_total, ค่าที่กรอก))` (มัดจำเกินยอดรวมไม่ได้)

#### `private static securityDeposit(Request $request, array $quote, bool $isNew): float`
- **ทำอะไร**: ช่องว่าง = ใช้ค่าประกันที่ระบบคิด. มีค่า: `max(0, ค่าที่กรอก)`; แต่ **การจองใหม่ที่กรอก 0 = ใช้ค่าตามเงื่อนไขรถ** ส่วนการแก้การจองเดิมกรอก 0 ได้ (ยกเว้นประกัน)

#### `private static create(array $data, Request $request): array`
- **ทำอะไร**: เติม `booking_no` (`Booking::nextNumber`), `status` (เลือกได้ `pending`/`confirmed`, default `confirmed`), `payment_status='unpaid'`, `source` (`admin|line|phone|web` default `admin`), `created_by = AuthService::id()` แล้ว `Booking::insert` + ActivityLog `booking_created_admin`
- **คืนค่า**: `['id'=>..., 'message'=>'สร้างการจอง BK... แล้ว']`

#### `private static update(array $existing, array $data): array`
- **ทำอะไร**: `Booking::updateById`; แล้ว `PaymentService::recalc` (ยอดรวมอาจเปลี่ยน ต้องคิดสถานะการชำระใหม่); ถ้าไม่ locked เรียก `resetRemindersIfDatesChanged`; ActivityLog `booking_updated` (note 'contact only' ถ้า locked)
- **คืนค่า**: `['id'=>..., 'message'=>'บันทึกการแก้ไขแล้ว']`

#### `private static resetRemindersIfDatesChanged(array $old, array $new): void`
- **ทำอะไร**: ถ้า `pickup_at` เปลี่ยน → `Notification::releaseReminder('cust_pickup_reminder', id)`; ถ้า `return_at` เปลี่ยน → ปลด `cust_return_reminder` เพื่อให้ระบบเตือนลูกค้าตามวันใหม่ได้อีกรอบ

#### `private static pick(string $value, array $allowed, string $default): string`
- **ทำอะไร**: คืน `$value` ถ้าอยู่ใน whitelist (strict) ไม่งั้นคืน `$default` – ใช้กับ `status` และ `source` จากฟอร์ม

---

## app/Services/BookingActionService.php

- **หน้าที่ของไฟล์**: ตัวกระจายคำสั่ง (dispatcher) ของปุ่มบนหน้างานการจองหลังบ้าน: ยืนยัน, ส่งมอบ, รับคืน, เช่าต่อ, ปิดเงินประกัน, ปิดงาน, ยกเลิก, รับเงิน, บันทึกหมายเหตุ, ส่ง/ยกเลิกลิงก์เซ็นเอกสาร. ตรวจ "สถานะปัจจุบันอนุญาตการกระทำนี้ไหม" ก่อนเสมอ เพื่อกันกดย้อน/ข้ามขั้นจากหน้าที่เปิดค้างไว้ (เช่น เปิดสองแท็บ แท็บหนึ่งยกเลิกไปแล้ว)
- **ใช้**: Models `Booking, Car, Inspection, Notification, Payment, Signature, ActivityLog`; Services `ReturnService, ExtendService, SecurityDepositService, PaymentService, InspectionService, NotificationService, LineService, SignatureService, AuthService`
- **เรียกโดย**: `Admin\BookingController` บรรทัด 191 `perform($this->request->str('act'), $b, $car, $this->request)`
- ทุกเมธอดคืน `[tone, message]` (tone: `ok` | `warn`) เป็น flash message; ถ้าทำไม่ได้โยน `RuntimeException`

### class `BookingActionService`
- `ALLOWED_STATUSES` – ตารางสถานะที่อนุญาตของแต่ละ action:
  | action | สถานะที่ทำได้ | เหตุผลทางธุรกิจ |
  |---|---|---|
  | `confirm` | pending | ยืนยันได้ครั้งเดียว |
  | `pickup` | pending, confirmed | ส่งมอบได้แม้ยังไม่กดยืนยัน (ลูกค้า walk-in) |
  | `return` | picked_up | ต้องส่งมอบก่อนจึงรับคืนได้ |
  | `complete` | returned | ปิดงานหลังรับคืน |
  | `cancel` | pending, confirmed | ยกเลิกตอนรถอยู่กับลูกค้าไม่ได้ ไม่งั้นรถจะกลับมา "ว่าง" ในระบบทั้งที่ยังไม่คืน |
  | `extend` | pending, confirmed, picked_up | เลื่อนวันคืนได้ก่อนรับคืน |
  | `security_settle` | returned, completed, cancelled | ปิดเงินประกันได้หลังจบงาน |
  action ที่ไม่อยู่ในตาราง (`payment`, `note`, `sign_send`, `sign_void`) ทำได้ทุกสถานะ

#### `public static perform(string $action, array $booking, array $car, Request $request): array`
- **ทำอะไร**: เรียก `checkStatusAllowsAction` แล้ว `switch` ตาม action: `confirm`→`confirm()`, `pickup`→`pickup()`, `return`→`ReturnService::run`, `extend`→`ExtendService::run`, `security_settle`→`SecurityDepositService::run`, `complete`, `cancel`, `payment`→`addPayment`, `note`→`saveNote`, `sign_send`→`sendSignLink`, `sign_void`→`voidSignature`. action ไม่รู้จัก → `['warn','ไม่รู้จักคำสั่งนี้']`
- **คืนค่า**: `[tone, message]`
- **เรียกใช้โดย**: `Admin\BookingController`

#### `private static checkStatusAllowsAction(string $action, array $booking): void`
- **ทำอะไร**: ถ้า action อยู่ในตารางและสถานะปัจจุบันไม่อยู่ในรายการ → throw `RuntimeException` "ทำรายการนี้ไม่ได้ เพราะการจองอยู่ในสถานะ "<ชื่อสถานะ>" แล้ว — หน้านี้อาจเปิดค้างไว้ กรุณาตรวจสถานะล่าสุด" (ชื่อสถานะจาก helper `booking_status_label`)

#### `private static confirm(array $booking, array $car): array`
- **ทำอะไร**: ตั้ง `status='confirmed'`, log `booking_confirmed`, ส่งแจ้งลูกค้า `NotificationService::bookingConfirmed` (error ถูกกลืน ไม่ทำให้การยืนยันพัง). คืน `['ok','ยืนยันการจองแล้ว']`

#### `private static pickup(array $booking, Request $request): array`
- **ทำอะไร**: ส่งมอบรถ. อ่านเลขไมล์ออก `mileage_out` (≥0) และน้ำมัน `fuel_out` (0–8, default 8). อัปเดต `status='picked_up'`, `mileage_out` (null ถ้า 0), `fuel_out`, `picked_up_at = now`. ถ้ามีไมล์ อัปเดตไมล์ปัจจุบันของรถ (`Car::setMileage`). จากนั้นสร้างใบตรวจสภาพ "ขาออก" ให้แน่ใจว่ามี (`Inspection::ensure`), เติมไมล์/น้ำมันที่ยังว่าง (`fillMissing`) และ **ล็อก** (`InspectionService::lock($id,'out')`) – สภาพรถวันส่งมอบต้องนิ่ง แก้ย้อนหลังไม่ได้ แม้ไม่ได้ถ่ายรูป. log `booking_pickup`. คืน `['ok','บันทึกการส่งมอบรถแล้ว']`
- ไม่ได้ตรวจว่าชำระมัดจำ/ประกันแล้วหรือไม่ (พนักงานตัดสินใจเอง)

#### `private static complete(array $booking): array`
- **ทำอะไร**: ตั้ง `status='completed'`, log. คืน `['ok','ปิดงานเรียบร้อย']` (ไม่ตรวจว่ายอดชำระครบหรือเงินประกันปิดแล้ว)

#### `private static cancel(array $booking, Request $request): array`
- **ทำอะไร**: ตั้ง `status='cancelled'`, `cancel_reason` (จาก `cancel_reason`, ว่างเป็น null), log. คืน `['warn','ยกเลิกการจองแล้ว']`. ไม่คืนเงินให้อัตโนมัติ – ต้องบันทึก payment ชนิด `refund` เอง

#### `private static addPayment(array $booking, Request $request): array`
- **ทำอะไร**: แอดมินบันทึกรับเงินเอง (อนุมัติทันที). ตรวจจำนวน (ปัด 2 ตำแหน่ง): ≤ 0 → "กรุณาระบุจำนวนเงิน"; > 5,000,000 → "จำนวนเงินสูงผิดปกติ ...". insert payments `status='approved'`, `approved_by=AuthService::id()`; `kind` เลือกจาก `deposit|balance|security|extra|refund` (default `balance`), `method` จาก `transfer|cash|card|promptpay` (default `cash`); เรียก `PaymentService::recalc`; log `payment_added`. คืน `['ok','บันทึกการชำระเงินแล้ว']`
- **หมายเหตุ**: `kind='security'` = รับเงินประกัน (ไม่นับเป็นยอดค่าเช่า); `refund` ลดยอดชำระ

#### `private static saveNote(array $booking, Request $request): array`
- **ทำอะไร**: เขียน `note` ใหม่ (ว่าง = null) ทับของเดิมทั้งก้อน. คืน `['ok','บันทึกหมายเหตุแล้ว']`

#### `private static sendSignLink(array $booking, array $car, Request $request): array`
- **ทำอะไร**: ส่งลิงก์เซ็นเอกสาร (สัญญา/ใบรับคืน) ให้ลูกค้าทาง LINE. ตรวจตามลำดับ โยน `RuntimeException`: `SignatureService::blocker` (ข้อความจาก service นั้น) → ลูกค้าเซ็นแล้ว (`Signature::active`) "เอกสารนี้ลูกค้าลงนามแล้ว" → ไม่มี LINE ผูก (`LineService::userForBooking`) "ลูกค้ายังไม่ได้ผูกบัญชี LINE ..." (แนะนำปุ่มคัดลอกลิงก์) → `LineService::notifyCustomer('sign_request', ...)` คืน false (แจ้งเตือนลูกค้าถูกปิด) "การแจ้งเตือนลูกค้าทาง LINE ถูกปิดอยู่ ...". จากนั้น `NotificationService::flush()` ส่งจริงทันทีแล้วอ่าน `Notification::latestFor('cust_sign_request', id)`; ถ้าไม่ `sent` → "ส่ง LINE ไม่สำเร็จ (<error>) — ใช้ปุ่ม "คัดลอกลิงก์" แทนได้". สำเร็จ log `signature_link_sent`. ตัวแปรในข้อความ: `ลิงก์เซ็น`, `เอกสาร`

#### `private static voidSignature(array $booking, Request $request): array`
- **ทำอะไร**: ขอให้ลูกค้าเซ็นใหม่ **เฉพาะเจ้าของร้าน** (`AuthService::isAdmin()` ไม่ใช่ → "การยกเลิกลายเซ็นทำได้เฉพาะเจ้าของร้าน"). ต้องมีลายเซ็นที่ใช้อยู่ (ไม่ใช่ → "ไม่พบลายเซ็นที่ใช้อยู่ของเอกสารนี้") และเหตุผล ≥ 3 ตัวอักษร ("กรุณาระบุเหตุผลที่ขอให้ลูกค้าเซ็นใหม่"). `Signature::void` (ไม่ลบ เก็บเป็นประวัติพร้อมเหตุผล) + log. คืน `['warn', ...]`

#### `private static signatureKind(Request $request): string`
- **ทำอะไร**: อ่าน `kind`; ถ้าอยู่ใน `SignatureService::kinds()` (contract/return) ใช้ค่านั้น ไม่งั้น `'contract'`

#### `private static allowedOrDefault(string $value, array $allowed, string $default): string`
- **ทำอะไร**: whitelist helper เหมือน `AdminBookingService::pick` (โค้ดซ้ำกัน)

---

## app/Services/ReturnService.php

- **หน้าที่ของไฟล์**: รับคืนรถ: บันทึกเลขไมล์เข้า น้ำมันเข้า เวลาคืนจริง คิดค่าใช้จ่ายเพิ่ม (คืนช้า/น้ำมัน/ระยะทาง + ค่าอื่นพนักงานพิมพ์เอง) ล็อกใบตรวจสภาพรถ ตั้งสถานะ `returned` และแจ้งลูกค้า
- **ใช้**: `LateReturnService` (คิดยอดแนะนำ), `PaymentService::recalc`, `InspectionService`, `NotificationService`; Models `Booking, Car, Inspection, ActivityLog`
- **เรียกโดย**: `BookingActionService::perform` (action `return`)

### class `ReturnService`
- `MAX_EXTRA_AMOUNT = 1,000,000` – เพดานค่าใช้จ่ายเพิ่มต่อรายการ (กันพิมพ์ผิด)

#### `public static run(array $booking, array $car, Request $request): array`
- **ทำอะไร**: (1) อ่าน `mileage_in` (≥0) และ `fuel_in` (0–8 default 8) (2) `checkMileage` (3) `returnedAt` (4) `chargeLines` → `extra = ผลรวม amount` (5) `Booking::updateById`: `status='returned'`, `mileage_in` (null ถ้า 0), `fuel_in`, `returned_at`, `extra_charge`, `extra_note` (สรุปสั้น), `extra_breakdown` (JSON หรือ null), `damage_note` (6) อัปเดตไมล์รถถ้า > 0 (7) `PaymentService::recalc` (ยอดที่ต้องจ่ายเปลี่ยน) (8) `lockInspection` (9) ActivityLog `booking_return` (10) `notifyCustomer`
- **รับเข้า**: จากฟอร์ม `mileage_in, fuel_in, rt_manual, returned_at, chg[key][on|amount], other_amount, other_label, damage_note`
- **คืนค่า**: `['ok','บันทึกการรับคืนรถแล้ว' + (extra>0 ? ' — ค่าใช้จ่ายเพิ่ม X บาท' : '')]`; โยน `RuntimeException` จาก `checkMileage`/`returnedAt`
- **เรียกใช้โดย**: `BookingActionService::perform`
- ข้อสังเกต: ถ้าฟอร์มไม่ส่ง `fuel_in` จะถือเป็น 8 (เต็มถัง) จึงไม่มีค่าน้ำมันขาด

#### `private static checkMileage(array $booking, int $mileageIn): void`
- **ทำอะไร**: ถ้าทั้งไมล์เข้าและไมล์ออก > 0 และ `in < out` → throw "เลขไมล์ตอนรับคืน (X) น้อยกว่าตอนส่งมอบ (Y) กรุณาตรวจตัวเลขอีกครั้ง". ถ้าไม่ได้กรอกไมล์ (0) ข้ามการตรวจ

#### `private static returnedAt(array $booking, Request $request): string`
- **ทำอะไร**: ค่า default = เวลาปัจจุบัน. พนักงานแก้เวลาได้เมื่อติ๊ก `rt_manual` (ใช้ย้อนหลัง เช่น รถคืน 18:00 แต่มาบันทึก 21:00 ไม่งั้นลูกค้าโดนค่าปรับคืนช้าทั้งที่คืนตรงเวลา). ถ้าแก้: parse ไม่ได้ → "รูปแบบเวลารับคืนรถไม่ถูกต้อง"; เกินปัจจุบัน +5 นาที → "เวลารับคืนรถอยู่ในอนาคต กรุณาตรวจอีกครั้ง"; ก่อน `picked_up_at` → "เวลารับคืนรถอยู่ก่อนเวลาส่งมอบรถ (<เวลา>)". ถ้าช่องว่าง ใช้ now
- **คืนค่า**: `Y-m-d H:i:s`

#### `private static chargeLines(array $booking, array $car, string $returnedAt, int $mileage, int $fuel, Request $request): array`
- **ทำอะไร**: ให้ระบบคิดยอดแนะนำ `LateReturnService::calculate(...)` (ไมล์ 0 ส่ง null) แล้วแต่ละบรรทัดผสมกับสิ่งที่พนักงานส่งมาใน `chg[key]` ผ่าน `lineFromForm`; ต่อท้ายด้วยรายการ "อื่นๆ" (`otherLine`) ถ้ามี
- **คืนค่า**: รายการ `[key,label,detail,suggested,amount,waived]`

#### `private static lineFromForm(array $line, $formValue): array`
- **ทำอะไร**: ถ้าฟอร์มส่ง array ของรายการนี้: ติ๊ก `on` → ใช้ `amountFromForm` (ช่องว่าง = ใช้ยอดแนะนำ) ; ไม่ติ๊ก = **ยกเว้น** (amount 0). ถ้าฟอร์มไม่มีรายการนี้ (เกิดขึ้นระหว่างเปิดหน้าค้าง) ใช้ยอดที่ระบบคิด. เก็บทั้ง `suggested` (ยอดแนะนำ) และ `amount` (ยอดจริง) พร้อม `waived = amount <= 0` เพื่อตรวจย้อนหลังได้ว่าอะไรถูกยกเว้น

#### `private static amountFromForm($text, float $default): float`
- **ทำอะไร**: ข้อความว่าง = `$default` ไม่งั้น `(float)$text`; ปัด 2 ตำแหน่ง และบีบ 0–`MAX_EXTRA_AMOUNT` (พนักงานปรับเพิ่ม/ลดจากยอดแนะนำได้)

#### `private static otherLine(Request $request): ?array`
- **ทำอะไร**: อ่าน `other_amount` (บีบ 0–1,000,000); ≤ 0 → null. label จาก `other_label` (ตัด 120 ตัวอักษร) หรือ "ค่าใช้จ่ายอื่น" (เช่น ค่าทำความสะอาด); `key='other'`

#### `private static summaryText(array $lines): ?string`
- **ทำอะไร**: ต่อข้อความสรุปสำหรับ `extra_note` เช่น `"น้ำมันขาด 2 ขีด 600 บ. · คืนรถช้า 3 ชม. (ยกเว้น)"` คั่นด้วย ` · ` ตัดไม่เกิน 250 ตัวอักษร; ไม่มีรายการ → null

#### `private static lockInspection(int $bookingId, int $mileage, int $fuel): void`
- **ทำอะไร**: ใบตรวจสภาพขาเข้าต้องมีเสมอและถูกล็อก แม้ไม่ได้ถ่ายรูป: `Inspection::ensure($id,'in',userId)`, `fillMissing` (เติมไมล์/น้ำมันจากฟอร์มรับคืนถ้าหน้าถ่ายรูปไม่ได้กรอก), `InspectionService::lock($id,'in')`

#### `private static notifyCustomer(int $bookingId, array $car, array $lines): void`
- **ทำอะไร**: `NotificationService::bookingReturned(...)` ครอบ try/catch กลืน error

---

## app/Services/ExtendService.php

- **หน้าที่ของไฟล์**: เช่าต่อ/เลื่อนวันคืนรถ. กติกา: (1) วันคืนใหม่ต้องหลังวันคืนเดิม (2) ช่วงที่ขอเพิ่มต้องไม่ชนการจองถัดไปของรถ (นับเวลาพักรถ) (3) นอกเวลาทำการเตือนแต่ไม่ห้าม (4) คิดเงินเฉพาะ **ส่วนต่าง** ของค่าเช่าและบริการเสริมรายวัน (ค่าส่งรถเป็นต่อเที่ยว ไม่คิดซ้ำ) (5) พนักงานลดราคาได้แต่เพิ่มเกินที่ระบบคิดไม่ได้
- **ใช้**: `AvailabilityService`, `PricingService`, `PaymentService`, `LineService`, `NotificationService`; Models `Booking, Car, Notification, ActivityLog`, `Database`
- **เรียกโดย**: `BookingActionService::perform` (action `extend`) และ `Admin\BookingController` บรรทัด 222 (`quote` เพื่อแสดงราคาล่วงหน้าแบบ AJAX)

### class `ExtendService`
- ไม่มี constant

#### `public static run(array $booking, array $car, Request $request): array`
- **ทำอะไร**: `quote(booking, car, new_return)`; ไม่ ok → throw `RuntimeException($quote['error'])`. `chargeToCollect` (ยอดที่เก็บจริง). `saveExtension` (transaction). `PaymentService::recalc`. ปลดเตือนคืนรถเดิม `Notification::releaseReminder('cust_return_reminder')` เพื่อให้ระบบเตือนตามวันใหม่. log `booking_extended` (from, to, charge, computed). `notifyCustomer`
- **รับเข้า**: จากฟอร์ม `new_return` (วันคืนใหม่), `charge` (ยอดที่จะเก็บ ว่าง = ตามระบบ)
- **คืนค่า**: `['ok','เลื่อนกำหนดคืนเป็น <วันที่> แล้ว — ค่าเช่าเพิ่ม X บาท']`
- **เรียกใช้โดย**: `BookingActionService::perform`

#### `public static quote(array $booking, array $car, string $newReturnText): array`
- **ทำอะไร**: คิดเงินเช่าต่อ
  1. parse วันใหม่ไม่ได้ → error "รูปแบบวัน–เวลาไม่ถูกต้อง"
  2. วันใหม่ ≤ วันคืนเดิม → error "วันคืนใหม่ต้องหลังกำหนดคืนเดิม (<เวลา>)"
  3. `AvailabilityService::isCarAvailable(car, return_at เดิม, วันใหม่, ignore = id ตัวเอง)`; ชน → error จาก `busyMessage`
  4. เตือนนอกเวลาทำการ: `validateServiceTime(newReturn, Booking::placeType(return_place ?? pickup_place), 'คืนรถ')` เก็บเป็น `warning` (ไม่ block)
  5. `daysOld`/`daysNew` ด้วย `rentalDays(pickup_at, ...)`
  6. `rentalDelta = calcRental(daysNew) − calcRental(daysOld)`; `addonDelta = calcAddons(addons, daysNew) − calcAddons(addons, daysOld)` (ไม่ส่งกม. → ค่าส่งเป็น 0 ทั้งสองฝั่ง จึงไม่ถูกนับ) ; ทั้งสองบีบไม่ต่ำกว่า 0
  7. `delta = rentalDelta + addonDelta`; `vat = delta × vat_rate_ของการจอง / 100` (ใช้ `bookings.vat_rate` ไม่ใช่ setting ปัจจุบัน); `charge = delta + vat`; `new_total = grand_total + delta + vat`
- **คืนค่า**: สำเร็จ `['ok'=>true,'error'=>null,'days_old','days_new','rental_delta','addon_delta','delta','vat','charge','new_total','new_return','warning'=>?string]`; ล้มเหลว ดู `quoteError`
- **ตัวอย่างตัวเลข** (Yaris 1200/7500/26000, จอง 3 วัน = 3,600): เลื่อนเป็น 8 วัน → calcRental(8) = 8,700 → delta = 8,700 − 3,600 = **5,100** (ถูกกว่า 5×1,200 = 6,000 เพราะเข้าเรตสัปดาห์) ; เลื่อนเป็น 4 วัน + VAT 7% → delta 1,200, vat 84, **charge 1,284**
- **เรียกใช้โดย**: `run`, `saveExtension` (ตรวจซ้ำ), `Admin\BookingController`

#### `private static quoteError(array $booking, string $message): array`
- **ทำอะไร**: สร้างผลลัพธ์ `ok=false` พร้อม `error` และค่าตัวเลขเป็น 0 (`days_old` = `rental_days` เดิม, `new_total` = `grand_total` เดิม). ไม่มีคีย์ `new_return`/`warning` ในกรณีนี้

#### `private static busyMessage(array $booking): string`
- **ทำอะไร**: ข้อความ "รถคันนี้ไม่ว่างถึงเวลาที่ขอ"; ถ้ามีการจองถัดไป (`Booking::nextPickupAfter`) เติม " — มีการจองถัดไปรับรถ <เวลา> และต้องเว้นเวลาพักรถ N ชม." (N = `AvailabilityService::gapHours()`)

#### `private static chargeToCollect(array $quote, Request $request): float`
- **ทำอะไร**: ช่อง `charge` ว่าง = ใช้ `quote['charge']`; มีค่า = `max(0, min(quote['charge'], ค่าที่กรอก))` – ลดราคา/ฟรีได้ แต่เกินที่ระบบคิดไม่ได้ (ถ้าจะคิดเพิ่มให้ใช้ค่าใช้จ่ายเพิ่มตอนรับคืน). ค่าที่ได้ **รวม VAT แล้ว**

#### `private static saveExtension(array $booking, array $car, array $quote, float $charge): void`
- **ทำอะไร**: แยกยอดที่เก็บ (รวม VAT) เป็น `net = charge ÷ (1 + vat_rate/100)` และ `vat = charge − net`. ใน transaction: `Car::lock(car_id)` (กันลูกค้าอื่นจองช่วงเดียวกันพร้อมกัน) → เรียก `quote()` ซ้ำกับการจองสดจาก DB; ไม่ ok → throw error → `Booking::updateById`: `return_at` ใหม่, `rental_days`, `rental_amount += rental_delta`, `addon_amount += addon_delta`, `discount += max(0, delta − net)` (ส่วนที่พนักงานลดให้ถูกบันทึกเป็นส่วนลด), `subtotal += net`, `vat_amount += vat`, `grand_total += charge`, `rate_breakdown` เติมบรรทัดเช่าต่อ. ล้มเหลว → `rollBack` แล้ว throw
- ข้อสังเกต: ผลรวมคำนวณจาก `$booking` ที่โหลดก่อนเข้า transaction (ไม่ใช่ค่าที่อ่านใหม่) ล็อกที่ใช้คือแถวรถ ไม่ใช่แถวการจอง

#### `private static breakdownWithExtension(array $booking, array $quote): array`
- **ทำอะไร**: ถอด `rate_breakdown` เดิม (หรือโครงว่าง) เพิ่มบรรทัด rental "เช่าต่อถึง <วันที่>" (qty = วันที่เพิ่ม ต่ำสุด 1, unit = delta ÷ วันที่เพิ่ม) และถ้า `addon_delta > 0` เพิ่ม addon key `ext` "บริการเสริมช่วงเช่าต่อ (N วัน)"

#### `private static notifyCustomer(int $bookingId, array $car, float $charge): void`
- **ทำอะไร**: `LineService::notifyCustomer('extended', booking, car, ['จำนวน'=>เงิน])`; ถ้าเข้าคิวได้เรียก `NotificationService::flush()` ส่งทันที. ครอบ try/catch กลืน error

---

## app/Services/SecurityDepositService.php

- **หน้าที่ของไฟล์**: ปิดเงินประกัน: หักชำระยอดค้าง (และค่าใช้จ่ายที่เพิ่งพบ) จากเงินประกันที่ร้านถือ แล้วคืนส่วนที่เหลือให้ลูกค้า. บันทึกเป็น payment 2 แบบ: `deduct` (method=deduct, นับเป็นรายได้) และ `security_refund` (คืนลูกค้า ไม่นับรายได้)
- **ใช้**: Models `Booking, Payment, ActivityLog`, `Database`; Services `PaymentService, LineService, NotificationService, AuthService`
- **เรียกโดย**: `BookingActionService::perform` (action `security_settle` สถานะ returned/completed/cancelled)

### class `SecurityDepositService`
- ไม่มี constant

#### `public static run(array $booking, array $car, Request $request): array`
- **ทำอะไร**: เปิด transaction → `Booking::lock($id)` (`SELECT ... FOR UPDATE` แถวการจอง กันกดปิดเงินประกันซ้ำสองครั้งพร้อมกัน) → `settle` → commit; error → rollBack แล้ว throw. หลัง commit: `PaymentService::recalc`, log `security_settled`, `notifyCustomer`
- **รับเข้า**: จากฟอร์ม `add_amount, add_label, deduct, refund_method, refund_note`
- **คืนค่า**: `['ok','ปิดเงินประกันแล้ว — หักชำระ X บาท · คืนลูกค้า Y บาท']`
- **เรียกใช้โดย**: `BookingActionService::perform`

#### `private static settle(int $bookingId, Request $request): array`
- **ทำอะไร**:
  1. `held = Payment::securityHeld` (เงินประกันรับเข้า − คืนแล้ว − หักไปแล้ว); `held <= 0` → throw "ไม่มีเงินประกันที่ร้านถืออยู่สำหรับการจองนี้ (อาจคืนไปแล้ว)"
  2. `owed = amountOwed(booking)` คิด **ก่อน** บวกรายการใหม่ ให้ตรงกับตัวเลขที่พนักงานเห็นบนจอ
  3. `addedAmount = max(0, min(held, add_amount))` ค่าใช้จ่ายใหม่ที่พบหลังรับคืน (เช่น ใบสั่งจราจร ค่าซ่อม) ถูกจำกัดไม่เกินเงินประกันที่ถือ; ถ้า > 0 เรียก `addExtraCharge` (ต้องมีชื่อรายการ)
  4. `chosen = max(0, min(owed, deduct))` ส่วนที่พนักงานเลือกหักจากยอดค้างเดิม
  5. `deduct = max(0, min(held, addedAmount + chosen))`; `refund = held − deduct`
  6. `method = refundMethod`; `recordPayments`
- **คืนค่า**: `['held','deduct','refund','method','added','added_label']`
- **ตัวอย่างตัวเลข**: ประกันที่ถือ 3,000; ยอดค้าง (ค่าน้ำมัน 600 ที่ยังไม่ได้เก็บ) 600; พนักงานเลือกหัก 600 และเพิ่มใบสั่ง 400 → addedAmount 400, chosen 600, deduct 1,000, refund **2,000**

#### `private static amountOwed(array $booking): float`
- **ทำอะไร**: ยอดที่ลูกค้ายังค้าง. ถ้าการจอง `cancelled`: ค่าเช่าที่ไม่ได้จ่ายไม่ใช่หนี้ (ไม่ได้เช่าจริง) นับเฉพาะ `max(0, extra_charge − max(0,paid_amount))`. กรณีอื่น: `max(0, grand_total + extra_charge − paid_amount)`

#### `private static addExtraCharge(int $bookingId, string $label, float $amount): void`
- **ทำอะไร**: ต้องมีชื่อรายการ ไม่งั้น throw "กรุณาระบุว่าค่าใช้จ่ายที่หักเพิ่มคือค่าอะไร". เพิ่มบรรทัด `key='after'` (detail "บันทึกหลังรับคืนรถ <เวลา>") ต่อ `extra_breakdown` เดิม (`Booking::extraLines`), บวก `extra_charge`, ต่อ `extra_note` (ตัด 250 ตัวอักษร) – บันทึกเป็นค่าใช้จ่ายของการจองก่อน แล้วจึงหักจากประกัน เพื่อให้ตรวจย้อนหลังได้ว่าเงินที่หักมาจากอะไร

#### `private static refundMethod(Request $request): string`
- **ทำอะไร**: `refund_method` ต้องเป็น `cash|transfer|promptpay` ไม่งั้น `cash`

#### `private static recordPayments(int $bookingId, float $deduct, float $refund, string $method, float $addedAmount, string $addedLabel, Request $request): void`
- **ทำอะไร**: ฐานของ payment: approved ทันที โดย `AuthService::id()`. ถ้า `deduct > 0` → insert `method='deduct'`, `kind` = `extra` ถ้าการจองมี `extra_charge > 0` (อ่านใหม่จาก DB) ไม่เช่นนั้น `balance`, note "หักจากเงินประกัน[ — ชื่อรายการ]". ถ้า `refund > 0` → insert `kind='security_refund'`, `method` ที่เลือก, note จาก `refund_note` หรือ "คืนเงินประกัน"

#### `private static notifyCustomer(int $bookingId, array $car, array $result): void`
- **ทำอะไร**: `LineService::notifyCustomer('deposit_refunded', ..., ['คืนเงิน'=>..., 'หัก'=>...])` ถ้าเข้าคิว → `NotificationService::flush()`; กลืน error

---

## ข้อสังเกต / ความไม่สอดคล้องที่พบ (ยังไม่ได้แก้โค้ด)

1. `PricingService::addonCatalog()` และ `calcAddons()` hard-code ราคา child_seat 150 / insurance_plus 300 / gps 100 ซ้ำกันสองที่ แก้ที่หนึ่งแล้วอีกที่ไม่ตามจะแสดงราคาไม่ตรงกับที่คิดจริง
2. `PricingService::GRACE_HOURS` (2 ชม., fix) ใช้นับวันเช่า ส่วน `LateReturnService::policy()['grace']` อ่านจาก setting `late_grace_hours` – ถ้าเจ้าของร้านเปลี่ยน setting นี้ กติกานับวันเช่ากับกติกาค่าปรับคืนช้าจะไม่สอดคล้องกัน
3. คอมเมนต์ใน `WebBookingService::saveWithRetry` บอกว่าเลขที่จองมาจาก "เลขล่าสุด + 1" แต่ `Booking::nextNumber()` ใช้ตัวนับ `Sequence::next` (กันซ้ำอยู่แล้ว) – การ retry จึงเป็นเพียงด่านสำรอง และ retry ทุก `PDOException` รหัส 23000 (รวม constraint อื่น)
4. `AdminBookingService::rentalColumns` ใช้ `$request->has('vat')` ส่ง true/false เสมอ (ไม่เคยส่ง null) setting `vat_enabled` จึงไม่มีผลกับการจองจากหลังบ้านถ้าฟอร์มไม่ติ๊ก VAT; และเมื่อแก้การจองที่ยังไม่ locked ถ้าล้างช่องมัดจำ/ประกันไว้ว่าง ค่าที่แอดมินเคยกรอกเองจะถูกแทนที่ด้วยค่าที่ระบบคิดใหม่
5. `AdminBookingService::rentalColumns` ไม่ใช้ transaction/`Car::lock` (ต่างจาก `WebBookingService` และ `ExtendService`) – แอดมินสองคนบันทึกจองรถคันเดียวกันพร้อมกันอาจชนกันได้
6. `ExtendService::saveExtension` บวกยอดจาก `$booking` ที่อ่านก่อนเข้า transaction และล็อกเฉพาะแถวรถ (ไม่ใช่แถวการจอง) ถ้าการจองถูกแก้ระหว่างนั้นยอดอาจ lost update (ลดความเสี่ยงบางส่วนด้วย `rev` ที่หน้าแก้ไข แต่ไม่ครอบคลุมปุ่มเช่าต่อ); `quoteError()` ไม่มีคีย์ `new_return`/`warning`
7. `SecurityDepositService::amountOwed` ใช้ `max(0, paid_amount)` เฉพาะกรณี cancelled แต่กรณีปกติใช้ `paid_amount` ตรงๆ (ไม่ผิดตรรกะ แต่ไม่สม่ำเสมอ)
8. `PaymentService::approve/reject` อ่านสถานะ `pending` แล้วค่อยเปลี่ยน โดยไม่ล็อกแถว – แอดมินสองคนกดอนุมัติสลิปเดียวกันพร้อมกันอาจผ่านทั้งคู่ (ขึ้นกับ `Payment::approve` ว่ากรองสถานะใน SQL หรือไม่ ซึ่งไม่ได้อยู่ในขอบเขตไฟล์นี้). และ auto-confirm ตรวจ `paid_amount >= deposit_amount` ถ้า `deposit_amount = 0` การอนุมัติสลิปใดๆ จะยืนยันการจองทันที
9. `ReturnService::run` ถ้าฟอร์มไม่ส่ง `fuel_in` จะตีเป็นน้ำมันเต็ม (8) → ไม่มีค่าน้ำมันขาด; `LateReturnService::lateLine` ใช้เรตรายวันเสมอแม้การจองเช่าด้วยเรตสัปดาห์/เดือน
10. `PricingService::emptyQuote()` ไม่มีคีย์ `balance_on_pickup`, `due_on_pickup`, `rental_amount`, `addon_amount` ที่ `quote()` มี – view ที่อ่านคีย์เหล่านี้โดยไม่เช็ก days = 0 อาจเกิด notice
