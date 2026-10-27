<?php
/**
 * ตัวช่วยของ tests/SignatureRaceTest.php — process ลูกที่ทำงานเอกสาร/ลายเซ็น 1 ครั้ง แล้วพิมพ์ผลบรรทัดเดียว
 *   php tests/workers/sign.php <sign|void|token|doc> <id> <startAtEpochFloat> <arg>
 *   sign: id = bookingId, arg = "seenHash|pngPath" ; void: id = signatureId, arg = userId ; token: id = bookingId ; doc: arg = issue_date
 */
require __DIR__ . '/../../app/bootstrap.php';

use App\Services\DocumentService;
use App\Services\SignatureService;
use App\Services\UserError;

[$script, $action, $id, $startAt] = $argv;
$arg = $argv[4] ?? '';

App\Models\Setting::all();
App\Core\Database::pdo();
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    switch ($action) {
        case 'sign':
            [$seen, $png] = explode('|', $arg);
            $dataUrl = 'data:image/png;base64,' . base64_encode((string) file_get_contents($png));
            $sigId = SignatureService::sign((int) $id, 'contract', ['signature' => $dataUrl, 'name' => 'ผู้เช่า ทดสอบ', 'accept_terms' => '1', 'consent' => '1', 'seen' => $seen], ['ip' => '192.0.2.88', 'ua' => 'worker']);
            echo "OK $sigId\n";
            exit;
        case 'void':
            SignatureService::void((int) $id, (int) $arg, 'ทดสอบพร้อมกัน', '');
            echo "OK\n";
            exit;
        case 'token':
            echo 'OK ' . App\Models\Booking::signToken((int) $id) . "\n";
            exit;
        case 'doc':
            $docId = DocumentService::create([
                'doc_type' => 'quotation', 'issue_date' => $arg, 'bill_name' => 'race',
                'items' => [['description' => 'x', 'qty' => '1', 'price' => '10']],
            ], null);
            echo 'OK ' . DocumentService::get($docId)['doc_no'] . "\n";
            exit;
    }
    throw new InvalidArgumentException('action?');
} catch (UserError $e) {
    echo 'REFUSED ' . $e->getMessage() . "\n";
} catch (\Throwable $e) {
    echo 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
