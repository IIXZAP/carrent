<?php
namespace App\Models;

use App\Core\Database;

final class CarType
{
    public static function all(): array
    {
        return Database::all('SELECT code, label_th FROM car_types ORDER BY sort_order, code');
    }

    public static function exists(string $code): bool
    {
        return Database::value('SELECT 1 FROM car_types WHERE code = :c', ['c' => $code]) !== null;
    }
}
