<?php
namespace App\Models;

use App\Core\Database;

final class CarBlock
{
    public static function overlapCount(int $carId, string $from, string $to): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM car_blocks WHERE car_id = :car AND start_at < :to_at AND end_at > :from_at',
            ['car' => $carId, 'from_at' => $from, 'to_at' => $to]
        );
    }
}
