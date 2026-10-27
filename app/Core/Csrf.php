<?php
namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set('_csrf', $token);
        }
        return $token;
    }

    public static function valid($given): bool
    {
        if (!is_string($given) || $given === '') {
            return false;
        }
        return hash_equals(self::token(), $given);
    }
}
