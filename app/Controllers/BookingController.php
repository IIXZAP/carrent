<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Models\Booking;
use App\Models\Car;
use App\Services\AbTrackService;
use App\Services\BookingService;

/** รับข้อมูลการจองจากหน้าเว็บลูกค้า → บันทึก → แจ้งเตือน → ส่งไปหน้ายืนยัน */
class BookingController extends Controller
{
    public function store(): Response
    {
        $r = $this->request;
        AbTrackService::trackOnce('submit');

        $carId = $r->int('car_id');
        $car   = Car::findPublic($carId);
        if (!$car) {
            $this->flash('danger', 'ไม่พบรถที่เลือก');
            return $this->redirect('cars');
        }

        // ข้อความทุกช่องถูกตัดให้ไม่เกินความยาวของคอลัมน์ — ไม่งั้นข้อความยาวเกินจะกลายเป็น error ของฐานข้อมูลโผล่ให้ลูกค้าเห็น
        $in = [
            'pickup'     => $r->str('pickup_at', 30),
            'ret'        => $r->str('return_at', 30),
            'name'       => $r->str('contact_name', 150),
            'phone'      => $r->str('contact_phone', 30),
            'email'      => $r->str('contact_email', 150),
            'line'       => $r->str('contact_line', 80),
            'note'       => $r->str('note', 1000),
            'place_pick' => $r->str('pickup_place', 230),
            'place_ret'  => $r->str('return_place', 230),
            'addons'     => BookingService::cleanAddons($r->arr('addons')),
            'km'         => max(0, min(2000, $r->float('delivery_km'))),
            'pick_type'  => $r->str('pickup_place_type') === 'airport' ? 'airport' : 'shop',
            'ret_type'   => $r->str('return_place_type') === 'airport' ? 'airport' : 'shop',
            'accept'     => $r->bool('accept'),
            'liff_uid'   => (string) ($_SESSION['liff']['uid'] ?? ''),
        ];

        // ---------- กดยืนยันซ้ำ (ดับเบิลคลิก / กดย้อนแล้วส่งใหม่) ----------
        // การจองแรกสำเร็จไปแล้ว คำขอที่สองจะเจอว่า "รถไม่ว่าง" (เพราะตัวเองเพิ่งจอง) แล้วลูกค้าไม่เห็นเลขที่จอง
        // ถ้าเซสชันนี้เพิ่งจองรถคันเดิม ช่วงเวลาเดิม ภายใน 3 นาที → พาไปหน้าจองสำเร็จของรายการนั้นเลย
        if (!empty($_SESSION['last_booking']) && $in['pickup'] !== '' && $in['ret'] !== '') {
            $dup = Booking::recentDuplicate(
                (string) $_SESSION['last_booking'],
                $carId,
                date('Y-m-d H:i:s', (int) strtotime($in['pickup'])),
                date('Y-m-d H:i:s', (int) strtotime($in['ret'])),
                date('Y-m-d H:i:s', time() - 180)
            );
            if ($dup) {
                return $this->redirect('booking/success?no=' . urlencode($dup['booking_no']));
            }
        }

        $result = BookingService::createFromWeb($car, $in, $r->ip());

        if ($result['errors']) {
            foreach ($result['errors'] as $err) {
                $this->flash('danger', $err);
            }
            if ($result['blacklisted']) {
                return $this->redirect('cars/' . $carId);
            }
            return $this->redirect('cars/' . $carId . '?pickup=' . urlencode($in['pickup']) . '&ret=' . urlencode($in['ret']) . '#book');
        }

        $bookingNo = (string) $result['booking']['booking_no'];
        $_SESSION['last_booking'] = $bookingNo;
        AbTrackService::track('booked', $carId);
        return $this->redirect('booking/success?no=' . urlencode($bookingNo));
    }

    /** หน้ายืนยันหลังจองสำเร็จ — แสดงเลขที่จอง + บัญชีโอน + ลิงก์อัปสลิป */
    public function success(): Response
    {
        $no = $this->request->str('no');
        $b  = $no !== '' ? Booking::findByNoWithCar($no) : null;

        // อนุญาตเฉพาะคนที่เพิ่งจอง (กันเดาเลขที่จองคนอื่น)
        if (!$b || ($_SESSION['last_booking'] ?? '') !== $no) {
            return $this->view('errors/404', [
                'title'     => 'ไม่พบการจอง',
                'message'   => 'กรุณาตรวจสอบผ่านหน้าตรวจสอบการจอง โดยใช้เลขที่จองและเบอร์โทรศัพท์',
                'backUrl'   => url('track'),
                'backLabel' => 'ตรวจสอบการจอง',
            ], 404);
        }
        return $this->view('public/booking_success', ['b' => $b, 'title' => 'จองสำเร็จ ' . $b['booking_no']]);
    }
}
