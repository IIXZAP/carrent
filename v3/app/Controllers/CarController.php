<?php
namespace App\Controllers;

use App\Models\Car;

final class CarController
{
    public function show(string $id): void
    {
        $car = Car::find((int) $id);
        if ($car === null || $car['status'] !== 'available') {
            abort(404);
        }
        view('public/car', ['title' => $car['brand'] . ' ' . $car['model'], 'car' => $car]);
    }
}
