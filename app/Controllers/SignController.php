<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Models\Booking;
use App\Models\Inspection;
use App\Models\Signature;
use App\Services\BookingService;
use App\Services\InspectionService;
use App\Services\NotificationService;
use App\Services\SignatureService;

/**
 * หน้าเซ็นเอกสารบนมือถือ — สำหรับลูกค้า ไม่ต้องล็อกอิน
 *
 *   /sign?no=<เลขที่จอง>&k=contract&t=<รหัสลิงก์>   เซ็นสัญญาเช่ารถ (วันรับรถ)
 *   /sign?no=<เลขที่จอง>&k=return&t=<รหัสลิงก์>     เซ็นใบรับคืนรถ (วันคืนรถ)
 *
 * ลิงก์ได้จากปุ่มในหน้าการจองของหลังบ้าน (เปิดบนเครื่องพนักงาน / คัดลอก / ส่งทาง LINE)
 * รหัสลิงก์เป็นรหัสสุ่ม 128 บิตของแต่ละการจอง คนที่ไม่มีลิงก์เปิดหน้านี้ไม่ได้
 */
class SignController extends Controller
{
    /** เตรียมข้อมูลทั้งหมดที่หน้าเซ็นต้องใช้ */
    private function context(): array
    {
        $no    = $this->request->str('no');
        $token = $this->request->str('t');
        $kind  = $this->request->str('k', 0, 'contract');
        $kinds = SignatureService::kinds();

        $b = ($no !== '' && preg_match('/^[0-9a-f]{32}$/', $token)) ? Booking::findByNoForContract($no) : null;
        $valid = $b && !empty($b['sign_token']) && hash_equals((string) $b['sign_token'], $token) && isset($kinds[$kind]);

        $ctx = ['valid' => (bool) $valid, 'no' => $no, 'token' => $token, 'kind' => $kind, 'b' => $b,
                'error' => '', 'sig' => null, 'blocker' => null, 'fields' => [], 'termsHtml' => '', 'termsText' => '',
                'insp' => null, 'photos' => [], 'extras' => [], 'docName' => '', 'car' => [], 'viewHash' => ''];
        if (!$valid) {
            return $ctx;
        }

        $docName = $kinds[$kind][0];
        $car     = ['brand' => $b['brand'], 'model' => $b['model'], 'plate' => $b['plate']];
        $insp    = Inspection::get((int) $b['id'], $kind === 'return' ? 'in' : 'out');

        // ข้อความที่ลูกค้าลงนามรับรอง — เก็บทั้งก้อนไว้กับลายเซ็น
        if ($kind === 'contract') {
            $b['mileage_out'] = $b['mileage_out'] ?: ($insp['mileage'] ?? null);   // ถ่ายรูปหน้าปัดไว้แล้วแต่ยังไม่กดส่งมอบ
            $termsHtml = SignatureService::contractTermsHtml($b, '(ตามที่บันทึกในวันรับรถ)');
        } else {
            $termsHtml = '<p>ผู้เช่าได้ตรวจสอบสภาพรถ ณ วันคืนรถร่วมกับผู้รับคืนรถแล้ว และรับทราบเลขไมล์ ระดับน้ำมัน '
                       . 'ความเสียหายที่บันทึก และรายการค่าใช้จ่ายเพิ่มตามที่แสดงในหน้านี้ '
                       . 'เงินประกันความเสียหายจะได้รับคืนหลังหักค่าใช้จ่ายค้างชำระ (ถ้ามี) ตามเงื่อนไขในสัญญาเช่า</p>';
        }
        $termsText = SignatureService::plainText($termsHtml);
        $sig       = Signature::active((int) $b['id'], $kind);

        return array_merge($ctx, [
            'b'         => $b,
            'docName'   => $docName,
            'car'       => $car,
            'insp'      => $insp,
            'photos'    => $insp ? InspectionService::photoMap($insp) : [],
            'extras'    => $kind === 'return' ? Booking::extraLines($b) : [],
            'fields'    => SignatureService::fields($b, $car, $kind),
            'termsHtml' => $termsHtml,
            'termsText' => $termsText,
            'sig'       => $sig,
            'blocker'   => $sig ? null : SignatureService::blocker($b, $kind),
            'viewHash'  => SignatureService::viewHash($b, $car, $kind, $termsText),
        ]);
    }

    private function render(array $ctx): Response
    {
        $headers = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer'];
        $res = $this->view('public/sign', $ctx + [
            'title'   => $ctx['valid'] ? $ctx['docName'] . ' ' . $ctx['b']['booking_no'] : 'ลิงก์ไม่ถูกต้อง',
            'heading' => $ctx['valid'] ? $ctx['docName'] : 'ลิงก์ไม่ถูกต้อง',
            'sub'     => $ctx['valid'] ? 'เลขที่ ' . $ctx['b']['booking_no'] : '',
        ], $ctx['valid'] ? 200 : 404);
        foreach ($headers as $k => $v) {
            $res->header($k, $v);
        }
        return $res;
    }

    public function show(): Response
    {
        return $this->render($this->context());
    }

    public function store(): Response
    {
        $r   = $this->request;
        $ctx = $this->context();
        if (!$ctx['valid'] || $ctx['sig']) {
            return $this->render($ctx);
        }
        $b = $ctx['b'];
        try {
            // ข้อมูลเปลี่ยนระหว่างที่ลูกค้าเปิดหน้านี้ค้างไว้ → ยังไม่รับลายเซ็น ให้ตรวจข้อมูลล่าสุดก่อน
            if (!hash_equals($ctx['viewHash'], (string) $r->post('seen', ''))) {
                throw new \RuntimeException('ร้านมีการปรับข้อมูลหลังจากที่เปิดหน้านี้ กรุณาตรวจข้อมูลด้านล่างอีกครั้งแล้วเซ็นใหม่');
            }
            SignatureService::save(
                $b, $ctx['car'], $ctx['kind'], (string) $r->post('sig', ''), (string) $r->post('name', ''),
                $r->bool('accept'), $r->bool('pdpa'), $ctx['termsText']
            );
            try {
                NotificationService::queue('line', setting('line_admin_user_id'),
                    "✍️ ลูกค้าเซ็น{$ctx['docName']}แล้ว\nเลขที่จอง: {$b['booking_no']}\n"
                    . "รถ: {$b['brand']} {$b['model']} ({$b['plate']})\nผู้ลงนาม: " . $r->str('name'),
                    null, 'bookings', (int) $b['id']);
                NotificationService::flush();
            } catch (\Throwable $e) {
                // แจ้งร้านไม่สำเร็จไม่ควรทำให้ลูกค้าเห็นว่าเซ็นไม่ผ่าน
            }
            return $this->redirect('sign?no=' . rawurlencode($ctx['no']) . '&k=' . $ctx['kind'] . '&t=' . $ctx['token'] . '&done=1');
        } catch (\Throwable $ex) {
            $ctx['error'] = BookingService::publicError($ex, 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
            $ctx['sig']   = Signature::active((int) $b['id'], $ctx['kind']);
            return $this->render($ctx);
        }
    }
}
