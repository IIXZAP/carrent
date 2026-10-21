<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;

/** หน้าแสดงข้อผิดพลาด (404 ฯลฯ) */
class ErrorController extends Controller
{
    public function notFound(string $title = 'ไม่พบหน้าที่ต้องการ', string $message = 'หน้านี้อาจถูกย้ายหรือลบไปแล้ว'): Response
    {
        if ($this->request->wantsJson()) {
            return $this->json(['ok' => false, 'error' => $title], 404);
        }
        return $this->view('errors/404', ['title' => $title, 'message' => $message], 404);
    }
}
