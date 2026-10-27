<?php
namespace App\Controllers\Admin;

use App\Models\Car;
use App\Models\CarType;
use App\Services\BookingService;
use App\Services\CarImageService;
use App\Services\CarService;
use App\Services\UserError;

final class CarController
{
    public function index(): void
    {
        view('admin/cars/index', ['title' => 'รถ', 'cars' => Car::allForAdmin()], 'layouts/admin');
    }

    public function create(): void
    {
        $this->form('เพิ่มรถ', CarService::defaults(), [], '/admin/cars');
    }

    public function store(): void
    {
        $in = request_strings($_POST);
        [$data, $errors] = CarService::validate($in);
        if ($errors) {
            $this->form('เพิ่มรถ', $in, $errors, '/admin/cars');
            return;
        }
        $id = Car::create($data);
        flash('success', 'เพิ่มรถเรียบร้อย — เพิ่มรูปรถได้ด้านล่าง');
        redirect('/admin/cars/' . $id . '/edit');
    }

    public function edit(string $id): void
    {
        $car = $this->findOrFail($id);
        $this->form('แก้ไขรถ ' . $car['plate'], $car, [], '/admin/cars/' . $car['id']);
    }

    public function update(string $id): void
    {
        $car = $this->findOrFail($id);
        $in = request_strings($_POST);
        [$data, $errors] = CarService::validate($in, (int) $car['id']);
        if ($errors) {
            $this->form('แก้ไขรถ ' . $car['plate'], $in, $errors, '/admin/cars/' . $car['id']);
            return;
        }
        Car::update((int) $car['id'], $data);
        flash('success', 'บันทึกเรียบร้อย');
        redirect('/admin/cars');
    }

    /** รูปปก: อัปโหลดแทนของเดิม */
    public function uploadCover(string $id): void
    {
        $car = $this->findOrFail($id);
        $files = uploaded_files('cover');
        try {
            if (count($files) !== 1) {
                throw new UserError('กรุณาเลือกไฟล์รูปปก 1 ไฟล์');
            }
            CarImageService::setCover((int) $car['id'], $files[0]);
            flash('success', 'เปลี่ยนรูปปกแล้ว');
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'อัปโหลดรูปไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
        }
        redirect('/admin/cars/' . $car['id'] . '/edit#images');
    }

    public function deleteCover(string $id): void
    {
        $car = $this->findOrFail($id);
        CarImageService::removeCover((int) $car['id']);
        flash('success', 'ลบรูปปกแล้ว');
        redirect('/admin/cars/' . $car['id'] . '/edit#images');
    }

    /** รูปแกลเลอรี: เลือกได้หลายไฟล์ ทำทีละไฟล์ — ไฟล์ที่ไม่ผ่านบอกชื่อ ไฟล์ที่ผ่านบันทึกแล้ว */
    public function addImages(string $id): void
    {
        $car = $this->findOrFail($id);
        $added = 0;
        $problems = [];
        foreach (uploaded_files('images') as $file) {
            try {
                CarImageService::addGallery((int) $car['id'], $file);
                $added++;
            } catch (\Throwable $e) {
                $problems[] = ($file['name'] !== '' ? mb_substr($file['name'], 0, 40) . ': ' : '') . BookingService::publicError($e, 'อัปโหลดไม่สำเร็จ');
            }
        }
        if ($added > 0) {
            flash('success', 'เพิ่มรูป ' . $added . ' รูปแล้ว');
        }
        foreach ($problems as $problem) {
            flash('error', $problem);
        }
        if ($added === 0 && $problems === []) {
            flash('error', 'กรุณาเลือกไฟล์รูป');
        }
        redirect('/admin/cars/' . $car['id'] . '/edit#images');
    }

    public function deleteImage(string $id): void
    {
        $image = ctype_digit($id) ? \App\Core\Database::one('SELECT * FROM car_images WHERE id = :i', ['i' => (int) $id]) : null;
        if ($image === null) {
            abort(404);
        }
        try {
            CarImageService::removeGallery((int) $image['id']);
            flash('success', 'ลบรูปแล้ว');
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e));
        }
        redirect('/admin/cars/' . $image['car_id'] . '/edit#images');
    }

    public function destroy(string $id): void
    {
        $car = $this->findOrFail($id);
        if (Car::hasOpenBookings((int) $car['id'])) {
            flash('error', 'ลบไม่ได้ เพราะรถคันนี้ยังมีการจองที่ยังไม่จบ');
            redirect('/admin/cars');
        }
        Car::softDelete((int) $car['id']);
        flash('success', 'ลบรถ ' . $car['plate'] . ' แล้ว');
        redirect('/admin/cars');
    }

    private function findOrFail(string $id): array
    {
        $car = Car::find((int) $id);
        if ($car === null) {
            abort(404);
        }
        return $car;
    }

    private function form(string $title, array $car, array $errors, string $action): void
    {
        view('admin/cars/form', [
            'title'  => $title,
            'car'    => $car,
            'errors' => $errors,
            'action' => $action,
            'types'  => CarType::all(),
            'images' => isset($car['id']) ? Car::images((int) $car['id']) : [],
        ], 'layouts/admin');
    }
}
