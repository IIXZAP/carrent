<?php
use App\Core\Database;
use App\Models\Booking;
use App\Models\Inspection;
use App\Services\BookingFlowService;
use App\Services\InspectionService;
use App\Services\UploadService;
use App\Services\UserError;

function t_photo(array $b, string $kind, string $position, ?int $user = null, ?string $caption = null): int
{
    $file = t_upload_file();
    try {
        $id = InspectionService::savePhoto((int) $b['id'], $kind, $position, $file, $caption, $user ?? t_user(), false);
    } finally {
        @unlink($file['tmp_name']);
    }
    $GLOBALS['t_photos_to_delete'][] = Inspection::photo($id)['file_path'];
    return $id;
}

function t_photos_cleanup(): void
{
    foreach ($GLOBALS['t_photos_to_delete'] ?? [] as $path) {
        UploadService::delete($path);
    }
    $GLOBALS['t_photos_to_delete'] = [];
}

function test_db_photos_replace_per_position_and_keep_history(): void
{
    $b = t_flow_booking();
    try {
        $first = t_photo($b, 'out', 'front');
        $second = t_photo($b, 'out', 'front', null, 'ถ่ายใหม่');
        $ins = Inspection::get((int) $b['id'], 'out');
        assert_same(1, count(array_filter($ins['photos'], static fn(array $p): bool => $p['position'] === 'front')), 'มุมละ 1 รูปที่ใช้อยู่');
        assert_same($second, (int) $ins['photos'][0]['id']);
        assert_same([$first], array_map('intval', array_column($ins['history'], 'id')), 'รูปเดิมถูกเก็บเป็นประวัติ ไม่ลบ');
        assert_true(UploadService::resolve(Inspection::photo($first)['file_path']) !== null, 'ไฟล์รูปเดิมยังอยู่');
    } finally {
        t_photos_cleanup();
    }
}

function test_db_damage_position_accepts_several_photos(): void
{
    $b = t_flow_booking();
    try {
        t_photo($b, 'out', 'damage');
        t_photo($b, 'out', 'damage');
        $ins = Inspection::get((int) $b['id'], 'out');
        assert_same(2, count($ins['photos']), 'จุดมีตำหนิถ่ายได้หลายรูป');
        assert_same([], $ins['history']);
    } finally {
        t_photos_cleanup();
    }
}

function test_db_photo_rules_by_phase_and_lock(): void
{
    $b = t_flow_booking();
    try {
        t_expect_refused(fn() => t_photo($b, 'in', 'front'), 'ยังไม่ได้ส่งมอบ ถ่ายรอบรับคืนไม่ได้');
        t_photo($b, 'out', 'front');
        BookingFlowService::pickup((int) $b['id'], 10000, 8, t_user(), '', t_now(T_PICKUP_AT));
        t_expect_refused(fn() => t_photo($b, 'out', 'rear'), 'ส่งมอบแล้ว ผลตรวจรอบส่งมอบถูกล็อก เพิ่มไม่ได้');
        t_photo($b, 'in', 'front');
        BookingFlowService::returnCar((int) $b['id'], t_return_input(['returned_at' => '2030-06-13 09:00']), t_user(), '', t_now(T_RETURN_DUE));
        t_expect_refused(fn() => t_photo($b, 'in', 'rear'), 'รับคืนแล้ว ผลตรวจรอบรับคืนถูกล็อก');
    } finally {
        t_photos_cleanup();
    }
}

function test_db_photo_validation(): void
{
    $b = t_flow_booking();
    try {
        t_expect_refused(fn() => t_photo($b, 'out', 'sky'), 'มุมที่ไม่รู้จัก');
        t_expect_refused(fn() => t_photo($b, 'sideways', 'front'), 'รอบที่ไม่รู้จัก');
        $evil = t_upload_file('png', "<?php system('id');");
        t_expect_refused(fn() => InspectionService::savePhoto((int) $b['id'], 'out', 'front', $evil, null, t_user(), false), 'ไม่ใช่รูป');
        @unlink($evil['tmp_name']);
        assert_same(null, Inspection::get((int) $b['id'], 'out'), 'ล้มเหลวแล้วไม่สร้างใบตรวจค้าง');
        assert_same([], glob(storage_path('inspections') . '/*') ?: [], 'ไม่ทิ้งไฟล์กำพร้า');
    } finally {
        t_photos_cleanup();
    }
}

function test_db_inspection_progress(): void
{
    $b = t_flow_booking();
    try {
        $empty = InspectionService::progress(Inspection::get((int) $b['id'], 'out'));
        assert_same(['done' => 0, 'required' => 7, 'complete' => false], $empty);
        foreach (['front', 'rear', 'left', 'right', 'dashboard', 'interior', 'tire'] as $position) {
            t_photo($b, 'out', $position);
        }
        $full = InspectionService::progress(Inspection::get((int) $b['id'], 'out'));
        assert_same(['done' => 7, 'required' => 7, 'complete' => true], $full, 'จุดที่มีตำหนิไม่บังคับ');
    } finally {
        t_photos_cleanup();
    }
}

function test_db_locked_inspection_rejects_photos_even_when_called_directly(): void
{
    // ด่านสุดท้ายอยู่ที่ Model: ผลตรวจที่ล็อกแล้วไม่รับรูปใหม่ ไม่ว่าใครเรียก (ไม่พึ่งการตรวจสถานะของ service)
    $b = t_flow_booking();
    $id = Inspection::ensure((int) $b['id'], 'out', t_user());
    Inspection::addPhoto($id, 'front', 'slips/' . str_repeat('a', 32) . '.jpg', null, t_user(), true);
    assert_true(Inspection::lock((int) $b['id'], 'out', t_user()), 'ล็อกครั้งแรก');
    assert_true(!Inspection::lock((int) $b['id'], 'out', t_user()), 'ล็อกซ้ำไม่เปลี่ยนเวลาเดิม');
    $before = Inspection::photoCount($id);

    $refused = false;
    try {
        Inspection::addPhoto($id, 'rear', 'slips/' . str_repeat('b', 32) . '.jpg', null, t_user(), true);
    } catch (LogicException $e) {
        $refused = true;
    }
    assert_true($refused, 'ล็อกแล้วต้องไม่รับรูปใหม่');
    assert_same($before, Inspection::photoCount($id), 'ไม่มีรูปเพิ่ม');

    Inspection::setReadings((int) $b['id'], 'out', 99999, 1);
    assert_true((int) Inspection::get((int) $b['id'], 'out')['mileage'] !== 99999, 'ล็อกแล้วแก้เลขไมล์/น้ำมันไม่ได้');
}
