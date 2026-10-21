/* ============================================================
   ย่อรูปในเครื่องก่อนอัปโหลด — ใช้ร่วมกันทุกหน้าที่มีการอัปไฟล์
   ============================================================

   ปัญหาที่แก้:
   เซิร์ฟเวอร์ที่ใช้ตั้ง upload_max_filesize ไว้ 2 MB (ค่ามาตรฐานของ cPanel)
   แต่รูปที่ถ่ายจากกล้องมือถือใบละ 3–8 MB จึงอัปไม่ผ่านทุกครั้ง
   และถ้าเกิน post_max_size ด้วย PHP จะทิ้งคำขอทั้งก้อนตั้งแต่ก่อนสคริปต์ทำงาน
   ($_POST และ $_FILES ว่างเปล่า) ทำให้หน้าเว็บเด้งกลับแบบไม่มีข้อความบอกเลย

   วิธีใช้:
     <input type="file" accept="image/*" data-shrink>
     <input type="file" accept="image/*" data-shrink data-max-side="1400">

   ตัวเลือก:
     data-max-side   ด้านยาวสุดหลังย่อ (ค่าเริ่มต้น 1600)
     data-quality    คุณภาพ JPEG 0–1 (ค่าเริ่มต้น 0.82)
     data-limit      ขนาดสูงสุดที่เซิร์ฟเวอร์รับได้ (ไบต์) — ใส่จาก PHP
     data-hint       id ของ element ที่จะแสดงสถานะ (ถ้าไม่ใส่ จะสร้างให้เอง)

   PDF และไฟล์ที่ไม่ใช่รูปจะถูกปล่อยผ่าน ไม่ย่อ
   ============================================================ */
(function () {
  'use strict';

  function sz(n) {
    return n < 1048576 ? Math.round(n / 1024) + ' KB' : (n / 1048576).toFixed(1) + ' MB';
  }

  function shrink(file, maxSide, quality) {
    return new Promise(function (resolve) {
      if (!/^image\//.test(file.type) || /svg/.test(file.type)) { resolve(null); return; }
      var img = new Image(), url = URL.createObjectURL(file);
      img.onload = function () {
        URL.revokeObjectURL(url);
        var w = img.naturalWidth, h = img.naturalHeight;
        var s = Math.min(1, maxSide / Math.max(w, h));
        w = Math.round(w * s); h = Math.round(h * s);
        var cv = document.createElement('canvas');
        cv.width = w; cv.height = h;
        try {
          cv.getContext('2d').drawImage(img, 0, 0, w, h);
          cv.toBlob(function (blob) { resolve(blob); }, 'image/jpeg', quality);
        } catch (e) { resolve(null); }
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(null); };
      img.src = url;
    });
  }

  /* ใส่ไฟล์ที่ย่อแล้วกลับเข้า input แทนไฟล์เดิม
     ทำแบบนี้เพื่อให้ฟอร์มส่งแบบปกติต่อไปได้ — ถ้าเปลี่ยนไปส่งด้วย fetch
     ข้อความยืนยันที่เซิร์ฟเวอร์ฝากมาจะถูกใช้ไปตั้งแต่ตอน fetch ลูกค้าจะไม่เห็นอะไรเลย */
  function swapFile(input, blob, name) {
    if (typeof DataTransfer === 'undefined') return false;
    try {
      var dt = new DataTransfer();
      dt.items.add(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
      input.files = dt.files;
      return input.files.length === 1;
    } catch (e) { return false; }
  }

  function attach(input) {
    var maxSide = parseInt(input.dataset.maxSide, 10) || 1600;
    var quality = parseFloat(input.dataset.quality) || 0.82;
    var limit   = parseInt(input.dataset.limit, 10) || 0;
    var busy    = false;

    var hint = input.dataset.hint ? document.getElementById(input.dataset.hint) : null;
    if (!hint) {
      hint = document.createElement('div');
      hint.className = 'hint upload-hint';
      input.parentNode.insertBefore(hint, input.nextSibling);
    }

    input.addEventListener('change', function () {
      if (busy) return;                       // เป็นการสลับไฟล์ที่เราทำเอง
      var f = input.files && input.files[0];
      if (!f) { hint.textContent = ''; return; }

      if (!/^image\//.test(f.type)) {         // PDF ฯลฯ — ไม่ย่อ แค่เตือนถ้าใหญ่เกิน
        hint.innerHTML = (limit && f.size > limit)
          ? '<b style="color:var(--danger)">ไฟล์ใหญ่เกินไป ' + sz(f.size)
            + ' (รับได้ไม่เกิน ' + sz(limit) + ')</b>'
          : 'ไฟล์พร้อมส่ง (' + sz(f.size) + ')';
        return;
      }

      hint.textContent = 'กำลังเตรียมไฟล์…';
      shrink(f, maxSide, quality).then(function (blob) {
        var done = false;
        if (blob && blob.size < f.size) {
          busy = true;
          done = swapFile(input, blob, 'upload.jpg');
          busy = false;
        }
        if (done) {
          hint.textContent = 'ย่อรูปให้แล้ว ' + sz(f.size) + ' → ' + sz(blob.size) + ' พร้อมส่ง';
        } else if (limit && f.size > limit) {
          hint.innerHTML = '<b style="color:var(--danger)">ไฟล์ใหญ่เกินไป ' + sz(f.size)
            + ' (เซิร์ฟเวอร์รับได้ไม่เกิน ' + sz(limit) + ')</b><br>'
            + 'กรุณาถ่ายภาพหน้าจอแทนการถ่ายรูปจากกล้อง หรือย่อรูปก่อนส่ง';
        } else {
          hint.textContent = 'ไฟล์พร้อมส่ง (' + sz(f.size) + ')';
        }
      });
    });

    /* กันส่งไฟล์ที่ยังใหญ่เกิน — ดีกว่าปล่อยให้กดส่งแล้วเด้งกลับโดยไม่รู้สาเหตุ */
    var form = input.form;
    if (form && limit && !form.dataset.shrinkGuard) {
      form.dataset.shrinkGuard = '1';
      form.addEventListener('submit', function (ev) {
        var big = [].slice.call(form.querySelectorAll('input[type=file][data-shrink]'))
          .map(function (el) { return el.files && el.files[0]; })
          .filter(function (f) { return f && f.size > limit; });
        if (big.length) {
          ev.preventDefault();
          hint.innerHTML = '<b style="color:var(--danger)">ไฟล์ยังใหญ่เกิน ' + sz(limit)
            + ' — กรุณาเลือกไฟล์ใหม่</b>';
        }
      });
    }
  }

  function init() {
    document.querySelectorAll('input[type=file][data-shrink]').forEach(attach);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
