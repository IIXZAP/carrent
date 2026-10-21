<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** งานประจำรถ — ประกัน / พ.ร.บ. / ภาษี / เช็กระยะ / ซ่อม */
final class Maintenance extends Model
{
    protected static string $table = 'maintenances';

    public const TYPES = ['service', 'insurance', 'act', 'tax', 'tyre', 'repair', 'other'];

    /** รายการงาน พร้อมข้อมูลรถ — $status = pending|done|skipped|all */
    public static function listByStatus(string $status, int $limit = 200): array
    {
        $w   = $status === 'all' ? '1=1' : 'm.status = :st';
        $prm = $status === 'all' ? [] : [':st' => $status];
        $limit = max(1, min(1000, $limit));
        return Database::all(
            "SELECT m.*, c.plate, c.brand, c.model, c.mileage
             FROM maintenances m JOIN cars c ON c.id = m.car_id
             WHERE $w ORDER BY m.status = 'pending' DESC, m.due_date IS NULL, m.due_date LIMIT $limit",
            $prm
        );
    }

    /** งานที่ค้างและใกล้ครบกำหนดภายในวันที่ $until */
    public static function dueUntil(string $until, int $limit = 10): array
    {
        $limit = max(1, min(200, $limit));
        return Database::all(
            "SELECT m.*, c.plate, c.brand, c.model FROM maintenances m JOIN cars c ON c.id = m.car_id
             WHERE m.status = 'pending' AND m.due_date IS NOT NULL AND m.due_date <= :d
             ORDER BY m.due_date LIMIT $limit",
            [':d' => $until]
        );
    }

    public static function dueCount(string $until): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM maintenances WHERE status = 'pending' AND due_date IS NOT NULL AND due_date <= :d",
            [':d' => $until]
        );
    }

    public static function overdueCount(string $today): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM maintenances WHERE status='pending' AND due_date < :d", [':d' => $today]);
    }

    public static function dueBetweenCount(string $from, string $to): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM maintenances WHERE status='pending' AND due_date BETWEEN :a AND :b",
            [':a' => $from, ':b' => $to]
        );
    }

    public static function costForYear(string $year): float
    {
        return (float) Database::value("SELECT COALESCE(SUM(cost),0) FROM maintenances WHERE status='done' AND YEAR(done_date) = :y", [':y' => $year]);
    }

    /** มีงานค้างชนิดและวันครบกำหนดเดียวกันอยู่แล้วหรือไม่ (กันสร้างซ้ำ) */
    public static function pendingExists(int $carId, string $type, string $due): bool
    {
        return (bool) Database::value(
            "SELECT id FROM maintenances WHERE car_id = :c AND task_type = :t AND due_date = :d AND status = 'pending'",
            [':c' => $carId, ':t' => $type, ':d' => $due]
        );
    }

    /** งานที่ cron ต้องแจ้งเตือน: ใกล้ครบและยังไม่เคยแจ้งวันนี้ */
    public static function needingReminder(string $until, string $today): array
    {
        return Database::all(
            "SELECT m.*, c.plate, c.brand, c.model FROM maintenances m JOIN cars c ON c.id = m.car_id
             WHERE m.status = 'pending' AND m.due_date IS NOT NULL
               AND m.due_date <= :d
               AND (m.notified_at IS NULL OR DATE(m.notified_at) < :today)",
            [':d' => $until, ':today' => $today]
        );
    }

    public static function markNotified(int $id): void
    {
        self::updateById($id, ['notified_at' => date('Y-m-d H:i:s')]);
    }

    public static function skip(int $id): void
    {
        self::updateById($id, ['status' => 'skipped']);
    }

    /** ผลตรวจสภาพรถ-ค่าบำรุงรักษาที่ปิดแล้วในช่วงวันที่ รวมต่อคัน [car_id => cost] */
    public static function costByCar(string $from, string $to): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT car_id, COALESCE(SUM(cost),0) AS cost FROM maintenances
             WHERE status = 'done' AND done_date BETWEEN :a AND :b GROUP BY car_id",
            [':a' => $from, ':b' => $to]
        ) as $r) {
            $out[(int) $r['car_id']] = (float) $r['cost'];
        }
        return $out;
    }
}
