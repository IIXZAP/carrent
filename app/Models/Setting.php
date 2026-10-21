<?php

namespace App\Models;

use App\Core\Database;

/**
 * ตั้งค่าระบบแบบ key/value (ตาราง settings) — เก็บแคชในหน่วยความจำ 1 request
 */
final class Setting
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::all('SELECT skey, svalue FROM settings') as $r) {
                self::$cache[$r['skey']] = $r['svalue'];
            }
        }
        return self::$cache;
    }

    /** ค่าว่าง/ไม่มี → คืน $default */
    public static function get(string $key, $default = '')
    {
        $s = self::all();
        return array_key_exists($key, $s) && $s[$key] !== null && $s[$key] !== '' ? $s[$key] : $default;
    }

    public static function set(string $key, $value): void
    {
        Database::exec(
            'INSERT INTO settings (skey, svalue) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
            [':k' => $key, ':v' => (string) $value]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = (string) $value;
        }
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
