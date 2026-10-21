<?php
/**
 * ==========================================================
 *  thonglorcarrent — ตรวจสอบระบบหลังติดตั้ง
 *  เปิด https://โดเมนของคุณ/check.php (หรือ /carrent/check.php)
 *  *** ลบไฟล์นี้ทิ้งเมื่อติดตั้งเสร็จแล้ว ***
 * ==========================================================
 */

$rows = [];
$fail = 0;
$warn = 0;

function chk(string $name, bool $ok, string $detail = '', bool $warnOnly = false): void
{
    global $rows, $fail, $warn;
    $rows[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'warn' => $warnOnly];
    if (!$ok) { $warnOnly ? $warn++ : $fail++; }
}

// ---------- 1. PHP ----------
chk('PHP เวอร์ชัน ' . PHP_VERSION, version_compare(PHP_VERSION, '8.0', '>='),
    version_compare(PHP_VERSION, '8.0', '>=') ? 'ผ่าน' : 'ต้องใช้ PHP 8.0 ขึ้นไป (เปลี่ยนได้ที่ cPanel → MultiPHP Manager)');

foreach (['pdo_mysql' => 'ต่อฐานข้อมูล', 'mbstring' => 'ภาษาไทย', 'fileinfo' => 'ตรวจไฟล์อัปโหลด',
          'json' => 'ข้อมูล JSON', 'curl' => 'แจ้งเตือน LINE', 'gd' => 'ย่อรูปสภาพรถ'] as $ext => $why) {
    chk("PHP extension: $ext ($why)", extension_loaded($ext),
        extension_loaded($ext) ? 'ผ่าน' : "ยังไม่เปิด — เปิดที่ cPanel → Select PHP Version → Extensions",
        in_array($ext, ['curl', 'gd'], true));
}

// ขนาดไฟล์ที่เซิร์ฟเวอร์ยอมรับ — สำคัญกับการถ่ายรูปสภาพรถ
$umax = (int) ini_get('upload_max_filesize');
chk('ขนาดไฟล์อัปโหลดสูงสุด ' . ini_get('upload_max_filesize'), $umax >= 2,
    $umax >= 4
        ? 'ผ่าน'
        : 'ต่ำไปหน่อย — หน้าถ่ายรูปย่อรูปให้ก่อนส่งอยู่แล้วจึงใช้งานได้ '
          . 'แต่ถ้าอัปรูปจากคอมโดยตรงอาจไม่ผ่าน เพิ่มได้ที่ cPanel → MultiPHP INI Editor → upload_max_filesize',
    true);

// ---------- 2. ไฟล์คอนฟิก ----------
$cfg = __DIR__ . '/config/config.php';
chk('มีไฟล์ config/config.php', is_file($cfg), is_file($cfg) ? 'ผ่าน' : 'ไม่พบไฟล์ — อัปโหลดไม่ครบ');
if (!is_file($cfg)) { render($rows, $fail, $warn); exit; }
require_once $cfg;

// ---------- ล็อกหน้านี้เมื่อระบบถูกใช้งานแล้ว ----------
// หน้านี้แสดงชื่อฐานข้อมูล ชื่อผู้ใช้ฐานข้อมูล path บนเซิร์ฟเวอร์ และสิทธิ์โฟลเดอร์ — เป็นข้อมูลที่ไม่ควรเปิดให้คนนอกเห็น
// ระหว่างติดตั้ง (ยังไม่เคยมีใครเข้าระบบ) เปิดได้ตามปกติ หลังจากนั้นเปิดได้เฉพาะเจ้าของร้านที่เข้าสู่ระบบอยู่
$inUse = false;
try {
    $lockPdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET), DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    $inUse = (int) $lockPdo->query('SELECT COUNT(*) FROM users WHERE last_login_at IS NOT NULL')->fetchColumn() > 0;
    $lockPdo = null;
} catch (Throwable $e) {
    $inUse = false;   // ยังต่อฐานข้อมูลไม่ได้หรือยังไม่มีตาราง = ยังติดตั้งไม่เสร็จ ต้องเปิดหน้านี้ได้
}
// จำไว้ว่าระบบเคยใช้งานแล้ว — วันที่ฐานข้อมูลล่ม หน้านี้ก็ต้องไม่เปิดให้คนนอกเห็นชื่อฐานข้อมูลและ path
$lockFile = __DIR__ . '/storage/.installed';
if ($inUse && !is_file($lockFile)) {
    @file_put_contents($lockFile, date('c'));
}
$inUse = $inUse || is_file($lockFile);
if ($inUse) {
    require_once __DIR__ . '/includes/auth.php';
    if (!auth_check() || !is_admin()) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo '<!DOCTYPE html><html lang="th"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>ตรวจการติดตั้ง</title><body style="font-family:Sarabun,system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 20px;line-height:1.7">'
           . '<h1 style="font-size:1.25rem">ระบบติดตั้งเรียบร้อยแล้ว</h1>'
           . '<p>หน้าตรวจการติดตั้งเปิดได้เฉพาะเจ้าของร้านที่เข้าสู่ระบบอยู่ '
           . '<a href="' . htmlspecialchars(BASE_URL . '/admin/login.php', ENT_QUOTES, 'UTF-8') . '">เข้าสู่ระบบหลังบ้าน</a></p></body></html>';
        exit;
    }
}

// ---------- 3. BASE_URL / assets ----------
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$cssPath = __DIR__ . '/assets/css/app.css';
$cssUrl  = $scheme . '://' . $host . BASE_URL . '/assets/css/app.css';

chk('ตรวจพบ BASE_URL = ' . (BASE_URL === '' ? "'' (ติดตั้งที่ root ของโดเมน)" : "'" . BASE_URL . "'"),
    true, 'ระบบตรวจหาเองจาก DOCUMENT_ROOT');

chk('มีไฟล์ assets/css/app.css บนเซิร์ฟเวอร์', is_file($cssPath),
    is_file($cssPath) ? 'ขนาด ' . number_format(filesize($cssPath) / 1024, 1) . ' KB'
                      : 'ไม่พบไฟล์ — อัปโหลดโฟลเดอร์ assets/ ไม่ครบ');

// เช็กว่า DOCUMENT_ROOT ตรงกับที่ระบบคำนวณจริงไหม
$docRoot = str_replace('\\', '/', rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
$appRoot = str_replace('\\', '/', __DIR__);
chk('อ่าน DOCUMENT_ROOT ได้', $docRoot !== '',
    $docRoot !== '' ? $docRoot : 'host ไม่ส่งค่ามา — ถ้า CSS ไม่ขึ้น ให้ตั้ง BASE_URL_MANUAL เอง', true);

// ---------- 4. ฐานข้อมูล ----------
$dbOk = false;
$miss = ['(not-checked)'];
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    $dbOk = true;
    chk('เชื่อมต่อฐานข้อมูล ' . DB_NAME, true, 'ผ่าน (user: ' . DB_USER . ')');

    $need = ['users','customers','cars','bookings','payments','documents','maintenances','car_blocks','settings','activity_logs','notifications'];
    $have = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $miss = array_diff($need, $have);
    chk('ตารางครบ ' . count($need) . ' ตาราง', empty($miss),
        empty($miss) ? 'ผ่าน' : 'ขาด: ' . implode(', ', $miss) . ' — import database/schema.sql ใหม่');

    if (empty($miss)) {
        $nCars = (int) $pdo->query('SELECT COUNT(*) FROM cars')->fetchColumn();
        $nUser = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $nSet  = (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn();
        chk('ข้อมูลตั้งต้น', $nUser > 0 && $nSet > 0,
            "รถ $nCars คัน · ผู้ใช้ $nUser คน · ตั้งค่า $nSet รายการ"
            . ($nUser === 0 ? ' — ยังไม่ได้ import database/seed.sql' : ''));

        $coll = $pdo->query("SELECT @@character_set_database")->fetchColumn();
        chk('Charset ฐานข้อมูล = ' . $coll, stripos((string) $coll, 'utf8') === 0,
            stripos((string) $coll, 'utf8') === 0 ? 'ภาษาไทยแสดงผลถูกต้อง' : 'ต้องเป็น utf8mb4 ไม่งั้นภาษาไทยจะเพี้ยน');
    }
} catch (Throwable $e) {
    chk('เชื่อมต่อฐานข้อมูล ' . DB_NAME, false,
        'ต่อไม่ได้: ' . $e->getMessage()
        . ' — เช็กชื่อ DB / user / รหัสผ่านใน config/config.php (cPanel มักเติม prefix เช่น user_carrent)');
}

// ---------- 4.5 รูปรถ + โลโก้ ----------
if ($dbOk && empty($miss)) {
    $carsAll = $pdo->query("SELECT id, brand, model, cover_image FROM cars WHERE status <> 'inactive'")
                   ->fetchAll(PDO::FETCH_ASSOC);
    $noDb = $noFile = [];
    foreach ($carsAll as $cc) {
        $nm = $cc['brand'] . ' ' . $cc['model'];
        if (empty($cc['cover_image'])) {
            $noDb[] = $nm;
        } elseif (!is_file(__DIR__ . '/uploads/' . ltrim($cc['cover_image'], '/'))) {
            $noFile[] = $nm . ' → ' . $cc['cover_image'];
        }
    }
    $okCars = count($carsAll) - count($noDb) - count($noFile);
    chk('รูปรถแสดงผลได้ ' . $okCars . '/' . count($carsAll) . ' คัน',
        empty($noDb) && empty($noFile),
        (empty($noDb) && empty($noFile)) ? 'ผ่าน'
          : (count($noDb)
              ? 'ฐานข้อมูลยังไม่รู้จักไฟล์รูป — ต้อง import database/update-v2.sql ใน phpMyAdmin. รถที่ยังไม่มีรูป: ' . implode(', ', array_slice($noDb, 0, 4))
              : 'ฐานข้อมูลชี้ไปไฟล์ที่ไม่มีบนเซิร์ฟเวอร์ — อัปโหลด uploads/cars/ ไม่ครบ: ' . implode(' | ', array_slice($noFile, 0, 3))));
}

$logoOk = is_file(__DIR__ . '/assets/img/logo-full.webp');
chk('ไฟล์โลโก้', $logoOk,
    $logoOk ? 'ผ่าน (assets/img/logo-full.webp)' : 'ไม่พบ — อัปโหลดโฟลเดอร์ assets/img/ ไม่ครบ');

$nCarImg = is_dir(__DIR__ . '/uploads/cars') ? count(glob(__DIR__ . '/uploads/cars/*.webp')) : 0;
chk('ไฟล์รูปรถในโฟลเดอร์ uploads/cars/', $nCarImg >= 8,
    $nCarImg . ' ไฟล์' . ($nCarImg >= 8 ? '' : ' — ควรมี 8 ไฟล์ .webp ตรวจว่าอัปโหลดครบไหม'));

// ---------- 5. โฟลเดอร์อัปโหลด ----------
foreach (['uploads', 'uploads/slips', 'uploads/cars', 'uploads/docs'] as $d) {
    $p = __DIR__ . '/' . $d;
    $ok = is_dir($p) && is_writable($p);
    chk("โฟลเดอร์ $d/ เขียนไฟล์ได้", $ok,
        $ok ? 'ผ่าน (' . substr(sprintf('%o', fileperms($p)), -3) . ')'
            : (is_dir($p) ? 'เขียนไม่ได้ — ตั้ง permission เป็น 755 หรือ 775' : 'ไม่มีโฟลเดอร์นี้ — สร้างเพิ่ม'));
}

// ---------- 6. ความปลอดภัย ----------
chk('APP_DEBUG ปิดอยู่', APP_DEBUG === false,
    APP_DEBUG === false ? 'ผ่าน' : 'ต้องตั้งเป็น false บน production', true);
chk('ใช้ HTTPS', $scheme === 'https',
    $scheme === 'https' ? 'ผ่าน' : 'ยังเป็น http — ติดตั้ง SSL ฟรีที่ cPanel → SSL/TLS Status', true);
chk('มี uploads/.htaccess (กันรันสคริปต์ในโฟลเดอร์อัปโหลด)', is_file(__DIR__ . '/uploads/.htaccess'),
    is_file(__DIR__ . '/uploads/.htaccess') ? 'ผ่าน' : 'ไม่พบ — อัปโหลดไฟล์ซ่อน (.htaccess) ไม่ครบ');
chk('มี config/.htaccess (ปิดการเข้าถึงไฟล์คอนฟิก)', is_file(__DIR__ . '/config/.htaccess'),
    is_file(__DIR__ . '/config/.htaccess') ? 'ผ่าน' : 'ไม่พบ — อัปโหลดไฟล์ซ่อนไม่ครบ');

render($rows, $fail, $warn);

// ==========================================================
function render(array $rows, int $fail, int $warn): void
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = defined('BASE_URL') ? BASE_URL : '';
    ?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>ตรวจสอบระบบ thonglorcarrent</title>
<style>
*{box-sizing:border-box}
body{font-family:'Sarabun',system-ui,-apple-system,'Segoe UI',sans-serif;background:#f5f7fb;color:#1f2937;
     margin:0;padding:24px 16px;line-height:1.6}
.wrap{max-width:820px;margin:0 auto}
h1{margin:0 0 4px;font-size:1.5rem;color:#10202f}
.sub{color:#8a96a8;font-size:.9rem;margin-bottom:22px}
.card{background:#fff;border:1px solid #e3e8ef;border-radius:14px;padding:22px;margin-bottom:16px;
      box-shadow:0 1px 2px rgba(16,32,47,.06)}
.big{font-size:1.15rem;font-weight:700;padding:16px 20px;border-radius:12px;margin-bottom:18px}
.big.ok{background:#e6f5ee;color:#0f7a4d;border:1px solid #bfe5d2}
.big.bad{background:#fdecea;color:#b3261e;border:1px solid #f3c9c5}
.big.warn{background:#fdf1dc;color:#9a6300;border:1px solid #f2dcb0}
table{width:100%;border-collapse:collapse;font-size:.93rem}
td{padding:9px 8px;border-bottom:1px solid #eef1f5;vertical-align:top}
td.i{width:28px;font-size:1.05rem;text-align:center}
td.d{color:#55637a;font-size:.86rem}
.n{font-weight:600}
.bad .n{color:#b3261e}
code{background:#f0f3f7;padding:2px 6px;border-radius:5px;font-size:.85em;word-break:break-all}
.hint{background:#eef4fa;border:1px dashed #0f3d68;border-radius:10px;padding:14px 16px;font-size:.9rem;margin-top:14px}
.hint b{color:#0f3d68}
#cssTest{padding:10px 14px;border-radius:8px;font-weight:600;font-size:.93rem}
a{color:#0f3d68}
</style>
</head>
<body>
<div class="wrap">
  <h1>ตรวจสอบระบบ thonglorcarrent</h1>
  <div class="sub">รันเมื่อ <?= date('d/m/Y H:i:s') ?> · <?= htmlspecialchars($scheme . '://' . $host . $base) ?></div>

  <?php if ($fail === 0 && $warn === 0): ?>
    <div class="big ok">✅ ผ่านทุกข้อ — ระบบพร้อมใช้งาน</div>
  <?php elseif ($fail === 0): ?>
    <div class="big warn">⚠️ ใช้งานได้ แต่มี <?= $warn ?> ข้อที่ควรแก้</div>
  <?php else: ?>
    <div class="big bad">❌ มีปัญหา <?= $fail ?> ข้อที่ต้องแก้ก่อนใช้งาน</div>
  <?php endif; ?>

  <!-- ทดสอบ CSS แบบเห็นผลทันที -->
  <div class="card">
    <h2 style="margin:0 0 12px;font-size:1.05rem">ทดสอบการโหลดไฟล์ CSS</h2>
    <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/assets/css/app.css">
    <div id="cssTest" style="background:#fdecea;color:#b3261e">
      ❌ CSS โหลดไม่สำเร็จ — หน้าเว็บจะไม่มีการจัดรูปแบบ
    </div>
    <p class="d" style="margin:10px 0 0">URL ที่เรียก:
      <code><?= htmlspecialchars($base) ?>/assets/css/app.css</code>
      — <a href="<?= htmlspecialchars($base) ?>/assets/css/app.css" target="_blank">กดเปิดดูตรงๆ</a>
      (ถ้าขึ้น 404 แปลว่า path ผิด)</p>
    <script>
    // ถ้า app.css โหลดได้ ตัวแปร --brand จะมีค่า
    (function () {
      var v = getComputedStyle(document.documentElement).getPropertyValue('--brand').trim();
      var el = document.getElementById('cssTest');
      if (v) {
        el.style.background = '#e6f5ee'; el.style.color = '#0f7a4d';
        el.textContent = '✅ CSS โหลดสำเร็จ (สีแบรนด์ที่อ่านได้: ' + v + ')';
      }
    })();
    </script>
  </div>

  <div class="card">
    <table>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['ok'] ? '' : ($r['warn'] ? '' : 'bad') ?>">
          <td class="i"><?= $r['ok'] ? '✅' : ($r['warn'] ? '⚠️' : '❌') ?></td>
          <td>
            <div class="n"><?= htmlspecialchars($r['name']) ?></div>
            <?php if ($r['detail']): ?><div class="d"><?= htmlspecialchars($r['detail']) ?></div><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>

    <div class="hint">
      <b>ถ้า CSS ยังไม่ขึ้น</b> — เปิด <code>config/config.php</code> แล้วตั้งค่าเองที่บรรทัด
      <code>define('BASE_URL_MANUAL', null);</code><br>
      • เว็บอยู่ที่ <code>example.com</code> → ใส่ <code>''</code><br>
      • เว็บอยู่ที่ <code>example.com/carrent</code> → ใส่ <code>'/carrent'</code>
    </div>
  </div>

  <div class="card" style="border-color:#f3c9c5;background:#fffafa">
    <b style="color:#b3261e">⚠️ ลบไฟล์ check.php ทิ้งเมื่อติดตั้งเสร็จ</b>
    <p class="d" style="margin:6px 0 0">ไฟล์นี้แสดงข้อมูลเซิร์ฟเวอร์ ไม่ควรเปิดทิ้งไว้บนเว็บจริง</p>
  </div>

  <p style="text-align:center"><a href="<?= htmlspecialchars($base) ?>/index.php">→ ไปหน้าเว็บลูกค้า</a> ·
     <a href="<?= htmlspecialchars($base) ?>/admin/login.php">เข้าหลังบ้าน</a></p>
</div>
</body>
</html>
<?php
}
