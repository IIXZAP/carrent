<?php
/**
 * เชื่อมต่อ LINE Official Account — ตั้งค่า ทดสอบ แก้ข้อความแจ้งเตือน ติดตั้งเมนู
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_admin();

// ที่อยู่เว็บเต็ม — งาน cron ไม่รู้ชื่อโดเมนเอง จึงจำไว้ตั้งแต่ครั้งแรกที่เจ้าของร้านเปิดหน้านี้
if (trim((string) setting('site_url')) === '' && !empty($_SERVER['HTTP_HOST'])) {
    set_setting('site_url', site_url());
}

$templates = line_templates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string) ($_POST['act'] ?? '');

    if ($act === 'save') {
        // ช่องรหัสลับ: เว้นว่าง = คงค่าเดิม (ไม่แสดงค่าเดิมกลับมาบนหน้าเว็บ)
        foreach (['line_channel_token', 'line_channel_secret'] as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($v !== '') {
                set_setting($k, $v);
            }
        }
        set_setting('line_liff_id', trim((string) ($_POST['line_liff_id'] ?? '')));
        set_setting('line_admin_user_id', implode(',', preg_split('/[\s,;]+/', (string) ($_POST['line_admin_user_id'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)));
        set_setting('notify_line_enabled', isset($_POST['notify_line_enabled']) ? '1' : '0');
        set_setting('line_notify_customer', isset($_POST['line_notify_customer']) ? '1' : '0');
        $su = rtrim(trim((string) ($_POST['site_url'] ?? '')), '/');
        if ($su === '' || preg_match('#^https?://[^\s/]+#i', $su)) {
            set_setting('site_url', $su);
        }
        log_activity('line_settings_updated');
        flash('ok', 'บันทึกการตั้งค่า LINE แล้ว');

    } elseif ($act === 'test') {
        [$ok, $info, $err] = line_api('GET', '/v2/bot/info');
        if ($ok) {
            $msg = 'เชื่อมต่อสำเร็จ — บัญชี "' . ($info['displayName'] ?? '-') . '" (' . ($info['basicId'] ?? '-') . ')';
            [$qOk, $q] = line_api('GET', '/v2/bot/message/quota');
            [$cOk, $c] = line_api('GET', '/v2/bot/message/quota/consumption');
            if ($qOk && $cOk) {
                $msg .= ' · เดือนนี้ส่งไปแล้ว ' . number_format((int) ($c['totalUsage'] ?? 0)) . ' ข้อความ'
                      . (($q['type'] ?? '') === 'limited' ? ' จากโควตา ' . number_format((int) ($q['value'] ?? 0)) : ' (ไม่จำกัดโควตา)');
            }
            flash('ok', $msg);
        } else {
            flash('danger', 'เชื่อมต่อไม่สำเร็จ — ' . $err);
        }

    } elseif ($act === 'test_push') {
        $to = (string) setting('line_admin_user_id');
        [$ok, $err] = line_push_to($to, '🔔 ทดสอบการแจ้งเตือนจาก ' . setting('site_name') . ' เวลา ' . date('H:i:s') . ' น.');
        flash($ok ? 'ok' : 'danger', $ok ? 'ส่งข้อความทดสอบถึงผู้รับแจ้งเตือนของร้านแล้ว' : 'ส่งไม่สำเร็จ — ' . $err);

    } elseif ($act === 'add_admin' || $act === 'remove_admin') {
        $uid = trim((string) ($_POST['uid'] ?? ''));
        $ids = preg_split('/[\s,;]+/', (string) setting('line_admin_user_id'), -1, PREG_SPLIT_NO_EMPTY);
        if (preg_match('/^[UCR][0-9a-f]{32}$/', $uid)) {
            $ids = $act === 'add_admin' ? array_unique(array_merge($ids, [$uid])) : array_diff($ids, [$uid]);
            set_setting('line_admin_user_id', implode(',', $ids));
            log_activity('line_admin_' . ($act === 'add_admin' ? 'added' : 'removed'), null, null, $uid);
            flash('ok', $act === 'add_admin' ? 'เพิ่มเป็นผู้รับแจ้งเตือนของร้านแล้ว' : 'เอาออกจากผู้รับแจ้งเตือนแล้ว');
        } else {
            flash('danger', 'LINE user id ไม่ถูกรูปแบบ');
        }

    } elseif ($act === 'tpl_save') {
        $off = [];
        foreach ($templates as $key => [, , $default]) {
            $text = str_replace("\r\n", "\n", trim((string) ($_POST['tpl'][$key] ?? '')));
            // ตรงกับข้อความตั้งต้น หรือเว้นว่าง → ไม่เก็บ เพื่อให้ได้ข้อความตั้งต้นรุ่นใหม่เมื่ออัปเดตระบบ
            set_setting('tpl_' . $key, ($text === '' || $text === $default) ? '' : $text);
            if (empty($_POST['on'][$key])) {
                $off[] = $key;
            }
        }
        set_setting('tpl_off', implode(',', $off));
        log_activity('line_templates_updated');
        flash('ok', 'บันทึกข้อความแจ้งเตือนแล้ว');

    } elseif ($act === 'tpl_reset') {
        $key = (string) ($_POST['key'] ?? '');
        if (isset($templates[$key])) {
            set_setting('tpl_' . $key, '');
            flash('ok', 'คืนข้อความ "' . $templates[$key][0] . '" เป็นค่าตั้งต้นแล้ว');
        }

    } elseif ($act === 'richmenu') {
        [$ok, $msg] = line_richmenu_install();
        log_activity('line_richmenu', null, null, $msg);
        flash($ok ? 'ok' : 'danger', $msg);
    }
    redirect('admin/line.php' . (in_array($act, ['tpl_save', 'tpl_reset'], true) ? '#templates' : ''));
}

// ---------- ข้อมูลแสดงผล ----------
$s          = settings();
$hasToken   = trim((string) ($s['line_channel_token'] ?? '')) !== '';
$hasSecret  = trim((string) ($s['line_channel_secret'] ?? '')) !== '';
$liffId     = trim((string) ($s['line_liff_id'] ?? ''));
$adminIds   = preg_split('/[\s,;]+/', (string) ($s['line_admin_user_id'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
$enabled    = ($s['notify_line_enabled'] ?? '0') === '1';
$custOn     = ($s['line_notify_customer'] ?? '1') === '1';
$webhookUrl = site_url('line/webhook.php');
$liffEndUrl = site_url('liff/');
$isHttps    = str_starts_with($webhookUrl, 'https://');

// นับจำนวนบัญชี LINE ของลูกค้าที่ผูกกับการจองอย่างน้อย 1 รายการ
$linked     = (int) db_val("SELECT COUNT(DISTINCT line_user_id) FROM bookings WHERE line_user_id IS NOT NULL AND line_user_id <> ''");
$sentMonth  = (int) db_val("SELECT COUNT(*) FROM notifications WHERE channel = 'line' AND status = 'sent' AND sent_at >= :d",
    [':d' => date('Y-m-01 00:00:00')]);
$failed7    = (int) db_val("SELECT COUNT(*) FROM notifications WHERE channel = 'line' AND status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");

// คนที่ทักเข้ามาล่าสุด (1 แถวต่อ 1 คน) — ใช้เลือกผู้รับแจ้งเตือนของร้าน
$people = db_all(
    "SELECT e.line_user_id, e.display_name, e.text, e.event_type, e.created_at
     FROM line_events e
     JOIN (SELECT line_user_id, MAX(id) AS mid FROM line_events GROUP BY line_user_id) t ON t.mid = e.id
     ORDER BY e.id DESC LIMIT 12"
);
$logs = db_all("SELECT * FROM notifications WHERE channel = 'line' ORDER BY id DESC LIMIT 12");

// ตัวอย่างข้อความ: ใช้การจองล่าสุดถ้ามี ไม่มีก็ใช้ข้อมูลสมมติ
$sample = db_one('SELECT * FROM bookings ORDER BY id DESC LIMIT 1');
$sampleMore = ['รายการ' => "ค่าใช้จ่ายเพิ่ม 800 บาท\n• คืนรถช้า 3 ชม. 200 บาท\n• น้ำมันขาด 2 ขีด 600 บาท",
               'คืนเงิน' => '2,200', 'หัก' => '800', 'ลิงก์เซ็น' => site_url() . '/sign.php?no=…', 'เอกสาร' => 'สัญญาเช่ารถ'];
$sampleVars = $sample
    ? line_booking_vars($sample, null, $sampleMore + ['จำนวน' => money0($sample['deposit_amount']), 'เหตุผล' => 'ยอดเงินในสลิปไม่ตรง'])
    : ['ชื่อ' => 'สมชาย ใจดี', 'เลขจอง' => 'BK' . date('ymd') . '-001', 'รถ' => 'Toyota Fortuner', 'ทะเบียน' => '1กข 1234',
       'วันรับ' => thai_date(date('Y-m-d 10:00', strtotime('+2 days')), true),
       'วันคืน' => thai_date(date('Y-m-d 10:00', strtotime('+5 days')), true),
       'จุดรับ' => 'ที่ร้าน', 'จุดคืน' => 'ที่ร้าน', 'ยอดรวม' => '6,600', 'มัดจำ' => '1,000', 'คงเหลือ' => '5,600',
       'เงินประกัน' => '10,000', 'จำนวน' => '1,000', 'เหตุผล' => 'ยอดเงินในสลิปไม่ตรง',
       'ร้าน' => (string) setting('site_name'), 'โทร' => (string) setting('phone'), 'ลิงก์' => liff_url()] + $sampleMore;

$steps = [
    ['ใส่ Channel Access Token', $hasToken],
    ['ใส่ Channel Secret', $hasSecret],
    ['ใส่ LIFF ID', $liffId !== ''],
    ['เลือกผู้รับแจ้งเตือนของร้าน', (bool) $adminIds],
    ['เปิดใช้งานการแจ้งเตือน', $enabled],
    ['ติดตั้งเมนูด้านล่าง', trim((string) ($s['line_richmenu_id'] ?? '')) !== ''],
];
$doneSteps = count(array_filter(array_column($steps, 1)));

admin_head('เชื่อมต่อ LINE', 'line.php');
?>
<div class="stats">
  <div class="stat <?= $doneSteps === count($steps) ? 'ok' : 'accent' ?>">
    <div class="lb">ความพร้อม</div>
    <div class="vl"><?= $doneSteps ?>/<?= count($steps) ?></div>
    <div class="sub"><?= $doneSteps === count($steps) ? 'ตั้งค่าครบแล้ว' : 'ดูขั้นที่เหลือด้านล่าง' ?></div>
  </div>
  <div class="stat">
    <div class="lb">ลูกค้าที่ผูกบัญชี LINE</div>
    <div class="vl"><?= number_format($linked) ?></div>
    <div class="sub">ได้รับแจ้งเตือนอัตโนมัติ</div>
  </div>
  <div class="stat">
    <div class="lb">ข้อความที่ส่งเดือนนี้</div>
    <div class="vl"><?= number_format($sentMonth) ?></div>
    <div class="sub">นับเฉพาะที่ระบบนี้ส่ง · ข้อความตอบกลับไม่นับโควตา</div>
  </div>
  <div class="stat <?= $failed7 > 0 ? 'danger' : '' ?>">
    <div class="lb">ส่งไม่สำเร็จ 7 วันล่าสุด</div>
    <div class="vl"><?= number_format($failed7) ?></div>
    <div class="sub"><?= $failed7 > 0 ? 'ดูสาเหตุในประวัติด้านล่าง' : 'ปกติ' ?></div>
  </div>
</div>

<?php if (!$isHttps): ?>
  <div class="alert alert-warn">LINE รับเฉพาะที่อยู่ที่ขึ้นต้นด้วย <b>https://</b> — เว็บนี้ต้องติดตั้ง SSL ก่อนจึงจะเชื่อมต่อได้</div>
<?php endif; ?>

<div class="g2">
  <div>
    <!-- ---------- ตั้งค่า ---------- -->
    <form method="post" class="panel">
      <?= csrf_field() ?><input type="hidden" name="act" value="save">
      <h3>ตั้งค่าการเชื่อมต่อ</h3>

      <ul class="line-steps">
        <?php foreach ($steps as [$label, $ok]): ?>
          <li class="<?= $ok ? 'done' : '' ?>"><?= $ok ? '✓' : '○' ?> <?= e($label) ?></li>
        <?php endforeach; ?>
      </ul>

      <div class="field">
        <label for="line_channel_token">Channel Access Token (long-lived)</label>
        <input type="password" id="line_channel_token" name="line_channel_token" autocomplete="new-password"
               placeholder="<?= $hasToken ? 'ตั้งค่าไว้แล้ว — เว้นว่างเพื่อคงเดิม' : 'วางจาก LINE Developers → Messaging API' ?>">
      </div>
      <div class="field">
        <label for="line_channel_secret">Channel Secret</label>
        <input type="password" id="line_channel_secret" name="line_channel_secret" autocomplete="new-password"
               placeholder="<?= $hasSecret ? 'ตั้งค่าไว้แล้ว — เว้นว่างเพื่อคงเดิม' : 'วางจาก LINE Developers → Basic settings' ?>">
        <div class="hint">ใช้ตรวจว่าข้อมูลที่ส่งเข้ามามาจาก LINE จริง</div>
      </div>
      <div class="field">
        <label for="line_liff_id">LIFF ID</label>
        <input type="text" id="line_liff_id" name="line_liff_id" value="<?= e($liffId) ?>" placeholder="1234567890-abcdefgh" autocomplete="off">
        <div class="hint">ใช้เปิดหน้า "การจองของฉัน" ในแอป LINE</div>
      </div>
      <div class="field">
        <label for="line_admin_user_id">ผู้รับแจ้งเตือนของร้าน (LINE user id)</label>
        <textarea id="line_admin_user_id" name="line_admin_user_id" rows="2" placeholder="Uxxxxxxxx… หลายคนคั่นด้วยจุลภาค"><?= e(implode(', ', $adminIds)) ?></textarea>
        <div class="hint">ไม่ต้องพิมพ์เอง — ให้คนนั้นเพิ่มเพื่อน LINE OA ของร้าน แล้วกดเลือกจากรายชื่อด้านขวา</div>
      </div>
      <div class="field">
        <label for="site_url">ที่อยู่เว็บเต็ม</label>
        <input type="text" id="site_url" name="site_url" value="<?= e((string) ($s['site_url'] ?? '')) ?>" placeholder="https://โดเมน/โฟลเดอร์">
        <div class="hint">ใช้สร้างลิงก์ในข้อความ ระบบเติมให้เองแล้ว แก้เมื่อย้ายโดเมนเท่านั้น</div>
      </div>
      <label class="checkline">
        <input type="checkbox" name="notify_line_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        <span>เปิดใช้งานการแจ้งเตือนทาง LINE</span>
      </label>
      <label class="checkline">
        <input type="checkbox" name="line_notify_customer" value="1" <?= $custOn ? 'checked' : '' ?>>
        <span>ส่งแจ้งเตือนถึงลูกค้าที่ผูกบัญชีไว้ <span class="tiny muted" style="display:block">ปิดข้อนี้ = แจ้งเฉพาะร้าน ประหยัดโควตาข้อความ</span></span>
      </label>
      <button class="btn btn-accent mt-2">บันทึกการตั้งค่า</button>
    </form>

    <!-- ---------- ค่าที่ต้องนำไปใส่ใน LINE ---------- -->
    <div class="panel mt-2">
      <h3>นำ 2 ค่านี้ไปใส่ใน LINE Developers</h3>
      <div class="field">
        <label>Webhook URL <span class="tiny muted">(Messaging API → Webhook settings แล้วเปิด Use webhook)</span></label>
        <div class="copyrow"><input type="text" readonly value="<?= e($webhookUrl) ?>" id="whUrl"><button type="button" class="btn btn-ghost btn-sm" data-copy="whUrl">คัดลอก</button></div>
      </div>
      <div class="field">
        <label>LIFF Endpoint URL <span class="tiny muted">(LINE Login channel → LIFF → Add)</span></label>
        <div class="copyrow"><input type="text" readonly value="<?= e($liffEndUrl) ?>" id="lfUrl"><button type="button" class="btn btn-ghost btn-sm" data-copy="lfUrl">คัดลอก</button></div>
        <div class="hint">Size: Full · Scopes: openid และ profile</div>
      </div>
      <form method="post" style="display:flex;gap:10px;flex-wrap:wrap">
        <?= csrf_field() ?>
        <button class="btn btn-ghost btn-sm" name="act" value="test">ทดสอบการเชื่อมต่อ</button>
        <button class="btn btn-ghost btn-sm" name="act" value="test_push" <?= $adminIds ? '' : 'disabled' ?>>ส่งข้อความทดสอบถึงร้าน</button>
      </form>
    </div>
  </div>

  <div>
    <!-- ---------- คนที่ทักเข้ามา ---------- -->
    <div class="panel">
      <h3>ผู้รับแจ้งเตือนของร้าน</h3>
      <p class="small muted">ให้เจ้าของร้านหรือพนักงานเพิ่มเพื่อน LINE OA ของร้าน ชื่อจะขึ้นที่นี่ แล้วกด "รับแจ้งเตือน"</p>
      <?php if (!$people): ?>
        <p class="small muted mb-0">ยังไม่มีใครทักเข้ามา — ตรวจว่าใส่ Webhook URL และเปิด Use webhook แล้ว</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>ชื่อใน LINE</th><th>ล่าสุด</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($people as $p):
                  $isAdmin = in_array($p['line_user_id'], $adminIds, true); ?>
                <tr>
                  <td><b><?= e($p['display_name'] ?: '(ไม่ทราบชื่อ)') ?></b>
                    <span class="tiny muted" style="display:block;word-break:break-all"><?= e($p['line_user_id']) ?></span></td>
                  <td class="small"><?= e(['follow' => 'เพิ่มเพื่อน', 'unfollow' => 'เลิกติดตาม', 'message' => 'ส่งข้อความ'][$p['event_type']] ?? $p['event_type']) ?>
                    <span class="tiny muted" style="display:block"><?= e(thai_date($p['created_at'], true)) ?></span></td>
                  <td>
                    <form method="post">
                      <?= csrf_field() ?><input type="hidden" name="uid" value="<?= e($p['line_user_id']) ?>">
                      <?php if ($isAdmin): ?>
                        <button class="btn btn-ghost btn-sm" name="act" value="remove_admin">เอาออก</button>
                      <?php else: ?>
                        <button class="btn btn-sm" name="act" value="add_admin">รับแจ้งเตือน</button>
                      <?php endif; ?>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- ---------- เมนูด้านล่าง ---------- -->
    <div class="panel mt-2">
      <h3>เมนูด้านล่างห้องแชต</h3>
      <img src="<?= e(url('assets/img/richmenu.png')) ?>?v=<?= APP_VERSION ?>" alt="ตัวอย่างเมนู 3 ปุ่ม: จองรถ การจองของฉัน โทรหาร้าน"
           style="width:100%;height:auto;border-radius:8px;border:1px solid var(--line)" width="2500" height="843" loading="lazy">
      <p class="small muted mt-1">3 ปุ่ม: จองรถ · การจองของฉัน · โทรหาร้าน — กดปุ่มด้านล่างครั้งเดียว ระบบสร้างและตั้งเป็นเมนูหลักให้</p>
      <form method="post">
        <?= csrf_field() ?>
        <button class="btn btn-sm" name="act" value="richmenu" <?= $hasToken && $liffId !== '' ? '' : 'disabled' ?>>
          <?= trim((string) ($s['line_richmenu_id'] ?? '')) !== '' ? 'ติดตั้งเมนูใหม่อีกครั้ง' : 'ติดตั้งเมนู' ?>
        </button>
        <?php if (!$hasToken || $liffId === ''): ?><span class="tiny muted">ต้องใส่ Token และ LIFF ID ก่อน</span><?php endif; ?>
      </form>
    </div>

    <!-- ---------- ประวัติการส่ง ---------- -->
    <div class="panel mt-2">
      <h3>ประวัติการส่งล่าสุด</h3>
      <?php if (!$logs): ?>
        <p class="small muted mb-0">ยังไม่มีการส่งข้อความ</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>เวลา</th><th>ข้อความ</th><th>ผล</th></tr></thead>
            <tbody>
              <?php foreach ($logs as $n): ?>
                <tr>
                  <td class="small" style="white-space:nowrap"><?= e(thai_date($n['created_at'], true)) ?></td>
                  <td class="small"><?= e(mb_strimwidth(strtok((string) $n['message'], "\n"), 0, 46, '…')) ?>
                    <span class="tiny muted" style="display:block"><?= str_starts_with((string) $n['tpl_key'], 'cust_') ? 'ถึงลูกค้า' : 'ถึงร้าน' ?></span>
                    <?php if ($n['status'] === 'failed' && $n['error']): ?><span class="tiny" style="display:block;color:var(--danger)"><?= e($n['error']) ?></span><?php endif; ?></td>
                  <td><span class="badge badge-<?= ['sent' => 'ok', 'failed' => 'danger', 'queued' => 'warn'][$n['status']] ?? 'muted' ?>">
                    <?= e(['sent' => 'ส่งแล้ว', 'failed' => 'ไม่สำเร็จ', 'queued' => 'รอส่ง'][$n['status']] ?? $n['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ---------- แบบข้อความ ---------- -->
<form method="post" class="panel mt-2" id="templates">
  <?= csrf_field() ?><input type="hidden" name="act" value="tpl_save">
  <h3>ข้อความแจ้งเตือนถึงลูกค้า</h3>
  <p class="small muted">แก้ถ้อยคำได้ตามต้องการ ส่วนที่อยู่ในวงเล็บปีกกาจะถูกแทนด้วยข้อมูลจริงของการจอง เอาติ๊กออก = ไม่ส่งข้อความแบบนั้น</p>
  <div class="tpl-vars">
    <?php foreach (line_tpl_vars() as $k => $desc): ?>
      <button type="button" class="tpl-var" data-var="{<?= e($k) ?>}" title="<?= e($desc) ?>">{<?= e($k) ?>}</button>
    <?php endforeach; ?>
  </div>
  <p class="tiny muted">แตะตัวแปรเพื่อแทรกลงในช่องข้อความที่กำลังพิมพ์อยู่</p>

  <div class="tpl-grid">
    <?php foreach ($templates as $key => [$label, $when, $default]):
        $custom  = trim((string) ($s['tpl_' . $key] ?? ''));
        $current = $custom !== '' ? $custom : $default;
        $on      = !line_tpl_off($key); ?>
      <div class="tpl-card">
        <label class="checkline" style="padding:0 0 6px">
          <input type="checkbox" name="on[<?= e($key) ?>]" value="1" <?= $on ? 'checked' : '' ?>>
          <span><b><?= e($label) ?></b><?= $custom !== '' ? ' <span class="badge badge-info">แก้ไขแล้ว</span>' : '' ?>
            <span class="tiny muted" style="display:block">ส่งเมื่อ: <?= e($when) ?></span></span>
        </label>
        <textarea name="tpl[<?= e($key) ?>]" rows="8" class="tpl-text" aria-label="ข้อความ <?= e($label) ?>"><?= e($current) ?></textarea>
        <details class="tpl-prev">
          <summary>ดูตัวอย่างที่ลูกค้าจะเห็น</summary>
          <div class="bubble"><?= nl2br(e(line_fill($current, $sampleVars))) ?></div>
        </details>
        <?php if ($custom !== ''): ?>
          <button class="btn btn-ghost btn-sm mt-1" name="act" value="tpl_reset" formnovalidate
                  onclick="this.form.querySelector('[name=key]').value='<?= e($key) ?>'">คืนค่าตั้งต้น</button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <input type="hidden" name="key" value="">
  <button class="btn btn-accent mt-2">บันทึกข้อความทั้งหมด</button>
</form>

<script>
(function () {
  /* คัดลอกที่อยู่ */
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.getElementById(b.dataset.copy);
      el.select();
      var done = function () { var t = b.textContent; b.textContent = 'คัดลอกแล้ว'; setTimeout(function () { b.textContent = t; }, 1500); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(el.value).then(done, function () {});
      else { try { document.execCommand('copy'); done(); } catch (e) {} }
    });
  });
  /* แทรกตัวแปรลงช่องข้อความที่โฟกัสล่าสุด */
  var last = null;
  document.querySelectorAll('.tpl-text').forEach(function (t) { t.addEventListener('focus', function () { last = t; }); });
  document.querySelectorAll('.tpl-var').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!last) { last = document.querySelector('.tpl-text'); }
      var s = last.selectionStart || 0, e = last.selectionEnd || 0, v = b.dataset.var;
      last.value = last.value.slice(0, s) + v + last.value.slice(e);
      last.focus(); last.selectionStart = last.selectionEnd = s + v.length;
    });
  });
})();
</script>
<?php admin_foot(); ?>
