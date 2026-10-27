<?php
use App\Core\Database;

function test_race_creating_same_phone_makes_one_customer_and_clean_refusals(): void
{
    $phone = '08' . random_int(10000000, 99999999);
    try {
        $startAt = microtime(true) + 1.5;
        $procs = [];
        for ($i = 0; $i < 8; $i++) {
            $proc = proc_open([PHP_BINARY, __DIR__ . '/workers/customer.php', sprintf('%.4f', $startAt), $phone, "คนที่ $i"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            assert_true(is_resource($proc), 'เปิด worker ไม่ได้');
            $procs[] = [$proc, $pipes];
        }
        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $results[] = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
        assert_same(1, count(array_filter($results, fn($r) => str_starts_with($r, 'OK '))), implode(' / ', $results));
        assert_same(7, count(array_filter($results, fn($r) => str_starts_with($r, 'REFUSED เบอร์นี้มีลูกค้าอยู่แล้ว'))), implode(' / ', $results));
        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM customers WHERE phone = :p', ['p' => $phone]));
        $codes = array_column(Database::all('SELECT code FROM customers WHERE phone = :p', ['p' => $phone]), 'code');
        assert_true(preg_match('/^CUS-\d{5}$/', $codes[0]) === 1);
    } finally {
        Database::run('DELETE FROM customers WHERE phone = :p', ['p' => $phone]);
        Database::run("DELETE FROM activity_logs WHERE action = 'customer_created'");
    }
}
