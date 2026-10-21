<?php
/** Controller: จัดการการจอง */
class BookingController
{
    /** POST /booking — รับฟอร์มจอง ตรวจข้อมูล แล้วบันทึก */
    public function store(): void
    {
        // 1) ป้องกัน CSRF
        if (!csrf_valid()) {
            http_response_code(419);
            echo 'หน้าเว็บหมดอายุ กรุณาลองใหม่';
            return;
        }

        // 2) อ่านค่าจากฟอร์ม (trim ตัดช่องว่างหัวท้าย)
        $carId  = (int) ($_POST['car_id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $phone  = trim($_POST['phone'] ?? '');
        $pickup = trim($_POST['pickup_at'] ?? '');
        $return = trim($_POST['return_at'] ?? '');

        // 3) ตรวจข้อมูล: ผิดตรงไหนก็แจ้งแล้วกลับไปหน้าเดิม (early return)
        $car = Car::find($carId);
        if (!$car) {
            flash('ไม่พบรถที่เลือก');
            redirect('/');
        }
        $error = $this->validate($carId, $name, $phone, $pickup, $return);
        if ($error !== '') {
            flash($error);
            redirect('/cars/' . $carId);
        }

        // 4) คิดราคา แล้วบันทึก
        $days  = max(1, (int) ceil((strtotime($return) - strtotime($pickup)) / 86400));
        $total = $days * (int) $car['price_day'];
        $bookingNo = Booking::create([
            'car_id' => $carId, 'name' => $name, 'phone' => $phone,
            'pickup' => $pickup, 'return' => $return, 'total' => $total,
        ]);

        // 5) ไปหน้าจองสำเร็จ
        redirect('/booking/success/' . $bookingNo);
    }

    /** GET /booking/success/{no} — หน้าแสดงผลการจอง */
    public function success(string $no): void
    {
        $booking = Booking::findByNo($no);
        if (!$booking) {
            http_response_code(404);
            echo 'ไม่พบการจองนี้';
            return;
        }
        view('booking/success', ['booking' => $booking]);
    }

    /** ตรวจข้อมูลฟอร์ม คืนข้อความผิดพลาด หรือ '' ถ้าผ่าน */
    private function validate(int $carId, string $name, string $phone, string $pickup, string $return): string
    {
        if ($name === '' || $phone === '') {
            return 'กรุณากรอกชื่อและเบอร์โทร';
        }
        if (!preg_match('/^[0-9\-\+\s]{9,20}$/', $phone)) {
            return 'เบอร์โทรไม่ถูกต้อง';
        }
        $pickupTime = strtotime($pickup);
        $returnTime = strtotime($return);
        if (!$pickupTime || !$returnTime) {
            return 'กรุณาเลือกวันรับและวันคืนรถ';
        }
        if ($returnTime <= $pickupTime) {
            return 'วันคืนรถต้องหลังวันรับรถ';
        }
        if (Booking::hasConflict($carId, date('Y-m-d H:i:s', $pickupTime), date('Y-m-d H:i:s', $returnTime))) {
            return 'รถคันนี้ไม่ว่างในช่วงเวลาที่เลือก';
        }
        return '';
    }
}
