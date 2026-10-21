<?php
/**
 * งานอัตโนมัติรายวัน — ตั้งใน cPanel → Cron Jobs (แนะนำวันละครั้ง 08:00)
 *
 *   แบบที่แนะนำ (รันบนเซิร์ฟเวอร์ตรงๆ ไม่ผ่านเว็บ ไม่โดนไฟร์วอลล์ของโฮสต์บล็อก):
 *     /usr/local/bin/php /home/ชื่อผู้ใช้/public_html/โฟลเดอร์ระบบ/cron/daily.php "CRON_KEY"
 *   แบบสำรอง (เรียกผ่านเว็บ):
 *     wget -q -O /dev/null "https://yourdomain.com/โฟลเดอร์ระบบ/cron/daily.php?key=CRON_KEY"
 *
 *   คำสั่งที่ใส่ค่าจริงให้แล้ว คัดลอกได้จากหน้า หลังบ้าน → ตั้งค่า → Cron
 *
 * หน้าที่:
 *   1. เตือนงานประจำรถที่ใกล้ครบกำหนด (ประกัน / พ.ร.บ. / ภาษี / เช็กระยะ)
 *   2. เตือนงานส่งมอบ–รับคืนรถของวันนี้
 *   3. เตือนการจองที่ค้างยืนยันเกิน 24 ชั่วโมง
 *   4. เตือนรถที่เลยกำหนดคืน
 *   5. เตือนลูกค้าทาง LINE ล่วงหน้า 1 วันก่อนรับรถและก่อนคืนรถ
 *   6. สำรองฐานข้อมูล (วันละครั้ง) + ลบไฟล์สำรองที่เก่าเกินกำหนด + แจ้งเจ้าของร้านเดือนละครั้งให้ดาวน์โหลดสำเนา
 *   7. ส่งคิวแจ้งเตือนที่ค้างอยู่ + ล้างบันทึกเหตุการณ์ LINE ที่เก่าเกิน 30 วัน
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/backup.php';

// ---------- ตรวจ key ----------
$isCli   = PHP_SAPI === 'cli';
$key     = $isCli ? ($argv[1] ?? '') : ($_GET['key'] ?? '');
$realKey = (string) setting('cron_key', 'CHANGE-THIS-CRON-KEY');
if (!hash_equals($realKey, (string) $key)) {
    http_response_code(403);
    die("forbidden\n");
}
// รหัสตั้งต้นใครก็รู้ — ยอมให้ใช้ได้เฉพาะตอนรันบนเซิร์ฟเวอร์เอง ถ้าเรียกผ่านเว็บต้องเปลี่ยนรหัสก่อน
if (!$isCli && $realKey === 'CHANGE-THIS-CRON-KEY') {
    http_response_code(403);
    die('กรุณาเปลี่ยน Cron Key ที่ หลังบ้าน → ตั้งค่า ก่อนเรียกงานอัตโนมัติผ่านเว็บ');
}

header('Content-Type: text/plain; charset=utf-8');
$today  = date('Y-m-d');
$lines  = [];
$adminLine = setting('line_admin_user_id');
$adminMail = setting('notify_admin_email');

// ------------------------------------------------------------
// 1) งานประจำรถใกล้ครบกำหนด
// ------------------------------------------------------------
$warnDays = (int) setting('reminder_days_before', '30');
$tasks = db_all(
    "SELECT m.*, c.plate, c.brand, c.model FROM maintenances m JOIN cars c ON c.id = m.car_id
     WHERE m.status = 'pending' AND m.due_date IS NOT NULL
       AND m.due_date <= :d
       AND (m.notified_at IS NULL OR DATE(m.notified_at) < :today)",
    [':d' => date('Y-m-d', strtotime("+$warnDays days")), ':today' => $today]
);
if ($tasks) {
    $msg = "🔧 งานประจำรถที่ต้องจัดการ\n";
    foreach ($tasks as $t) {
        $left = (int) floor((strtotime($t['due_date']) - strtotime($today)) / 86400);
        $msg .= sprintf("• %s (%s) — %s %s\n", $t['title'], $t['plate'],
            $left < 0 ? 'เลยกำหนด ' . abs($left) . ' วัน' : 'อีก ' . $left . ' วัน',
            thai_date($t['due_date']));
        db_update('maintenances', ['notified_at' => date('Y-m-d H:i:s')], 'id', (int) $t['id']);
    }
    notify_queue('line', $adminLine, $msg, null, 'maintenances', null);
    notify_queue('email', $adminMail, $msg, 'งานประจำรถที่ต้องจัดการ');
    $lines[] = 'maintenance reminders: ' . count($tasks);
}

// ------------------------------------------------------------
// 2) งานวันนี้
// ------------------------------------------------------------
$pickups = db_all(
    "SELECT b.*, c.plate, c.brand, c.model FROM bookings b JOIN cars c ON c.id = b.car_id
     WHERE DATE(b.pickup_at) = :d AND b.status IN ('pending','confirmed') ORDER BY b.pickup_at",
    [':d' => $today]
);
$returns = db_all(
    "SELECT b.*, c.plate, c.brand, c.model FROM bookings b JOIN cars c ON c.id = b.car_id
     WHERE DATE(b.return_at) = :d AND b.status = 'picked_up' ORDER BY b.return_at",
    [':d' => $today]
);
if ($pickups || $returns) {
    $msg = '📅 งานวันนี้ ' . thai_date($today, false, true) . "\n";
    if ($pickups) {
        $msg .= "\n🚗 ส่งมอบรถ (" . count($pickups) . ")\n";
        foreach ($pickups as $p) {
            $msg .= sprintf("• %s น. %s (%s) — %s %s\n", date('H:i', strtotime($p['pickup_at'])),
                $p['brand'] . ' ' . $p['model'], $p['plate'], $p['contact_name'], $p['contact_phone']);
        }
    }
    if ($returns) {
        $msg .= "\n🔙 รับคืนรถ (" . count($returns) . ")\n";
        foreach ($returns as $r) {
            $msg .= sprintf("• %s น. %s (%s) — %s %s\n", date('H:i', strtotime($r['return_at'])),
                $r['brand'] . ' ' . $r['model'], $r['plate'], $r['contact_name'], $r['contact_phone']);
        }
    }
    notify_queue('line', $adminLine, $msg, null, 'bookings', null);
    notify_queue('email', $adminMail, $msg, 'งานวันนี้ ' . thai_date($today));
    $lines[] = 'today jobs: ' . (count($pickups) + count($returns));
}

// ------------------------------------------------------------
// 2.5) ยกเลิกการจองที่ไม่จ่ายมัดจำตามเวลาที่ตั้งไว้ (ปิดไว้เป็นค่าตั้งต้น — เปิดที่ หลังบ้าน → ตั้งค่า)
//      การจองที่ยังไม่จ่ายก็กันรถไว้ไม่ให้คนอื่นจอง ถ้าไม่มีใครมายกเลิก รถคันนั้นจะขายไม่ได้ในช่วงนั้น
//      ยกเลิกเฉพาะรายการที่: ยังรอยืนยัน · ยังไม่มีเงินเข้าเลย · ไม่มีสลิปรอตรวจ · จองผ่านหน้าเว็บ
// ------------------------------------------------------------
$expH = (int) setting('pending_expire_hours', '0');
if ($expH > 0) {
    $gone = db_all(
        "SELECT b.id, b.booking_no FROM bookings b
         WHERE b.status = 'pending' AND b.source = 'web' AND b.paid_amount <= 0 AND b.created_at < :t
           AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.status IN ('pending','approved'))",
        [':t' => date('Y-m-d H:i:s', time() - $expH * 3600)]
    );
    foreach ($gone as $g) {
        db_exec("UPDATE bookings SET status = 'cancelled', cancel_reason = :r WHERE id = :i AND status = 'pending'",
            [':r' => 'ระบบยกเลิกอัตโนมัติ: ไม่ชำระมัดจำภายใน ' . $expH . ' ชั่วโมง', ':i' => (int) $g['id']]);
        log_activity('booking_auto_cancelled', 'bookings', (int) $g['id'], $g['booking_no']);
    }
    if ($gone) {
        notify_queue('line', $adminLine, "🗑️ ระบบยกเลิกการจองที่ไม่ชำระมัดจำภายใน {$expH} ชม. (" . count($gone) . " รายการ)\n"
            . implode("\n", array_map(fn($g) => '• ' . $g['booking_no'], array_slice($gone, 0, 15))));
        $lines[] = 'auto-cancelled unpaid: ' . count($gone);
    }
}

// ------------------------------------------------------------
// 3) จองค้างยืนยันเกิน 24 ชม.
// ------------------------------------------------------------
$stale = db_all(
    "SELECT b.*, c.plate FROM bookings b JOIN cars c ON c.id = b.car_id
     WHERE b.status = 'pending' AND b.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
       AND b.pickup_at > NOW()"
);
if ($stale) {
    $msg = "⏳ การจองค้างยืนยันเกิน 24 ชั่วโมง (" . count($stale) . " รายการ)\n";
    foreach ($stale as $s) {
        $msg .= sprintf("• %s %s (%s) รับรถ %s\n", $s['booking_no'], $s['contact_name'],
            $s['contact_phone'], thai_date($s['pickup_at'], true));
    }
    notify_queue('line', $adminLine, $msg);
    $lines[] = 'stale pending: ' . count($stale);
}

// ------------------------------------------------------------
// 4) รถเลยกำหนดคืน
// ------------------------------------------------------------
$overdue = db_all(
    "SELECT b.*, c.plate, c.price_day FROM bookings b JOIN cars c ON c.id = b.car_id
     WHERE b.status = 'picked_up' AND b.return_at < NOW()"
);
if ($overdue) {
    $msg = "⚠️ รถเลยกำหนดคืน (" . count($overdue) . " คัน)\n";
    foreach ($overdue as $o) {
        $hours = (int) floor((time() - strtotime($o['return_at'])) / 3600);
        $msg .= sprintf("• %s (%s) %s %s — เลยมา %d ชม. (ค่าปรับประมาณ %s บาท)\n",
            $o['booking_no'], $o['plate'], $o['contact_name'], $o['contact_phone'], $hours,
            money0(calc_return_charges($o, ['price_day' => $o['price_day']], date('Y-m-d H:i:s'), null, null)['total']));
    }
    notify_queue('line', $adminLine, $msg);
    notify_queue('email', $adminMail, $msg, 'รถเลยกำหนดคืน');
    $lines[] = 'overdue: ' . count($overdue);
}

// ------------------------------------------------------------
// 4.5) ลองส่งข้อความที่ค้างส่งไม่สำเร็จอีกรอบ — ต้องทำก่อนขั้นเตือนลูกค้า
//      (ถ้าทำทีหลัง ข้อความเตือนที่เคยส่งไม่ผ่านจะถูกสร้างใหม่ในขั้นที่ 5 แล้วถูกปลุกซ้ำอีกรอบ = ลูกค้าได้สองข้อความ)
// ------------------------------------------------------------
// ข้อความที่ส่งไม่สำเร็จในช่วง 20 ชม. ที่ผ่านมา (LINE หรืออีเมลขัดข้องชั่วคราว) → ลองส่งอีก 1 รอบ
// ไม่ลองซ้ำ: ช่องทางที่ปิดไว้ / ไม่มีผู้รับ / รายการที่ค้าง "กำลังส่ง" (อาจถึงผู้รับไปแล้ว ส่งซ้ำจะได้ข้อความสองรอบ)
$retry = db_exec(
    "UPDATE notifications SET status = 'queued'
     WHERE status = 'failed' AND created_at >= :t
       AND error IS NOT NULL AND error <> 'กำลังส่ง' AND error NOT LIKE 'ปิดการใช้งาน%' AND error NOT LIKE 'ไม่มี%'",
    [':t' => date('Y-m-d H:i:s', time() - 20 * 3600)]
);
if ($retry) {
    $lines[] = 'retry failed notifications: ' . $retry;
}

// ------------------------------------------------------------
// 5) เตือนลูกค้าทาง LINE — เฉพาะคนที่ผูกบัญชีไว้ และส่งครั้งเดียวต่อการจอง
//    ช่วงที่จับ = วันนี้ถึงพรุ่งนี้ เพื่อให้การเช่า 1 วันที่จองกระชั้นชิดยังได้ข้อความ
//    (ถ้าจับเฉพาะ "พรุ่งนี้" การจองที่รับรถวันนี้ตอนบ่ายจะไม่เคยถูกเตือนเลย)
// ------------------------------------------------------------
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$remind   = 0;
foreach (db_all(
    "SELECT * FROM bookings WHERE status = 'confirmed'
       AND DATE(pickup_at) BETWEEN :a AND :b AND pickup_at > NOW()",
    [':a' => $today, ':b' => $tomorrow]
) as $bk) {
    if (line_notify_customer('pickup_reminder', $bk, null, [], true)) {
        $remind++;
    }
}
foreach (db_all(
    "SELECT * FROM bookings WHERE status = 'picked_up'
       AND DATE(return_at) BETWEEN :a AND :b AND return_at > NOW()",
    [':a' => $today, ':b' => $tomorrow]
) as $bk) {
    if (line_notify_customer('return_reminder', $bk, null, [], true)) {
        $remind++;
    }
}
if ($remind) {
    $lines[] = 'customer reminders: ' . $remind;
}

// ------------------------------------------------------------
// 6) สำรองฐานข้อมูล — วันละครั้ง (ถ้า cron ถูกเรียกถี่กว่านั้นจะข้าม ไม่สำรองซ้ำ)
// ------------------------------------------------------------
try {
    $lastBk = (string) setting('backup_last_at');
    if ($lastBk === '' || time() - strtotime($lastBk) > 20 * 3600) {
        $bk      = backup_create();
        $pruned  = backup_prune();
        $lines[] = 'backup: ' . human_bytes($bk['size']) . ', ' . $bk['rows'] . ' rows' . ($pruned ? ", pruned $pruned" : '');

        // เดือนละครั้ง: บอกเจ้าของร้านให้ดาวน์โหลดสำเนาไปเก็บนอกเซิร์ฟเวอร์
        // (ไม่แนบไฟล์ไปกับอีเมล เพราะในฐานข้อมูลมีข้อมูลส่วนบุคคลของลูกค้า)
        $ym = date('Y-m');
        if ((string) setting('backup_notice_month') !== $ym) {
            $msg = "🗄️ สำเนาฐานข้อมูลประจำเดือน " . thai_date(date('Y-m-d')) . " พร้อมแล้ว
"
                 . 'ขนาด ' . human_bytes($bk['size']) . ' · ' . number_format($bk['rows']) . " แถว
"
                 . "เข้าสู่ระบบแล้วดาวน์โหลดไปเก็บไว้นอกเซิร์ฟเวอร์ได้ที่
" . site_url('admin/backups.php');
            notify_queue('line', $adminLine, $msg);
            notify_queue('email', $adminMail, $msg, 'สำเนาฐานข้อมูลประจำเดือน');
            set_setting('backup_notice_month', $ym);
        }
    }
} catch (Throwable $e) {
    $lines[] = 'backup FAILED: ' . $e->getMessage();
    notify_queue('line', $adminLine, "⚠️ สำรองฐานข้อมูลอัตโนมัติไม่สำเร็จ
" . $e->getMessage());
    notify_queue('email', $adminMail, "สำรองฐานข้อมูลอัตโนมัติไม่สำเร็จ
" . $e->getMessage(), 'สำรองข้อมูลไม่สำเร็จ');
}

// ------------------------------------------------------------
// 7) ส่งคิวที่ค้าง + เก็บกวาด
// ------------------------------------------------------------
$sent = notify_flush(50);
$lines[] = 'notifications sent: ' . $sent;

try {
    db_exec('DELETE FROM line_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
} catch (Throwable $e) {
    // ยังไม่ได้อัปเดตฐานข้อมูลเป็นเวอร์ชัน 4 — ข้ามไป ไม่ให้งานรายวันล้ม
}
set_setting('cron_last_at', date('Y-m-d H:i:s'));

log_activity('cron_daily', null, null, implode(' | ', $lines));
echo "thonglorcarrent cron " . date('Y-m-d H:i:s') . "\n" . implode("\n", $lines) . "\n";
