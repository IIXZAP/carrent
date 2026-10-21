<?php
/**
 * หน้างานการจอง 1 รายการ — ยืนยัน / ส่งมอบรถ / รับคืนรถ / ยกเลิก / บันทึกเงิน
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/inspection.php';
require_once __DIR__ . '/../includes/signature.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_login();

/** ปุ่มถ่ายรูปสภาพรถ + สถานะความครบ — ใช้ในกล่องส่งมอบและกล่องรับคืน */
function inspect_block(int $bookingId, string $kind, ?array $ins, array $prog): string
{
    $link  = url('admin/inspection.php?booking=' . $bookingId . '&kind=' . $kind);
    $cls   = $prog['complete'] ? 'ok' : 'warn';
    $label = $prog['done'] > 0
        ? 'ถ่ายแล้ว ' . $prog['done'] . '/' . $prog['required'] . ' มุม'
        : 'ยังไม่ได้ถ่ายรูปสภาพรถ';
    return '<a class="btn btn-ghost btn-block mb-2" href="' . e($link) . '">'
         . icon('camera') . ' ถ่ายรูปสภาพรถ'
         . ' <span class="badge badge-' . $cls . '" style="margin-left:6px">' . e($label) . '</span></a>';
}

$id = (int) ($_GET['id'] ?? 0);
$b  = db_one('SELECT b.*, c.brand, c.model, c.plate, c.color, c.mileage AS car_mileage
              FROM bookings b JOIN cars c ON c.id = b.car_id WHERE b.id = :id', [':id' => $id]);
if (!$b) {
    admin_head('ไม่พบการจอง', 'bookings.php');
    echo '<div class="panel center"><h3>ไม่พบการจองนี้</h3><a class="btn" href="' . url('admin/bookings.php') . '">กลับรายการ</a></div>';
    admin_foot();
    exit;
}

$car = db_one('SELECT * FROM cars WHERE id = :c', [':c' => (int) $b['car_id']]) ?: [];

// ------------------------------------------------------------
// ตัวอย่างการคิดเงิน (เรียกด้วย fetch จากหน้านี้เอง) — ตอบเป็น JSON
//   ?preview=return&km=&fuel=   ค่าใช้จ่ายเพิ่มตอนคืนรถ ณ เวลานี้
//   ?preview=extend&ret=        ค่าเช่าเพิ่มถ้าเลื่อนวันคืน
// ใช้ฟังก์ชันเดียวกับตอนบันทึกจริง ตัวเลขที่เห็นกับที่บันทึกจึงตรงกันเสมอ
// ------------------------------------------------------------
if (isset($_GET['preview'])) {
    if ($_GET['preview'] === 'return') {
        $km   = ($_GET['km'] ?? '') !== '' ? max(0, (int) $_GET['km']) : null;
        $fuel = ($_GET['fuel'] ?? '') !== '' ? max(0, min(8, (int) $_GET['fuel'])) : null;
        $ts   = ($_GET['at'] ?? '') !== '' ? strtotime((string) $_GET['at']) : false;
        $at   = date('Y-m-d H:i:s', $ts !== false ? min($ts, time()) : time());
        json_out(['ok' => true] + calc_return_charges($b, $car, $at, $km, $fuel));
    }
    if ($_GET['preview'] === 'extend') {
        $q = extension_quote($b, $car, (string) ($_GET['ret'] ?? ''));
        if ($q['ok']) {
            $q['new_return_text'] = thai_date($q['new_return'], true);
        }
        json_out($q);
    }
    json_out(['ok' => false, 'error' => 'unknown'], 400);
}

// ------------------------------------------------------------
// การกระทำ
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';

    // สถานะที่อนุญาตให้ทำแต่ละการกระทำ — กันกดย้อนขั้นหรือข้ามขั้นจากหน้าที่เปิดค้างไว้
    // (เช่น เปิดสองแท็บ แท็บหนึ่งยกเลิกไปแล้ว อีกแท็บยังกดส่งมอบรถได้)
    $allowed = [
        'confirm'  => ['pending'],
        'pickup'   => ['pending', 'confirmed'],
        'return'   => ['picked_up'],
        'complete' => ['returned'],
        // ยกเลิกได้เฉพาะก่อนส่งมอบรถ — ถ้ายกเลิกตอนรถอยู่กับลูกค้า รถจะกลับมา "ว่าง" ในระบบทั้งที่ยังไม่ได้คืน
        'cancel'   => ['pending', 'confirmed'],
        'extend'   => ['pending', 'confirmed', 'picked_up'],
        'security_settle' => ['returned', 'completed', 'cancelled'],
    ];
    try {
        if (isset($allowed[$act]) && !in_array($b['status'], $allowed[$act], true)) {
            [$curLabel] = booking_status_label($b['status']);
            throw new RuntimeException('ทำรายการนี้ไม่ได้ เพราะการจองอยู่ในสถานะ "' . $curLabel . '" แล้ว — หน้านี้อาจเปิดค้างไว้ กรุณาตรวจสถานะล่าสุด');
        }

        if ($act === 'confirm') {
            db_update('bookings', ['status' => 'confirmed'], 'id', $id);
            log_activity('booking_confirmed', 'bookings', $id);
            $fresh = db_one('SELECT * FROM bookings WHERE id = :i', [':i' => $id]);
            try { notify_booking_confirmed($fresh, $b); } catch (Throwable $e) {}
            flash('ok', 'ยืนยันการจองแล้ว');

        } elseif ($act === 'pickup') {
            $km   = max(0, (int) ($_POST['mileage_out'] ?? 0));
            $fuel = max(0, min(8, (int) ($_POST['fuel_out'] ?? 8)));
            db_update('bookings', [
                'status'       => 'picked_up',
                'mileage_out'  => $km ?: null,
                'fuel_out'     => $fuel,
                'picked_up_at' => date('Y-m-d H:i:s'),
            ], 'id', $id);
            if ($km > 0) {
                db_update('cars', ['mileage' => $km], 'id', (int) $b['car_id']);
            }
            // ส่งมอบรถแล้ว = สภาพรถ ณ วันส่งมอบต้องนิ่ง แก้ย้อนหลังไม่ได้
            // ถ้าพนักงานถ่ายรูปแต่ไม่ได้กรอกเลขไมล์ในหน้าถ่ายรูป ให้ใช้ค่าจากฟอร์มส่งมอบเติมก่อนล็อก
            // ไม่งั้นใบแนบท้ายสัญญาจะไม่มีเลขไมล์ ทั้งที่กรอกไว้แล้วในอีกหน้า
            // ไม่ได้ถ่ายรูปก็ต้องมีบันทึกรอบส่งมอบ (เลขไมล์ + น้ำมัน) และถูกล็อกเหมือนกัน
            inspection_ensure($id, 'out');
            db_exec("UPDATE inspections SET mileage = COALESCE(mileage, :km), fuel_level = COALESCE(fuel_level, :f)
                     WHERE booking_id = :b AND kind = 'out' AND locked_at IS NULL",
                [':km' => $km ?: null, ':f' => $fuel, ':b' => $id]);
            inspection_lock($id, 'out');
            log_activity('booking_pickup', 'bookings', $id, $km);
            flash('ok', 'บันทึกการส่งมอบรถแล้ว');

        } elseif ($act === 'return') {
            $km     = max(0, (int) ($_POST['mileage_in'] ?? 0));
            $fuelIn = max(0, min(8, (int) ($_POST['fuel_in'] ?? 8)));
            if ($km > 0 && (int) $b['mileage_out'] > 0 && $km < (int) $b['mileage_out']) {
                throw new RuntimeException('เลขไมล์ตอนรับคืน (' . number_format($km) . ') น้อยกว่าตอนส่งมอบ ('
                    . number_format((int) $b['mileage_out']) . ') กรุณาตรวจตัวเลขอีกครั้ง');
            }
            // เวลารับคืนจริง — ค่าเริ่มต้นคือตอนนี้ แต่แก้ย้อนหลังได้ (รถคืน 18:00 พนักงานมาบันทึก 21:00
            // ถ้าใช้เวลาที่กดบันทึก ลูกค้าจะโดนค่าปรับคืนช้าทั้งที่คืนตรงเวลา)
            $now = date('Y-m-d H:i:s');
            // ช่องเวลาในฟอร์มถูกเติมไว้ตอนเปิดหน้า — ถ้าพนักงานไม่ได้แก้ ให้ใช้เวลาที่กดบันทึกจริง
            // (หน้าเปิดค้างไว้ 2 ชั่วโมงแล้วค่อยกดบันทึก ต้องไม่ทำให้ลูกค้าที่คืนช้ารอดค่าปรับ)
            $at  = !empty($_POST['rt_manual']) ? trim((string) ($_POST['returned_at'] ?? '')) : '';
            if ($at !== '') {
                $ts = strtotime($at);
                if ($ts === false) {
                    throw new RuntimeException('รูปแบบเวลารับคืนรถไม่ถูกต้อง');
                }
                if ($ts > time() + 300) {
                    throw new RuntimeException('เวลารับคืนรถอยู่ในอนาคต กรุณาตรวจอีกครั้ง');
                }
                if (!empty($b['picked_up_at']) && $ts < strtotime($b['picked_up_at'])) {
                    throw new RuntimeException('เวลารับคืนรถอยู่ก่อนเวลาส่งมอบรถ (' . thai_date($b['picked_up_at'], true) . ')');
                }
                $now = date('Y-m-d H:i:s', $ts);
            }

            // ยอดแนะนำคิดจากข้อมูลจริง ณ เวลาที่กดบันทึก — พนักงานแก้จำนวนหรือยกเว้นได้ในฟอร์ม
            // เก็บทั้ง "ยอดแนะนำ" และ "ยอดที่คิดจริง" เพื่อให้ตรวจย้อนหลังได้ว่ารายการไหนถูกยกเว้น
            $suggest = calc_return_charges($b, $car, $now, $km ?: null, $fuelIn);
            $posted  = is_array($_POST['chg'] ?? null) ? $_POST['chg'] : [];
            $lines   = [];
            foreach ($suggest['lines'] as $l) {
                $in = $posted[$l['key']] ?? null;
                if (is_array($in)) {
                    $on  = !empty($in['on']);
                    $amt = $on ? max(0, min(1000000, round((float) (($in['amount'] ?? '') !== '' ? $in['amount'] : $l['amount']), 2))) : 0.0;
                } else {
                    $on  = true;              // รายการที่เพิ่งเกิดระหว่างเปิดหน้าค้างไว้ → คิดตามเงื่อนไข
                    $amt = $l['amount'];
                }
                $lines[] = ['key' => $l['key'], 'label' => $l['label'], 'detail' => $l['detail'],
                            'suggested' => $l['amount'], 'amount' => $amt, 'waived' => !$on || $amt <= 0];
            }
            $otherAmt = max(0, min(1000000, round((float) ($_POST['other_amount'] ?? ($_POST['extra_charge'] ?? 0)), 2)));
            $otherLbl = trim((string) ($_POST['other_label'] ?? ($_POST['extra_note'] ?? '')));
            if ($otherAmt > 0) {
                $lines[] = ['key' => 'other', 'label' => $otherLbl !== '' ? mb_substr($otherLbl, 0, 120) : 'ค่าใช้จ่ายอื่น',
                            'detail' => '', 'suggested' => $otherAmt, 'amount' => $otherAmt, 'waived' => false];
            }
            $extra = round(array_sum(array_column($lines, 'amount')), 2);
            $note  = implode(' · ', array_map(
                fn($l) => $l['label'] . ' ' . ($l['waived'] ? '(ยกเว้น)' : money0($l['amount']) . ' บ.'), $lines));

            db_update('bookings', [
                'status'          => 'returned',
                'mileage_in'      => $km ?: null,
                'fuel_in'         => $fuelIn,
                'returned_at'     => $now,
                'extra_charge'    => $extra,
                'extra_note'      => $note !== '' ? mb_substr($note, 0, 250) : null,
                'extra_breakdown' => $lines ? json_encode($lines, JSON_UNESCAPED_UNICODE) : null,
                'damage_note'     => trim($_POST['damage_note'] ?? '') ?: null,
            ], 'id', $id);
            if ($km > 0) {
                db_update('cars', ['mileage' => $km], 'id', (int) $b['car_id']);
            }
            // ค่าใช้จ่ายเพิ่มเติมทำให้ยอดที่ต้องชำระเปลี่ยน → คำนวณสถานะการชำระเงินใหม่
            recalc_booking_payment($id);
            inspection_ensure($id, 'in');
            db_exec("UPDATE inspections SET mileage = COALESCE(mileage, :km), fuel_level = COALESCE(fuel_level, :f)
                     WHERE booking_id = :b AND kind = 'in' AND locked_at IS NULL",
                [':km' => $km ?: null, ':f' => $fuelIn, ':b' => $id]);
            inspection_lock($id, 'in');
            log_activity('booking_return', 'bookings', $id, ['km' => $km, 'extra' => $extra, 'lines' => $lines]);
            $fresh = db_one('SELECT * FROM bookings WHERE id = :i', [':i' => $id]);
            try { notify_booking_returned($fresh, $car, $lines); } catch (Throwable $e) {}
            flash('ok', 'บันทึกการรับคืนรถแล้ว' . ($extra > 0 ? ' — ค่าใช้จ่ายเพิ่ม ' . money0($extra) . ' บาท' : ''));

        } elseif ($act === 'extend') {
            $q = extension_quote($b, $car, (string) ($_POST['new_return'] ?? ''));
            if (!$q['ok']) {
                throw new RuntimeException($q['error']);
            }
            // พนักงานลดราคาส่วนเช่าต่อได้ แต่ไม่เกินยอดที่ระบบคิด (ถ้าจะคิดเพิ่มให้ใช้ค่าใช้จ่ายเพิ่มตอนคืนรถ)
            $charge = ($_POST['charge'] ?? '') !== '' ? max(0, min($q['charge'], round((float) $_POST['charge'], 2))) : $q['charge'];
            $rate   = (float) $b['vat_rate'];
            $net    = $rate > 0 ? round($charge / (1 + $rate / 100), 2) : $charge;
            $vat    = round($charge - $net, 2);
            $bd     = json_decode((string) $b['rate_breakdown'], true) ?: ['rental' => [], 'addons' => []];
            $addDays = $q['days_new'] - $q['days_old'];
            $bd['rental'][] = ['label' => 'เช่าต่อถึง ' . thai_date($q['new_return'], true), 'qty' => max(1, $addDays),
                               'unit' => $addDays > 0 ? round($q['rental_delta'] / $addDays, 2) : $q['rental_delta'], 'amount' => $q['rental_delta']];
            if ($q['addon_delta'] > 0) {
                $bd['addons'][] = ['key' => 'ext', 'label' => 'บริการเสริมช่วงเช่าต่อ (' . $addDays . ' วัน)', 'qty' => $addDays,
                                   'unit' => round($q['addon_delta'] / max(1, $addDays), 2), 'amount' => $q['addon_delta']];
            }
            db()->beginTransaction();
            try {
                // ล็อกแถวของรถ (แบบเดียวกับหน้าจองของลูกค้า) แล้วตรวจซ้ำ — กันลูกค้ารายอื่นจองช่วงเดียวกันเข้ามาพร้อมกันพอดี
                db_one('SELECT id FROM cars WHERE id = :c FOR UPDATE', [':c' => (int) $b['car_id']]);
                $again = extension_quote(db_one('SELECT * FROM bookings WHERE id = :i', [':i' => $id]), $car, $q['new_return']);
                if (!$again['ok']) {
                    throw new RuntimeException($again['error']);
                }
                db_update('bookings', [
                    'return_at'      => $q['new_return'],
                    'rental_days'    => $q['days_new'],
                    'rental_amount'  => round((float) $b['rental_amount'] + $q['rental_delta'], 2),
                    'addon_amount'   => round((float) $b['addon_amount'] + $q['addon_delta'], 2),
                    'discount'       => round((float) $b['discount'] + max(0, $q['delta'] - $net), 2),
                    'subtotal'       => round((float) $b['subtotal'] + $net, 2),
                    'vat_amount'     => round((float) $b['vat_amount'] + $vat, 2),
                    'grand_total'    => round((float) $b['grand_total'] + $charge, 2),
                    'rate_breakdown' => json_encode($bd, JSON_UNESCAPED_UNICODE),
                ], 'id', $id);
                db()->commit();
            } catch (Throwable $e) {
                db_rollback();
                throw $e;
            }
            recalc_booking_payment($id);
            // เตือนวันคืนรถที่เคยส่งไปเป็นของกำหนดเดิม — ปลดออกเพื่อให้ระบบเตือนกำหนดใหม่ได้อีกครั้ง
            db_exec("UPDATE notifications SET tpl_key = 'cust_return_reminder_old'
                     WHERE tpl_key = 'cust_return_reminder' AND ref_table = 'bookings' AND ref_id = :i", [':i' => $id]);
            log_activity('booking_extended', 'bookings', $id,
                ['from' => $b['return_at'], 'to' => $q['new_return'], 'charge' => $charge, 'computed' => $q['charge']]);
            $fresh = db_one('SELECT * FROM bookings WHERE id = :i', [':i' => $id]);
            try {
                if (line_notify_customer('extended', $fresh, $car, ['จำนวน' => money0($charge)])) {
                    notify_flush();
                }
            } catch (Throwable $e) {}
            flash('ok', 'เลื่อนกำหนดคืนเป็น ' . thai_date($q['new_return'], true) . ' แล้ว — ค่าเช่าเพิ่ม ' . money0($charge) . ' บาท');

        } elseif ($act === 'security_settle') {
            db()->beginTransaction();
            try {
                db_one('SELECT id FROM bookings WHERE id = :i FOR UPDATE', [':i' => $id]);   // กันกดปิดเงินประกันสองครั้งพร้อมกัน
                $held = security_held($id);
                if ($held <= 0) {
                    throw new RuntimeException('ไม่มีเงินประกันที่ร้านถืออยู่สำหรับการจองนี้ (อาจคืนไปแล้ว)');
                }
                // ค่าใช้จ่ายที่พบหลังรับคืนรถ (ค่าปรับจราจรที่ตามมา ค่าซ่อมจากใบเสนอราคาอู่ ค่าธรรมเนียมยกเลิก)
                // บันทึกเป็นรายการค่าใช้จ่ายเพิ่มของการจองก่อน แล้วจึงหักจากเงินประกัน — ที่มาของเงินที่หักจึงตรวจย้อนหลังได้
                $addAmt = max(0, min($held, round((float) ($_POST['add_amount'] ?? 0), 2)));
                $addLbl = mb_substr(trim((string) ($_POST['add_label'] ?? '')), 0, 120);
                // ยอดค้างชำระ "ก่อน" บวกรายการใหม่ — เป็นตัวเลขเดียวกับที่หน้าจอแสดงให้พนักงานเห็น
                // การจองที่ยกเลิก: ค่าเช่าที่ยังไม่จ่ายไม่ใช่หนี้ (ไม่ได้เช่าจริง) — นับเฉพาะค่าใช้จ่ายที่บันทึกไว้
                $pre = db_one('SELECT status, grand_total, extra_charge, paid_amount FROM bookings WHERE id = :i', [':i' => $id]);
                $owe = $pre['status'] === 'cancelled'
                    ? max(0, round((float) $pre['extra_charge'] - max(0, (float) $pre['paid_amount']), 2))
                    : max(0, round((float) $pre['grand_total'] + (float) $pre['extra_charge'] - (float) $pre['paid_amount'], 2));
                if ($addAmt > 0) {
                    if ($addLbl === '') {
                        throw new RuntimeException('กรุณาระบุว่าค่าใช้จ่ายที่หักเพิ่มคือค่าอะไร');
                    }
                    $row   = db_one('SELECT extra_charge, extra_note, extra_breakdown FROM bookings WHERE id = :i', [':i' => $id]);
                    $lines = booking_extra_lines($row);
                    $lines[] = ['key' => 'after', 'label' => $addLbl, 'detail' => 'บันทึกหลังรับคืนรถ ' . thai_date(date('Y-m-d H:i:s'), true),
                                'suggested' => $addAmt, 'amount' => $addAmt, 'waived' => false];
                    db_update('bookings', [
                        'extra_charge'    => round((float) $row['extra_charge'] + $addAmt, 2),
                        'extra_breakdown' => json_encode($lines, JSON_UNESCAPED_UNICODE),
                        'extra_note'      => mb_substr(trim((string) $row['extra_note'] . ' · ' . $addLbl . ' ' . money0($addAmt) . ' บ.', ' ·'), 0, 250),
                    ], 'id', $id);
                }
                $cur = db_one('SELECT status, grand_total, extra_charge, paid_amount FROM bookings WHERE id = :i', [':i' => $id]);
                // ยอดที่หัก = ส่วนที่พนักงานเลือกหักจากยอดค้างเดิม (ไม่เกินยอดค้าง) + ค่าใช้จ่ายที่เพิ่งบันทึก (หักเต็มเสมอ)
                // สูตรเดียวกับที่หน้าจอคำนวณ "คืนลูกค้า" ให้เห็นก่อนกดยืนยัน
                $deduct  = max(0, min($held, $addAmt + max(0, min($owe, round((float) ($_POST['deduct'] ?? 0), 2)))));
                $refund  = round($held - $deduct, 2);
                $method  = in_array($_POST['refund_method'] ?? '', ['cash', 'transfer', 'promptpay'], true) ? $_POST['refund_method'] : 'cash';
                $nowTs   = date('Y-m-d H:i:s');
                $base    = ['booking_id' => $id, 'paid_at' => $nowTs, 'status' => 'approved',
                            'approved_by' => auth_user()['id'], 'approved_at' => $nowTs];
                if ($deduct > 0) {
                    db_insert('payments', $base + ['kind' => (float) $cur['extra_charge'] > 0 ? 'extra' : 'balance',
                        'method' => 'deduct', 'amount' => $deduct, 'note' => 'หักจากเงินประกัน' . ($addAmt > 0 ? ' — ' . $addLbl : '')]);
                }
                if ($refund > 0) {
                    db_insert('payments', $base + ['kind' => 'security_refund', 'method' => $method, 'amount' => $refund,
                        'note' => mb_substr(trim((string) ($_POST['refund_note'] ?? '')), 0, 200) ?: 'คืนเงินประกัน']);
                }
                db()->commit();
            } catch (Throwable $e) {
                db_rollback();
                throw $e;
            }
            recalc_booking_payment($id);
            log_activity('security_settled', 'bookings', $id, ['held' => $held, 'deduct' => $deduct, 'refund' => $refund, 'method' => $method, 'added' => $addAmt, 'added_label' => $addLbl]);
            $fresh = db_one('SELECT * FROM bookings WHERE id = :i', [':i' => $id]);
            try {
                if (line_notify_customer('deposit_refunded', $fresh, $car, ['คืนเงิน' => money0($refund), 'หัก' => money0($deduct)])) {
                    notify_flush();
                }
            } catch (Throwable $e) {}
            flash('ok', 'ปิดเงินประกันแล้ว — หักชำระ ' . money0($deduct) . ' บาท · คืนลูกค้า ' . money0($refund) . ' บาท');

        } elseif ($act === 'sign_send') {
            // ส่งลิงก์หน้าเซ็นเอกสารให้ลูกค้าทาง LINE (เฉพาะลูกค้าที่ผูกบัญชีไว้)
            $kind = isset(signature_kinds()[$_POST['kind'] ?? '']) ? (string) $_POST['kind'] : 'contract';
            if ($msg = signature_blocker($b, $kind)) {
                throw new RuntimeException($msg);
            }
            if (signature_get($id, $kind)) {
                throw new RuntimeException('เอกสารนี้ลูกค้าลงนามแล้ว');
            }
            if (!line_user_for_booking($b)) {
                throw new RuntimeException('ลูกค้ายังไม่ได้ผูกบัญชี LINE กับการจองนี้ — ใช้ปุ่ม "คัดลอกลิงก์" แล้วส่งให้ลูกค้าทางช่องทางอื่นแทน');
            }
            if (!line_notify_customer('sign_request', $b, $car, ['ลิงก์เซ็น' => sign_url($b, $kind), 'เอกสาร' => signature_kinds()[$kind][0]])) {
                throw new RuntimeException('การแจ้งเตือนลูกค้าทาง LINE ถูกปิดอยู่ — เปิดได้ที่เมนู LINE หรือใช้ปุ่ม "คัดลอกลิงก์" แทน');
            }
            notify_flush();
            $st = db_one("SELECT status, error FROM notifications WHERE tpl_key = 'cust_sign_request' AND ref_table = 'bookings' AND ref_id = :i ORDER BY id DESC LIMIT 1", [':i' => $id]);
            if (($st['status'] ?? '') !== 'sent') {
                throw new RuntimeException('ส่ง LINE ไม่สำเร็จ' . (!empty($st['error']) ? ' (' . $st['error'] . ')' : '') . ' — ใช้ปุ่ม "คัดลอกลิงก์" แทนได้');
            }
            log_activity('signature_link_sent', 'bookings', $id, $kind);
            flash('ok', 'ส่งลิงก์เซ็น' . signature_kinds()[$kind][0] . 'ให้ลูกค้าทาง LINE แล้ว');

        } elseif ($act === 'sign_void') {
            // ขอให้ลูกค้าเซ็นใหม่ — ฉบับเดิมไม่ถูกลบ เก็บเป็นประวัติพร้อมเหตุผล
            if (!is_admin()) {
                throw new RuntimeException('การยกเลิกลายเซ็นทำได้เฉพาะเจ้าของร้าน');
            }
            $kind   = isset(signature_kinds()[$_POST['kind'] ?? '']) ? (string) $_POST['kind'] : 'contract';
            $reason = trim((string) ($_POST['void_reason'] ?? ''));
            $sg     = signature_get($id, $kind);
            if (!$sg) {
                throw new RuntimeException('ไม่พบลายเซ็นที่ใช้อยู่ของเอกสารนี้');
            }
            if (mb_strlen($reason) < 3) {
                throw new RuntimeException('กรุณาระบุเหตุผลที่ขอให้ลูกค้าเซ็นใหม่');
            }
            signature_void((int) $sg['id'], (int) auth_user()['id'], $reason);
            log_activity('signature_voided', 'bookings', $id, ['kind' => $kind, 'signature_id' => (int) $sg['id'], 'reason' => $reason]);
            flash('warn', 'ยกเลิกลายเซ็นฉบับเดิมแล้ว (เก็บไว้เป็นประวัติ) — ส่งลิงก์ให้ลูกค้าเซ็นใหม่ได้');

        } elseif ($act === 'complete') {
            db_update('bookings', ['status' => 'completed'], 'id', $id);
            log_activity('booking_completed', 'bookings', $id);
            flash('ok', 'ปิดงานเรียบร้อย');

        } elseif ($act === 'cancel') {
            db_update('bookings', [
                'status'        => 'cancelled',
                'cancel_reason' => trim($_POST['cancel_reason'] ?? '') ?: null,
            ], 'id', $id);
            log_activity('booking_cancelled', 'bookings', $id, $_POST['cancel_reason'] ?? '');
            flash('warn', 'ยกเลิกการจองแล้ว');

        } elseif ($act === 'payment') {
            $amount = round((float) ($_POST['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new RuntimeException('กรุณาระบุจำนวนเงิน');
            }
            if ($amount > 5000000) {
                throw new RuntimeException('จำนวนเงินสูงผิดปกติ กรุณาตรวจตัวเลขอีกครั้ง');
            }
            $pid = db_insert('payments', [
                'booking_id'  => $id,
                'kind'        => in_array($_POST['kind'] ?? '', ['deposit','balance','security','extra','refund'], true) ? $_POST['kind'] : 'balance',
                'method'      => in_array($_POST['method'] ?? '', ['transfer','cash','card','promptpay'], true) ? $_POST['method'] : 'cash',
                'amount'      => $amount,
                'paid_at'     => date('Y-m-d H:i:s'),
                'status'      => 'approved',
                'approved_by' => auth_user()['id'],
                'approved_at' => date('Y-m-d H:i:s'),
                'note'        => trim($_POST['pnote'] ?? '') ?: null,
            ]);
            recalc_booking_payment($id);
            log_activity('payment_added', 'payments', $pid, $amount);
            flash('ok', 'บันทึกการชำระเงินแล้ว');

        } elseif ($act === 'note') {
            db_update('bookings', ['note' => trim($_POST['note'] ?? '') ?: null], 'id', $id);
            flash('ok', 'บันทึกหมายเหตุแล้ว');
        }
    } catch (Throwable $ex) {
        flash('danger', public_error($ex, 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
    }
    redirect('admin/booking-view.php?id=' . $id);
}

$payments  = db_all('SELECT p.*, u.name AS approver FROM payments p LEFT JOIN users u ON u.id = p.approved_by
                     WHERE p.booking_id = :b ORDER BY p.id DESC', [':b' => $id]);
$customer  = $b['customer_id'] ? db_one('SELECT * FROM customers WHERE id = :c', [':c' => (int) $b['customer_id']]) : null;
$docs      = db_all('SELECT * FROM documents WHERE booking_id = :b ORDER BY id DESC', [':b' => $id]);
$breakdown = json_decode((string) $b['rate_breakdown'], true) ?: [];

[$sl, $sc] = booking_status_label($b['status']);
[$pl, $pc] = payment_status_label($b['payment_status']);
$due     = (float) $b['grand_total'] + (float) $b['extra_charge'];
$balance = $due - (float) $b['paid_amount'];
$held    = security_held($id);                                   // เงินประกันที่ร้านยังถืออยู่
$xLines  = booking_extra_lines($b);
$kindLabel   = ['deposit' => 'มัดจำ', 'balance' => 'ค่าเช่า', 'security' => 'เงินประกัน', 'extra' => 'ค่าใช้จ่ายเพิ่ม',
                'refund' => 'คืนเงินค่าเช่า', 'security_refund' => 'คืนเงินประกัน'];
$methodLabel = ['cash' => 'เงินสด', 'transfer' => 'โอนธนาคาร', 'promptpay' => 'PromptPay', 'card' => 'บัตรเครดิต',
                'deduct' => 'หักจากเงินประกัน'];
$nowLocal    = date('Y-m-d\TH:i');
$sigs        = ['contract' => signature_get($id, 'contract'), 'return' => signature_get($id, 'return')];
$sigVoided   = signature_voided($id);
$hasLine     = (bool) line_user_for_booking($b);

// ผลตรวจสภาพรถทั้ง 2 รอบ
$inspOut = inspection_get($id, 'out');
$inspIn  = inspection_get($id, 'in');
$progOut = inspection_progress($inspOut);
$progIn  = inspection_progress($inspIn);

admin_head('การจอง ' . $b['booking_no'], 'bookings.php');
?>

<div class="toolbar no-print">
  <a class="btn btn-ghost btn-sm" href="<?= url('admin/bookings.php') ?>">← รายการจอง</a>
  <div class="spacer"></div>
  <a class="btn btn-ghost btn-sm" href="<?= url('admin/booking-edit.php?id=' . $id) ?>">แก้ไขข้อมูล</a>
  <a class="btn btn-ghost btn-sm" href="<?= url('admin/document-edit.php?booking_id=' . $id . '&type=quotation') ?>">ออกใบเสนอราคา</a>
  <a class="btn btn-ghost btn-sm" href="<?= url('admin/document-edit.php?booking_id=' . $id . '&type=receipt') ?>">ออกใบเสร็จ</a>
  <a class="btn btn-ghost btn-sm" href="<?= url('admin/contract-print.php?id=' . $id) ?>" target="_blank">พิมพ์สัญญาเช่า</a>
</div>

<div class="split">
  <div>
    <!-- ---------- สรุปงาน ---------- -->
    <div class="panel">
      <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start">
        <div>
          <h2 class="mb-0"><?= e($b['booking_no']) ?></h2>
          <p class="small muted mb-0">สร้างเมื่อ <?= e(thai_date($b['created_at'], true)) ?> · ช่องทาง <?= e($b['source']) ?></p>
        </div>
        <div class="right">
          <span class="badge badge-<?= $sc ?>"><?= e($sl) ?></span>
          <span class="badge badge-<?= $pc ?>"><?= e($pl) ?></span>
        </div>
      </div>

      <div class="g2 mt-3">
        <div>
          <h4 class="small">ข้อมูลรถ</h4>
          <table class="table">
            <tbody>
              <tr><th>รถ</th><td><?= e($b['brand'] . ' ' . $b['model']) ?></td></tr>
              <tr><th>ทะเบียน</th><td><?= e($b['plate']) ?> · สี<?= e($b['color']) ?></td></tr>
              <tr><th>รับรถ</th><td><?= e(thai_date($b['pickup_at'], true, true)) ?></td></tr>
              <tr><th>คืนรถ</th><td><?= e(thai_date($b['return_at'], true, true)) ?></td></tr>
              <tr><th>ระยะเวลา</th><td><?= (int) $b['rental_days'] ?> วัน</td></tr>
              <tr><th>จุดรับ</th><td><?= e($b['pickup_place'] ?: '-') ?></td></tr>
              <tr><th>จุดคืน</th><td><?= e($b['return_place'] ?: '-') ?></td></tr>
            </tbody>
          </table>
        </div>
        <div>
          <h4 class="small">ข้อมูลผู้เช่า</h4>
          <table class="table">
            <tbody>
              <tr><th>ชื่อ</th><td><?= e($b['contact_name']) ?></td></tr>
              <tr><th>โทร</th><td><a href="tel:<?= e($b['contact_phone']) ?>"><?= e($b['contact_phone']) ?></a></td></tr>
              <tr><th>อีเมล</th><td><?= e($b['contact_email'] ?: '-') ?></td></tr>
              <tr><th>LINE</th><td><?= e($b['contact_line'] ?: '-') ?></td></tr>
              <?php if ($customer): ?>
                <tr><th>รหัสลูกค้า</th><td><a href="<?= url('admin/customer-edit.php?id=' . (int) $customer['id']) ?>"><?= e($customer['code']) ?></a>
                  <?= (int) $customer['is_blacklist'] ? ' <span class="badge badge-danger">Blacklist</span>' : '' ?></td></tr>
                <tr><th>ใบขับขี่</th><td><?= e($customer['license_no'] ?: '-') ?></td></tr>
              <?php endif; ?>
              <tr><th>หมายเหตุ</th><td><?= nl2br(e($b['note'] ?: '-')) ?></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ---------- การเงิน ---------- -->
    <div class="panel mt-2">
      <h3>การเงิน</h3>
      <div class="g2 mt-2">
        <div>
          <div class="sum">
            <?php foreach (($breakdown['rental'] ?? []) as $l): ?>
              <div class="sum-row"><span><?= e($l['label']) ?> × <?= (int) $l['qty'] ?></span><span><?= money($l['amount']) ?></span></div>
            <?php endforeach; ?>
            <?php foreach (($breakdown['addons'] ?? []) as $l): ?>
              <div class="sum-row"><span class="muted"><?= e($l['label']) ?></span><span><?= money($l['amount']) ?></span></div>
            <?php endforeach; ?>
            <?php if ((float) $b['discount'] > 0): ?>
              <div class="sum-row"><span>ส่วนลด</span><span>-<?= money($b['discount']) ?></span></div>
            <?php endif; ?>
            <?php if ((float) $b['vat_rate'] > 0): ?>
              <div class="sum-row"><span>VAT <?= (float) $b['vat_rate'] ?>%</span><span><?= money($b['vat_amount']) ?></span></div>
            <?php endif; ?>
            <?php foreach ($xLines as $l): ?>
              <div class="sum-row"><span><?= e($l['label']) ?><?php if (!empty($l['detail'])): ?>
                  <span class="tiny muted" style="display:block"><?= e($l['detail']) ?></span><?php endif; ?></span>
                <span><?php if (!empty($l['waived'])): ?><span class="badge badge-info">ยกเว้น</span>
                  <s class="tiny muted"><?= money($l['suggested'] ?? 0) ?></s><?php else: ?><?= money($l['amount']) ?><?php endif; ?></span></div>
            <?php endforeach; ?>
            <div class="sum-row total"><span>ยอดที่ต้องเก็บ</span><span>฿<?= money($due) ?></span></div>
            <div class="sum-row"><span>เก็บแล้ว</span><span><?= money($b['paid_amount']) ?></span></div>
            <div class="sum-row <?= $balance > 0 ? 'hl' : '' ?>"><span>คงเหลือ</span><span>฿<?= money(max(0, $balance)) ?></span></div>
            <div class="sum-row"><span class="muted">เงินประกันตามเงื่อนไข</span><span><?= money($b['security_deposit']) ?></span></div>
            <div class="sum-row"><span class="muted">เงินประกันที่ร้านถืออยู่</span>
              <span><?= $held > 0 ? '<b>' . money($held) . '</b>' : money(0) ?></span></div>
          </div>
        </div>
        <div>
          <form method="post" class="panel" style="background:var(--bg);box-shadow:none">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="payment">
            <h4 class="small mt-0">บันทึกรับเงิน</h4>
            <div class="field">
              <label>ประเภท</label>
              <select name="kind">
                <option value="deposit">มัดจำ</option>
                <option value="balance" selected>ค่าเช่าส่วนที่เหลือ</option>
                <option value="security">เงินประกัน</option>
                <option value="extra">ค่าใช้จ่ายเพิ่มเติม</option>
                <option value="refund">คืนเงินค่าเช่า</option>
              </select>
              <div class="hint">คืนเงินประกันให้ใช้ปุ่ม "ปิดเงินประกัน" ทางขวา ระบบจะไม่นับเป็นรายได้ติดลบ</div>
            </div>
            <div class="field">
              <label>ช่องทาง</label>
              <select name="method">
                <option value="cash">เงินสด</option>
                <option value="transfer">โอนธนาคาร</option>
                <option value="promptpay">PromptPay</option>
                <option value="card">บัตรเครดิต</option>
              </select>
            </div>
            <div class="field">
              <label>จำนวนเงิน (บาท)</label>
              <input type="number" name="amount" step="0.01" min="0" value="<?= e(max(0, $balance)) ?>" required>
            </div>
            <div class="field">
              <label>หมายเหตุ</label>
              <input type="text" name="pnote" maxlength="200">
            </div>
            <button class="btn btn-block">บันทึกรับเงิน</button>
          </form>
        </div>
      </div>

      <?php if ($payments): ?>
        <h4 class="small mt-3">ประวัติการเงิน</h4>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>วันที่</th><th>ประเภท</th><th>ช่องทาง</th><th class="num">จำนวน</th><th>สถานะ</th><th>สลิป</th><th></th></tr></thead>
            <tbody>
              <?php
              foreach ($payments as $p): ?>
                <tr>
                  <td class="small"><?= e(thai_date($p['paid_at'] ?: $p['created_at'], true)) ?></td>
                  <td class="small"><?= e($kindLabel[$p['kind']] ?? $p['kind']) ?></td>
                  <td class="small"><?= e($methodLabel[$p['method']] ?? $p['method']) ?><?php if ($p['note']): ?>
                    <span class="tiny muted" style="display:block"><?= e($p['note']) ?></span><?php endif; ?></td>
                  <td class="num"><?= money($p['amount']) ?></td>
                  <td>
                    <?php if ($p['status'] === 'approved'): ?><span class="badge badge-ok">ยืนยันแล้ว</span>
                    <?php elseif ($p['status'] === 'rejected'): ?><span class="badge badge-danger">ไม่ผ่าน</span>
                    <?php else: ?><span class="badge badge-warn">รอตรวจ</span><?php endif; ?>
                  </td>
                  <td><?= $p['slip_file'] ? '<a href="' . e(upload_url($p['slip_file'])) . '" target="_blank">ดู</a>' : '-' ?></td>
                  <td class="right">
                    <?php if ($p['status'] === 'pending'): ?>
                      <form method="post" action="<?= url('admin/payments.php') ?>" style="display:inline-block"
                            onsubmit="return confirm('ยืนยันว่าได้รับเงิน <?= money($p['amount']) ?> บาทแล้ว?')">
                        <?= csrf_field() ?><input type="hidden" name="act" value="approve">
                        <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                        <button class="btn btn-sm">อนุมัติ</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($inspOut || $inspIn): ?>
      <div class="panel mt-2">
        <h3><?= icon('compare') ?> สภาพรถ ก่อน–หลัง</h3>
        <p class="tiny muted">
          <?php foreach ([['ส่งมอบ', $inspOut], ['รับคืน', $inspIn]] as [$lb, $iv]):
              if (!$iv) continue; ?>
            <span style="display:block">
              <?= e($lb) ?> <?= e(thai_date($iv['inspected_at'], true)) ?><?= $iv['inspector'] ? ' โดย ' . e($iv['inspector']) : '' ?>
              <?php if (inspection_is_locked($iv)): ?>
                · <span class="badge badge-ok">🔒 ล็อกแล้ว <?= e(thai_date($iv['locked_at'], true)) ?></span>
              <?php else: ?>
                · <span class="badge badge-warn">ยังไม่ล็อก แก้ไขได้</span>
              <?php endif; ?>
              <?php if (!empty($iv['history'])): ?> · ถ่ายซ้ำ <?= count($iv['history']) ?> รูป (เก็บรูปเดิมไว้)<?php endif; ?>
            </span>
          <?php endforeach; ?>
        </p>

        <div class="g2 mt-1">
          <div>
            <table class="table tiny">
              <tr><th>เลขไมล์ตอนส่งมอบ</th><td class="num"><?= $inspOut && $inspOut['mileage'] ? number_format((float) $inspOut['mileage']) . ' กม.' : '-' ?></td></tr>
              <tr><th>เลขไมล์ตอนรับคืน</th><td class="num"><?= $inspIn && $inspIn['mileage'] ? number_format((float) $inspIn['mileage']) . ' กม.' : '-' ?></td></tr>
              <?php if ($inspOut && $inspIn && $inspOut['mileage'] && $inspIn['mileage']): ?>
                <tr><th>ระยะที่ใช้ไป</th><td class="num"><b><?= number_format((float) $inspIn['mileage'] - (float) $inspOut['mileage']) ?> กม.</b></td></tr>
              <?php endif; ?>
            </table>
          </div>
          <div>
            <table class="table tiny">
              <tr><th>น้ำมันตอนส่งมอบ</th><td class="num"><?= e(fuel_label($inspOut['fuel_level'] ?? null)) ?></td></tr>
              <tr><th>น้ำมันตอนรับคืน</th><td class="num"><?= e(fuel_label($inspIn['fuel_level'] ?? null)) ?></td></tr>
              <?php if ($inspOut && $inspIn && $inspOut['fuel_level'] !== null && $inspIn['fuel_level'] !== null
                        && (int) $inspIn['fuel_level'] < (int) $inspOut['fuel_level']): ?>
                <tr><th>น้ำมันขาด</th><td class="num"><b class="txt-danger"><?= (int) $inspOut['fuel_level'] - (int) $inspIn['fuel_level'] ?> ขีด</b></td></tr>
              <?php endif; ?>
            </table>
          </div>
        </div>

        <?php $mOut = inspection_photo_map($inspOut); $mIn = inspection_photo_map($inspIn); ?>
        <div class="insp-pair">
          <?php foreach (inspection_positions() as $pk => [$plabel, ]):
              if (empty($mOut[$pk]) && empty($mIn[$pk])) continue; ?>
            <div class="insp-pair-item">
              <h4><?= e($plabel) ?></h4>
              <div class="insp-pair-row">
                <figure>
                  <?php if (!empty($mOut[$pk])): ?>
                    <a href="<?= e(upload_url($mOut[$pk]['file_path'])) ?>" target="_blank">
                      <img src="<?= e(upload_url($mOut[$pk]['file_path'])) ?>" alt="" loading="lazy"></a>
                  <?php else: ?><div class="insp-none">ไม่มีรูป</div><?php endif; ?>
                  <figcaption>ส่งมอบ<?php if (!empty($mOut[$pk])): ?><span class="tiny muted" style="display:block"><?= e(thai_date($mOut[$pk]['created_at'], true)) ?><?= !empty($mOut[$pk]['uploader']) ? ' · ' . e($mOut[$pk]['uploader']) : '' ?></span><?php endif; ?></figcaption>
                </figure>
                <figure>
                  <?php if (!empty($mIn[$pk])): ?>
                    <a href="<?= e(upload_url($mIn[$pk]['file_path'])) ?>" target="_blank">
                      <img src="<?= e(upload_url($mIn[$pk]['file_path'])) ?>" alt="" loading="lazy"></a>
                  <?php else: ?><div class="insp-none">ไม่มีรูป</div><?php endif; ?>
                  <figcaption>รับคืน<?php if (!empty($mIn[$pk])): ?><span class="tiny muted" style="display:block"><?= e(thai_date($mIn[$pk]['created_at'], true)) ?><?= !empty($mIn[$pk]['uploader']) ? ' · ' . e($mIn[$pk]['uploader']) : '' ?></span><?php endif; ?></figcaption>
                </figure>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php foreach ([['out', $inspOut], ['in', $inspIn]] as [$k, $iv]):
            if ($iv && !empty($iv['note'])): ?>
          <div class="alert alert-warn mt-2">
            <b>หมายเหตุ<?= e(inspection_kind_label($k)) ?>:</b> <?= e($iv['note']) ?>
          </div>
        <?php endif; endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($docs): ?>
      <div class="panel mt-2">
        <h3>เอกสารที่ออกแล้ว</h3>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>เลขที่</th><th>ประเภท</th><th>วันที่</th><th class="num">ยอด</th><th></th></tr></thead>
            <tbody>
              <?php $dt = ['quotation'=>'ใบเสนอราคา','invoice'=>'ใบแจ้งหนี้','receipt'=>'ใบเสร็จรับเงิน','contract'=>'สัญญาเช่า'];
              foreach ($docs as $d): ?>
                <tr>
                  <td><?= e($d['doc_no']) ?></td>
                  <td><?= e($dt[$d['doc_type']] ?? $d['doc_type']) ?></td>
                  <td><?= e(thai_date($d['issue_date'])) ?></td>
                  <td class="num"><?= money($d['grand_total']) ?></td>
                  <td class="right"><a class="btn btn-ghost btn-sm" href="<?= url('admin/document-print.php?id=' . (int) $d['id']) ?>" target="_blank">พิมพ์</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- ---------- คอลัมน์ขวา: การกระทำ ---------- -->
  <div class="no-print">
    <div id="actions" class="panel">
      <h3>ดำเนินการ</h3>

      <?php if ($b['status'] === 'pending'): ?>
        <form method="post" class="mb-2">
          <?= csrf_field() ?><input type="hidden" name="act" value="confirm">
          <button class="btn btn-block">✅ ยืนยันการจอง</button>
          <p class="tiny muted mt-1 mb-0">ระบบจะส่งอีเมล/LINE ยืนยันให้ลูกค้าอัตโนมัติ</p>
        </form>
      <?php endif; ?>

      <?php if (in_array($b['status'], ['pending','confirmed'], true)): ?>
        <details class="mb-2">
          <summary class="btn btn-accent btn-block" style="list-style:none;cursor:pointer">🚗 ส่งมอบรถ</summary>
          <form method="post" class="mt-2">
            <?= csrf_field() ?><input type="hidden" name="act" value="pickup">
            <?= inspect_block($id, 'out', $inspOut, $progOut) ?>
            <div class="field"><label>เลขไมล์ตอนส่งมอบ</label>
              <input type="number" name="mileage_out"
                     value="<?= (int) ($inspOut['mileage'] ?? $b['car_mileage']) ?>" min="0"></div>
            <div class="field"><label>ระดับน้ำมัน (0–8 ขีด)</label>
              <input type="number" name="fuel_out"
                     value="<?= (int) ($inspOut['fuel_level'] ?? 8) ?>" min="0" max="8"></div>
            <button class="btn btn-accent btn-block"
              <?= $progOut['complete'] ? '' : 'onclick="return confirm(\'ยังถ่ายรูปสภาพรถไม่ครบ (' . (int) $progOut['done'] . '/' . (int) $progOut['required'] . ' มุม)\\nถ้าเกิดข้อพิพาทเรื่องรอยขีดข่วนจะไม่มีหลักฐาน\\n\\nต้องการส่งมอบรถเลยหรือไม่?\')"' ?>>
              บันทึกส่งมอบรถ
            </button>
          </form>
        </details>
      <?php endif; ?>

      <?php if ($b['status'] === 'picked_up'):
          $overdueMin = (int) floor((time() - strtotime($b['return_at'])) / 60); ?>
        <details class="mb-2" open>
          <summary class="btn btn-block" style="list-style:none;cursor:pointer">🔙 รับคืนรถ</summary>
          <form method="post" class="mt-2" id="returnForm"
                data-url="<?= e(url('admin/booking-view.php?id=' . $id . '&preview=return')) ?>"
                data-balance="<?= e(round($balance, 2)) ?>" data-held="<?= e($held) ?>">
            <?= csrf_field() ?><input type="hidden" name="act" value="return">
            <?= inspect_block($id, 'in', $inspIn, $progIn) ?>
            <div class="field"><label>เวลาที่รับคืนรถจริง</label>
              <input type="datetime-local" name="returned_at" value="<?= e($nowLocal) ?>">
              <input type="hidden" name="rt_manual" value="">
              <div class="hint">กำหนดคืน <?= e(thai_date($b['return_at'], true)) ?><?= $overdueMin > 0 ? ' — <b class="txt-danger">เลยกำหนดแล้ว</b>' : '' ?> · ไม่แก้ = ใช้เวลาที่กดบันทึก · ถ้าบันทึกย้อนหลังให้แก้เป็นเวลาที่รถมาถึงจริง</div></div>
            <div class="g2c">
              <div class="field"><label>เลขไมล์ตอนรับคืน</label>
                <input type="number" name="mileage_in" inputmode="numeric"
                       value="<?= (int) ($inspIn['mileage'] ?? ($b['mileage_out'] ?: $b['car_mileage'])) ?>" min="<?= (int) $b['mileage_out'] ?>"></div>
              <div class="field"><label>น้ำมัน (0–8 ขีด)</label>
                <input type="number" name="fuel_in" inputmode="numeric"
                       value="<?= (int) ($inspIn['fuel_level'] ?? ($b['fuel_out'] ?? 8)) ?>" min="0" max="8">
                <div class="hint">ตอนรับรถ <?= $b['fuel_out'] !== null ? (int) $b['fuel_out'] . '/8' : '-' ?></div></div>
            </div>

            <div class="chg-box">
              <div class="chg-head"><b>ค่าใช้จ่ายเพิ่ม</b>
                <span class="tiny muted">ระบบคิดให้ตามเงื่อนไข — แก้จำนวน หรือเอาติ๊กออกเพื่อยกเว้นได้</span></div>
              <div id="chgLines"></div>
              <p id="chgNone" class="tiny muted mb-0">ไม่มีค่าใช้จ่ายเพิ่มตามเงื่อนไข (คืนตรงเวลา น้ำมันครบ)</p>
              <div class="chg-row chg-other">
                <input type="text" name="other_label" maxlength="120" placeholder="ค่าใช้จ่ายอื่น เช่น ค่าทำความสะอาด" aria-label="รายการค่าใช้จ่ายอื่น">
                <input type="number" name="other_amount" class="chg-amt" step="0.01" min="0" placeholder="0" inputmode="numeric" aria-label="จำนวนเงินค่าใช้จ่ายอื่น">
              </div>
              <div class="chg-total"><span>รวมค่าใช้จ่ายเพิ่ม</span><b><span id="chgTotal">0</span> บาท</b></div>
              <div class="chg-total sub"><span>ยอดที่ต้องเก็บจากลูกค้าตอนนี้</span><b><span id="chgDue"><?= money0(max(0, $balance)) ?></span> บาท</b></div>
              <?php if ($held > 0): ?>
                <p class="tiny muted mb-0 mt-1">ร้านถือเงินประกันอยู่ <?= money0($held) ?> บาท — หักชำระยอดนี้ได้ในขั้น "ปิดเงินประกัน" หลังบันทึกรับคืน</p>
              <?php endif; ?>
            </div>

            <div class="field"><label>บันทึกความเสียหาย</label>
              <textarea name="damage_note" maxlength="1000" placeholder="ไม่มี ให้เว้นว่าง"></textarea></div>
            <button class="btn btn-block"
              <?= $progIn['complete'] ? '' : 'onclick="return confirm(\'ยังถ่ายรูปสภาพรถตอนรับคืนไม่ครบ (' . (int) $progIn['done'] . '/' . (int) $progIn['required'] . ' มุม)\\nถ้าพบรอยภายหลังจะไม่มีหลักฐานเทียบ\\n\\nต้องการบันทึกรับคืนเลยหรือไม่?\')"' ?>>
              บันทึกรับคืนรถ
            </button>
          </form>
        </details>
      <?php endif; ?>

      <?php if (in_array($b['status'], ['pending', 'confirmed', 'picked_up'], true)): ?>
        <details class="mb-2">
          <summary class="btn btn-ghost btn-block" style="list-style:none;cursor:pointer">⏩ เช่าต่อ / เลื่อนวันคืน</summary>
          <form method="post" class="mt-2" id="extendForm"
                data-url="<?= e(url('admin/booking-view.php?id=' . $id . '&preview=extend')) ?>">
            <?= csrf_field() ?><input type="hidden" name="act" value="extend">
            <div class="field"><label>วัน–เวลาคืนรถใหม่</label>
              <input type="datetime-local" name="new_return" required
                     min="<?= e(date('Y-m-d\TH:i', strtotime($b['return_at']) + 60)) ?>"
                     value="<?= e(date('Y-m-d\TH:i', strtotime($b['return_at']) + 86400)) ?>">
              <div class="hint">กำหนดคืนเดิม <?= e(thai_date($b['return_at'], true)) ?> · ระบบตรวจให้ว่ารถไม่ชนคิวถัดไป</div></div>
            <div class="chg-box" id="extOut" aria-live="polite">
              <p class="tiny muted mb-0" id="extMsg">กำลังคำนวณ…</p>
              <div id="extRows" hidden>
                <div class="chg-total sub"><span>จำนวนวันเช่า</span><b><span id="extDays"></span></b></div>
                <div class="chg-total sub"><span>ค่าเช่าเพิ่ม</span><b><span id="extRental"></span> บาท</b></div>
                <div class="chg-total sub" id="extAddonRow"><span>บริการเสริมช่วงเช่าต่อ</span><b><span id="extAddon"></span> บาท</b></div>
                <div class="chg-total sub" id="extVatRow"><span>VAT</span><b><span id="extVat"></span> บาท</b></div>
                <div class="chg-total"><span>ค่าเช่าต่อที่ต้องเก็บ</span><b><span id="extCharge"></span> บาท</b></div>
                <p class="tiny mb-0 mt-1" id="extWarn" hidden style="color:var(--warn,#9a5b00)"></p>
              </div>
            </div>
            <div class="field" id="extChargeField" hidden><label>เก็บจริง (บาท)</label>
              <input type="number" name="charge" step="0.01" min="0" inputmode="decimal">
              <div class="hint">ลดให้ลูกค้าได้ แต่ไม่เกินยอดที่ระบบคิด ส่วนต่างบันทึกเป็นส่วนลด</div></div>
            <button class="btn btn-block" id="extBtn" disabled>บันทึกเช่าต่อ</button>
          </form>
        </details>
      <?php endif; ?>

      <?php if ($held > 0 && in_array($b['status'], ['returned', 'completed', 'cancelled'], true)):
          $paidNow = (float) $b['paid_amount'];
          $owe = $b['status'] === 'cancelled' ? max(0, round((float) $b['extra_charge'] - max(0, $paidNow), 2)) : max(0, round($balance, 2));
          $maxDeduct = min($held, $owe); ?>
        <form method="post" class="chg-box mb-2" id="secForm" data-held="<?= e($held) ?>" data-owe="<?= e($owe) ?>"
              onsubmit="return confirm('ยืนยันปิดเงินประกัน?\nรายการนี้บันทึกเป็นประวัติการเงิน แก้ย้อนหลังไม่ได้')">
          <?= csrf_field() ?><input type="hidden" name="act" value="security_settle">
          <div class="chg-head"><b>ปิดเงินประกัน</b>
            <span class="tiny muted">ร้านถืออยู่ <?= money0($held) ?> บาท · <?= $b['status'] === 'cancelled' ? 'ค่าใช้จ่ายค้างชำระ' : 'ลูกค้าค้างชำระ' ?> <?= money0($owe) ?> บาท</span></div>
          <div class="field"><label>หักชำระยอดค้าง (บาท)</label>
            <input type="number" name="deduct" step="0.01" min="0" max="<?= e($maxDeduct) ?>" value="<?= e($maxDeduct) ?>" inputmode="decimal" <?= $maxDeduct <= 0 ? 'readonly' : '' ?>>
            <div class="hint"><?= $maxDeduct > 0 ? 'หักได้ไม่เกิน ' . money0($maxDeduct) . ' บาท' : 'ไม่มียอดค้าง' ?></div></div>
          <details class="mb-2">
            <summary class="tiny" style="cursor:pointer;color:var(--brand)">+ หักค่าใช้จ่ายที่พบหลังคืนรถ (ค่าปรับจราจร ค่าซ่อม)</summary>
            <div class="chg-row chg-other" style="border-top:0">
              <input type="text" name="add_label" maxlength="120" placeholder="ค่าอะไร เช่น ใบสั่งความเร็ว 12 ก.ย." aria-label="รายการค่าใช้จ่ายที่หักเพิ่ม">
              <input type="number" name="add_amount" class="chg-amt" step="0.01" min="0" max="<?= e($held) ?>" placeholder="0" inputmode="numeric" aria-label="จำนวนเงินที่หักเพิ่ม">
            </div>
            <p class="tiny muted mb-0">ระบบบันทึกเป็นค่าใช้จ่ายเพิ่มของการจองนี้ แล้วหักจากเงินประกัน</p>
          </details>
          <div class="chg-total"><span>คืนลูกค้า</span><b><span id="secRefund"><?= money0($held - $maxDeduct) ?></span> บาท</b></div>
          <div class="field mt-1"><label>คืนเงินทาง</label>
            <select name="refund_method">
              <option value="cash">เงินสด</option>
              <option value="transfer">โอนธนาคาร</option>
              <option value="promptpay">PromptPay</option>
            </select></div>
          <div class="field"><label>หมายเหตุ</label>
            <input type="text" name="refund_note" maxlength="200" placeholder="เช่น โอนเข้าบัญชีลงท้าย 1234"></div>
          <button class="btn btn-accent btn-block">ปิดเงินประกัน</button>
        </form>
      <?php endif; ?>

      <?php if ($b['status'] === 'returned'):
          $warn = [];
          if ($balance > 0.009) { $warn[] = 'ยังมียอดค้าง ' . money($balance) . ' บาท'; }
          if ($held > 0)        { $warn[] = 'ยังถือเงินประกัน ' . money($held) . ' บาท ที่ยังไม่ได้คืนลูกค้า'; } ?>
        <form method="post" class="mb-2">
          <?= csrf_field() ?><input type="hidden" name="act" value="complete">
          <button class="btn btn-block" <?= $warn ? 'onclick="return confirm(\'' . e(implode('\\n', $warn)) . '\\n\\nต้องการปิดงานเลยหรือไม่?\')"' : '' ?>>
            🏁 ปิดงาน
          </button>
        </form>
      <?php endif; ?>

      <?php if (in_array($b['status'], ['pending', 'confirmed'], true)): ?>
        <details>
          <summary class="btn btn-ghost btn-block" style="list-style:none;cursor:pointer;color:var(--danger)">ยกเลิกการจอง</summary>
          <form method="post" class="mt-2" onsubmit="return confirm('ยืนยันยกเลิกการจองนี้?')">
            <?= csrf_field() ?><input type="hidden" name="act" value="cancel">
            <div class="field"><label>เหตุผล</label><input type="text" name="cancel_reason" maxlength="200" required></div>
            <button class="btn btn-danger btn-block">ยืนยันยกเลิก</button>
          </form>
        </details>
      <?php elseif (in_array($b['status'], ['completed', 'cancelled'], true) && $held <= 0): ?>
        <p class="tiny muted mb-0">งานนี้ปิดแล้ว ไม่มีรายการที่ต้องทำต่อ</p>
      <?php endif; ?>
    </div>

    <!-- ---------- ลายเซ็นลูกค้า ---------- -->
    <div class="panel mt-2" id="signatures">
      <h3 class="small">ลายเซ็นลูกค้า</h3>
      <?php foreach (signature_kinds() as $sk => [$sname, ]):
          $sg = $sigs[$sk]; $blk = $sg ? null : signature_blocker($b, $sk); ?>
        <div class="sig-item">
          <div class="sig-head"><b><?= e($sname) ?></b>
            <?php if ($sg): ?><span class="badge badge-ok">ลงนามแล้ว</span>
            <?php elseif ($blk === null): ?><span class="badge badge-warn">รอลงนาม</span>
            <?php else: ?><span class="badge">ยังไม่ถึงขั้นตอน</span><?php endif; ?></div>

          <?php if ($sg):
              $chg = signature_changes($sg, $b, $car); ?>
            <?php if ($img = private_data_uri($sg['file_path'])): ?>
              <img class="sig-img" src="<?= e($img) ?>" alt="ลายเซ็นของ <?= e($sg['signer_name']) ?>">
            <?php endif; ?>
            <p class="tiny muted mb-0"><?= e($sg['signer_name']) ?> · <?= e(thai_date($sg['signed_at'], true)) ?><br>
              รหัสอ้างอิง <?= e(signature_ref($sg)) ?> · IP <?= e($sg['ip'] ?: '-') ?></p>
            <?php if (!signature_intact($sg)): ?>
              <div class="alert alert-danger mt-1 tiny">สำเนาข้อมูลที่เก็บไว้กับลายเซ็นไม่ตรงกับรหัสตรวจสอบ — ข้อมูลอาจถูกแก้ในฐานข้อมูลโดยตรง</div>
            <?php endif; ?>
            <?php if ($chg): ?>
              <div class="alert alert-warn mt-1 tiny"><b>ข้อมูลถูกแก้หลังลูกค้าลงนาม</b>
                <?php foreach ($chg as $x): ?>
                  <span style="display:block">• <?= e($x['label']) ?>: <?= e($x['signed']) ?> → <?= e($x['now']) ?></span>
                <?php endforeach; ?>
                <span style="display:block;margin-top:3px">ควรให้ลูกค้าเซ็นฉบับใหม่เพื่อรับรองข้อมูลล่าสุด</span></div>
            <?php endif; ?>
            <?php if (is_admin() && $blk === null && signature_blocker($b, $sk) === null): ?>
              <details class="mt-1">
                <summary class="tiny" style="cursor:pointer;color:var(--brand)">ขอให้ลูกค้าเซ็นใหม่</summary>
                <form method="post" class="mt-1" onsubmit="return confirm('ยกเลิกลายเซ็นฉบับนี้เพื่อให้ลูกค้าเซ็นใหม่?\nฉบับเดิมยังเก็บไว้เป็นประวัติ')">
                  <?= csrf_field() ?><input type="hidden" name="act" value="sign_void"><input type="hidden" name="kind" value="<?= e($sk) ?>">
                  <div class="field"><label>เหตุผล</label><input type="text" name="void_reason" maxlength="200" required placeholder="เช่น เลื่อนวันคืนรถ"></div>
                  <button class="btn btn-ghost btn-sm btn-block">ยกเลิกฉบับนี้ ให้เซ็นใหม่</button>
                </form>
              </details>
            <?php endif; ?>

          <?php elseif ($blk === null): $link = sign_url($b, $sk); ?>
            <a class="btn btn-accent btn-block btn-sm" href="<?= e($link) ?>" target="_blank" rel="noopener">✍️ ให้ลูกค้าเซ็นบนเครื่องนี้</a>
            <div class="sig-copy mt-1">
              <input type="text" readonly value="<?= e($link) ?>" id="signLink_<?= e($sk) ?>" aria-label="ลิงก์เซ็น<?= e($sname) ?>" onfocus="this.select()">
              <button type="button" class="btn btn-ghost btn-sm" data-copy="signLink_<?= e($sk) ?>">คัดลอกลิงก์</button>
            </div>
            <?php if ($hasLine): ?>
              <form method="post" class="mt-1">
                <?= csrf_field() ?><input type="hidden" name="act" value="sign_send"><input type="hidden" name="kind" value="<?= e($sk) ?>">
                <button class="btn btn-ghost btn-sm btn-block">ส่งลิงก์ให้ลูกค้าทาง LINE</button>
              </form>
            <?php else: ?>
              <p class="tiny muted mb-0 mt-1">ลูกค้ายังไม่ผูก LINE — คัดลอกลิงก์ส่งทางแชตหรือ SMS ได้</p>
            <?php endif; ?>
          <?php else: ?>
            <p class="tiny muted mb-0"><?= $sk === 'return' && in_array($b['status'], ['pending', 'confirmed', 'picked_up'], true)
                ? 'เซ็นได้หลังบันทึกรับคืนรถ' : 'ไม่ได้ลงนามในระบบ' ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($sigVoided): ?>
        <details class="mt-1">
          <summary class="tiny muted" style="cursor:pointer">ประวัติ: ฉบับที่ถูกยกเลิก <?= count($sigVoided) ?> ฉบับ</summary>
          <?php foreach ($sigVoided as $vs): ?>
            <p class="tiny muted mb-0 mt-1"><?= e(signature_kinds()[$vs['kind']][0] ?? $vs['kind']) ?> · เซ็น <?= e(thai_date($vs['signed_at'], true)) ?>
              · ยกเลิก <?= e(thai_date($vs['voided_at'], true)) ?><?= $vs['voider'] ? ' โดย ' . e($vs['voider']) : '' ?>
              <?= $vs['void_reason'] ? ' — ' . e($vs['void_reason']) : '' ?></p>
          <?php endforeach; ?>
        </details>
      <?php endif; ?>
    </div>

    <div class="panel mt-2">
      <h3 class="small">หมายเหตุภายใน</h3>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="act" value="note">
        <div class="field"><textarea name="note" maxlength="1000"><?= e($b['note']) ?></textarea></div>
        <button class="btn btn-ghost btn-sm btn-block">บันทึก</button>
      </form>
    </div>

    <?php if ($b['mileage_out'] || $b['mileage_in']): ?>
      <div class="panel mt-2">
        <h3 class="small">บันทึกรับ–คืนรถ</h3>
        <table class="table">
          <tbody>
            <tr><th>ไมล์ออก</th><td><?= $b['mileage_out'] ? number_format((int) $b['mileage_out']) . ' กม.' : '-' ?></td></tr>
            <tr><th>ไมล์เข้า</th><td><?= $b['mileage_in'] ? number_format((int) $b['mileage_in']) . ' กม.' : '-' ?></td></tr>
            <tr><th>ระยะทางที่ใช้</th><td><?= ($b['mileage_in'] && $b['mileage_out']) ? number_format((int) $b['mileage_in'] - (int) $b['mileage_out']) . ' กม.' : '-' ?></td></tr>
            <tr><th>น้ำมัน ออก/เข้า</th><td><?= $b['fuel_out'] !== null ? (int) $b['fuel_out'] . '/8' : '-' ?> → <?= $b['fuel_in'] !== null ? (int) $b['fuel_in'] . '/8' : '-' ?></td></tr>
            <?php if ($b['damage_note']): ?><tr><th>ความเสียหาย</th><td><?= nl2br(e($b['damage_note'])) ?></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var fmt = function (n) { return Math.round(n).toLocaleString('en-US'); };
  var debounce = function (fn, ms) { var t; return function () { clearTimeout(t); t = setTimeout(fn, ms); }; };

  /* ---------- รับคืนรถ: ค่าใช้จ่ายเพิ่มที่ระบบคิดให้ ---------- */
  var rf = document.getElementById('returnForm');
  if (rf) {
    var box = document.getElementById('chgLines'), none = document.getElementById('chgNone');
    var state = {};   // key → {on, amount, suggested} จำสิ่งที่พนักงานแก้ไว้ระหว่างที่ระบบคำนวณใหม่
    var total = function () {
      var sum = 0;
      box.querySelectorAll('.chg-row').forEach(function (r) {
        var on = r.querySelector('input[type=checkbox]').checked, a = r.querySelector('.chg-amt');
        a.readOnly = !on; r.classList.toggle('off', !on);
        if (on) sum += parseFloat(a.value) || 0;
        state[r.dataset.key] = { on: on, amount: a.value, suggested: parseFloat(r.dataset.suggested) };
      });
      sum += parseFloat(rf.other_amount.value) || 0;
      document.getElementById('chgTotal').textContent = fmt(sum);
      document.getElementById('chgDue').textContent = fmt(Math.max(0, parseFloat(rf.dataset.balance) + sum));
    };
    var render = function (lines) {
      box.textContent = '';
      lines.forEach(function (l) {
        var st = state[l.key], keep = st && st.suggested === l.amount;   // ยอดแนะนำเปลี่ยน → ใช้ยอดใหม่ แต่คงการติ๊กไว้
        var row = document.createElement('div');
        row.className = 'chg-row'; row.dataset.key = l.key; row.dataset.suggested = l.amount;
        var seen = document.createElement('input'); seen.type = 'hidden'; seen.name = 'chg[' + l.key + '][seen]'; seen.value = '1';
        var lab = document.createElement('label'); lab.className = 'chg-main';
        var cb = document.createElement('input'); cb.type = 'checkbox'; cb.name = 'chg[' + l.key + '][on]'; cb.value = '1';
        cb.checked = st ? st.on : true;
        var tx = document.createElement('span'), b = document.createElement('b'), d = document.createElement('span');
        b.textContent = l.label; d.className = 'tiny muted'; d.textContent = l.detail;
        tx.appendChild(b); tx.appendChild(d); lab.appendChild(cb); lab.appendChild(tx);
        var amt = document.createElement('input'); amt.type = 'number'; amt.className = 'chg-amt'; amt.min = '0'; amt.step = '0.01';
        amt.name = 'chg[' + l.key + '][amount]'; amt.inputMode = 'numeric'; amt.setAttribute('aria-label', 'จำนวนเงิน ' + l.label);
        amt.value = keep ? st.amount : l.amount;
        row.appendChild(seen); row.appendChild(lab); row.appendChild(amt); box.appendChild(row);
      });
      none.hidden = lines.length > 0;
      total();
    };
    var load = function () {
      var u = rf.dataset.url + '&km=' + encodeURIComponent(rf.mileage_in.value) + '&fuel=' + encodeURIComponent(rf.fuel_in.value)
            + '&at=' + encodeURIComponent(rf.rt_manual.value ? rf.returned_at.value : '');
      fetch(u, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) render(j.lines || []); })
        .catch(function () { /* คำนวณไม่ได้ก็ยังบันทึกได้ — ฝั่งเซิร์ฟเวอร์คิดซ้ำตอนบันทึก */ });
    };
    /* เวลารับคืน: พนักงานแก้เอง = ใช้ค่านั้น · ไม่แตะ = เดินตามนาฬิกา และตอนบันทึกเซิร์ฟเวอร์ใช้เวลาจริง */
    var pad2 = function (n) { return (n < 10 ? '0' : '') + n; };
    rf.returned_at.addEventListener('input', function () { rf.rt_manual.value = '1'; });
    setInterval(function () {
      if (rf.rt_manual.value) return;
      var d = new Date();
      rf.returned_at.value = d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
      load();
    }, 60000);
    var later = debounce(load, 350);
    [rf.mileage_in, rf.fuel_in, rf.returned_at].forEach(function (el) { el.addEventListener('input', later); el.addEventListener('change', later); });
    rf.addEventListener('input', function (e) { if (e.target.closest('.chg-box')) total(); });
    rf.addEventListener('change', function (e) { if (e.target.closest('.chg-box')) total(); });
    load();
  }

  /* ---------- เช่าต่อ ---------- */
  var ef = document.getElementById('extendForm');
  if (ef) {
    var msg = document.getElementById('extMsg'), rows = document.getElementById('extRows'),
        btn = document.getElementById('extBtn'), cf = document.getElementById('extChargeField');
    var quote = function () {
      btn.disabled = true;
      if (!ef.new_return.value) { msg.hidden = false; msg.textContent = 'เลือกวัน–เวลาคืนรถใหม่'; rows.hidden = true; cf.hidden = true; return; }
      fetch(ef.dataset.url + '&ret=' + encodeURIComponent(ef.new_return.value), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (q) {
          if (!q.ok) { msg.hidden = false; msg.textContent = q.error || 'คำนวณไม่ได้'; msg.className = 'tiny txt-danger mb-0'; rows.hidden = true; cf.hidden = true; return; }
          msg.hidden = true; rows.hidden = false; cf.hidden = false; msg.className = 'tiny muted mb-0';
          document.getElementById('extDays').textContent = q.days_old + ' → ' + q.days_new + ' วัน';
          document.getElementById('extRental').textContent = fmt(q.rental_delta);
          document.getElementById('extAddon').textContent = fmt(q.addon_delta);
          document.getElementById('extAddonRow').hidden = !(q.addon_delta > 0);
          document.getElementById('extVat').textContent = fmt(q.vat);
          document.getElementById('extVatRow').hidden = !(q.vat > 0);
          document.getElementById('extCharge').textContent = fmt(q.charge);
          var w = document.getElementById('extWarn');
          w.hidden = !q.warning; w.textContent = q.warning ? 'นอกเวลาทำการ: ' + q.warning + ' — บันทึกได้ถ้าตกลงกับลูกค้าแล้ว' : '';
          ef.charge.max = q.charge; ef.charge.value = q.charge;
          btn.disabled = false;
        })
        .catch(function () { msg.hidden = false; msg.textContent = 'เชื่อมต่อไม่ได้ ลองใหม่อีกครั้ง'; rows.hidden = true; });
    };
    var once = false;
    ef.closest('details').addEventListener('toggle', function () { if (this.open && !once) { once = true; quote(); } });
    ef.new_return.addEventListener('change', quote);
    ef.addEventListener('submit', function (e) {
      if (!confirm('ยืนยันเลื่อนกำหนดคืนรถ?\nยอดค่าเช่าของการจองนี้จะเพิ่มขึ้น ' + fmt(parseFloat(ef.charge.value) || 0) + ' บาท')) e.preventDefault();
    });
  }

  /* ---------- คัดลอกลิงก์เซ็นเอกสาร ---------- */
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.getElementById(b.dataset.copy), t = b.textContent;
      var done = function () { b.textContent = 'คัดลอกแล้ว'; setTimeout(function () { b.textContent = t; }, 1600); };
      el.select();
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(el.value).then(done, function () {});
      else { try { document.execCommand('copy'); done(); } catch (e) {} }
    });
  });

  /* ---------- ปิดเงินประกัน ---------- */
  var sf = document.getElementById('secForm');
  if (sf) {
    var secCalc = function () {
      var held = parseFloat(sf.dataset.held), owe = parseFloat(sf.dataset.owe) || 0;
      var add = Math.max(0, Math.min(held, parseFloat(sf.add_amount.value) || 0));
      var d = Math.max(0, Math.min(held, owe + add, (parseFloat(sf.deduct.value) || 0) + add));
      document.getElementById('secRefund').textContent = fmt(held - d);
    };
    sf.deduct.addEventListener('input', secCalc);
    sf.add_amount.addEventListener('input', secCalc);
  }
})();
</script>

<?php admin_foot(); ?>
