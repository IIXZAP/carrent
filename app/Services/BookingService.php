<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Inspection;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Signature;

/**
 * กฎธุรกิจของการจอง: จองจากหน้าเว็บ, สร้าง/แก้จากหลังบ้าน, และขั้นตอนทำงาน
 * (ยืนยัน → ส่งมอบ → รับคืน → ปิดงาน / ยกเลิก / เช่าต่อ / ปิดเงินประกัน / เซ็นเอกสาร)
 *
 * เมธอดที่เปลี่ยนข้อมูลจะโยน \RuntimeException พร้อมข้อความที่ตั้งใจให้ผู้ใช้อ่านได้
 * (ข้อผิดพลาดจากฐานข้อมูลถูกซ่อน — ใช้ BookingService::publicError)
 */
final class BookingService
{
    /** ข้อความแจ้งผู้ใช้เมื่อเกิดข้อผิดพลาด — ข้อความที่ระบบตั้งใจบอก (RuntimeException) แสดงตามนั้น ส่วนข้อผิดพลาดจากฐานข้อมูล/โค้ดบันทึกลง log แล้วแสดงข้อความกลางแทน */
    public static function publicError(\Throwable $e, string $fallback = 'ทำรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'): string
    {
        if ($e instanceof \RuntimeException && !($e instanceof \PDOException)) {
            return $e->getMessage();
        }
        error_log('[thonglorcarrent] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return $fallback;
    }

    /** แปลงค่าจำนวนบริการเสริมจากฟอร์ม: 0–5 ต่อรายการ (คาร์ซีทเลือกได้หลายตัว ที่เหลือเป็นเลือก/ไม่เลือก) */
    public static function cleanAddons(array $raw): array
    {
        return array_map(static fn($v) => max(0, min(5, (int) (is_scalar($v) ? $v : 0))), $raw);
    }

    private static function toDb(string $dt): string
    {
        $ts = $dt !== '' ? strtotime($dt) : false;
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : '';
    }

    // ============================================================
    // จองจากหน้าเว็บลูกค้า
    // ============================================================
    /**
     * @param array $in ค่าจากฟอร์ม: pickup, ret, name, phone, email, line, note, place_pick, place_ret,
     *                  addons, km, pick_type, ret_type, accept, liff_uid
     * @return array{errors:string[],booking:?array,blacklisted:bool}
     */
    public static function createFromWeb(array $car, array $in, string $ip): array
    {
        $carId  = (int) $car['id'];
        $errors = [];

        $phone = $in['phone'];
        if ($in['name'] === '' || mb_strlen($in['name']) < 2) {
            $errors[] = 'กรุณากรอกชื่อ–นามสกุล';
        }
        if (!preg_match('/^[0-9\-\+\s]{9,20}$/', $phone) || strlen(phone_digits($phone)) < 9) {
            $errors[] = 'เบอร์โทรศัพท์ไม่ถูกต้อง';
        }
        if ($in['email'] !== '' && !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'อีเมลไม่ถูกต้อง';
        }
        if (empty($in['accept'])) {
            $errors[] = 'กรุณายอมรับเงื่อนไขการเช่า';
        }

        $pickDb = self::toDb($in['pickup']);
        $retDb  = self::toDb($in['ret']);
        if (!$pickDb || !$retDb) {
            $errors[] = 'กรุณาเลือกวัน–เวลารับและคืนรถ';
        } elseif ($msg = AvailabilityService::validatePeriod($pickDb, $retDb, $in['pick_type'], $in['ret_type'])) {
            $errors[] = $msg;
        } elseif (!AvailabilityService::isCarAvailable($carId, $pickDb, $retDb)) {
            $errors[] = 'ขออภัย รถคันนี้เพิ่งถูกจองในช่วงเวลาดังกล่าว กรุณาเลือกวันใหม่';
        }

        if (PricingService::deliveryOutOfRange($in['addons'], $in['km'])) {
            $errors[] = 'ระยะทาง ' . number_format($in['km']) . ' กม. อยู่นอกพื้นที่ที่คิดค่าส่งรถอัตโนมัติ กรุณาติดต่อเจ้าหน้าที่ ' . setting('phone') . ' เพื่อขอราคา';
        }

        // ---------- กันการจองถี่ผิดปกติ ----------
        // การจองที่ยังไม่จ่ายมัดจำก็กันรถไว้ไม่ให้คนอื่นจอง — ถ้าไม่จำกัด คนเดียวจองรัวๆ ก็ทำให้รถทั้งร้าน "ไม่ว่าง" ได้
        if (!$errors) {
            $openByPhone = Booking::openUnpaidCountByPhone($phone);
            $byIp = ActivityLog::bookingsFromIpSince($ip, date('Y-m-d H:i:s', time() - 3600));
            if ($openByPhone >= 3) {
                $errors[] = 'เบอร์นี้มีการจองที่ยังไม่ได้ชำระมัดจำอยู่ ' . $openByPhone . ' รายการ กรุณาชำระมัดจำรายการเดิมก่อน หรือติดต่อเจ้าหน้าที่ ' . setting('phone');
            } elseif ($byIp >= 8) {
                $errors[] = 'มีการจองจากอุปกรณ์นี้หลายรายการในเวลาใกล้กัน กรุณาลองใหม่ภายหลัง หรือติดต่อเจ้าหน้าที่ ' . setting('phone');
            }
        }
        if ($errors) {
            return ['errors' => $errors, 'booking' => null, 'blacklisted' => false];
        }

        // ---------- คำนวณราคา ----------
        $q = PricingService::quote($car, $pickDb, $retDb, $in['addons'], 0.0, null, $in['km']);

        // ---------- ลูกค้า: หาเดิมจากเบอร์ ไม่มีก็สร้างใหม่ ----------
        $customer   = Customer::byPhone($phone);
        $customerId = $customer['id'] ?? null;
        if ($customer && (int) $customer['is_blacklist'] === 1) {
            return ['errors' => ['ไม่สามารถจองออนไลน์ได้ กรุณาติดต่อเจ้าหน้าที่ ' . setting('phone')], 'booking' => null, 'blacklisted' => true];
        }

        // ---------- บันทึกการจอง (ใช้ transaction กันจองชนกัน) ----------
        $bookingId = 0;
        // เลขที่จองออกจาก "เลขล่าสุด + 1" — ถ้ามีสองคนจองพร้อมกันพอดีจะได้เลขซ้ำ ระบบจึงลองใหม่ให้เองสูงสุด 3 รอบ
        for ($try = 1; $try <= 3 && !$bookingId; $try++) {
            Database::begin();
            try {
                // ล็อกแถวของรถคันนี้ไว้จนบันทึกเสร็จ — คนที่จองรถคันเดียวกันพร้อมกันต้องรอคิว แล้วจะเห็นว่ารถถูกจองไปแล้ว
                Car::lock($carId);
                if (!AvailabilityService::isCarAvailable($carId, $pickDb, $retDb)) {
                    throw new \RuntimeException('รถคันนี้เพิ่งถูกจองไปแล้ว กรุณาเลือกวันใหม่');
                }
                if (!$customerId) {
                    $customerId = Customer::create([
                        'type'      => 'individual',
                        'full_name' => $in['name'],
                        'phone'     => $phone,
                        'email'     => $in['email'] ?: null,
                        'line_id'   => $in['line'] ?: null,
                    ]);
                }
                $bookingId = Booking::insert([
                    'booking_no'       => Booking::nextNumber(),
                    'customer_id'      => $customerId,
                    'car_id'           => $carId,
                    'contact_name'     => $in['name'],
                    'contact_phone'    => $phone,
                    'contact_email'    => $in['email'] ?: null,
                    'contact_line'     => $in['line'] ?: null,
                    'pickup_at'        => $pickDb,
                    'return_at'        => $retDb,
                    // เก็บประเภทจุดไว้หน้าข้อความ เพื่อให้แอดมินเห็นชัดว่าเป็นสนามบินหรือไม่
                    // (ใช้กับกติกาเวลาทำการ และเป็นข้อมูลให้คนไปรับ–ส่งรถ)
                    'pickup_place'     => trim(($in['pick_type'] === 'airport' ? '[สนามบิน] ' : '') . $in['place_pick']) ?: null,
                    'return_place'     => trim(($in['ret_type'] === 'airport' ? '[สนามบิน] ' : '') . $in['place_ret']) ?: null,
                    'delivery_km'      => $in['km'] > 0 ? $in['km'] : null,
                    // จองจากในแอป LINE (เซสชันนี้ผ่านการยืนยันตัวตนกับ LINE มาแล้วในหน้า "การจองของฉัน")
                    // → ผูกการจองกับ LINE นั้นทันที ลูกค้าได้ข้อความยืนยันและเห็นการจองโดยไม่ต้องกรอกอะไรอีก
                    'line_user_id'     => preg_match('/^U[0-9a-f]{32}$/', (string) $in['liff_uid']) ? $in['liff_uid'] : null,
                    'rental_days'      => $q['days'],
                    'rate_breakdown'   => json_encode(['rental' => $q['rental_lines'], 'addons' => $q['addon_lines']], JSON_UNESCAPED_UNICODE),
                    'rental_amount'    => $q['rental_amount'],
                    'addon_amount'     => $q['addon_amount'],
                    'discount'         => 0,
                    'subtotal'         => $q['subtotal'],
                    'vat_rate'         => $q['vat_rate'],
                    'vat_amount'       => $q['vat_amount'],
                    'grand_total'      => $q['grand_total'],
                    'deposit_amount'   => $q['deposit_amount'],
                    'security_deposit' => $q['security_deposit'],
                    'addons'           => json_encode($in['addons'], JSON_UNESCAPED_UNICODE),
                    'status'           => 'pending',
                    'payment_status'   => 'unpaid',
                    'source'           => 'web',
                    'note'             => $in['note'] ?: null,
                ]);
                Database::commit();
            } catch (\Throwable $ex) {
                Database::rollBack();
                $bookingId  = 0;
                $customerId = $customer['id'] ?? null;      // ลูกค้าใหม่ที่เพิ่งสร้างถูกย้อนกลับไปด้วย
                $dupNo = $ex instanceof \PDOException && (string) $ex->getCode() === '23000';
                if ($dupNo && $try < 3) {
                    usleep(random_int(20000, 120000));
                    continue;
                }
                // ข้อความจากระบบเองแสดงได้ — ข้อผิดพลาดจากฐานข้อมูลบันทึกลง log ไม่แสดงให้ลูกค้าเห็น
                return [
                    'errors' => [self::publicError($ex, 'บันทึกการจองไม่สำเร็จ กรุณาลองใหม่อีกครั้ง หรือติดต่อเจ้าหน้าที่ ' . setting('phone'))],
                    'booking' => null, 'blacklisted' => false,
                ];
            }
        }

        $booking = Booking::find($bookingId);
        ActivityLog::log('booking_created', 'bookings', $bookingId, $booking['booking_no']);
        try {
            NotificationService::newBooking($booking, $car);
        } catch (\Throwable $ex) {
            // แจ้งเตือนล้มเหลวไม่ควรทำให้การจองพัง
        }
        return ['errors' => [], 'booking' => $booking, 'blacklisted' => false];
    }

    // ============================================================
    // สร้าง / แก้ไขจากหลังบ้าน
    // ============================================================
    /** หลังส่งมอบรถแล้ว รถ วัน–เวลา และราคา ผูกกับผลตรวจสภาพรถ เลขไมล์ และค่าใช้จ่ายตอนคืนรถไปแล้ว */
    public static function isLocked(?array $b): bool
    {
        return $b !== null && in_array($b['status'], ['picked_up', 'returned', 'completed', 'cancelled'], true);
    }

    /**
     * บันทึกการจองจากหลังบ้าน (สร้างใหม่ถ้า $existing = null)
     * @return array{id:int,message:string}
     */
    public static function saveFromAdmin(?array $existing, Request $r): array
    {
        $id     = $existing ? (int) $existing['id'] : 0;
        $locked = self::isLocked($existing);

        // หน้านี้เปิดค้างไว้แล้วมีคนแก้การจองจากที่อื่น (เช่น กดเช่าต่อ รับเงิน) — ถ้าบันทึกทับ ข้อมูลใหม่จะหาย
        if ($existing && (string) $r->post('rev', '') !== (string) $existing['updated_at']) {
            throw new \RuntimeException('การจองนี้ถูกแก้ไขจากหน้าอื่นหลังจากที่เปิดหน้านี้ กรุณาตรวจข้อมูลล่าสุดด้านล่างแล้วแก้ไขใหม่อีกครั้ง');
        }
        $name  = $r->str('contact_name', 150);
        $phone = $r->str('contact_phone', 30);
        if ($name === '' || $phone === '') {
            throw new \RuntimeException('กรุณากรอกชื่อและเบอร์โทร');
        }
        if (strlen(phone_digits($phone)) < 9) {
            throw new \RuntimeException('เบอร์โทรต้องมีตัวเลขอย่างน้อย 9 หลัก (ใช้ยืนยันตัวตนตอนลูกค้าตรวจสอบการจอง)');
        }

        // ผูกลูกค้าจากเบอร์โทร
        $customer   = Customer::byPhone($phone);
        $customerId = $customer['id'] ?? null;
        if (!$customerId) {
            $customerId = Customer::create([
                'type' => 'individual', 'full_name' => $name, 'phone' => $phone,
                'email' => $r->str('contact_email') ?: null, 'line_id' => $r->str('contact_line') ?: null,
            ]);
        }
        $data = [
            'customer_id'   => $customerId,
            'contact_name'  => $name,
            'contact_phone' => $phone,
            'contact_email' => $r->str('contact_email', 150) ?: null,
            'contact_line'  => $r->str('contact_line', 80) ?: null,
            'pickup_place'  => $r->str('pickup_place', 250) ?: null,
            'return_place'  => $r->str('return_place', 250) ?: null,
            'note'          => $r->str('note', 1000) ?: null,
        ];
        // เปลี่ยนเบอร์ = อาจเป็นคนละคน → เลิกส่งแจ้งเตือน LINE ไปหาคนเดิม (ลูกค้าผูกใหม่ได้จากหน้า "การจองของฉัน")
        if ($existing && !phone_match($existing['contact_phone'], $phone)) {
            $data['line_user_id'] = null;
        }

        if (!$locked) {
            $carId = $r->int('car_id');
            $car   = Car::find($carId);
            if (!$car) {
                throw new \RuntimeException('กรุณาเลือกรถ');
            }
            $pTs = strtotime($r->str('pickup_at'));
            $rTs = strtotime($r->str('return_at'));
            if ($pTs === false || $rTs === false) {
                throw new \RuntimeException('กรุณาเลือกวัน–เวลารับและคืนรถ');
            }
            $pickDb = date('Y-m-d H:i:s', $pTs);
            $retDb  = date('Y-m-d H:i:s', $rTs);
            if ($rTs <= $pTs) {
                throw new \RuntimeException('วันคืนรถต้องหลังวันรับรถ');
            }
            if (PricingService::rentalDays($pickDb, $retDb) > 365) {
                throw new \RuntimeException('ระยะเวลาเช่าเกิน 1 ปี กรุณาตรวจวันที่อีกครั้ง');
            }
            if (!AvailabilityService::isCarAvailable($carId, $pickDb, $retDb, $id ?: null)) {
                throw new \RuntimeException('รถคันนี้ไม่ว่างในช่วงเวลาที่เลือก');
            }

            $addons   = self::cleanAddons($r->arr('addons'));
            $discount = max(0, round($r->float('discount'), 2));
            $vat      = $r->has('vat');
            $km       = max(0, min(2000, $r->float('delivery_km')));
            if (PricingService::deliveryOutOfRange($addons, $km)) {
                throw new \RuntimeException('ระยะทาง ' . number_format($km) . ' กม. เกินตารางค่าส่งรถที่ตั้งไว้ ระบบจึงคิดค่าส่งให้ไม่ได้ — เพิ่มช่วงระยะทางที่ หลังบ้าน → ตั้งค่า หรือใส่ระยะทางไม่เกินช่วงสุดท้ายของตาราง');
            }
            $q = PricingService::quote($car, $pickDb, $retDb, $addons, $discount, $vat, $km);

            // เงินประกัน: เว้นว่างหรือใส่ 0 ตอนสร้างใหม่ = ใช้ตามเงื่อนไขของรถคันนั้น
            $secIn = trim((string) $r->post('security_deposit', ''));
            $sec   = $secIn === '' ? (float) $q['security_deposit'] : max(0, (float) $secIn);
            if (!$id && $sec <= 0) {
                $sec = (float) $q['security_deposit'];
            }
            $depIn = trim((string) $r->post('deposit_amount', ''));
            $dep   = $depIn === '' ? (float) $q['deposit_amount'] : max(0, min((float) $q['grand_total'], (float) $depIn));

            $data += [
                'car_id'           => $carId,
                'pickup_at'        => $pickDb,
                'return_at'        => $retDb,
                'rental_days'      => $q['days'],
                'rate_breakdown'   => json_encode(['rental' => $q['rental_lines'], 'addons' => $q['addon_lines']], JSON_UNESCAPED_UNICODE),
                'rental_amount'    => $q['rental_amount'],
                'addon_amount'     => $q['addon_amount'],
                'discount'         => $q['discount'],
                'subtotal'         => $q['subtotal'],
                'vat_rate'         => $q['vat_rate'],
                'vat_amount'       => $q['vat_amount'],
                'grand_total'      => $q['grand_total'],
                'deposit_amount'   => $dep,
                'security_deposit' => $sec,
                'delivery_km'      => $km > 0 ? $km : null,
                'addons'           => json_encode($addons, JSON_UNESCAPED_UNICODE),
            ];
        }

        if ($id) {
            Booking::updateById($id, $data);
            // ยอดรวมอาจเปลี่ยน → สถานะการชำระเงินต้องคิดใหม่ (ไม่งั้นขึ้น "ชำระครบ" ทั้งที่ยังค้าง)
            PaymentService::recalc($id);
            if (!$locked) {
                // วันรับ/คืนเปลี่ยน → ปลดเครื่องหมาย "เตือนแล้ว" ให้ระบบเตือนลูกค้าตามวันใหม่
                if ($data['pickup_at'] !== $existing['pickup_at']) {
                    Notification::releaseReminder('cust_pickup_reminder', $id);
                }
                if ($data['return_at'] !== $existing['return_at']) {
                    Notification::releaseReminder('cust_return_reminder', $id);
                }
            }
            ActivityLog::log('booking_updated', 'bookings', $id, $locked ? 'contact only' : null);
            return ['id' => $id, 'message' => 'บันทึกการแก้ไขแล้ว'];
        }

        $data['booking_no']     = Booking::nextNumber();
        $data['status']         = in_array($r->str('status'), ['pending', 'confirmed'], true) ? $r->str('status') : 'confirmed';
        $data['payment_status'] = 'unpaid';
        $data['source']         = in_array($r->str('source'), ['admin', 'line', 'phone', 'web'], true) ? $r->str('source') : 'admin';
        $data['created_by']     = AuthService::id();
        $newId = Booking::insert($data);
        ActivityLog::log('booking_created_admin', 'bookings', $newId, $data['booking_no']);
        return ['id' => $newId, 'message' => 'สร้างการจอง ' . $data['booking_no'] . ' แล้ว'];
    }

    // ============================================================
    // ขั้นตอนทำงานของการจอง (หน้างานหลังบ้าน)
    // ============================================================
    /** สถานะที่อนุญาตให้ทำแต่ละการกระทำ — กันกดย้อนขั้นหรือข้ามขั้นจากหน้าที่เปิดค้างไว้ */
    private const ALLOWED = [
        'confirm'         => ['pending'],
        'pickup'          => ['pending', 'confirmed'],
        'return'          => ['picked_up'],
        'complete'        => ['returned'],
        // ยกเลิกได้เฉพาะก่อนส่งมอบรถ — ถ้ายกเลิกตอนรถอยู่กับลูกค้า รถจะกลับมา "ว่าง" ในระบบทั้งที่ยังไม่ได้คืน
        'cancel'          => ['pending', 'confirmed'],
        'extend'          => ['pending', 'confirmed', 'picked_up'],
        'security_settle' => ['returned', 'completed', 'cancelled'],
    ];

    /**
     * ทำการกระทำกับการจอง คืน [โทน flash, ข้อความ]
     * @return array{0:string,1:string}
     */
    public static function perform(string $act, array $b, array $car, Request $r): array
    {
        if (isset(self::ALLOWED[$act]) && !in_array($b['status'], self::ALLOWED[$act], true)) {
            [$cur] = booking_status_label($b['status']);
            throw new \RuntimeException('ทำรายการนี้ไม่ได้ เพราะการจองอยู่ในสถานะ "' . $cur . '" แล้ว — หน้านี้อาจเปิดค้างไว้ กรุณาตรวจสถานะล่าสุด');
        }
        return match ($act) {
            'confirm'         => self::confirm($b, $car),
            'pickup'          => self::pickup($b, $r),
            'return'          => self::returnCar($b, $car, $r),
            'extend'          => self::extend($b, $car, $r),
            'security_settle' => self::settleSecurity($b, $car, $r),
            'sign_send'       => self::sendSignLink($b, $car, $r),
            'sign_void'       => self::voidSignature($b, $r),
            'complete'        => self::complete($b),
            'cancel'          => self::cancel($b, $r),
            'payment'         => self::addPayment($b, $r),
            'note'            => self::saveNote($b, $r),
            default           => ['warn', 'ไม่รู้จักคำสั่งนี้'],
        };
    }

    private static function confirm(array $b, array $car): array
    {
        $id = (int) $b['id'];
        Booking::updateById($id, ['status' => 'confirmed']);
        ActivityLog::log('booking_confirmed', 'bookings', $id);
        $fresh = Booking::find($id);
        try {
            NotificationService::bookingConfirmed($fresh, $car);
        } catch (\Throwable $e) {
        }
        return ['ok', 'ยืนยันการจองแล้ว'];
    }

    private static function pickup(array $b, Request $r): array
    {
        $id   = (int) $b['id'];
        $km   = max(0, $r->int('mileage_out'));
        $fuel = max(0, min(8, $r->int('fuel_out', 8)));
        Booking::updateById($id, [
            'status'       => 'picked_up',
            'mileage_out'  => $km ?: null,
            'fuel_out'     => $fuel,
            'picked_up_at' => date('Y-m-d H:i:s'),
        ]);
        if ($km > 0) {
            Car::setMileage((int) $b['car_id'], $km);
        }
        // ส่งมอบรถแล้ว = สภาพรถ ณ วันส่งมอบต้องนิ่ง แก้ย้อนหลังไม่ได้
        // ถ้าพนักงานถ่ายรูปแต่ไม่ได้กรอกเลขไมล์ในหน้าถ่ายรูป ให้ใช้ค่าจากฟอร์มส่งมอบเติมก่อนล็อก
        // ไม่ได้ถ่ายรูปก็ต้องมีบันทึกรอบส่งมอบ (เลขไมล์ + น้ำมัน) และถูกล็อกเหมือนกัน
        Inspection::ensure($id, 'out', AuthService::id());
        Inspection::fillMissing($id, 'out', $km ?: null, $fuel);
        InspectionService::lock($id, 'out');
        ActivityLog::log('booking_pickup', 'bookings', $id, $km);
        return ['ok', 'บันทึกการส่งมอบรถแล้ว'];
    }

    private static function returnCar(array $b, array $car, Request $r): array
    {
        $id     = (int) $b['id'];
        $km     = max(0, $r->int('mileage_in'));
        $fuelIn = max(0, min(8, $r->int('fuel_in', 8)));
        if ($km > 0 && (int) $b['mileage_out'] > 0 && $km < (int) $b['mileage_out']) {
            throw new \RuntimeException('เลขไมล์ตอนรับคืน (' . number_format($km) . ') น้อยกว่าตอนส่งมอบ ('
                . number_format((int) $b['mileage_out']) . ') กรุณาตรวจตัวเลขอีกครั้ง');
        }
        // เวลารับคืนจริง — ค่าเริ่มต้นคือตอนนี้ แต่แก้ย้อนหลังได้ (รถคืน 18:00 พนักงานมาบันทึก 21:00
        // ถ้าใช้เวลาที่กดบันทึก ลูกค้าจะโดนค่าปรับคืนช้าทั้งที่คืนตรงเวลา)
        $now = date('Y-m-d H:i:s');
        // ช่องเวลาในฟอร์มถูกเติมไว้ตอนเปิดหน้า — ถ้าพนักงานไม่ได้แก้ ให้ใช้เวลาที่กดบันทึกจริง
        // (หน้าเปิดค้างไว้ 2 ชั่วโมงแล้วค่อยกดบันทึก ต้องไม่ทำให้ลูกค้าที่คืนช้ารอดค่าปรับ)
        $at = $r->bool('rt_manual') ? $r->str('returned_at') : '';
        if ($at !== '') {
            $ts = strtotime($at);
            if ($ts === false) {
                throw new \RuntimeException('รูปแบบเวลารับคืนรถไม่ถูกต้อง');
            }
            if ($ts > time() + 300) {
                throw new \RuntimeException('เวลารับคืนรถอยู่ในอนาคต กรุณาตรวจอีกครั้ง');
            }
            if (!empty($b['picked_up_at']) && $ts < strtotime($b['picked_up_at'])) {
                throw new \RuntimeException('เวลารับคืนรถอยู่ก่อนเวลาส่งมอบรถ (' . thai_date($b['picked_up_at'], true) . ')');
            }
            $now = date('Y-m-d H:i:s', $ts);
        }

        // ยอดแนะนำคิดจากข้อมูลจริง ณ เวลาที่กดบันทึก — พนักงานแก้จำนวนหรือยกเว้นได้ในฟอร์ม
        // เก็บทั้ง "ยอดแนะนำ" และ "ยอดที่คิดจริง" เพื่อให้ตรวจย้อนหลังได้ว่ารายการไหนถูกยกเว้น
        $suggest = PricingService::calcReturnCharges($b, $car, $now, $km ?: null, $fuelIn);
        $posted  = $r->arr('chg');
        $lines   = [];
        foreach ($suggest['lines'] as $l) {
            $in = $posted[$l['key']] ?? null;
            if (is_array($in)) {
                $on  = !empty($in['on']);
                $amt = $on ? max(0, min(1000000, round((float) (($in['amount'] ?? '') !== '' ? $in['amount'] : $l['amount']), 2))) : 0.0;
            } else {
                $on  = true;              // รายการที่เพิ่งเกิดระหว่างเปิดหน้าค้างไว้ → คิดตามเงื่อนไข
                $amt = $l['amount'];
            }
            $lines[] = ['key' => $l['key'], 'label' => $l['label'], 'detail' => $l['detail'],
                        'suggested' => $l['amount'], 'amount' => $amt, 'waived' => !$on || $amt <= 0];
        }
        $otherAmt = max(0, min(1000000, round($r->float('other_amount'), 2)));
        $otherLbl = $r->str('other_label');
        if ($otherAmt > 0) {
            $lines[] = ['key' => 'other', 'label' => $otherLbl !== '' ? mb_substr($otherLbl, 0, 120) : 'ค่าใช้จ่ายอื่น',
                        'detail' => '', 'suggested' => $otherAmt, 'amount' => $otherAmt, 'waived' => false];
        }
        $extra = round(array_sum(array_column($lines, 'amount')), 2);
        $note  = implode(' · ', array_map(
            fn($l) => $l['label'] . ' ' . ($l['waived'] ? '(ยกเว้น)' : money0($l['amount']) . ' บ.'),
            $lines
        ));

        Booking::updateById($id, [
            'status'          => 'returned',
            'mileage_in'      => $km ?: null,
            'fuel_in'         => $fuelIn,
            'returned_at'     => $now,
            'extra_charge'    => $extra,
            'extra_note'      => $note !== '' ? mb_substr($note, 0, 250) : null,
            'extra_breakdown' => $lines ? json_encode($lines, JSON_UNESCAPED_UNICODE) : null,
            'damage_note'     => $r->str('damage_note') ?: null,
        ]);
        if ($km > 0) {
            Car::setMileage((int) $b['car_id'], $km);
        }
        // ค่าใช้จ่ายเพิ่มเติมทำให้ยอดที่ต้องชำระเปลี่ยน → คำนวณสถานะการชำระเงินใหม่
        PaymentService::recalc($id);
        Inspection::ensure($id, 'in', AuthService::id());
        Inspection::fillMissing($id, 'in', $km ?: null, $fuelIn);
        InspectionService::lock($id, 'in');
        ActivityLog::log('booking_return', 'bookings', $id, ['km' => $km, 'extra' => $extra, 'lines' => $lines]);
        $fresh = Booking::find($id);
        try {
            NotificationService::bookingReturned($fresh, $car, $lines);
        } catch (\Throwable $e) {
        }
        return ['ok', 'บันทึกการรับคืนรถแล้ว' . ($extra > 0 ? ' — ค่าใช้จ่ายเพิ่ม ' . money0($extra) . ' บาท' : '')];
    }

    private static function extend(array $b, array $car, Request $r): array
    {
        $id = (int) $b['id'];
        $q  = PricingService::extensionQuote($b, $car, $r->str('new_return'));
        if (!$q['ok']) {
            throw new \RuntimeException($q['error']);
        }
        // พนักงานลดราคาส่วนเช่าต่อได้ แต่ไม่เกินยอดที่ระบบคิด (ถ้าจะคิดเพิ่มให้ใช้ค่าใช้จ่ายเพิ่มตอนคืนรถ)
        $chargeIn = $r->str('charge');
        $charge   = $chargeIn !== '' ? max(0, min($q['charge'], round((float) $chargeIn, 2))) : $q['charge'];
        $rate     = (float) $b['vat_rate'];
        $net      = $rate > 0 ? round($charge / (1 + $rate / 100), 2) : $charge;
        $vat      = round($charge - $net, 2);
        $bd       = json_decode((string) $b['rate_breakdown'], true) ?: ['rental' => [], 'addons' => []];
        $addDays  = $q['days_new'] - $q['days_old'];
        $bd['rental'][] = ['label' => 'เช่าต่อถึง ' . thai_date($q['new_return'], true), 'qty' => max(1, $addDays),
                           'unit' => $addDays > 0 ? round($q['rental_delta'] / $addDays, 2) : $q['rental_delta'], 'amount' => $q['rental_delta']];
        if ($q['addon_delta'] > 0) {
            $bd['addons'][] = ['key' => 'ext', 'label' => 'บริการเสริมช่วงเช่าต่อ (' . $addDays . ' วัน)', 'qty' => $addDays,
                               'unit' => round($q['addon_delta'] / max(1, $addDays), 2), 'amount' => $q['addon_delta']];
        }
        Database::begin();
        try {
            // ล็อกแถวของรถ (แบบเดียวกับหน้าจองของลูกค้า) แล้วตรวจซ้ำ — กันลูกค้ารายอื่นจองช่วงเดียวกันเข้ามาพร้อมกันพอดี
            Car::lock((int) $b['car_id']);
            $again = PricingService::extensionQuote(Booking::find($id), $car, $q['new_return']);
            if (!$again['ok']) {
                throw new \RuntimeException($again['error']);
            }
            Booking::updateById($id, [
                'return_at'      => $q['new_return'],
                'rental_days'    => $q['days_new'],
                'rental_amount'  => round((float) $b['rental_amount'] + $q['rental_delta'], 2),
                'addon_amount'   => round((float) $b['addon_amount'] + $q['addon_delta'], 2),
                'discount'       => round((float) $b['discount'] + max(0, $q['delta'] - $net), 2),
                'subtotal'       => round((float) $b['subtotal'] + $net, 2),
                'vat_amount'     => round((float) $b['vat_amount'] + $vat, 2),
                'grand_total'    => round((float) $b['grand_total'] + $charge, 2),
                'rate_breakdown' => json_encode($bd, JSON_UNESCAPED_UNICODE),
            ]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        PaymentService::recalc($id);
        // เตือนวันคืนรถที่เคยส่งไปเป็นของกำหนดเดิม — ปลดออกเพื่อให้ระบบเตือนกำหนดใหม่ได้อีกครั้ง
        Notification::releaseReminder('cust_return_reminder', $id);
        ActivityLog::log('booking_extended', 'bookings', $id,
            ['from' => $b['return_at'], 'to' => $q['new_return'], 'charge' => $charge, 'computed' => $q['charge']]);
        $fresh = Booking::find($id);
        try {
            if (LineService::notifyCustomer('extended', $fresh, $car, ['จำนวน' => money0($charge)])) {
                NotificationService::flush();
            }
        } catch (\Throwable $e) {
        }
        return ['ok', 'เลื่อนกำหนดคืนเป็น ' . thai_date($q['new_return'], true) . ' แล้ว — ค่าเช่าเพิ่ม ' . money0($charge) . ' บาท'];
    }

    private static function settleSecurity(array $b, array $car, Request $r): array
    {
        $id = (int) $b['id'];
        Database::begin();
        try {
            Booking::lock($id);   // กันกดปิดเงินประกันสองครั้งพร้อมกัน
            $held = Payment::securityHeld($id);
            if ($held <= 0) {
                throw new \RuntimeException('ไม่มีเงินประกันที่ร้านถืออยู่สำหรับการจองนี้ (อาจคืนไปแล้ว)');
            }
            // ค่าใช้จ่ายที่พบหลังรับคืนรถ (ค่าปรับจราจรที่ตามมา ค่าซ่อมจากใบเสนอราคาอู่ ค่าธรรมเนียมยกเลิก)
            // บันทึกเป็นรายการค่าใช้จ่ายเพิ่มของการจองก่อน แล้วจึงหักจากเงินประกัน — ที่มาของเงินที่หักจึงตรวจย้อนหลังได้
            $addAmt = max(0, min($held, round($r->float('add_amount'), 2)));
            $addLbl = $r->str('add_label', 120);
            // ยอดค้างชำระ "ก่อน" บวกรายการใหม่ — เป็นตัวเลขเดียวกับที่หน้าจอแสดงให้พนักงานเห็น
            // การจองที่ยกเลิก: ค่าเช่าที่ยังไม่จ่ายไม่ใช่หนี้ (ไม่ได้เช่าจริง) — นับเฉพาะค่าใช้จ่ายที่บันทึกไว้
            $pre = Booking::find($id);
            $owe = $pre['status'] === 'cancelled'
                ? max(0, round((float) $pre['extra_charge'] - max(0, (float) $pre['paid_amount']), 2))
                : max(0, round((float) $pre['grand_total'] + (float) $pre['extra_charge'] - (float) $pre['paid_amount'], 2));
            if ($addAmt > 0) {
                if ($addLbl === '') {
                    throw new \RuntimeException('กรุณาระบุว่าค่าใช้จ่ายที่หักเพิ่มคือค่าอะไร');
                }
                $lines   = Booking::extraLines($pre);
                $lines[] = ['key' => 'after', 'label' => $addLbl, 'detail' => 'บันทึกหลังรับคืนรถ ' . thai_date(date('Y-m-d H:i:s'), true),
                            'suggested' => $addAmt, 'amount' => $addAmt, 'waived' => false];
                Booking::updateById($id, [
                    'extra_charge'    => round((float) $pre['extra_charge'] + $addAmt, 2),
                    'extra_breakdown' => json_encode($lines, JSON_UNESCAPED_UNICODE),
                    'extra_note'      => mb_substr(trim((string) $pre['extra_note'] . ' · ' . $addLbl . ' ' . money0($addAmt) . ' บ.', ' ·'), 0, 250),
                ]);
            }
            $cur = Booking::find($id);
            // ยอดที่หัก = ส่วนที่พนักงานเลือกหักจากยอดค้างเดิม (ไม่เกินยอดค้าง) + ค่าใช้จ่ายที่เพิ่งบันทึก (หักเต็มเสมอ)
            // สูตรเดียวกับที่หน้าจอคำนวณ "คืนลูกค้า" ให้เห็นก่อนกดยืนยัน
            $deduct = max(0, min($held, $addAmt + max(0, min($owe, round($r->float('deduct'), 2)))));
            $refund = round($held - $deduct, 2);
            $method = in_array($r->str('refund_method'), ['cash', 'transfer', 'promptpay'], true) ? $r->str('refund_method') : 'cash';
            $nowTs  = date('Y-m-d H:i:s');
            $base   = ['booking_id' => $id, 'paid_at' => $nowTs, 'status' => 'approved',
                       'approved_by' => AuthService::id(), 'approved_at' => $nowTs];
            if ($deduct > 0) {
                Payment::insert($base + ['kind' => (float) $cur['extra_charge'] > 0 ? 'extra' : 'balance',
                    'method' => 'deduct', 'amount' => $deduct, 'note' => 'หักจากเงินประกัน' . ($addAmt > 0 ? ' — ' . $addLbl : '')]);
            }
            if ($refund > 0) {
                Payment::insert($base + ['kind' => 'security_refund', 'method' => $method, 'amount' => $refund,
                    'note' => $r->str('refund_note', 200) ?: 'คืนเงินประกัน']);
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        PaymentService::recalc($id);
        ActivityLog::log('security_settled', 'bookings', $id,
            ['held' => $held, 'deduct' => $deduct, 'refund' => $refund, 'method' => $method, 'added' => $addAmt, 'added_label' => $addLbl]);
        $fresh = Booking::find($id);
        try {
            if (LineService::notifyCustomer('deposit_refunded', $fresh, $car, ['คืนเงิน' => money0($refund), 'หัก' => money0($deduct)])) {
                NotificationService::flush();
            }
        } catch (\Throwable $e) {
        }
        return ['ok', 'ปิดเงินประกันแล้ว — หักชำระ ' . money0($deduct) . ' บาท · คืนลูกค้า ' . money0($refund) . ' บาท'];
    }

    /** ส่งลิงก์หน้าเซ็นเอกสารให้ลูกค้าทาง LINE (เฉพาะลูกค้าที่ผูกบัญชีไว้) */
    private static function sendSignLink(array $b, array $car, Request $r): array
    {
        $id   = (int) $b['id'];
        $kind = isset(SignatureService::kinds()[$r->str('kind')]) ? $r->str('kind') : 'contract';
        if ($msg = SignatureService::blocker($b, $kind)) {
            throw new \RuntimeException($msg);
        }
        if (Signature::active($id, $kind)) {
            throw new \RuntimeException('เอกสารนี้ลูกค้าลงนามแล้ว');
        }
        if (!LineService::userForBooking($b)) {
            throw new \RuntimeException('ลูกค้ายังไม่ได้ผูกบัญชี LINE กับการจองนี้ — ใช้ปุ่ม "คัดลอกลิงก์" แล้วส่งให้ลูกค้าทางช่องทางอื่นแทน');
        }
        $docName = SignatureService::kinds()[$kind][0];
        if (!LineService::notifyCustomer('sign_request', $b, $car, ['ลิงก์เซ็น' => SignatureService::url($b, $kind), 'เอกสาร' => $docName])) {
            throw new \RuntimeException('การแจ้งเตือนลูกค้าทาง LINE ถูกปิดอยู่ — เปิดได้ที่เมนู LINE หรือใช้ปุ่ม "คัดลอกลิงก์" แทน');
        }
        NotificationService::flush();
        $st = Notification::latestFor('cust_sign_request', $id);
        if (($st['status'] ?? '') !== 'sent') {
            throw new \RuntimeException('ส่ง LINE ไม่สำเร็จ' . (!empty($st['error']) ? ' (' . $st['error'] . ')' : '') . ' — ใช้ปุ่ม "คัดลอกลิงก์" แทนได้');
        }
        ActivityLog::log('signature_link_sent', 'bookings', $id, $kind);
        return ['ok', 'ส่งลิงก์เซ็น' . $docName . 'ให้ลูกค้าทาง LINE แล้ว'];
    }

    /** ขอให้ลูกค้าเซ็นใหม่ — ฉบับเดิมไม่ถูกลบ เก็บเป็นประวัติพร้อมเหตุผล */
    private static function voidSignature(array $b, Request $r): array
    {
        if (!AuthService::isAdmin()) {
            throw new \RuntimeException('การยกเลิกลายเซ็นทำได้เฉพาะเจ้าของร้าน');
        }
        $id     = (int) $b['id'];
        $kind   = isset(SignatureService::kinds()[$r->str('kind')]) ? $r->str('kind') : 'contract';
        $reason = $r->str('void_reason');
        $sg     = Signature::active($id, $kind);
        if (!$sg) {
            throw new \RuntimeException('ไม่พบลายเซ็นที่ใช้อยู่ของเอกสารนี้');
        }
        if (mb_strlen($reason) < 3) {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่ขอให้ลูกค้าเซ็นใหม่');
        }
        Signature::void((int) $sg['id'], (int) AuthService::id(), $reason);
        ActivityLog::log('signature_voided', 'bookings', $id, ['kind' => $kind, 'signature_id' => (int) $sg['id'], 'reason' => $reason]);
        return ['warn', 'ยกเลิกลายเซ็นฉบับเดิมแล้ว (เก็บไว้เป็นประวัติ) — ส่งลิงก์ให้ลูกค้าเซ็นใหม่ได้'];
    }

    private static function complete(array $b): array
    {
        Booking::updateById((int) $b['id'], ['status' => 'completed']);
        ActivityLog::log('booking_completed', 'bookings', (int) $b['id']);
        return ['ok', 'ปิดงานเรียบร้อย'];
    }

    private static function cancel(array $b, Request $r): array
    {
        $reason = $r->str('cancel_reason');
        Booking::updateById((int) $b['id'], ['status' => 'cancelled', 'cancel_reason' => $reason ?: null]);
        ActivityLog::log('booking_cancelled', 'bookings', (int) $b['id'], $reason);
        return ['warn', 'ยกเลิกการจองแล้ว'];
    }

    private static function addPayment(array $b, Request $r): array
    {
        $id     = (int) $b['id'];
        $amount = round($r->float('amount'), 2);
        if ($amount <= 0) {
            throw new \RuntimeException('กรุณาระบุจำนวนเงิน');
        }
        if ($amount > 5000000) {
            throw new \RuntimeException('จำนวนเงินสูงผิดปกติ กรุณาตรวจตัวเลขอีกครั้ง');
        }
        $now = date('Y-m-d H:i:s');
        $pid = Payment::insert([
            'booking_id'  => $id,
            'kind'        => in_array($r->str('kind'), ['deposit', 'balance', 'security', 'extra', 'refund'], true) ? $r->str('kind') : 'balance',
            'method'      => in_array($r->str('method'), ['transfer', 'cash', 'card', 'promptpay'], true) ? $r->str('method') : 'cash',
            'amount'      => $amount,
            'paid_at'     => $now,
            'status'      => 'approved',
            'approved_by' => AuthService::id(),
            'approved_at' => $now,
            'note'        => $r->str('pnote') ?: null,
        ]);
        PaymentService::recalc($id);
        ActivityLog::log('payment_added', 'payments', $pid, $amount);
        return ['ok', 'บันทึกการชำระเงินแล้ว'];
    }

    private static function saveNote(array $b, Request $r): array
    {
        Booking::updateById((int) $b['id'], ['note' => $r->str('note') ?: null]);
        return ['ok', 'บันทึกหมายเหตุแล้ว'];
    }
}
