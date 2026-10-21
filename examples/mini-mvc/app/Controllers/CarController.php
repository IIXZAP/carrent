<?php
/** Controller: รับคำขอเกี่ยวกับรถ → ให้ Model ดึงข้อมูล → ส่งให้ View แสดง */
class CarController
{
    /** GET /  — รายการรถทั้งหมด */
    public function index(): void
    {
        $cars = Car::all();
        view('cars/index', ['cars' => $cars]);
    }

    /** GET /cars/{id} — รายละเอียดรถ + ฟอร์มจอง */
    public function show(string $id): void
    {
        $car = Car::find((int) $id);
        if (!$car) {
            http_response_code(404);
            echo 'ไม่พบรถคันนี้';
            return;
        }
        view('cars/show', ['car' => $car]);
    }
}
