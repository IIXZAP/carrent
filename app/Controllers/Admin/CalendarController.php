<?php
namespace App\Controllers\Admin;

use App\Models\CarBlock;
use App\Services\Auth;
use App\Services\BookingService;
use App\Services\CalendarService;
use App\Services\CarBlockService;

/** ปฏิทินรถแบบ timeline — ทุกคันพร้อมกัน + ปิดรถชั่วคราว */
final class CalendarController
{
    public function index(): void
    {
        $cal = CalendarService::month(get_str('m'));
        view('admin/calendar', $cal + [
            'title'     => 'ปฏิทินรถ',
            'today'     => date('Y-m-d'),
            'allBlocks' => CarBlock::upcoming(20),
        ], 'layouts/admin');
    }

    public function block(): void
    {
        $month = CalendarService::normalizeMonth(post('m'));
        try {
            CarBlockService::create((int) post('car_id'), post('start_at'), post('end_at'), post('reason'), (int) Auth::user()['id']);
            flash('success', 'บันทึกช่วงปิดรถแล้ว');
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
        }
        redirect('/admin/calendar?m=' . $month);
    }

    public function unblock(): void
    {
        $month = CalendarService::normalizeMonth(post('m'));
        try {
            CarBlockService::remove((int) post('block_id'), (int) Auth::user()['id']);
            flash('success', 'ยกเลิกช่วงปิดรถแล้ว');
        } catch (\Throwable $e) {
            flash('error', BookingService::publicError($e, 'ลบไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
        }
        redirect('/admin/calendar?m=' . $month);
    }
}
