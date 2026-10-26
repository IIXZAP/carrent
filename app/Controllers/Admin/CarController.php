<?php
namespace App\Controllers\Admin;

use App\Models\Car;
use App\Models\CarType;
use App\Services\CarService;

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
        [$data, $errors] = CarService::validate($_POST);
        if ($errors) {
            $this->form('เพิ่มรถ', $_POST, $errors, '/admin/cars');
            return;
        }
        Car::create($data);
        flash('success', 'เพิ่มรถเรียบร้อย');
        redirect('/admin/cars');
    }

    public function edit(string $id): void
    {
        $car = $this->findOrFail($id);
        $this->form('แก้ไขรถ ' . $car['plate'], $car, [], '/admin/cars/' . $car['id']);
    }

    public function update(string $id): void
    {
        $car = $this->findOrFail($id);
        [$data, $errors] = CarService::validate($_POST, (int) $car['id']);
        if ($errors) {
            $this->form('แก้ไขรถ ' . $car['plate'], $_POST, $errors, '/admin/cars/' . $car['id']);
            return;
        }
        Car::update((int) $car['id'], $data);
        flash('success', 'บันทึกเรียบร้อย');
        redirect('/admin/cars');
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
        ], 'layouts/admin');
    }
}
