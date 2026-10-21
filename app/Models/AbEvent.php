<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use PDO;

/**
 * สถิติกรวยการจองเพื่อเทียบดีไซน์ A/B — ไม่เก็บข้อมูลส่วนบุคคล (ไม่มีชื่อ เบอร์ อีเมล IP)
 * visitor_id เป็นเลขสุ่มที่ผูกกับเซสชันเท่านั้น
 */
final class AbEvent extends Model
{
    protected static string $table = 'ab_events';

    public const STEPS = ['view_home', 'view_car', 'start_form', 'submit', 'booked'];

    /**
     * สรุปกรวย — นับเป็น "จำนวนคน" ไม่ใช่จำนวนครั้ง
     * $pdo เป็นฐานข้อมูลของเวอร์ชันนี้ หรือของอีกเวอร์ชัน (หน้าเทียบผล) $device: all | mobile | desktop
     */
    public static function funnel(string $from, string $to, string $device = 'all', ?PDO $pdo = null): array
    {
        $where = 'DATE(created_at) BETWEEN :a AND :b';
        $p     = [':a' => $from, ':b' => $to];
        if ($device !== 'all') {
            $where .= ' AND device = :d';
            $p[':d'] = $device;
        }
        $sql = "SELECT step, COUNT(DISTINCT visitor_id) AS n FROM ab_events WHERE $where GROUP BY step";

        if ($pdo === null) {
            $rows = Database::all($sql, $p);
        } else {
            $st = $pdo->prepare($sql);
            $st->execute($p);
            $rows = $st->fetchAll();
        }

        $out = array_fill_keys(self::STEPS, 0);
        foreach ($rows as $r) {
            if (isset($out[$r['step']])) {
                $out[$r['step']] = (int) $r['n'];
            }
        }
        return $out;
    }
}
