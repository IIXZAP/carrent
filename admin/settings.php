<?php
/**
 * ตั้งค่าระบบ — ข้อมูลร้าน สี บัญชีรับโอน เงื่อนไขเช่า แจ้งเตือน
 */
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/layout_admin.php';
require_admin();

$keys = [
    // ข้อมูลร้าน
    'site_name','site_tagline','company_name','company_tax_id','company_address',
    'phone','phone2','office_phone','email','line_id','facebook',
    // แบรนด์
    'brand_primary','brand_accent','brand_dark',
    // การเงิน
    'vat_enabled','vat_rate','wht_rate','deposit_percent','security_deposit_default',
    'bank_name','bank_account_no','bank_account_name','promptpay_id',
    // เงื่อนไขเช่า
    'min_rental_days','booking_lead_hours','late_fee_per_hour','free_km_per_day','over_km_fee','open_time','close_time',
    'late_grace_hours','late_full_day_hours','fuel_fee_per_level','pending_expire_hours',
    'booking_gap_hours','airport_open_time','airport_close_time',
    'booking_deposit','free_delivery_km','delivery_tiers',
    // แจ้งเตือน
    'notify_email_enabled','smtp_host','smtp_port','smtp_user','smtp_pass','smtp_secure',
    'smtp_from_name','notify_admin_email','reminder_days_before','cron_key',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['act'] ?? '') === 'test_notify') {
        $msg = '🔔 ทดสอบการแจ้งเตือนจาก ' . setting('site_name') . ' เวลา ' . date('H:i:s');
        notify_queue('line', setting('line_admin_user_id'), $msg, 'ทดสอบ');
        notify_queue('email', setting('notify_admin_email'), $msg, 'ทดสอบการแจ้งเตือน');
        $n = notify_flush(5);
        $fail = db_one("SELECT error FROM notifications WHERE status='failed' ORDER BY id DESC LIMIT 1");
        flash($n > 0 ? 'ok' : 'warn',
              $n > 0 ? "ส่งทดสอบสำเร็จ $n ข้อความ" : 'ส่งไม่สำเร็จ: ' . ($fail['error'] ?? 'ตรวจสอบการเปิดใช้งานช่องทาง'));
    } else {
        foreach ($keys as $k) {
            if (in_array($k, ['vat_enabled','notify_email_enabled'], true)) {
                set_setting($k, isset($_POST[$k]) ? '1' : '0');
            } elseif (array_key_exists($k, $_POST)) {
                // ไม่ล้างรหัสผ่าน SMTP ถ้าปล่อยว่าง
                if ($k === 'smtp_pass' && trim($_POST[$k]) === '') continue;
                set_setting($k, trim((string) $_POST[$k]));
            }
        }
        log_activity('settings_updated');
        flash('ok', 'บันทึกการตั้งค่าแล้ว');
    }
    redirect('admin/settings.php');
}

$s = settings();
$g = fn(string $k, string $d = '') => e($s[$k] ?? $d);

admin_head('ตั้งค่าระบบ', 'settings.php');
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="g2">
    <div>
      <div class="panel">
        <h3>ข้อมูลร้าน</h3>
        <div class="field"><label>ชื่อเว็บ/แบรนด์</label><input type="text" name="site_name" value="<?= $g('site_name') ?>"></div>
        <div class="field"><label>สโลแกน</label><input type="text" name="site_tagline" value="<?= $g('site_tagline') ?>"></div>
        <div class="field"><label>ชื่อนิติบุคคล (ใช้ในเอกสาร)</label><input type="text" name="company_name" value="<?= $g('company_name') ?>"></div>
        <div class="field"><label>เลขประจำตัวผู้เสียภาษี</label><input type="text" name="company_tax_id" value="<?= $g('company_tax_id') ?>"></div>
        <div class="field"><label>ที่อยู่</label><textarea name="company_address"><?= $g('company_address') ?></textarea></div>
        <div class="g2">
          <div class="field"><label>โทรศัพท์หลัก</label><input type="text" name="phone" value="<?= $g('phone') ?>"></div>
          <div class="field"><label>โทรศัพท์สำรอง</label><input type="text" name="phone2" value="<?= $g('phone2') ?>"></div>
          <div class="field"><label>เบอร์สำนักงาน</label><input type="text" name="office_phone" value="<?= $g('office_phone') ?>"></div>
          <div class="field"><label>อีเมล</label><input type="email" name="email" value="<?= $g('email') ?>"></div>
          <div class="field"><label>LINE ID</label><input type="text" name="line_id" value="<?= $g('line_id') ?>"></div>
          <div class="field"><label>Facebook</label><input type="text" name="facebook" value="<?= $g('facebook') ?>"></div>
        </div>
      </div>

      <div class="panel mt-2">
        <h3>สีแบรนด์</h3>
        <p class="small muted">เปลี่ยนที่นี่จุดเดียว มีผลทั้งหน้าเว็บลูกค้า หลังบ้าน และเอกสาร</p>
        <div class="g3">
          <div class="field"><label>สีหลัก</label><input type="color" name="brand_primary" value="<?= $g('brand_primary', '#0f3d68') ?>" style="height:42px;padding:4px"></div>
          <div class="field"><label>สีเน้น</label><input type="color" name="brand_accent" value="<?= $g('brand_accent', '#e8a33d') ?>" style="height:42px;padding:4px"></div>
          <div class="field"><label>สีเข้ม</label><input type="color" name="brand_dark" value="<?= $g('brand_dark', '#10202f') ?>" style="height:42px;padding:4px"></div>
        </div>
      </div>

      <div class="panel mt-2">
        <h3>บัญชีรับเงิน</h3>
        <div class="g2">
          <div class="field"><label>ธนาคาร</label><input type="text" name="bank_name" value="<?= $g('bank_name') ?>"></div>
          <div class="field"><label>เลขที่บัญชี</label><input type="text" name="bank_account_no" value="<?= $g('bank_account_no') ?>"></div>
        </div>
        <div class="field"><label>ชื่อบัญชี</label><input type="text" name="bank_account_name" value="<?= $g('bank_account_name') ?>"></div>
        <div class="field"><label>PromptPay (เบอร์/เลขประจำตัว)</label><input type="text" name="promptpay_id" value="<?= $g('promptpay_id') ?>"></div>
      </div>
    </div>

    <div>
      <div class="panel">
        <h3>ภาษีและมัดจำ</h3>
        <label class="checkline">
          <input type="checkbox" name="vat_enabled" value="1" <?= ($s['vat_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
          <span>คิด VAT อัตโนมัติในการจองหน้าเว็บ</span>
        </label>
        <div class="g2">
          <div class="field"><label>อัตรา VAT (%)</label><input type="number" name="vat_rate" step="0.01" value="<?= $g('vat_rate', '7') ?>"></div>
          <div class="field"><label>หัก ณ ที่จ่าย (%)</label><input type="number" name="wht_rate" step="0.01" value="<?= $g('wht_rate', '5') ?>"></div>
          <div class="field"><label>มัดจำ (% ของยอดรวม)</label><input type="number" name="deposit_percent" step="1" min="0" max="100" value="<?= $g('deposit_percent', '20') ?>">
            <div class="hint">ใช้เมื่อรถคันนั้นไม่ได้ตั้งมัดจำเฉพาะไว้</div></div>
          <div class="field"><label>เงินประกันเริ่มต้น (บาท)</label><input type="number" name="security_deposit_default" step="1" min="0" value="<?= $g('security_deposit_default', '3000') ?>"></div>
        </div>
      </div>

      <div class="panel mt-2">
        <h3>เงื่อนไขการเช่า</h3>
        <div class="g2">
          <div class="field"><label>เช่าขั้นต่ำ (วัน)</label><input type="number" name="min_rental_days" min="1" value="<?= $g('min_rental_days', '1') ?>"></div>
          <div class="field"><label>ต้องจองล่วงหน้า (ชั่วโมง)</label><input type="number" name="booking_lead_hours" min="0" value="<?= $g('booking_lead_hours', '3') ?>"></div>
          <div class="field"><label>ยกเลิกการจองที่ไม่จ่ายมัดจำ หลัง (ชั่วโมง)</label><input type="number" name="pending_expire_hours" min="0" max="720" value="<?= $g('pending_expire_hours', '0') ?>">
            <div class="hint">0 = ไม่ยกเลิกอัตโนมัติ · แนะนำ 24–48 · เฉพาะการจองผ่านเว็บที่ยังไม่มีเงินเข้าและไม่มีสลิปรอตรวจ</div></div>
          <div class="field"><label>ผ่อนผันคืนช้า (ชั่วโมง)</label><input type="number" name="late_grace_hours" min="0" max="12" value="<?= $g('late_grace_hours', '2') ?>">
            <div class="hint">คืนช้าไม่เกินนี้ไม่คิดเงิน</div></div>
          <div class="field"><label>ค่าปรับคืนช้า (บาท/ชม.)</label><input type="number" name="late_fee_per_hour" min="0" value="<?= $g('late_fee_per_hour', '200') ?>">
            <div class="hint">คิดเฉพาะชั่วโมงที่เกินเวลาผ่อนผัน</div></div>
          <div class="field"><label>คืนช้าเกินกี่ชั่วโมง คิดเต็มวัน</label><input type="number" name="late_full_day_hours" min="1" max="24" value="<?= $g('late_full_day_hours', '6') ?>">
            <div class="hint">คิดค่าเช่ารายวันของรถคันนั้นเพิ่ม 1 วัน</div></div>
          <div class="field"><label>ค่าน้ำมันที่ขาด (บาท/ขีด)</label><input type="number" name="fuel_fee_per_level" min="0" step="10" value="<?= $g('fuel_fee_per_level', '300') ?>">
            <div class="hint">1 ขีด = 1/8 ถัง · 0 = ไม่คิดอัตโนมัติ</div></div>
          <div class="field"><label>ระยะทางฟรี (กม./วัน)</label><input type="number" name="free_km_per_day" min="0" value="<?= $g('free_km_per_day', '0') ?>">
            <div class="hint">0 = ไม่จำกัดระยะทาง</div></div>
          <div class="field"><label>ค่าเกินระยะ (บาท/กม.)</label><input type="number" name="over_km_fee" step="0.5" min="0" value="<?= $g('over_km_fee', '4') ?>"></div>
          <div class="field"><label>เตือนงานประจำรถล่วงหน้า (วัน)</label><input type="number" name="reminder_days_before" min="1" value="<?= $g('reminder_days_before', '30') ?>"></div>
          <div class="field"><label>รับ–คืนรถได้ตั้งแต่</label><input type="time" name="open_time" value="<?= $g('open_time', '08:00') ?>"></div>
          <div class="field"><label>รับ–คืนรถได้ถึง</label><input type="time" name="close_time" value="<?= $g('close_time', '20:00') ?>"></div>
          <div class="field"><label>สนามบิน — ตั้งแต่</label><input type="time" name="airport_open_time" value="<?= $g('airport_open_time', '05:00') ?>">
            <div class="hint">สนามบินเปิดกว้างกว่าจุดอื่น รองรับเที่ยวบินเช้ามืด–ดึก</div></div>
          <div class="field"><label>สนามบิน — ถึง</label><input type="text" name="airport_close_time" value="<?= $g('airport_close_time', '24:00') ?>" placeholder="24:00">
            <div class="hint">ใส่ 24:00 ได้ หมายถึงถึงเที่ยงคืน</div></div>
          <div class="field"><label>เว้นระยะระหว่างการจอง (ชั่วโมง)</label><input type="number" name="booking_gap_hours" min="0" max="48" value="<?= $g('booking_gap_hours', '4') ?>">
            <div class="hint">เวลาล้างรถ เติมน้ำมัน ตรวจสภาพ · 0 = ให้จองติดกันได้</div></div>
          <div class="field"><label>มัดจำการจอง (บาท)</label><input type="number" name="booking_deposit" min="0" step="100" value="<?= $g('booking_deposit', '1000') ?>">
            <div class="hint">หักจากค่าเช่า ไม่ใช่เงินเพิ่ม · ใช้เมื่อรถคันนั้นไม่ได้ตั้งค่าเฉพาะ</div></div>
        </div>
      </div>

      <div class="panel mt-2">
        <h3>ค่ารับ–ส่งรถถึงที่</h3>
        <div class="g2">
          <div class="field"><label>ระยะที่ส่งฟรี (กม.)</label><input type="number" name="free_delivery_km" min="0" value="<?= $g('free_delivery_km', '30') ?>">
            <div class="hint">นับจากกรุงเทพ</div></div>
          <div class="field f-full"><label>ตารางค่าเดินทาง</label>
            <input type="text" name="delivery_tiers" value="<?= $g('delivery_tiers', '30:0,50:500,80:1000,120:1500,200:2500') ?>">
            <div class="hint">รูปแบบ <code>ถึงกม.:ราคา</code> คั่นด้วยจุลภาค · ระยะที่เกินช่วงสุดท้ายจะให้ลูกค้าติดต่อเจ้าหน้าที่</div></div>
        </div>
        <p class="tiny muted mb-0">ตอนนี้คิดเป็น: <?= e(delivery_tiers_text()) ?></p>
      </div>

      <div class="panel mt-2">
        <h3>แจ้งเตือน LINE</h3>
        <p class="small muted">การตั้งค่า LINE ทั้งหมด ข้อความแจ้งเตือนถึงลูกค้า และเมนูด้านล่างห้องแชต ย้ายไปรวมไว้ที่หน้าเดียวแล้ว</p>
        <a class="btn btn-ghost btn-sm" href="<?= url('admin/line.php') ?>">ไปหน้า เชื่อมต่อ LINE</a>
      </div>

      <div class="panel mt-2">
        <h3>แจ้งเตือนอีเมล</h3>
        <label class="checkline">
          <input type="checkbox" name="notify_email_enabled" value="1" <?= ($s['notify_email_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
          <span>เปิดใช้งานส่งอีเมล</span>
        </label>
        <div class="field"><label>อีเมลผู้รับแจ้งเตือน (แอดมิน)</label>
          <input type="email" name="notify_admin_email" value="<?= $g('notify_admin_email') ?>"></div>
        <p class="small muted">เว้น SMTP Host ว่างไว้ = ใช้ mail() ของ cPanel (ง่ายสุด แต่บางทีเข้า Junk)</p>
        <div class="g2">
          <div class="field"><label>SMTP Host</label><input type="text" name="smtp_host" value="<?= $g('smtp_host') ?>" placeholder="mail.yourdomain.com"></div>
          <div class="field"><label>Port</label><input type="number" name="smtp_port" value="<?= $g('smtp_port', '587') ?>"></div>
          <div class="field"><label>Username</label><input type="text" name="smtp_user" value="<?= $g('smtp_user') ?>" autocomplete="off"></div>
          <div class="field"><label>Password</label><input type="password" name="smtp_pass" value="" placeholder="<?= !empty($s['smtp_pass']) ? '(ตั้งค่าไว้แล้ว — เว้นว่างเพื่อคงเดิม)' : '' ?>" autocomplete="new-password"></div>
          <div class="field"><label>ความปลอดภัย</label>
            <select name="smtp_secure">
              <option value="tls" <?= ($s['smtp_secure'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS (587)</option>
              <option value="ssl" <?= ($s['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
              <option value="none" <?= ($s['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>ไม่เข้ารหัส</option>
            </select></div>
          <div class="field"><label>ชื่อผู้ส่ง</label><input type="text" name="smtp_from_name" value="<?= $g('smtp_from_name', 'thonglorcarrent') ?>"></div>
        </div>
      </div>

      <div class="panel mt-2">
        <h3>Cron (งานอัตโนมัติ)</h3>
        <div class="field"><label>Cron Key</label><input type="text" name="cron_key" value="<?= $g('cron_key') ?>">
          <div class="hint">ใช้ป้องกันไม่ให้คนนอกเรียก cron ได้</div></div>
        <p class="small muted">ตั้งใน cPanel → Cron Jobs → Once Per Day (เช่น 08:00) แล้ววางคำสั่งนี้:</p>
        <code style="display:block;font-size:.78rem;word-break:break-all;background:var(--bg);padding:9px 11px;border-radius:7px">/usr/local/bin/php <?= e(APP_ROOT) ?>/cron/daily.php "<?= $g('cron_key') ?>"</code>
        <p class="tiny muted mt-1">งานนี้ทำ: เตือนลูกค้าทาง LINE ก่อนรับรถ–คืนรถ · สำรองฐานข้อมูล · เตือนงานประจำรถ · เตือนรถเลยกำหนดคืน</p>
        <details class="small muted">
          <summary style="cursor:pointer">ถ้าโฮสต์ไม่ให้รันคำสั่ง php โดยตรง ใช้แบบเรียกผ่านเว็บแทน</summary>
          <code style="display:block;font-size:.78rem;word-break:break-all;background:var(--bg);padding:9px 11px;border-radius:7px;margin-top:6px">wget -q -O /dev/null "<?= e(site_url('cron/daily.php')) ?>?key=<?= e(urlencode((string) ($s['cron_key'] ?? ''))) ?>"</code>
          <span class="tiny" style="display:block;margin-top:4px">ต้องเปลี่ยน Cron Key จากค่าตั้งต้นก่อน แบบนี้จึงจะทำงาน</span>
        </details>
        <p class="tiny muted mb-0 mt-1">ตรวจว่างานทำงานจริงได้ที่ <a href="<?= url('admin/health.php') ?>">สุขภาพระบบ</a></p>
      </div>
    </div>
  </div>

  <div class="panel mt-2" style="position:sticky;bottom:0;z-index:20">
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-accent">บันทึกการตั้งค่าทั้งหมด</button>
      <button class="btn btn-ghost" name="act" value="test_notify" formnovalidate>ส่งทดสอบแจ้งเตือน</button>
    </div>
  </div>
</form>
<?php admin_foot(); ?>
