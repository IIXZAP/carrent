<?php
namespace App\Models;

use App\Core\Database;

final class Addon
{
    /** บริการเสริมที่เปิดขาย เรียงตามลำดับ : code => [id, name, unit, price] */
    public static function activeCatalog(): array
    {
        $rows = Database::all('SELECT id, code, name, unit, price FROM addons WHERE is_active = 1 ORDER BY sort_order, id');
        $catalog = [];
        foreach ($rows as $row) {
            $catalog[$row['code']] = [
                'id'    => (int) $row['id'],
                'name'  => $row['name'],
                'unit'  => $row['unit'],
                'price' => (float) $row['price'],
            ];
        }
        return $catalog;
    }
}
