<?php
namespace App\Controllers;

use App\Models\Booking;
use App\Models\Inspection;
use App\Models\Signature;
use App\Services\BookingService;
use App\Services\SignatureService;
use App\Services\UploadService;
use App\Services\UserError;

/**
 * หน้าเซ็นสัญญา/ใบรับคืนสำหรับลูกค้า — ไม่ต้องล็อกอิน ยืนยันด้วยเลขที่จอง + รหัสลับในลิงก์
 * ทุกคำตอบ: ห้ามแคช · ห้ามให้ค้นหาเจอ · ไม่ส่ง referrer (รหัสอยู่ใน URL)
 */
final class SignController
{
    public function show(): void
    {
        [$booking, $kind] = $this->resolve($_GET);
        $this->render($booking, $kind, null, []);
    }

    public function submit(): void
    {
        [$booking, $kind] = $this->resolve($_POST);
        $in = request_strings($_POST);
        try {
            SignatureService::sign((int) $booking['id'], $kind, $in, ['ip' => client_ip(), 'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')]);
        } catch (\Throwable $e) {
            $error = BookingService::publicError($e, 'บันทึกลายเซ็นไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
            $this->headers();
            $this->render(Booking::find((int) $booking['id']), $kind, $error, $in);
            return;
        }
        $this->headers();
        redirect('/sign?' . http_build_query(['no' => $booking['booking_no'], 'k' => $kind, 't' => $booking['sign_token']]));
    }

    /** รูปตรวจสภาพรถที่ลูกค้าเห็นประกอบการเซ็น (ต้องเป็นของการจองนี้และอยู่ในรอบที่เซ็นเท่านั้น) */
    public function photo(string $id): void
    {
        [$booking, $kind] = $this->resolve($_GET);
        $photo = ctype_digit($id) ? Inspection::photo((int) $id) : null;
        $ins = Inspection::get((int) $booking['id'], $kind === 'return' ? 'in' : 'out');
        $allowed = $photo !== null && $ins !== null && in_array($photo['file_path'], array_column($ins['photos'], 'file_path'), true);
        if (!$allowed) {
            $this->headers();
            abort(404);
        }
        $this->headers();
        UploadService::serve($photo['file_path']);
    }

    private function headers(): void
    {
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
    }

    /** @return array{0:array,1:string} */
    private function resolve(array $source): array
    {
        $this->headers();
        $no = is_string($source['no'] ?? null) ? $source['no'] : '';
        $token = is_string($source['t'] ?? null) ? $source['t'] : '';
        $kind = is_string($source['k'] ?? null) ? $source['k'] : 'contract';
        $booking = Booking::findBySignToken($no, $token);
        if ($booking === null || !isset(SignatureService::kinds()[$kind])) {
            abort(404);
        }
        return [$booking, $kind];
    }

    private function render(array $booking, string $kind, ?string $error, array $form): void
    {
        $active = Signature::active((int) $booking['id'], $kind);
        $view = SignatureService::view($booking, $kind);
        view('sign/show', [
            'title'   => SignatureService::kinds()[$kind][0],
            'booking' => $booking,
            'kind'    => $kind,
            'active'  => $active,
            'view'    => $view,
            'error'   => $error,
            'form'    => $form,
            'photos'  => ($ins = Inspection::get((int) $booking['id'], $kind === 'return' ? 'in' : 'out')) ? $ins['photos'] : [],
        ], 'layouts/sign');
    }
}
