<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Models\Car;
use App\Services\AbTrackService;

/** หน้าแรก */
class HomeController extends Controller
{
    public function index(): Response
    {
        AbTrackService::trackOnce('view_home');

        $cars = Car::allPublic();
        return $this->view('public/home', [
            'cars'      => $cars,
            'totalCars' => count($cars),
            'minPrice'  => Car::minAvailablePrice(),
            'heroCar'   => Car::hero() ?: ($cars[0] ?? null),
        ]);
    }
}
