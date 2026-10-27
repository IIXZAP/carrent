<?php
use App\Core\Database;
use App\Models\Booking;
use App\Models\Signature;
use App\Services\SignatureService;
use App\Services\UploadService;

/** เซ็น/ยกเลิก/ออกเลขเอกสารพร้อมกันด้วย process จริง (worker commit จริง เทสต์ลบข้อมูลทิ้งเอง) */

function t_sign_race_run(array $jobs): array
{
    $startAt = microtime(true) + 1.5;
    $procs = [];
    foreach ($jobs as [$action, $id, $arg]) {
        $proc = proc_open([PHP_BINARY, __DIR__ . '/workers/sign.php', $action, (string) $id, sprintf('%.4f', $startAt), (string) $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        assert_true(is_resource($proc), 'เปิด worker ไม่ได้');
        $procs[] = [$proc, $pipes];
    }
    $out = [];
    foreach ($procs as [$proc, $pipes]) {
        $out[] = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    return $out;
}

function t_sign_race_fixture(): array
{
    t_booking_settings_committed();
    $car = t_make_car();
    Database::run('UPDATE cars SET price_day = 1000, booking_deposit = 1000, security_deposit = 3000 WHERE id = :id', ['id' => $car]);
    $uid = Database::insert("INSERT INTO users (name, email, password_hash, role) VALUES ('race', :e, 'x', 'staff')", ['e' => 'race' . bin2hex(random_bytes(5)) . '@x.test']);
    $bid = \App\Services\BookingService::createBooking($car, '2031-07-10 10:00:00', '2031-07-13 10:00:00', ['name' => 'race', 'phone' => '0877771999', 'email' => '', 'line' => ''], ['source' => 'web']);
    $png = tempnam(sys_get_temp_dir(), 'sig');
    file_put_contents($png, base64_decode(substr(t_sig_png(), strlen('data:image/png;base64,'))));
    return ['car' => $car, 'user' => $uid, 'booking' => $bid, 'png' => $png];
}

function t_sign_race_cleanup(array $fx): void
{
    foreach (Database::all('SELECT file_path FROM signatures WHERE booking_id = :b', ['b' => $fx['booking']]) as $row) {
        UploadService::delete($row['file_path']);
    }
    Database::run('DELETE FROM signatures WHERE booking_id = :b', ['b' => $fx['booking']]);
    Database::run("DELETE FROM activity_logs WHERE action IN ('signature_signed', 'signature_voided')");
    Database::run('DELETE FROM booking_addons WHERE booking_id = :b', ['b' => $fx['booking']]);
    Database::run('DELETE FROM bookings WHERE id = :b', ['b' => $fx['booking']]);
    Database::run("DELETE FROM customers WHERE phone = '0877771999'");
    Database::run('DELETE FROM cars WHERE id = :c', ['c' => $fx['car']]);
    Database::run('DELETE FROM users WHERE id = :u', ['u' => $fx['user']]);
    @unlink($fx['png']);
}

function t_sign_files(): int
{
    return count(glob(storage_path('signatures') . '/*.png') ?: []);
}

function test_race_signature_same_booking_gets_exactly_one_active_signature(): void
{
    $fx = t_sign_race_fixture();
    try {
        $before = t_sign_files();
        $seen = SignatureService::view(Booking::find($fx['booking']), 'contract')['hash'];
        $results = t_sign_race_run(array_fill(0, 6, ['sign', $fx['booking'], $seen . '|' . $fx['png']]));
        assert_same(1, count(array_filter($results, fn($r) => str_starts_with($r, 'OK '))), 'ต้องเซ็นสำเร็จฉบับเดียว: ' . implode(' / ', $results));
        assert_same(5, count(array_filter($results, fn($r) => str_starts_with($r, 'REFUSED'))), implode(' / ', $results));
        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM signatures WHERE booking_id = :b AND voided_at IS NULL', ['b' => $fx['booking']]));
        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM signatures WHERE booking_id = :b', ['b' => $fx['booking']]));
        assert_same($before + 1, t_sign_files(), 'ไฟล์ของคำขอที่แพ้ต้องถูกลบ ไม่ทิ้งขยะ');
    } finally {
        t_sign_race_cleanup($fx);
    }
}

function test_race_signature_void_same_signature_only_once(): void
{
    $fx = t_sign_race_fixture();
    try {
        $seen = SignatureService::view(Booking::find($fx['booking']), 'contract')['hash'];
        $sigId = SignatureService::sign($fx['booking'], 'contract', ['signature' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($fx['png'])), 'name' => 'ผู้เช่า', 'accept_terms' => '1', 'consent' => '1', 'seen' => $seen], []);
        $results = t_sign_race_run(array_fill(0, 5, ['void', $sigId, $fx['user']]));
        assert_same(1, count(array_filter($results, fn($r) => $r === 'OK')), implode(' / ', $results));
        assert_same(4, count(array_filter($results, fn($r) => str_starts_with($r, 'REFUSED'))), implode(' / ', $results));
        assert_same(1, (int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'signature_voided'"));
    } finally {
        t_sign_race_cleanup($fx);
    }
}

function test_race_sign_token_first_use_yields_one_token(): void
{
    $fx = t_sign_race_fixture();
    try {
        $results = t_sign_race_run(array_fill(0, 6, ['token', $fx['booking'], '']));
        assert_same(6, count(array_filter($results, fn($r) => preg_match('/^OK [0-9a-f]{32}$/', $r) === 1)), implode(' / ', $results));
        assert_same(1, count(array_unique($results)), 'ทุกคนต้องได้รหัสเดียวกัน: ' . implode(' / ', $results));
    } finally {
        t_sign_race_cleanup($fx);
    }
}

function test_race_document_numbers_are_unique_and_gapless(): void
{
    $period = '3207';
    Database::run("DELETE FROM documents WHERE doc_no LIKE 'QT3207-%'");
    Database::run("DELETE FROM sequences WHERE name = 'doc_quotation' AND period = '3207'");
    try {
        $results = t_sign_race_run(array_fill(0, 8, ['doc', 0, '2032-07-15']));
        assert_same(8, count(array_filter($results, fn($r) => str_starts_with($r, 'OK QT3207-'))), implode(' / ', $results));
        $numbers = array_map(fn($r) => substr($r, 3), $results);
        sort($numbers);
        assert_same(array_map(fn($i) => sprintf('QT3207-%04d', $i), range(1, 8)), $numbers, 'เลขต้องไม่ซ้ำและไม่ขาด');
    } finally {
        Database::run("DELETE FROM documents WHERE doc_no LIKE 'QT3207-%'");
        Database::run("DELETE FROM sequences WHERE name = 'doc_quotation' AND period = '3207'");
    }
}
