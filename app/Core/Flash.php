<?php

namespace App\Core;

/**
 * ข้อความแจ้งผลครั้งเดียว (แสดงในหน้าถัดไปแล้วหาย)
 * ชนิด: ok | warn | danger | info
 */
final class Flash
{
    public static function add(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
    }

    public static function has(): bool
    {
        return !empty($_SESSION['flash']);
    }

    /** ดึงข้อความทั้งหมดออกมาแล้วล้างทิ้ง */
    public static function pull(): array
    {
        $all = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $all;
    }
}
