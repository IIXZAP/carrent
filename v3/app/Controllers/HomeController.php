<?php
namespace App\Controllers;

use App\Models\Car;

final class HomeController
{
    public function index(): void
    {
        view('public/home', ['title' => 'เช่ารถ', 'cars' => Car::allPublic()]);
    }
}
