<?php

namespace App\Core;

/**
 * ป้องกัน CSRF — โทเคนเก็บในเซสชัน ส่งมากับฟอร์ม (_csrf) หรือส่วนหัว X-CSRF-Token
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function valid(Request $request): bool
    {
        $sent = $request->post('_csrf');
        if (!is_string($sent) || $sent === '') {
            $sent = $request->header('X-CSRF-Token');
        }
        if ($sent === '') {
            $sent = (string) $request->query('_csrf', '');
        }
        return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $sent);
    }
}
