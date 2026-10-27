<?php
/** แจ้งเตือน LINE: $errors $v $tokenHint $counts $recent $hasCurl */
$on = static fn(string $k): bool => (string) ($v[$k] ?? '') === '1';
$stateLabel = ['queued' => ['รอส่ง', 'badge-info'], 'sending' => ['กำลังส่ง', 'badge-info'], 'sent' => ['ส่งแล้ว', 'badge-ok'], 'failed' => ['ไม่สำเร็จ', 'badge-danger']];
?>
<?php if ($errors): ?>
  <div class="alert alert-danger"><div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div></div>
<?php endif; ?>
<?php if (!$hasCurl): ?>
  <div class="alert alert-danger">เซิร์ฟเวอร์นี้ไม่มี PHP cURL จึงส่ง LINE ไม่ได้ — ให้โฮสต์เปิด extension curl</div>
<?php endif; ?>

<div class="grid items-start gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
  <form method="post" action="<?= e(url('/admin/line')) ?>" class="panel">
    <?= csrf_field() ?>
    <h3>ตั้งค่าแจ้งเตือนร้านทาง LINE</h3>
    <label class="checkline"><input type="checkbox" name="notify_line_enabled" value="1"<?= checked($on('notify_line_enabled')) ?>><span>เปิดการแจ้งเตือน</span></label>

    <div class="field mt-3">
      <label for="line_channel_token">Channel access token (long-lived)</label>
      <input id="line_channel_token" name="line_channel_token" type="password" autocomplete="off" spellcheck="false"
             placeholder="<?= $tokenHint ? 'ตั้งไว้แล้ว ' . e($tokenHint) . ' — เว้นว่างถ้าไม่เปลี่ยน' : 'วาง token จาก LINE Developers Console' ?>">
      <?php if ($tokenHint): ?>
        <label class="checkline"><input type="checkbox" name="clear_token" value="1"><span>ลบ token ที่บันทึกไว้</span></label>
      <?php endif; ?>
      <div class="hint">เก็บใน ฐานข้อมูลของร้าน ไม่แสดงซ้ำหลังบันทึก — ถ้า token หลุดให้กด Reissue ใน LINE Developers Console</div>
    </div>

    <div class="field">
      <label for="line_admin_user_id">รหัสผู้รับแจ้งเตือน (ใส่ได้ไม่เกิน <?= (int) \App\Services\NotificationService::RECIPIENT_LIMIT ?> รายการ — 1 บรรทัดต่อ 1 รายการ)</label>
      <textarea id="line_admin_user_id" name="line_admin_user_id" rows="3" spellcheck="false" placeholder="U0123456789abcdef0123456789abcdef"><?= e($v['line_admin_user_id'] ?? '') ?></textarea>
      <div class="hint">รหัส User ID ของเจ้าของ/พนักงานอยู่ที่ LINE Developers Console → Channel → Basic settings → "Your user ID" · ผู้รับต้องเพิ่มบัญชี LINE OA ของร้านเป็นเพื่อนก่อน</div>
    </div>

    <div class="mt-2">
      <div class="label">แจ้งเมื่อ</div>
      <label class="checkline"><input type="checkbox" name="notify_new_booking" value="1"<?= checked($on('notify_new_booking')) ?>><span>มีการจองใหม่จากหน้าเว็บ</span></label>
      <label class="checkline"><input type="checkbox" name="notify_new_slip" value="1"<?= checked($on('notify_new_slip')) ?>><span>ลูกค้าแจ้งโอน (สลิปใหม่รอตรวจ)</span></label>
      <label class="checkline"><input type="checkbox" name="notify_signed" value="1"<?= checked($on('notify_signed')) ?>><span>ลูกค้าเซ็นสัญญา/ใบรับคืนแล้ว</span></label>
    </div>
    <button class="btn mt-3">บันทึก</button>
    <p class="mb-0 mt-3 text-xs text-muted">ข้อความมีชื่อและเบอร์โทรลูกค้า — ใส่เฉพาะคนในร้านที่ควรเห็นข้อมูลนี้ (PDPA)</p>
  </form>

  <div class="space-y-4">
    <div class="panel">
      <h3>ทดสอบ / คิวส่ง</h3>
      <form method="post" action="<?= e(url('/admin/line/test')) ?>" class="mb-3">
        <?= csrf_field() ?>
        <button class="btn btn-ghost btn-block">ส่งข้อความทดสอบถึงผู้รับที่บันทึกไว้</button>
      </form>
      <dl class="dl">
        <dt>รอส่ง / กำลังส่ง</dt><dd><?= (int) $counts['queued'] + (int) $counts['sending'] ?></dd>
        <dt>ส่งแล้ว</dt><dd><?= (int) $counts['sent'] ?></dd>
        <dt>ไม่สำเร็จ</dt><dd><?= (int) $counts['failed'] ?></dd>
      </dl>
      <?php if ((int) $counts['queued'] > 0): ?>
        <form method="post" action="<?= e(url('/admin/line/flush')) ?>" class="mt-3">
          <?= csrf_field() ?>
          <button class="btn btn-ghost btn-block btn-sm">ส่งรายการที่ค้างตอนนี้</button>
        </form>
      <?php endif; ?>
      <p class="mb-0 mt-3 text-xs text-muted">ถ้าส่งไม่ผ่านระบบจะลองใหม่เอง (1 นาที → 5 นาที → 30 นาที → 2 ชม.) ต้องตั้ง cron ให้รัน <code>bin/cron.php</code> ทุกชั่วโมงเป็นอย่างน้อย</p>
    </div>
  </div>
</div>

<div class="panel mt-4">
  <h3>รายการล่าสุด</h3>
  <?php if (!$recent): ?>
    <p class="m-0 text-muted">ยังไม่มีการแจ้งเตือน</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>เวลา</th><th>ผู้รับ</th><th>ข้อความ</th><th>สถานะ</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $n): [$label, $cls] = $stateLabel[$n['status']] ?? [$n['status'], 'badge-info']; ?>
          <tr>
            <td class="whitespace-nowrap"><?= e(thai_date($n['created_at'], true)) ?></td>
            <td class="whitespace-nowrap"><code><?= e(substr((string) $n['recipient'], 0, 7)) ?>…</code></td>
            <td><?= e(strtok((string) $n['message'], "\n")) ?></td>
            <td>
              <span class="badge <?= e($cls) ?>"><?= e($label) ?></span>
              <?php if ((int) $n['attempts'] > 1 || $n['error']): ?>
                <div class="text-xs text-muted">ลอง <?= (int) $n['attempts'] ?> ครั้ง<?= $n['error'] ? ' · ' . e($n['error']) : '' ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($n['status'] === 'failed'): ?>
                <form method="post" action="<?= e(url('/admin/line/' . (int) $n['id'] . '/retry')) ?>">
                  <?= csrf_field() ?><button class="btn btn-ghost btn-sm">ส่งใหม่</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
