<?php
/**
 * เชื่อมต่อ LINE Official Account
 * ตัวแปร: s, hasToken, liffId, adminIds, enabled, custOn, webhookUrl, liffEndUrl, isHttps, steps, doneSteps,
 *         linked, sentMonth, failed7, people, logs, tplView, tplVars
 */
$this->layout('layouts/admin', ['title' => $title, 'active' => 'line']);
$allDone = $doneSteps === count($steps);
?>
<div class="stat-grid-lg">
  <div class="stat <?= $allDone ? 'stat-ok' : 'stat-accent' ?>">
    <div class="lb">ความพร้อม</div>
    <div class="vl"><?= (int) $doneSteps ?>/<?= count($steps) ?></div>
    <div class="sub"><?= $allDone ? 'ตั้งค่าครบแล้ว' : 'ดูขั้นที่เหลือด้านล่าง' ?></div>
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
  <div class="stat <?= $failed7 > 0 ? 'stat-danger' : '' ?>">
    <div class="lb">ส่งไม่สำเร็จ 7 วันล่าสุด</div>
    <div class="vl"><?= number_format($failed7) ?></div>
    <div class="sub"><?= $failed7 > 0 ? 'ดูสาเหตุในประวัติด้านล่าง' : 'ปกติ' ?></div>
  </div>
</div>

<?php if (!$isHttps): ?>
  <div class="alert alert-warn">LINE รับเฉพาะที่อยู่ที่ขึ้นต้นด้วย <b>https://</b> — เว็บนี้ต้องติดตั้ง SSL ก่อนจึงจะเชื่อมต่อได้</div>
<?php endif; ?>

<div class="grid items-start gap-4 lg:grid-cols-2">
  <div class="min-w-0">
    <!-- ---------- ตั้งค่า ---------- -->
    <form method="post" action="<?= url('admin/line') ?>" class="panel">
      <?= csrf_field() ?><input type="hidden" name="act" value="save">
      <h3>ตั้งค่าการเชื่อมต่อ</h3>

      <ul class="mb-3.5 grid list-none gap-x-3.5 gap-y-1 rounded-lg bg-surface px-3 py-2.5 text-[0.86rem] text-ink-2 [grid-template-columns:repeat(auto-fit,minmax(190px,1fr))]">
        <?php foreach ($steps as [$label, $ok]): ?>
          <li class="<?= $ok ? 'font-semibold text-ok' : '' ?>"><?= $ok ? '✓' : '○' ?> <?= e($label) ?></li>
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
               placeholder="<?= trim((string) ($s['line_channel_secret'] ?? '')) !== '' ? 'ตั้งค่าไว้แล้ว — เว้นว่างเพื่อคงเดิม' : 'วางจาก LINE Developers → Basic settings' ?>">
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
        <input type="checkbox" name="notify_line_enabled" value="1" <?= checked($enabled) ?>>
        <span>เปิดใช้งานการแจ้งเตือนทาง LINE</span>
      </label>
      <label class="checkline">
        <input type="checkbox" name="line_notify_customer" value="1" <?= checked($custOn) ?>>
        <span>ส่งแจ้งเตือนถึงลูกค้าที่ผูกบัญชีไว้ <span class="block text-xs text-muted">ปิดข้อนี้ = แจ้งเฉพาะร้าน ประหยัดโควตาข้อความ</span></span>
      </label>
      <button class="btn btn-accent mt-3">บันทึกการตั้งค่า</button>
    </form>

    <!-- ---------- ค่าที่ต้องนำไปใส่ใน LINE ---------- -->
    <div class="panel mt-4">
      <h3>นำ 2 ค่านี้ไปใส่ใน LINE Developers</h3>
      <div class="field">
        <label>Webhook URL <span class="text-xs text-muted">(Messaging API → Webhook settings แล้วเปิด Use webhook)</span></label>
        <div class="flex items-stretch gap-2"><input type="text" readonly value="<?= e($webhookUrl) ?>" id="whUrl" class="min-w-0 flex-1 !text-[0.84rem]"><button type="button" class="btn btn-ghost btn-sm flex-none !m-0" data-copy="whUrl">คัดลอก</button></div>
      </div>
      <div class="field">
        <label>LIFF Endpoint URL <span class="text-xs text-muted">(LINE Login channel → LIFF → Add)</span></label>
        <div class="flex items-stretch gap-2"><input type="text" readonly value="<?= e($liffEndUrl) ?>" id="lfUrl" class="min-w-0 flex-1 !text-[0.84rem]"><button type="button" class="btn btn-ghost btn-sm flex-none !m-0" data-copy="lfUrl">คัดลอก</button></div>
        <div class="hint">Size: Full · Scopes: openid และ profile</div>
      </div>
      <form method="post" action="<?= url('admin/line') ?>" class="flex flex-wrap gap-2.5">
        <?= csrf_field() ?>
        <button class="btn btn-ghost btn-sm" name="act" value="test">ทดสอบการเชื่อมต่อ</button>
        <button class="btn btn-ghost btn-sm" name="act" value="test_push" <?= $adminIds ? '' : 'disabled' ?>>ส่งข้อความทดสอบถึงร้าน</button>
      </form>
    </div>
  </div>

  <div class="min-w-0">
    <!-- ---------- คนที่ทักเข้ามา ---------- -->
    <div class="panel">
      <h3>ผู้รับแจ้งเตือนของร้าน</h3>
      <p class="text-sm text-muted">ให้เจ้าของร้านหรือพนักงานเพิ่มเพื่อน LINE OA ของร้าน ชื่อจะขึ้นที่นี่ แล้วกด "รับแจ้งเตือน"</p>
      <?php if (!$people): ?>
        <p class="mb-0 text-sm text-muted">ยังไม่มีใครทักเข้ามา — ตรวจว่าใส่ Webhook URL และเปิด Use webhook แล้ว</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>ชื่อใน LINE</th><th>ล่าสุด</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($people as $p):
                  $isAdm = in_array($p['line_user_id'], $adminIds, true); ?>
                <tr>
                  <td><b><?= e($p['display_name'] ?: '(ไม่ทราบชื่อ)') ?></b>
                    <span class="block break-all text-xs text-muted"><?= e($p['line_user_id']) ?></span></td>
                  <td class="text-sm"><?= e(['follow' => 'เพิ่มเพื่อน', 'unfollow' => 'เลิกติดตาม', 'message' => 'ส่งข้อความ'][$p['event_type']] ?? $p['event_type']) ?>
                    <span class="block text-xs text-muted"><?= e(thai_date($p['created_at'], true)) ?></span></td>
                  <td>
                    <form method="post" action="<?= url('admin/line') ?>">
                      <?= csrf_field() ?><input type="hidden" name="uid" value="<?= e($p['line_user_id']) ?>">
                      <?php if ($isAdm): ?>
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
    <div class="panel mt-4">
      <h3>เมนูด้านล่างห้องแชต</h3>
      <img class="h-auto w-full rounded-lg border border-line" src="<?= e(url('assets/img/richmenu.png')) ?>?v=<?= e(APP_VERSION) ?>"
           alt="ตัวอย่างเมนู 3 ปุ่ม: จองรถ การจองของฉัน โทรหาร้าน" width="2500" height="843" loading="lazy">
      <p class="mt-2 text-sm text-muted">3 ปุ่ม: จองรถ · การจองของฉัน · โทรหาร้าน — กดปุ่มด้านล่างครั้งเดียว ระบบสร้างและตั้งเป็นเมนูหลักให้</p>
      <form method="post" action="<?= url('admin/line') ?>">
        <?= csrf_field() ?>
        <button class="btn btn-sm" name="act" value="richmenu" <?= $hasToken && $liffId !== '' ? '' : 'disabled' ?>>
          <?= trim((string) ($s['line_richmenu_id'] ?? '')) !== '' ? 'ติดตั้งเมนูใหม่อีกครั้ง' : 'ติดตั้งเมนู' ?>
        </button>
        <?php if (!$hasToken || $liffId === ''): ?><span class="text-xs text-muted">ต้องใส่ Token และ LIFF ID ก่อน</span><?php endif; ?>
      </form>
    </div>

    <!-- ---------- ประวัติการส่ง ---------- -->
    <div class="panel mt-4">
      <h3>ประวัติการส่งล่าสุด</h3>
      <?php if (!$logs): ?>
        <p class="mb-0 text-sm text-muted">ยังไม่มีการส่งข้อความ</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>เวลา</th><th>ข้อความ</th><th>ผล</th></tr></thead>
            <tbody>
              <?php foreach ($logs as $n): ?>
                <tr>
                  <td class="whitespace-nowrap text-sm"><?= e(thai_date($n['created_at'], true)) ?></td>
                  <td class="text-sm"><?= e(mb_strimwidth((string) strtok((string) $n['message'], "\n"), 0, 46, '…')) ?>
                    <span class="block text-xs text-muted"><?= str_starts_with((string) $n['tpl_key'], 'cust_') ? 'ถึงลูกค้า' : 'ถึงร้าน' ?></span>
                    <?php if ($n['status'] === 'failed' && $n['error']): ?><span class="block text-xs text-danger"><?= e($n['error']) ?></span><?php endif; ?></td>
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
<form method="post" action="<?= url('admin/line') ?>" class="panel mt-4" id="templates">
  <?= csrf_field() ?><input type="hidden" name="act" value="tpl_save">
  <h3>ข้อความแจ้งเตือนถึงลูกค้า</h3>
  <p class="text-sm text-muted">แก้ถ้อยคำได้ตามต้องการ ส่วนที่อยู่ในวงเล็บปีกกาจะถูกแทนด้วยข้อมูลจริงของการจอง เอาติ๊กออก = ไม่ส่งข้อความแบบนั้น</p>
  <div class="my-1.5 flex flex-wrap gap-1.5">
    <?php foreach ($tplVars as $k => $desc): ?>
      <button type="button" class="tpl-var min-h-[32px] cursor-pointer rounded-md border border-line bg-surface px-[9px] py-1 text-[0.8rem] text-brand hover:border-brand" data-var="{<?= e($k) ?>}" title="<?= e($desc) ?>">{<?= e($k) ?>}</button>
    <?php endforeach; ?>
  </div>
  <p class="text-xs text-muted">แตะตัวแปรเพื่อแทรกลงในช่องข้อความที่กำลังพิมพ์อยู่</p>

  <div class="mt-2.5 grid gap-3.5 [grid-template-columns:repeat(auto-fit,minmax(300px,1fr))]">
    <?php foreach ($tplView as $key => $t): ?>
      <div class="min-w-0 rounded-[10px] border border-line p-3">
        <label class="checkline !pb-1.5 !pt-0">
          <input type="checkbox" name="on[<?= e($key) ?>]" value="1" <?= checked($t['on']) ?>>
          <span><b><?= e($t['label']) ?></b><?= $t['custom'] ? ' <span class="badge badge-info">แก้ไขแล้ว</span>' : '' ?>
            <span class="block text-xs text-muted">ส่งเมื่อ: <?= e($t['when']) ?></span></span>
        </label>
        <textarea name="tpl[<?= e($key) ?>]" rows="8" class="tpl-text !min-h-[150px] !text-[0.88rem] !leading-[1.55]" aria-label="ข้อความ <?= e($t['label']) ?>"><?= e($t['current']) ?></textarea>
        <details class="mt-2 text-[0.84rem]">
          <summary class="flex min-h-[32px] cursor-pointer items-center text-brand">ดูตัวอย่างที่ลูกค้าจะเห็น</summary>
          <div class="mt-1.5 max-w-[320px] rounded-[4px_14px_14px_14px] bg-[#e9f7e1] px-3 py-2.5 text-[0.88rem] leading-[1.55] text-[#1c2a17] [overflow-wrap:anywhere]"><?= nl2br(e($t['preview'])) ?></div>
        </details>
        <?php if ($t['custom']): ?>
          <button class="btn btn-ghost btn-sm mt-2" name="act" value="tpl_reset" formnovalidate
                  onclick="this.form.querySelector('[name=key]').value='<?= e($key) ?>'">คืนค่าตั้งต้น</button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <input type="hidden" name="key" value="">
  <button class="btn btn-accent mt-3">บันทึกข้อความทั้งหมด</button>
</form>

<?php $this->start('after_main'); ?>
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
<?php $this->end(); ?>
