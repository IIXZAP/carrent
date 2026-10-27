<?php
namespace App\Controllers;

use App\Models\Car;

final class HomeController
{
    /** หน้าแรก: hero + แถบค้นหา + รถทั้งหมด (ค้นหาจริงอยู่ที่ /cars) */
    public function index(): void
    {
        $cars = Car::allPublic();
        $prices = array_map(static fn(array $c): float => (float) $c['price_day'], $cars);
        $heroCar = null;
        foreach ($cars as $c) {
            if (upload_exists($c['cover_image'] ?? null)) {
                $heroCar = $c;
                break;
            }
        }
        view('public/home', [
            'cars'      => $cars,
            'totalCars' => count($cars),
            'minPrice'  => $prices ? min($prices) : 0,
            'heroCar'   => $heroCar,
        ]);
    }
}
