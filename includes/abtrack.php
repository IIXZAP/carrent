<?php
/**
 * เก็บสถิติเทียบ UX สองเวอร์ชัน (A = ดีไซน์เดิม, B = ดีไซน์ใหม่)
 *
 * หลักการ: เทียบดีไซน์ด้วยความรู้สึกไม่ได้ ต้องดูว่าคนหลุดตรงไหน
 * จึงเก็บแค่ 5 จุดในกรวยการจอง ไม่เก็บข้อมูลส่วนบุคคลใดๆ
 *
 *   view_home   → เข้าหน้าแรก
 *   view_car    → เปิดดูรายละเอียดรถ (เริ่มสนใจจริง)
 *   start_form  → แตะช่องแรกในฟอร์มจอง (ตั้งใจจอง)
 *   submit      → กดยืนยันการจอง
 *   booked      → จองสำเร็จ
 *
 * ทั้งสองเวอร์ชันเขียนลงตารางเดียวกันในฐานข้อมูลของตัวเอง
 * แล้วหน้า ab-report.php ดึงมารวมเทียบกัน
 */
// functions.php ให้ start_session() / settings() — จำเป็นเมื่อถูกเรียกตรงจาก api/track.php
// ที่ไม่ได้โหลด pricing.php มาก่อน
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

/** ไอดีผู้เยี่ยมชมแบบไม่ระบุตัวตน — สุ่มขึ้นมา ไม่ผูกกับคนจริง */
function ab_visitor(): string
{
    start_session();
    if (empty($_SESSION['ab_vid'])) {
        $_SESSION['ab_vid'] = bin2hex(random_bytes(8));
    }
    return $_SESSION['ab_vid'];
}

/** บันทึก 1 เหตุการณ์ — ล้มเหลวเงียบๆ ไม่ให้กระทบหน้าเว็บ */
function ab_track(string $step, ?int $carId = null): void
{
    if (!defined('SITE_VARIANT')) {
        return;
    }
    try {
        $ua     = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $device = preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 'mobile' : 'desktop';

        db_insert('ab_events', [
            'variant'    => SITE_VARIANT,
            'step'       => $step,
            'visitor_id' => ab_visitor(),
            'car_id'     => $carId,
            'device'     => $device,
        ]);
    } catch (Throwable $e) {
        // สถิติพังไม่ควรทำให้ลูกค้าจองไม่ได้
    }
}

/** กันนับซ้ำ — 1 คน 1 ขั้น นับครั้งเดียวต่อเซสชัน */
function ab_track_once(string $step, ?int $carId = null): void
{
    start_session();
    $key = 'ab_done_' . $step . ($carId ? '_' . $carId : '');
    if (!empty($_SESSION[$key])) {
        return;
    }
    $_SESSION[$key] = 1;
    ab_track($step, $carId);
}

/**
 * สรุปกรวยของเวอร์ชันนี้ — นับเป็น "จำนวนคน" ไม่ใช่จำนวนครั้ง
 * $device: 'all' | 'mobile' | 'desktop'
 */
function ab_funnel(string $from, string $to, string $device = 'all'): array
{
    $where = 'DATE(created_at) BETWEEN :a AND :b';
    $p     = [':a' => $from, ':b' => $to];
    if ($device !== 'all') {
        $where .= ' AND device = :d';
        $p[':d'] = $device;
    }

    $rows = db_all(
        "SELECT step, COUNT(DISTINCT visitor_id) AS n
         FROM ab_events WHERE $where GROUP BY step",
        $p
    );

    $out = ['view_home' => 0, 'view_car' => 0, 'start_form' => 0, 'submit' => 0, 'booked' => 0];
    foreach ($rows as $r) {
        if (isset($out[$r['step']])) {
            $out[$r['step']] = (int) $r['n'];
        }
    }
    return $out;
}

/** ชื่อขั้นตอนเป็นภาษาไทย */
function ab_step_label(string $step): string
{
    return [
        'view_home'  => 'เข้าหน้าแรก',
        'view_car'   => 'เปิดดูรถ',
        'start_form' => 'เริ่มกรอกฟอร์ม',
        'submit'     => 'กดยืนยัน',
        'booked'     => 'จองสำเร็จ',
    ][$step] ?? $step;
}
