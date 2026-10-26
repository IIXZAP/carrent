<?php
namespace App\Models;

use App\Core\Database;

final class Addon
{
    /** บริการเสริมที่เปิดขาย เรียงตามลำดับ : code => [name, unit, price] */
    public static function activeCatalog(): array
    {
        $rows = Database::all('SELECT code, name, unit, price FROM addons WHERE is_active = 1 ORDER BY sort_order, id');
        $catalog = [];
        foreach ($rows as $row) {
            $catalog[$row['code']] = [
                'name'  => $row['name'],
                'unit'  => $row['unit'],
                'price' => (float) $row['price'],
            ];
        }
        return $catalog;
    }
}
