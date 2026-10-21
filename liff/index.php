<?php
/**
 * หน้า "การจองของฉัน" — เปิดในแอป LINE (LIFF)
 *
 * ที่อยู่นี้นำไปใส่เป็น Endpoint URL ของ LIFF app:
 *     https://โดเมน/โฟลเดอร์ระบบ/liff/
 *
 * ลูกค้าทำได้ 3 อย่างโดยไม่ต้องออกจาก LINE:
 *   1. ผูกบัญชี LINE กับการจอง (กรอกเลขที่จอง + เบอร์โทร ครั้งเดียว)
 *   2. ดูสถานะการจองและยอดเงินทั้งหมดของตัวเอง
 *   3. แจ้งโอนเงินพร้อมแนบสลิป
 *
 * หน้านี้มี CSS ในตัว ไม่ใช้ app.css — เพื่อให้หน้าตาเหมือนกันทุกเวอร์ชันของเว็บหลัก
 */
require_once __DIR__ . '/../includes/line.php';

$liffId  = trim((string) setting('line_liff_id'));
$site    = (string) setting('site_name', 'thonglorcarrent');
$brand   = (string) setting('brand_primary', '#1f2d3d');
$accent  = (string) setting('brand_accent', '#e09a3c');
$safeHex = static fn(string $c, string $d): string => preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? $c : $d;
$brand   = $safeHex($brand, '#1f2d3d');
$accent  = $safeHex($accent, '#e09a3c');

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>การจองของฉัน — <?= e($site) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<style>
/* ---------- ตัวแปรสี: เปลี่ยนตามแบรนด์ที่ตั้งในหลังบ้าน ---------- */
:root{
  --brand:<?= e($brand) ?>; --accent:<?= e($accent) ?>;
  --bg:#f3f5f8; --card:#fff; --ink:#18212e; --ink-2:#4a5568; --muted:#7a8597; --line:#e2e7ee;
  --ok:#15803d; --ok-bg:#e8f6ee; --warn:#9a5b00; --warn-bg:#fdf3dc;
  --danger:#b4232a; --danger-bg:#fdecec; --info:#1d4ed8; --info-bg:#e8effd;
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;font-family:'Sarabun',system-ui,-apple-system,sans-serif;font-size:16px;line-height:1.6;
  color:var(--ink);background:var(--bg);padding-bottom:calc(24px + env(safe-area-inset-bottom,0px))}
button,input{font:inherit;color:inherit}
[hidden]{display:none!important}

details.more summary{cursor:pointer;font-weight:600;color:var(--brand);min-height:44px;display:flex;align-items:center}
details.more[open] summary{margin-bottom:6px}
details.more h2{font-size:1rem}
/* ---------- หัวหน้า ---------- */
.top{background:var(--brand);color:#fff;padding:calc(14px + env(safe-area-inset-top,0px)) 16px 16px}
.top .shop{font-size:.8rem;opacity:.75;letter-spacing:.02em}
.top h1{margin:2px 0 0;font-size:1.25rem;font-weight:700;line-height:1.3}
.top .who{display:flex;align-items:center;gap:8px;margin-top:10px;font-size:.88rem;opacity:.92}
.top .who img{width:28px;height:28px;border-radius:50%;object-fit:cover;background:rgba(255,255,255,.2)}

.wrap{max-width:560px;margin:0 auto;padding:14px 14px 0}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:12px}
.card h2{margin:0 0 4px;font-size:1.05rem;font-weight:700}
.sub{color:var(--ink-2);font-size:.9rem;margin:0 0 12px}

/* ---------- ฟอร์ม ---------- */
label{display:block;font-size:.84rem;font-weight:600;color:var(--ink-2);margin:10px 0 5px}
input[type=text],input[type=tel],input[type=number]{width:100%;min-height:48px;padding:10px 12px;
  border:1.5px solid var(--line);border-radius:9px;background:#fff;font-size:1rem}
input:focus-visible,button:focus-visible,a:focus-visible{outline:3px solid color-mix(in srgb,var(--accent) 60%,transparent);outline-offset:2px}
.btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:50px;
  border:0;border-radius:10px;background:var(--brand);color:#fff;font-weight:700;font-size:1rem;
  cursor:pointer;text-decoration:none;margin-top:14px}
.btn.accent{background:var(--accent);color:#1b1200}
.btn.ghost{background:transparent;color:var(--brand);border:1.5px solid var(--line)}
.btn.sm{min-height:44px;font-size:.92rem;margin-top:8px}
.btn[disabled]{opacity:.55;cursor:default}
.hint{font-size:.8rem;color:var(--muted);margin-top:5px}

/* ---------- ป้ายแจ้ง ---------- */
.note{border-radius:10px;padding:11px 13px;font-size:.92rem;margin-bottom:12px}
.note.ok{background:var(--ok-bg);color:var(--ok)}
.note.err{background:var(--danger-bg);color:var(--danger)}

/* ---------- การ์ดการจอง ---------- */
.bk-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.bk-no{font-size:.8rem;color:var(--muted);font-variant-numeric:tabular-nums}
.bk-car{font-size:1.08rem;font-weight:700;line-height:1.35;margin-top:1px}
.chip{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.78rem;font-weight:700;white-space:nowrap}
.t-warn{background:var(--warn-bg);color:var(--warn)} .t-ok{background:var(--ok-bg);color:var(--ok)}
.t-danger{background:var(--danger-bg);color:var(--danger)} .t-info,.t-primary{background:var(--info-bg);color:var(--info)}
.t-muted{background:#eef1f5;color:var(--muted)}
.rows{margin-top:12px;border-top:1px solid var(--line)}
.row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid var(--line);font-size:.93rem}
.row span:first-child{color:var(--ink-2);flex:none}
.row span:last-child{text-align:right;min-width:0;overflow-wrap:anywhere}
.row.big span:last-child{font-weight:700;font-size:1.05rem}
.row .num{font-variant-numeric:tabular-nums}

.pay{margin-top:14px;background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:13px}
.pay h3{margin:0 0 8px;font-size:.98rem}
.bank{font-size:.93rem;line-height:1.7}
.bank b{font-variant-numeric:tabular-nums;letter-spacing:.02em}
.copy{display:inline-flex;align-items:center;min-height:32px;padding:2px 10px;margin-left:6px;border:1px solid var(--line);
  border-radius:7px;background:#fff;font-size:.8rem;font-weight:600;color:var(--brand);cursor:pointer}
.file{display:flex;align-items:center;gap:10px;min-height:52px;padding:10px 12px;border:1.5px dashed #c4ccd8;
  border-radius:9px;background:#fff;cursor:pointer;font-size:.93rem;color:var(--ink-2)}
.file.has{border-style:solid;border-color:var(--ok);color:var(--ok)}
.file input{position:absolute;width:1px;height:1px;opacity:0}
.hist{margin-top:12px}
.hist h3{margin:0 0 4px;font-size:.88rem;color:var(--ink-2)}
.hist .row{font-size:.87rem;padding:7px 0}
.reason{display:block;font-size:.8rem;color:var(--danger)}

.foot{display:flex;flex-direction:column;gap:0;margin-top:4px}
.linkbtn{background:none;border:0;padding:12px 4px;min-height:44px;color:var(--muted);font-size:.86rem;
  text-decoration:underline;cursor:pointer;width:100%;text-align:center}
.center{text-align:center}
.spin{width:30px;height:30px;border-radius:50%;border:3px solid var(--line);border-top-color:var(--brand);
  animation:sp .8s linear infinite;margin:34px auto 12px}
@keyframes sp{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.spin{animation-duration:2.4s}}
</style>
</head>
<body>

<header class="top">
  <div class="shop"><?= e($site) ?></div>
  <h1>การจองของฉัน</h1>
  <div class="who" id="who" hidden><img id="whoPic" alt=""><span id="whoName"></span></div>
</header>

<main class="wrap" id="app">
  <?php if ($liffId === ''): ?>
    <div class="card center">
      <h2>ยังไม่เปิดใช้งาน</h2>
      <p class="sub">ร้านยังไม่ได้ตั้งค่าการเชื่อมต่อ LINE<br>ตรวจสอบการจองบนเว็บได้ที่ปุ่มด้านล่าง</p>
      <a class="btn" href="<?= e(url('track.php')) ?>">ตรวจสอบการจอง</a>
    </div>
  <?php else: ?>
    <div id="loading" class="center"><div class="spin"></div><p class="sub">กำลังเชื่อมต่อกับ LINE…</p></div>
  <?php endif; ?>
</main>

<?php if ($liffId !== ''): ?>
<script charset="utf-8" src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
<script>
(function () {
  'use strict';
  var LIFF_ID = <?= json_encode($liffId) ?>;
  var API     = <?= json_encode(url('liff/api.php')) ?>;
  var TRACK   = <?= json_encode(url('track.php')) ?>;
  var app     = document.getElementById('app');
  var state   = null;      // ข้อมูลล่าสุดจากเซิร์ฟเวอร์
  var notice  = null;      // {type:'ok'|'err', text}
  var picked  = {};        // ไฟล์สลิปที่เลือกไว้ แยกตามการจอง

  /* ---------- ตัวช่วย ---------- */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function call(act, fd) {
    fd = fd || new FormData();
    if (state && state.csrf) fd.append('_csrf', state.csrf);
    return fetch(API + '?act=' + act, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'เซิร์ฟเวอร์ตอบกลับผิดรูปแบบ (' + r.status + ')' }; });
      });
  }
  function fail(msg) {
    app.innerHTML = '<div class="card center"><h2>เปิดหน้านี้ไม่สำเร็จ</h2><p class="sub">' + esc(msg) + '</p>'
      + '<button class="btn" id="retry" type="button">ลองใหม่</button>'
      + '<a class="btn ghost" href="' + esc(TRACK) + '">ตรวจสอบการจองบนเว็บแทน</a></div>';
    document.getElementById('retry').onclick = function () { location.reload(); };
  }
  /* ย่อรูปสลิปในเครื่องก่อนส่ง — โฮสต์รับไฟล์ได้จำกัด รูปจากกล้องมือถือใหญ่เกินเสมอ */
  function shrink(file) {
    if (!file || !/^image\//.test(file.type)) return Promise.resolve(file);
    return new Promise(function (resolve) {
      var img = new Image(), u = URL.createObjectURL(file);
      img.onload = function () {
        URL.revokeObjectURL(u);
        var s = Math.min(1, 1400 / Math.max(img.naturalWidth, img.naturalHeight));
        var cv = document.createElement('canvas');
        cv.width = Math.round(img.naturalWidth * s); cv.height = Math.round(img.naturalHeight * s);
        cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
        cv.toBlob(function (b) { resolve(b || file); }, 'image/jpeg', 0.82);
      };
      img.onerror = function () { URL.revokeObjectURL(u); resolve(file); };
      img.src = u;
    });
  }

  /* ---------- วาดหน้าจอ ---------- */
  function render() {
    var h = '';
    if (notice) h += '<div class="note ' + (notice.type === 'ok' ? 'ok' : 'err') + '" role="status">' + esc(notice.text) + '</div>';

    if (state.profile && state.profile.name) {
      document.getElementById('whoName').textContent = state.profile.name;
      var pic = document.getElementById('whoPic');
      if (state.profile.picture) { pic.src = state.profile.picture; pic.hidden = false; } else { pic.hidden = true; }
      document.getElementById('who').hidden = false;
    }

    /* ฟอร์มยืนยันการจอง — ใช้ทั้งครั้งแรก และตอนเพิ่มการจองรายการถัดไป (ยืนยันทีละการจอง) */
    var pre = (new URLSearchParams(location.search).get('no') || '').toUpperCase();
    var known = state.bookings.some(function (b) { return b.no === pre; });
    var linkForm = function (title, sub, no) {
      return '<h2>' + esc(title) + '</h2><p class="sub">' + esc(sub) + '</p>'
        + '<form id="linkForm" novalidate>'
        + '<label for="bno">เลขที่จอง</label>'
        + '<input type="text" id="bno" name="booking_no" autocapitalize="characters" autocomplete="off" placeholder="เช่น BK261002-001" value="' + esc(no) + '" required>'
        + '<label for="ph">เบอร์โทรที่ใช้จอง</label>'
        + '<input type="tel" id="ph" name="phone" inputmode="tel" autocomplete="tel" placeholder="08x-xxx-xxxx" required>'
        + '<button class="btn" type="submit">ยืนยัน</button></form>';
    };
    if (!state.linked) {
      h += '<div class="card">' + linkForm('ยืนยันการจองของคุณ', 'กรอกเลขที่จองกับเบอร์โทรที่ใช้จอง ระบบจะจำการจองนี้ไว้กับ LINE ของคุณ ครั้งต่อไปเปิดมาเห็นได้เลย', pre) + '</div>'
        + '<div class="card center"><p class="sub" style="margin:0">ยังไม่ได้จองรถ?</p>'
        + '<a class="btn accent" href="' + esc(state.shop.cars) + '" data-ext>ดูรถว่างและจองรถ</a></div>';
    } else {
      if (pre && !known) {
        h += '<div class="card">' + linkForm('เพิ่มการจอง ' + pre, 'กรอกเบอร์โทรที่ใช้จองรายการนี้ เพื่อยืนยันว่าเป็นการจองของคุณ', pre) + '</div>';
      }
      if (!state.bookings.length) {
        h += '<div class="card center"><h2>ยังไม่มีการจอง</h2><p class="sub">เมื่อจองรถแล้ว รายการจะแสดงที่นี่</p>'
          + '<a class="btn accent" href="' + esc(state.shop.cars) + '" data-ext>ดูรถว่างและจองรถ</a></div>';
      } else {
        state.bookings.forEach(function (b) { h += bookingCard(b); });
      }
      if (!(pre && !known)) {
        h += '<details class="card more"><summary>มีการจองอื่นที่ยังไม่แสดง?</summary>'
          + linkForm('เพิ่มการจอง', 'กรอกเลขที่จองกับเบอร์โทรที่ใช้จองรายการนั้น', '') + '</details>';
      }
    }

    h += '<div class="foot">';
    if (state.shop.phone) h += '<a class="btn ghost" href="tel:' + esc(state.shop.phone.replace(/[^0-9+]/g, '')) + '">โทรหาร้าน ' + esc(state.shop.phone) + '</a>';
    if (state.linked) {
      h += '<a class="btn ghost sm" href="' + esc(state.shop.cars) + '" data-ext>จองรถเพิ่ม</a>'
        + '<button class="linkbtn" id="unlinkAsk" type="button">ยกเลิกการผูกบัญชี LINE นี้</button>'
        + '<div class="card" id="unlinkBox" hidden><p class="sub">ยกเลิกแล้วจะไม่ได้รับแจ้งเตือนทาง LINE และต้องยืนยันตัวตนใหม่เมื่อกลับมา</p>'
        + '<button class="btn" id="unlinkYes" type="button" style="background:var(--danger)">ยืนยันยกเลิกการผูกบัญชี</button>'
        + '<button class="btn ghost sm" id="unlinkNo" type="button">ไม่ยกเลิก</button></div>';
    }
    h += '</div>';
    app.innerHTML = h;
    bind();
  }

  function bookingCard(b) {
    var h = '<article class="card" data-b="' + b.id + '">'
      + '<div class="bk-head"><div><div class="bk-no">' + esc(b.no) + '</div>'
      + '<div class="bk-car">' + esc(b.car) + '</div></div>'
      + '<span class="chip t-' + esc(b.status_tone) + '">' + esc(b.status_label) + '</span></div>'
      + '<div class="rows">'
      + '<div class="row"><span>ทะเบียน</span><span>' + esc(b.plate) + '</span></div>'
      + '<div class="row"><span>รับรถ</span><span>' + esc(b.pickup) + '<br>' + esc(b.pickup_place) + '</span></div>'
      + '<div class="row"><span>คืนรถ</span><span>' + esc(b.return) + '<br>' + esc(b.return_place) + '</span></div>'
      + (b.extras || []).map(function (x) { return '<div class="row"><span>' + esc(x.label) + '</span><span class="num">' + esc(x.amount) + ' บาท</span></div>'; }).join('')
      + '<div class="row"><span>' + ((b.extras || []).length ? 'ยอดรวมทั้งหมด' : 'ค่าเช่ารวม') + ' (' + b.days + ' วัน)</span><span class="num">' + esc(b.total) + ' บาท</span></div>'
      + '<div class="row"><span>ชำระแล้ว</span><span class="num">' + esc(b.paid) + ' บาท <span class="chip t-' + esc(b.pay_tone) + '">' + esc(b.pay_label) + '</span></span></div>'
      + '<div class="row big"><span>ยอดคงเหลือ</span><span class="num">' + esc(b.balance) + ' บาท</span></div>';
    if (b.open) h += '<div class="row"><span>เงินประกัน (วันรับรถ)</span><span class="num">' + esc(b.security) + ' บาท</span></div>';
    h += '</div>';

    if (b.can_pay) {
      var k = state.bank;
      h += '<div class="pay"><h3>แจ้งโอนเงิน</h3><div class="bank">';
      if (k.name) h += esc(k.name) + '<br>';
      if (k.no) h += 'เลขที่บัญชี <b>' + esc(k.no) + '</b><button type="button" class="copy" data-copy="' + esc(k.no) + '">คัดลอก</button><br>';
      if (k.holder) h += 'ชื่อบัญชี ' + esc(k.holder) + '<br>';
      if (k.promptpay) h += 'พร้อมเพย์ <b>' + esc(k.promptpay) + '</b><button type="button" class="copy" data-copy="' + esc(k.promptpay) + '">คัดลอก</button>';
      h += '</div><form class="slipForm" data-b="' + b.id + '" novalidate>'
        + '<label for="amt' + b.id + '">จำนวนเงินที่โอน (บาท)</label>'
        + '<input type="number" id="amt' + b.id + '" name="amount" inputmode="decimal" min="1" step="1" value="' + (b.suggest > 0 ? b.suggest : '') + '" required>'
        + '<label for="slip' + b.id + '">สลิปการโอน</label>'
        + '<label class="file" id="fl' + b.id + '"><input type="file" id="slip' + b.id + '" accept="image/*,application/pdf">'
        + '<span>แตะเพื่อเลือกภาพสลิป</span></label>'
        + '<div class="hint">ใช้ภาพหน้าจอจากแอปธนาคารได้เลย ระบบย่อรูปให้อัตโนมัติ</div>'
        + '<button class="btn accent" type="submit">ส่งแจ้งโอน</button></form></div>';
    }

    if (b.payments.length) {
      h += '<div class="hist"><h3>ประวัติการแจ้งโอน</h3>';
      b.payments.forEach(function (p) {
        var tone = p.status === 'approved' ? 'ok' : (p.status === 'rejected' ? 'danger' : 'warn');
        h += '<div class="row"><span>' + esc(p.date) + '</span><span class="num">' + esc(p.amount) + ' บาท '
          + '<span class="chip t-' + tone + '">' + esc(p.label) + '</span>'
          + (p.status === 'rejected' && p.reason ? '<span class="reason">' + esc(p.reason) + '</span>' : '')
          + '</span></div>';
      });
      h += '</div>';
    }
    return h + '</article>';
  }

  /* ---------- ผูกเหตุการณ์ ---------- */
  function busy(btn, on, text) {
    if (!btn) return;
    if (on) { btn.dataset.t = btn.textContent; btn.textContent = text; btn.disabled = true; }
    else { btn.textContent = btn.dataset.t || btn.textContent; btn.disabled = false; }
  }
  function apply(d, btn) {
    busy(btn, false);
    if (!d.ok) { notice = { type: 'err', text: d.error || 'ทำรายการไม่สำเร็จ' }; render(); window.scrollTo(0, 0); return false; }
    state = d; notice = d.notice ? { type: 'ok', text: d.notice } : null; render(); window.scrollTo(0, 0); return true;
  }

  function bind() {
    var lf = document.getElementById('linkForm');
    if (lf) lf.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = lf.querySelector('button');
      busy(btn, true, 'กำลังตรวจสอบ…');
      call('link', new FormData(lf)).then(function (d) { apply(d, btn); })
        .catch(function () { apply({ ok: false, error: 'เชื่อมต่อไม่ได้ กรุณาลองใหม่' }, btn); });
    });

    app.querySelectorAll('.slipForm').forEach(function (f) {
      var id = f.dataset.b, input = f.querySelector('input[type=file]'), lab = document.getElementById('fl' + id);
      if (picked[id]) { lab.classList.add('has'); lab.querySelector('span').textContent = 'เลือกแล้ว: ' + picked[id].name; }
      input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) return;
        picked[id] = file;
        lab.classList.add('has');
        lab.querySelector('span').textContent = 'เลือกแล้ว: ' + file.name;
      });
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = f.querySelector('button[type=submit]');
        var amount = parseFloat(f.amount.value);
        if (!(amount > 0)) { notice = { type: 'err', text: 'กรุณาระบุจำนวนเงินที่โอน' }; render(); window.scrollTo(0, 0); return; }
        if (!picked[id]) { notice = { type: 'err', text: 'กรุณาแนบภาพสลิป' }; render(); window.scrollTo(0, 0); return; }
        busy(btn, true, 'กำลังส่ง…');
        shrink(picked[id]).then(function (blob) {
          var fd = new FormData();
          fd.append('booking_id', id);
          fd.append('amount', amount);
          fd.append('slip', blob, /^image\//.test(picked[id].type) ? 'slip.jpg' : picked[id].name);
          return call('slip', fd);
        }).then(function (d) { if (apply(d, btn)) delete picked[id]; })
          .catch(function () { apply({ ok: false, error: 'ส่งไม่สำเร็จ สัญญาณอาจหลุด กรุณาลองใหม่' }, btn); });
      });
    });

    app.querySelectorAll('[data-copy]').forEach(function (b) {
      b.addEventListener('click', function () {
        var v = b.dataset.copy, done = function () { b.textContent = 'คัดลอกแล้ว'; setTimeout(function () { b.textContent = 'คัดลอก'; }, 1600); };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(v).then(done, function () {});
      });
    });

    /* ลิงก์ไปเว็บหลัก: เปิดในหน้าต่างของ LINE เอง ไม่เด้งออกเบราว์เซอร์นอก */
    app.querySelectorAll('a[data-ext]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        if (window.liff && liff.isInClient && liff.isInClient()) { e.preventDefault(); liff.openWindow({ url: a.href, external: false }); }
      });
    });

    var ask = document.getElementById('unlinkAsk');
    if (ask) {
      var box = document.getElementById('unlinkBox');
      ask.onclick = function () { box.hidden = false; ask.hidden = true; box.scrollIntoView({ block: 'center' }); };
      document.getElementById('unlinkNo').onclick = function () { box.hidden = true; ask.hidden = false; };
      document.getElementById('unlinkYes').onclick = function () {
        var btn = this; busy(btn, true, 'กำลังยกเลิก…');
        call('unlink').then(function (d) { apply(d, btn); });
      };
    }
  }

  /* ---------- เริ่มต้น: ยืนยันตัวตนกับ LINE ---------- */
  function start() {
    if (!window.liff) { fail('โหลดส่วนเชื่อมต่อ LINE ไม่สำเร็จ ตรวจสอบสัญญาณอินเทอร์เน็ตแล้วลองใหม่'); return; }
    liff.init({ liffId: LIFF_ID }).then(function () {
      if (!liff.isLoggedIn()) { liff.login({ redirectUri: location.href }); return; }
      var fd = new FormData();
      fd.append('id_token', liff.getIDToken() || '');
      return call('session', fd).then(function (d) {
        if (d.ok) { try { sessionStorage.removeItem('liff_relogin'); } catch (e) {} state = d; render(); return; }
        /* token หมดอายุ: ออกแล้วเข้าใหม่ 1 ครั้ง (กันวนซ้ำด้วย sessionStorage) */
        var tried = false;
        try { tried = sessionStorage.getItem('liff_relogin') === '1'; sessionStorage.setItem('liff_relogin', '1'); } catch (e) { tried = true; }
        if (!tried && !(liff.isInClient && liff.isInClient())) { liff.logout(); liff.login({ redirectUri: location.href }); return; }
        fail(d.error || 'ยืนยันตัวตนไม่สำเร็จ');
      });
    }).catch(function (err) {
      fail('เชื่อมต่อ LINE ไม่สำเร็จ' + (err && err.message ? ' (' + err.message + ')' : ''));
    });
  }
  start();
})();
</script>
<?php endif; ?>
</body>
</html>
