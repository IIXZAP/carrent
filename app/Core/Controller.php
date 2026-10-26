<?php

namespace App\Core;

/**
 * คลาสแม่ของ controller ทุกตัว: ช่วยแสดง view / redirect / ตอบ JSON
 * controller มีหน้าที่แค่รับคำขอ เรียก model/service แล้วเลือก view — ไม่เขียน SQL ที่นี่
 */
abstract class Controller
{
    public function __construct(protected Request $request)
    {
    }

    protected function view(string $name, array $data = [], int $status = 200): Response
    {
        return Response::html(View::make($name, $data), $status);
    }

    /** $path เป็น path ภายในระบบ เช่น 'cars/3' หรือ 'admin/bookings' */
    protected function redirect(string $path, int $status = 302): Response
    {
        return Response::redirect($path, $status);
    }

    protected function json($data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /** กลับหน้าที่มา (เฉพาะ referer ภายในเว็บนี้) ไม่มีก็ไป $fallback */
    protected function back(string $fallback = ''): Response
    {
        return $this->redirect($this->request->safeRefererPath($fallback));
    }

    protected function flash(string $type, string $message): void
    {
        Flash::add($type, $message);
    }

    protected function notFound(string $title = 'ไม่พบหน้าที่ต้องการ', string $message = 'หน้านี้อาจถูกย้ายหรือลบไปแล้ว'): Response
    {
        return (new \App\Controllers\ErrorController($this->request))->notFound($title, $message);
    }

    /** หยุดงานทันทีแล้วตอบ $response (ใช้ได้จากชั้นลึก) */
    protected function halt(Response $response): never
    {
        throw new HttpException($response);
    }
}
