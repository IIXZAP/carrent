<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;

/**
 * ตรวจสอบระบบหลังติดตั้ง (/check)
 *
 * หน้านี้แสดงชื่อฐานข้อมูล ชื่อผู้ใช้ฐานข้อมูล path บนเซิร์ฟเวอร์ และสิทธิ์โฟลเดอร์ — เป็นข้อมูลที่ไม่ควรเปิดให้คนนอกเห็น
 * ระหว่างติดตั้ง (ยังไม่เคยมีใครเข้าระบบ) เปิดได้ตามปกติ หลังจากนั้นเปิดได้เฉพาะเจ้าของร้านที่เข้าสู่ระบบอยู่
 */
class InstallController extends Controller
{
    /** @var list<array{name:string,ok:bool,detail:string,warn:bool}> */
    private array $rows = [];
    private int $fail = 0;
    private int $warn = 0;

    private function chk(string $name, bool $ok, string $detail = '', bool $warnOnly = false): void
    {
        $this->rows[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'warn' => $warnOnly];
        if (!$ok) {
            $warnOnly ? $this->warn++ : $this->fail++;
        }
    }

    public function index(): Response
    {
        $res = $this->guard();
        if ($res) {
            return $res;
        }

        $this->checkPhp();
        $this->checkConfig();
        $pdo = $this->checkDatabase();
        $this->checkFiles();
        $this->checkSecurity();

        return $this->view('install/check', [
            'title'   => 'ตรวจสอบระบบ',
            'heading' => 'ตรวจสอบระบบ thonglorcarrent',
            'sub'     => 'รันเมื่อ ' . date('d/m/Y H:i:s'),
            'narrow'  => 'max-w-[820px]',
            'rows'    => $this->rows,
            'fail'    => $this->fail,
            'warn'    => $this->warn,
            'dbOk'    => $pdo,
        ])->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store');
    }

    /** ล็อกหน้านี้เมื่อระบบถูกใช้งานแล้ว */
    private function guard(): ?Response
    {
        $inUse = false;
        try {
            $pdo = $this->connect();
            $inUse = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE last_login_at IS NOT NULL')->fetchColumn() > 0;
        } catch (\Throwable $e) {
            $inUse = false;   // ยังต่อฐานข้อมูลไม่ได้หรือยังไม่มีตาราง = ยังติดตั้งไม่เสร็จ ต้องเปิดหน้านี้ได้
        }

        // จำไว้ว่าระบบเคยใช้งานแล้ว — วันที่ฐานข้อมูลล่ม หน้านี้ก็ต้องไม่เปิดให้คนนอกเห็นชื่อฐานข้อมูลและ path
        $lockFile = APP_ROOT . '/storage/.installed';
        if ($inUse && !is_file($lockFile)) {
            @file_put_contents($lockFile, date('c'));
        }
        $inUse = $inUse || is_file($lockFile);

        if ($inUse && (!auth_user() || !is_admin())) {
            return $this->view('install/locked', [
                'title'   => 'ตรวจการติดตั้ง',
                'heading' => 'ระบบติดตั้งเรียบร้อยแล้ว',
                'narrow'  => 'max-w-[520px]',
            ], 403)->header('X-Robots-Tag', 'noindex');
        }
        return null;
    }

    private function connect(): \PDO
    {
        return new \PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
            DB_USER,
            DB_PASS,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]
        );
    }

    private function checkPhp(): void
    {
        $okVer = version_compare(PHP_VERSION, '8.1', '>=');
        $this->chk('PHP เวอร์ชัน ' . PHP_VERSION, $okVer,
            $okVer ? 'ผ่าน' : 'ต้องใช้ PHP 8.1 ขึ้นไป (เปลี่ยนได้ที่ cPanel → MultiPHP Manager)');

        foreach ([
            'pdo_mysql' => 'ต่อฐานข้อมูล', 'mbstring' => 'ภาษาไทย', 'fileinfo' => 'ตรวจไฟล์อัปโหลด',
            'json' => 'ข้อมูล JSON', 'curl' => 'แจ้งเตือน LINE', 'gd' => 'ย่อรูปสภาพรถ',
        ] as $ext => $why) {
            $this->chk("PHP extension: $ext ($why)", extension_loaded($ext),
                extension_loaded($ext) ? 'ผ่าน' : 'ยังไม่เปิด — เปิดที่ cPanel → Select PHP Version → Extensions',
                in_array($ext, ['curl', 'gd'], true));
        }

        // ขนาดไฟล์ที่เซิร์ฟเวอร์ยอมรับ — สำคัญกับการถ่ายรูปสภาพรถ
        $umax = (int) ini_get('upload_max_filesize');
        $this->chk('ขนาดไฟล์อัปโหลดสูงสุด ' . ini_get('upload_max_filesize'), $umax >= 2,
            $umax >= 4
                ? 'ผ่าน'
                : 'ต่ำไปหน่อย — หน้าถ่ายรูปย่อรูปให้ก่อนส่งอยู่แล้วจึงใช้งานได้ '
                  . 'แต่ถ้าอัปรูปจากคอมโดยตรงอาจไม่ผ่าน เพิ่มได้ที่ cPanel → MultiPHP INI Editor → upload_max_filesize',
            true);
    }

    private function checkConfig(): void
    {
        $cssPath = APP_ROOT . '/assets/css/app.css';
        $this->chk('ตรวจพบ BASE_URL = ' . (BASE_URL === '' ? "'' (ติดตั้งที่ root ของโดเมน)" : "'" . BASE_URL . "'"),
            true, 'ระบบตรวจหาเองจาก DOCUMENT_ROOT');
        $this->chk('มีไฟล์ assets/css/app.css บนเซิร์ฟเวอร์', is_file($cssPath),
            is_file($cssPath) ? 'ขนาด ' . number_format(filesize($cssPath) / 1024, 1) . ' KB'
                              : 'ไม่พบไฟล์ — อัปโหลดโฟลเดอร์ assets/ ไม่ครบ (หรือยังไม่ได้รัน npm run build)');

        $docRoot = str_replace('\\', '/', rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
        $this->chk('อ่าน DOCUMENT_ROOT ได้', $docRoot !== '',
            $docRoot !== '' ? $docRoot : 'host ไม่ส่งค่ามา — ถ้า CSS ไม่ขึ้น ให้ตั้ง BASE_URL_MANUAL เอง', true);
    }

    /** @return bool ต่อฐานข้อมูลได้และตารางครบหรือไม่ */
    private function checkDatabase(): bool
    {
        try {
            $pdo = $this->connect();
            $this->chk('เชื่อมต่อฐานข้อมูล ' . DB_NAME, true, 'ผ่าน (user: ' . DB_USER . ')');

            $need = ['users', 'customers', 'cars', 'bookings', 'payments', 'documents', 'maintenances',
                     'car_blocks', 'settings', 'activity_logs', 'notifications'];
            $have = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            $miss = array_diff($need, $have);
            $this->chk('ตารางครบ ' . count($need) . ' ตาราง', empty($miss),
                empty($miss) ? 'ผ่าน' : 'ขาด: ' . implode(', ', $miss) . ' — import database/schema.sql ใหม่');
            if ($miss) {
                return false;
            }

            $nCars = (int) $pdo->query('SELECT COUNT(*) FROM cars')->fetchColumn();
            $nUser = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $nSet  = (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn();
            $this->chk('ข้อมูลตั้งต้น', $nUser > 0 && $nSet > 0,
                "รถ $nCars คัน · ผู้ใช้ $nUser คน · ตั้งค่า $nSet รายการ"
                . ($nUser === 0 ? ' — ยังไม่ได้ import database/seed.sql' : ''));

            $coll = (string) $pdo->query('SELECT @@character_set_database')->fetchColumn();
            $this->chk('Charset ฐานข้อมูล = ' . $coll, stripos($coll, 'utf8') === 0,
                stripos($coll, 'utf8') === 0 ? 'ภาษาไทยแสดงผลถูกต้อง' : 'ต้องเป็น utf8mb4 ไม่งั้นภาษาไทยจะเพี้ยน');

            // รูปรถ
            $carsAll = $pdo->query("SELECT id, brand, model, cover_image FROM cars WHERE status <> 'inactive'")
                           ->fetchAll(\PDO::FETCH_ASSOC);
            $noDb = $noFile = [];
            foreach ($carsAll as $cc) {
                $nm = $cc['brand'] . ' ' . $cc['model'];
                if (empty($cc['cover_image'])) {
                    $noDb[] = $nm;
                } elseif (!is_file(UPLOAD_DIR . '/' . ltrim($cc['cover_image'], '/'))) {
                    $noFile[] = $nm . ' → ' . $cc['cover_image'];
                }
            }
            $okCars = count($carsAll) - count($noDb) - count($noFile);
            $this->chk('รูปรถแสดงผลได้ ' . $okCars . '/' . count($carsAll) . ' คัน', !$noDb && !$noFile,
                (!$noDb && !$noFile) ? 'ผ่าน'
                    : ($noDb
                        ? 'ฐานข้อมูลยังไม่รู้จักไฟล์รูป — ต้อง import database/update-v2.sql ใน phpMyAdmin. รถที่ยังไม่มีรูป: ' . implode(', ', array_slice($noDb, 0, 4))
                        : 'ฐานข้อมูลชี้ไปไฟล์ที่ไม่มีบนเซิร์ฟเวอร์ — อัปโหลด uploads/cars/ ไม่ครบ: ' . implode(' | ', array_slice($noFile, 0, 3))));
            return true;
        } catch (\Throwable $e) {
            $this->chk('เชื่อมต่อฐานข้อมูล ' . DB_NAME, false,
                'ต่อไม่ได้: ' . $e->getMessage()
                . ' — เช็กชื่อ DB / user / รหัสผ่านใน config/config.php (cPanel มักเติม prefix เช่น user_carrent)');
            return false;
        }
    }

    private function checkFiles(): void
    {
        $logoOk = is_file(APP_ROOT . '/assets/img/logo-full.webp');
        $this->chk('ไฟล์โลโก้', $logoOk,
            $logoOk ? 'ผ่าน (assets/img/logo-full.webp)' : 'ไม่พบ — อัปโหลดโฟลเดอร์ assets/img/ ไม่ครบ');

        $nCarImg = is_dir(UPLOAD_DIR . '/cars') ? count(glob(UPLOAD_DIR . '/cars/*.webp') ?: []) : 0;
        $this->chk('ไฟล์รูปรถในโฟลเดอร์ uploads/cars/', $nCarImg >= 8,
            $nCarImg . ' ไฟล์' . ($nCarImg >= 8 ? '' : ' — ควรมี 8 ไฟล์ .webp ตรวจว่าอัปโหลดครบไหม'));

        foreach (['uploads', 'uploads/slips', 'uploads/cars', 'uploads/docs', 'uploads/inspections', 'storage', 'storage/backups', 'storage/private'] as $d) {
            $p  = APP_ROOT . '/' . $d;
            $ok = is_dir($p) && is_writable($p);
            $this->chk("โฟลเดอร์ $d/ เขียนไฟล์ได้", $ok,
                $ok ? 'ผ่าน (' . substr(sprintf('%o', fileperms($p)), -3) . ')'
                    : (is_dir($p) ? 'เขียนไม่ได้ — ตั้ง permission เป็น 755 หรือ 775' : 'ไม่มีโฟลเดอร์นี้ — สร้างเพิ่ม'));
        }
    }

    private function checkSecurity(): void
    {
        $https = $this->request->isSecure();
        $this->chk('APP_DEBUG ปิดอยู่', APP_DEBUG === false,
            APP_DEBUG === false ? 'ผ่าน' : 'ต้องตั้งเป็น false บน production', true);
        $this->chk('ใช้ HTTPS', $https,
            $https ? 'ผ่าน' : 'ยังเป็น http — ติดตั้ง SSL ฟรีที่ cPanel → SSL/TLS Status', true);
        $this->chk('มี uploads/.htaccess (กันรันสคริปต์ในโฟลเดอร์อัปโหลด)', is_file(UPLOAD_DIR . '/.htaccess'),
            is_file(UPLOAD_DIR . '/.htaccess') ? 'ผ่าน' : 'ไม่พบ — อัปโหลดไฟล์ซ่อน (.htaccess) ไม่ครบ');
        foreach (['config', 'app', 'storage'] as $d) {
            $this->chk("มี $d/.htaccess (ปิดการเข้าถึงจากเว็บ)", is_file(APP_ROOT . "/$d/.htaccess"),
                is_file(APP_ROOT . "/$d/.htaccess") ? 'ผ่าน' : 'ไม่พบ — อัปโหลดไฟล์ซ่อนไม่ครบ');
        }
    }
}
