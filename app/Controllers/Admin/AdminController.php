<?php

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Response;

/**
 * ฐานของ controller หลังบ้านทุกตัว
 * ใช้ร่วมกัน: ตรวจรหัส id จาก URL, ดึงระเบียนหรือตอบ 404, ตัวช่วยผู้ใช้ปัจจุบัน
 */
abstract class AdminController extends Controller
{
    /** @param array<string,string> $params พารามิเตอร์จาก URL (Router ส่งเป็นอาร์กิวเมนต์ของเมธอด) */
    protected function userId(): ?int
    {
        $u = auth_user();
        return $u ? (int) $u['id'] : null;
    }

    /** ตอบหน้า 404 ของหลังบ้านทันที */
    protected function missing(string $what = 'ไม่พบข้อมูลที่ต้องการ'): never
    {
        $this->halt($this->notFound($what));
    }

    /** ส่งกลับไปหน้ารายการพร้อมข้อความ */
    protected function done(string $path, string $type, string $message): Response
    {
        $this->flash($type, $message);
        return $this->redirect($path);
    }

    /** ตอบเป็นไฟล์ CSV (UTF-8 มี BOM เพื่อให้ Excel อ่านภาษาไทยได้) */
    protected function csv(string $filename, array $header, iterable $rows): Response
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header);
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        rewind($fh);
        $body = (string) stream_get_contents($fh);
        fclose($fh);

        return (new Response($body, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store',
        ]));
    }
}
