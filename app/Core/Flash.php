<?php
namespace App\Core;

/** ข้อความแจ้งผลครั้งเดียว (แสดงแล้วหาย) */
final class Flash
{
    public static function add(string $type, string $message): void
    {
        $all = Session::get('_flash', []);
        $all[] = ['type' => $type, 'message' => $message];
        Session::set('_flash', $all);
    }

    public static function pull(): array
    {
        $all = Session::get('_flash', []);
        Session::forget('_flash');
        return $all;
    }
}
