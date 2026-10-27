<?php
use App\Core\Database;
use App\Models\Notification;
use App\Models\Setting;

/** หลาย process ส่ง/เข้าคิวพร้อมกัน: ข้อความต้องไปถึง LINE ครั้งเดียว ไม่เบิ้ล ไม่หาย (ชื่อ test_race_* ไม่ถูกครอบด้วย transaction) */

function t_notify_race_setup(string $token): array
{
    t_fake_line();
    t_fake_line_reset();
    $saved = [];
    foreach (['notify_line_enabled' => '1', 'line_channel_token' => $token] as $k => $v) {
        $saved[$k] = Setting::get($k);
        Setting::set($k, $v);
    }
    Database::run('DELETE FROM notifications');
    return $saved;
}

function t_notify_race_restore(array $saved): void
{
    foreach ($saved as $k => $v) {
        Setting::set($k, (string) $v);
    }
    Database::run('DELETE FROM notifications');
}

function test_race_parallel_flush_sends_each_message_exactly_once(): void
{
    $saved = t_notify_race_setup(T_TOK_OK);
    try {
        for ($i = 1; $i <= 24; $i++) {
            Notification::queue('line', T_LINE_U1, "ข้อความ $i", null, null, "race$i");
        }
        $out = t_calendar_race_run(array_map(static fn() => ['notify.php', ['flush', '@start']], range(1, 6)));
        foreach ($out as $line) {
            assert_true(strpos($line, 'SENT') === 0, "worker ผิดพลาด: $line");
        }
        $sentByWorkers = array_sum(array_map(static fn(string $l): int => (int) substr($l, 5), $out));
        assert_same(24, $sentByWorkers, 'รวมที่ส่งต้องเท่าจำนวนข้อความ');
        $req = t_fake_line_requests();
        assert_same(24, count($req), 'LINE ได้รับครั้งเดียวต่อข้อความ');
        $texts = array_map(static fn(array $r): string => $r['body']['messages'][0]['text'], $req);
        assert_same(24, count(array_unique($texts)), 'ไม่มีข้อความเบิ้ล');
        assert_same(24, count(array_unique(array_column($req, 'retry_key'))));
        $counts = Notification::countByStatus();
        assert_same(24, $counts['sent']);
        assert_same(0, $counts['queued'] + $counts['sending'] + $counts['failed']);
        assert_same(0, (int) Database::value('SELECT COUNT(*) FROM notifications WHERE attempts <> 1'), 'ทุกแถวถูกลองครั้งเดียว');
    } finally {
        t_notify_race_restore($saved);
    }
}

function test_race_same_event_queued_by_many_processes_makes_one_row(): void
{
    $saved = t_notify_race_setup(T_TOK_OK);
    try {
        $out = t_calendar_race_run(array_map(static fn() => ['notify.php', ['queue', '@start', 'new_booking:777:U0123']], range(1, 8)));
        $made = 0;
        foreach ($out as $line) {
            assert_true(strpos($line, 'QUEUED') === 0, "worker ผิดพลาด: $line");
            $made += (int) substr($line, 7) > 0 ? 1 : 0;
        }
        assert_same(1, $made, 'มีแค่ process เดียวที่สร้างแถวได้');
        assert_same(1, (int) Database::value('SELECT COUNT(*) FROM notifications'));
    } finally {
        t_notify_race_restore($saved);
    }
}
