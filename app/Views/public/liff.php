<?php
/**
 * หน้า "การจองของฉัน" — เปิดในแอป LINE (LIFF)
 *
 * ที่อยู่นี้นำไปใส่เป็น Endpoint URL ของ LIFF app:  https://โดเมน/โฟลเดอร์ระบบ/liff
 *
 * ลูกค้าทำได้ 3 อย่างโดยไม่ต้องออกจาก LINE:
 *   1. ผูกบัญชี LINE กับการจอง (กรอกเลขที่จอง + เบอร์โทร ครั้งเดียว)
 *   2. ดูสถานะการจองและยอดเงินทั้งหมดของตัวเอง
 *   3. แจ้งโอนเงินพร้อมแนบสลิป
 *
 * ตัวแปร: liffId
 * หน้าจอวาดด้วย JavaScript จากข้อมูล JSON ของ /liff/api — คลาส Tailwind ในสตริงด้านล่างถูกสแกนตอน build เช่นเดียวกับ HTML ปกติ
 */
$this->layout('layouts/focus', [
    'title'   => $title,
    'heading' => $heading,
    'who'     => true,
    'narrow'  => $narrow,
]);
?>
<?php if ($liffId === ''): ?>
  <div class="panel text-center">
    <h2>ยังไม่เปิดใช้งาน</h2>
    <p class="text-sm text-muted">ร้านยังไม่ได้ตั้งค่าการเชื่อมต่อ LINE<br>ตรวจสอบการจองบนเว็บได้ที่ปุ่มด้านล่าง</p>
    <a class="btn btn-block" href="<?= e(url('track')) ?>">ตรวจสอบการจอง</a>
  </div>
<?php else: ?>
  <div id="loading" class="text-center">
    <div class="mx-auto mb-3 mt-9 h-[30px] w-[30px] animate-spin rounded-full border-[3px] border-line border-t-brand motion-reduce:[animation-duration:2.4s]"></div>
    <p class="text-sm text-muted">กำลังเชื่อมต่อกับ LINE…</p>
  </div>
<?php endif; ?>

<?php if ($liffId !== ''): ?>
<?php $this->start('after_main'); ?>
<script charset="utf-8" src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
<script>
(function () {
  'use strict';
  var LIFF_ID = <?= json_encode($liffId) ?>;
  var API     = <?= json_encode(url('liff/api')) ?>;
  var TRACK   = <?= json_encode(url('track')) ?>;
  var app     = document.getElementById('app');
  var state   = null;      // ข้อมูลล่าสุดจากเซิร์ฟเวอร์
  var notice  = null;      // {type:'ok'|'err', text}
  var picked  = {};        // ไฟล์สลิปที่เลือกไว้ แยกตามการจอง

  /* คลาสที่ใช้ซ้ำในสตริง HTML */
  var C = {
    card:  'mb-3 rounded-xl border border-line bg-white p-4',
    h2:    'm-0 mb-1 text-[1.05rem] font-bold',
    sub:   'm-0 mb-3 text-[0.9rem] text-slate-600',
    label: 'mb-1 mt-2.5 block text-[0.84rem] font-semibold text-slate-600',
    input: 'block min-h-[48px] w-full rounded-[9px] border-[1.5px] border-line bg-white px-3 py-2.5 text-base',
    btn:   'btn btn-block mt-3.5 min-h-[50px]',
    ghost: 'btn btn-ghost btn-block mt-2 min-h-[44px]',
    row:   'flex justify-between gap-3 border-b border-line py-[9px] text-[0.93rem]',
    hint:  'mt-1.5 text-[0.8rem] text-muted'
  };

  /* ---------- ตัวช่วย ---------- */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  /* ชื่อโทนจากเซิร์ฟเวอร์ → คลาสป้าย (ต้องเขียนเต็มเพื่อให้ Tailwind สแกนเจอ) */
  var BADGE = {
    ok: 'badge badge-ok', warn: 'badge badge-warn', danger: 'badge badge-danger',
    info: 'badge badge-info', primary: 'badge badge-primary', muted: 'badge badge-muted'
  };
  function badge(tone, text) { return '<span class="' + (BADGE[tone] || BADGE.muted) + ' whitespace-nowrap">' + esc(text) + '</span>'; }

  function call(act, fd) {
    fd = fd || new FormData();
    if (state && state.csrf) fd.append('_csrf', state.csrf);
    return fetch(API + '?act=' + act, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'เซิร์ฟเวอร์ตอบกลับผิดรูปแบบ (' + r.status + ')' }; });
      });
  }
  function fail(msg) {
    app.innerHTML = '<div class="' + C.card + ' text-center"><h2 class="' + C.h2 + '">เปิดหน้านี้ไม่สำเร็จ</h2><p class="' + C.sub + '">' + esc(msg) + '</p>'
      + '<button class="' + C.btn + '" id="retry" type="button">ลองใหม่</button>'
      + '<a class="' + C.ghost + '" href="' + esc(TRACK) + '">ตรวจสอบการจองบนเว็บแทน</a></div>';
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
  function linkForm(title, sub, no) {
    return '<h2 class="' + C.h2 + '">' + esc(title) + '</h2><p class="' + C.sub + '">' + esc(sub) + '</p>'
      + '<form id="linkForm" novalidate>'
      + '<label class="' + C.label + '" for="bno">เลขที่จอง</label>'
      + '<input class="' + C.input + '" type="text" id="bno" name="booking_no" autocapitalize="characters" autocomplete="off" placeholder="เช่น BK261002-001" value="' + esc(no) + '" required>'
      + '<label class="' + C.label + '" for="ph">เบอร์โทรที่ใช้จอง</label>'
      + '<input class="' + C.input + '" type="tel" id="ph" name="phone" inputmode="tel" autocomplete="tel" placeholder="08x-xxx-xxxx" required>'
      + '<button class="' + C.btn + '" type="submit">ยืนยัน</button></form>';
  }

  function render() {
    var h = '';
    if (notice) {
      h += '<div class="alert ' + (notice.type === 'ok' ? 'alert-ok' : 'alert-danger') + '" role="status">' + esc(notice.text) + '</div>';
    }

    if (state.profile && state.profile.name) {
      document.getElementById('whoName').textContent = state.profile.name;
      var pic = document.getElementById('whoPic');
      if (state.profile.picture) { pic.src = state.profile.picture; pic.hidden = false; } else { pic.hidden = true; }
      document.getElementById('who').hidden = false;
    }

    /* ฟอร์มยืนยันการจอง — ใช้ทั้งครั้งแรก และตอนเพิ่มการจองรายการถัดไป (ยืนยันทีละการจอง) */
    var pre = (new URLSearchParams(location.search).get('no') || '').toUpperCase();
    var known = state.bookings.some(function (b) { return b.no === pre; });
    if (!state.linked) {
      h += '<div class="' + C.card + '">' + linkForm('ยืนยันการจองของคุณ', 'กรอกเลขที่จองกับเบอร์โทรที่ใช้จอง ระบบจะจำการจองนี้ไว้กับ LINE ของคุณ ครั้งต่อไปเปิดมาเห็นได้เลย', pre) + '</div>'
        + '<div class="' + C.card + ' text-center"><p class="m-0 text-[0.9rem] text-slate-600">ยังไม่ได้จองรถ?</p>'
        + '<a class="btn btn-accent btn-block mt-3 min-h-[50px]" href="' + esc(state.shop.cars) + '" data-ext>ดูรถว่างและจองรถ</a></div>';
    } else {
      if (pre && !known) {
        h += '<div class="' + C.card + '">' + linkForm('เพิ่มการจอง ' + pre, 'กรอกเบอร์โทรที่ใช้จองรายการนี้ เพื่อยืนยันว่าเป็นการจองของคุณ', pre) + '</div>';
      }
      if (!state.bookings.length) {
        h += '<div class="' + C.card + ' text-center"><h2 class="' + C.h2 + '">ยังไม่มีการจอง</h2><p class="' + C.sub + '">เมื่อจองรถแล้ว รายการจะแสดงที่นี่</p>'
          + '<a class="btn btn-accent btn-block min-h-[50px]" href="' + esc(state.shop.cars) + '" data-ext>ดูรถว่างและจองรถ</a></div>';
      } else {
        state.bookings.forEach(function (b) { h += bookingCard(b); });
      }
      if (!(pre && !known)) {
        h += '<details class="' + C.card + '"><summary class="flex min-h-[44px] cursor-pointer items-center font-semibold text-brand">มีการจองอื่นที่ยังไม่แสดง?</summary>'
          + linkForm('เพิ่มการจอง', 'กรอกเลขที่จองกับเบอร์โทรที่ใช้จองรายการนั้น', '') + '</details>';
      }
    }

    h += '<div class="mt-1 flex flex-col">';
    if (state.shop.phone) {
      h += '<a class="' + C.ghost + '" href="tel:' + esc(state.shop.phone.replace(/[^0-9+]/g, '')) + '">โทรหาร้าน ' + esc(state.shop.phone) + '</a>';
    }
    if (state.linked) {
      h += '<a class="' + C.ghost + '" href="' + esc(state.shop.cars) + '" data-ext>จองรถเพิ่ม</a>'
        + '<button class="min-h-[44px] w-full cursor-pointer border-0 bg-transparent px-1 py-3 text-center text-[0.86rem] text-muted underline" id="unlinkAsk" type="button">ยกเลิกการผูกบัญชี LINE นี้</button>'
        + '<div class="' + C.card + '" id="unlinkBox" hidden><p class="' + C.sub + '">ยกเลิกแล้วจะไม่ได้รับแจ้งเตือนทาง LINE และต้องยืนยันตัวตนใหม่เมื่อกลับมา</p>'
        + '<button class="btn btn-danger btn-block min-h-[50px]" id="unlinkYes" type="button">ยืนยันยกเลิกการผูกบัญชี</button>'
        + '<button class="' + C.ghost + '" id="unlinkNo" type="button">ไม่ยกเลิก</button></div>';
    }
    h += '</div>';
    app.innerHTML = h;
    bind();
  }

  function bookingCard(b) {
    var h = '<article class="' + C.card + '" data-b="' + b.id + '">'
      + '<div class="flex items-start justify-between gap-2.5"><div>'
      + '<div class="text-[0.8rem] tabular-nums text-muted">' + esc(b.no) + '</div>'
      + '<div class="mt-px text-[1.08rem] font-bold leading-snug">' + esc(b.car) + '</div></div>'
      + badge(b.status_tone, b.status_label) + '</div>'
      + '<div class="mt-3 border-t border-line">'
      + '<div class="' + C.row + '"><span class="flex-none text-slate-600">ทะเบียน</span><span>' + esc(b.plate) + '</span></div>'
      + '<div class="' + C.row + '"><span class="flex-none text-slate-600">รับรถ</span><span class="min-w-0 break-words text-right">' + esc(b.pickup) + '<br>' + esc(b.pickup_place) + '</span></div>'
      + '<div class="' + C.row + '"><span class="flex-none text-slate-600">คืนรถ</span><span class="min-w-0 break-words text-right">' + esc(b.return) + '<br>' + esc(b.return_place) + '</span></div>'
      + (b.extras || []).map(function (x) {
          return '<div class="' + C.row + '"><span class="flex-none text-slate-600">' + esc(x.label) + '</span><span class="tabular-nums">' + esc(x.amount) + ' บาท</span></div>';
        }).join('')
      + '<div class="' + C.row + '"><span class="flex-none text-slate-600">' + ((b.extras || []).length ? 'ยอดรวมทั้งหมด' : 'ค่าเช่ารวม') + ' (' + b.days + ' วัน)</span><span class="tabular-nums">' + esc(b.total) + ' บาท</span></div>'
      + '<div class="' + C.row + '"><span class="flex-none text-slate-600">ชำระแล้ว</span><span class="tabular-nums">' + esc(b.paid) + ' บาท ' + badge(b.pay_tone, b.pay_label) + '</span></div>'
      + '<div class="' + C.row + '"><span class="flex-none text-slate-600">ยอดคงเหลือ</span><span class="text-[1.05rem] font-bold tabular-nums">' + esc(b.balance) + ' บาท</span></div>';
    if (b.open) {
      h += '<div class="' + C.row + '"><span class="flex-none text-slate-600">เงินประกัน (วันรับรถ)</span><span class="tabular-nums">' + esc(b.security) + ' บาท</span></div>';
    }
    h += '</div>';

    if (b.can_pay) {
      var k = state.bank;
      var copy = 'ml-1.5 inline-flex min-h-[32px] cursor-pointer items-center rounded-[7px] border border-line bg-white px-2.5 py-0.5 text-[0.8rem] font-semibold text-brand';
      h += '<div class="mt-3.5 rounded-[10px] border border-line bg-slate-50 p-3"><h3 class="m-0 mb-2 text-[0.98rem]">แจ้งโอนเงิน</h3><div class="text-[0.93rem] leading-[1.7]">';
      if (k.name) h += esc(k.name) + '<br>';
      if (k.no) h += 'เลขที่บัญชี <b class="tracking-wide tabular-nums">' + esc(k.no) + '</b><button type="button" class="' + copy + '" data-copy="' + esc(k.no) + '">คัดลอก</button><br>';
      if (k.holder) h += 'ชื่อบัญชี ' + esc(k.holder) + '<br>';
      if (k.promptpay) h += 'พร้อมเพย์ <b class="tracking-wide tabular-nums">' + esc(k.promptpay) + '</b><button type="button" class="' + copy + '" data-copy="' + esc(k.promptpay) + '">คัดลอก</button>';
      h += '</div><form class="slipForm" data-b="' + b.id + '" novalidate>'
        + '<label class="' + C.label + '" for="amt' + b.id + '">จำนวนเงินที่โอน (บาท)</label>'
        + '<input class="' + C.input + '" type="number" id="amt' + b.id + '" name="amount" inputmode="decimal" min="1" step="1" value="' + (b.suggest > 0 ? b.suggest : '') + '" required>'
        + '<label class="' + C.label + '" for="slip' + b.id + '">สลิปการโอน</label>'
        + '<label class="slipPick flex min-h-[52px] cursor-pointer items-center gap-2.5 rounded-[9px] border-[1.5px] border-dashed border-slate-300 bg-white px-3 py-2.5 text-[0.93rem] text-slate-600" id="fl' + b.id + '">'
        + '<input class="sr-only" type="file" id="slip' + b.id + '" accept="image/*,application/pdf"><span>แตะเพื่อเลือกภาพสลิป</span></label>'
        + '<div class="' + C.hint + '">ใช้ภาพหน้าจอจากแอปธนาคารได้เลย ระบบย่อรูปให้อัตโนมัติ</div>'
        + '<button class="btn btn-accent btn-block mt-3.5 min-h-[50px]" type="submit">ส่งแจ้งโอน</button></form></div>';
    }

    if (b.payments.length) {
      h += '<div class="mt-3"><h3 class="m-0 mb-1 text-[0.88rem] text-slate-600">ประวัติการแจ้งโอน</h3>';
      b.payments.forEach(function (p) {
        var tone = p.status === 'approved' ? 'ok' : (p.status === 'rejected' ? 'danger' : 'warn');
        h += '<div class="' + C.row + ' !py-[7px] !text-[0.87rem]"><span class="flex-none text-slate-600">' + esc(p.date) + '</span><span class="min-w-0 text-right tabular-nums">' + esc(p.amount) + ' บาท '
          + badge(tone, p.label)
          + (p.status === 'rejected' && p.reason ? '<span class="block text-[0.8rem] text-danger">' + esc(p.reason) + '</span>' : '')
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
      var mark = function (file) {
        lab.classList.remove('border-dashed', 'border-slate-300', 'text-slate-600');
        lab.classList.add('border-solid', 'border-ok', 'text-ok');
        lab.querySelector('span').textContent = 'เลือกแล้ว: ' + file.name;
      };
      if (picked[id]) mark(picked[id]);
      input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) return;
        picked[id] = file;
        mark(file);
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
<?php $this->end(); ?>
<?php endif; ?>
