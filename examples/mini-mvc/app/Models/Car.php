<?php
/** Model: คุยกับตาราง cars (SQL อยู่ที่นี่ที่เดียว ไม่ไปเขียนใน Controller) */
class Car
{
    public static function all(): array
    {
        return Database::all('SELECT * FROM cars ORDER BY id');
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM cars WHERE id = :id', [':id' => $id]);
    }
}
