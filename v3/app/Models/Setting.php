<?php
namespace App\Models;

use App\Core\Database;

/** ตั้งค่าระบบ (key/value) — โหลดครั้งเดียวต่อคำขอ */
final class Setting
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::all('SELECT skey, svalue FROM settings') as $row) {
                self::$cache[$row['skey']] = $row['svalue'];
            }
        }
        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        $all = self::all();
        return isset($all[$key]) ? (string) $all[$key] : $default;
    }

    public static function set(string $key, string $value): void
    {
        Database::run(
            'INSERT INTO settings (skey, svalue) VALUES (:k, :v) ON DUPLICATE KEY UPDATE svalue = :v2',
            ['k' => $key, 'v' => $value, 'v2' => $value]
        );
        self::$cache = null;
    }
}
