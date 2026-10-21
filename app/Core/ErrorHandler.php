<?php

namespace App\Core;

/**
 * ข้อผิดพลาดที่ไม่มีใครดักไว้ → หน้าข้อความสั้นๆ แทนหน้าขาว และไม่เผยรายละเอียดภายในระบบ
 */
final class ErrorHandler
{
    public static function register(): void
    {
        set_exception_handler([self::class, 'handle']);
    }

    public static function handle(\Throwable $e): void
    {
        error_log('[thonglorcarrent] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

        $dbDown = $e instanceof DatabaseConnectionException;
        $bad    = $e instanceof \TypeError || $e instanceof \ValueError;          // ข้อมูลที่ส่งมารูปแบบผิด
        $code   = $dbDown ? 500 : ($bad ? 400 : 500);
        $msg    = $dbDown
            ? 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาติดต่อผู้ดูแลระบบ'
            : ($bad ? 'ข้อมูลที่ส่งมาไม่ถูกรูปแบบ กรุณากลับไปตรวจแล้วลองใหม่' : 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง หากยังไม่ได้โปรดติดต่อร้าน');

        $wantsJson = PHP_SAPI !== 'cli' && Request::current()->wantsJson();

        if (!headers_sent()) {
            http_response_code($code);
        }
        if ($wantsJson) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'error' => $msg] + (APP_DEBUG ? ['debug' => $e->getMessage()] : []), JSON_UNESCAPED_UNICODE);
            return;
        }
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $msg . (APP_DEBUG ? "\n" . $e : '') . "\n");
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        // หน้านี้ไม่พึ่ง view/ฐานข้อมูล เพราะข้อผิดพลาดอาจมาจากสองอย่างนั้นเอง
        echo '<!DOCTYPE html><html lang="th"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>เกิดข้อผิดพลาด</title><body style="font-family:Sarabun,system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 20px;line-height:1.7;color:#1f2937">'
           . '<h1 style="font-size:1.3rem">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</h1>'
           . (APP_DEBUG ? '<pre style="white-space:pre-wrap;font-size:.85rem">' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>' : '')
           . '<p><a href="javascript:history.back()">← กลับไปหน้าก่อนหน้า</a></p></body></html>';
    }
}
