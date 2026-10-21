<?php
/**
 * ไอคอน SVG inline — ไม่ต้องโหลดไฟล์ภายนอก โหลดเร็วบนมือถือ
 * ใช้: <?= icon('car') ?>
 */

function icon(string $name, string $cls = ''): string
{
    $c = $cls ? ' class="' . e($cls) . '"' : '';
    $o = '<svg' . $c . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
    $paths = [
        'car'      => '<path d="M5 17h14M6.5 17a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM20.5 17a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/><path d="M3 17v-4.2a2 2 0 01.3-1L5.6 8A2 2 0 017.3 7h9.4a2 2 0 011.7 1l2.3 3.8a2 2 0 01.3 1V17"/><path d="M3.5 12.8h17"/>',
        'calendar' => '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.8-3.8"/>',
        'phone'    => '<path d="M21.5 16.9v2.6a1.8 1.8 0 01-2 1.8 17.8 17.8 0 01-7.7-2.8 17.5 17.5 0 01-5.4-5.4A17.8 17.8 0 013.6 5.4a1.8 1.8 0 011.8-2H8a1.8 1.8 0 011.8 1.6c.1.9.3 1.7.6 2.5a1.8 1.8 0 01-.4 1.9L8.8 10.6a14.4 14.4 0 005.4 5.4l1.2-1.2a1.8 1.8 0 011.9-.4c.8.3 1.6.5 2.5.6a1.8 1.8 0 011.7 1.9z"/>',
        'user'     => '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7.5" r="4"/>',
        'menu'     => '<path d="M3 6.5h18M3 12h18M3 17.5h18"/>',
        'close'    => '<path d="M18 6L6 18M6 6l12 12"/>',
        'seat'     => '<path d="M6 19V8a3 3 0 013-3h1.5a3 3 0 013 3v4"/><path d="M6 14h9a3 3 0 013 3v2"/><path d="M4 19h16"/>',
        'gear'     => '<path d="M6 4.5v15M12 4.5v15M18 4.5v9"/><path d="M6 9h12M6 14.5h6"/><circle cx="6" cy="4.5" r="1.4"/><circle cx="12" cy="4.5" r="1.4"/><circle cx="18" cy="4.5" r="1.4"/>',
        'fuel'     => '<path d="M4 20V5.5A1.5 1.5 0 015.5 4h6A1.5 1.5 0 0113 5.5V20"/><path d="M3 20h11"/><path d="M13 10h2.5a2 2 0 012 2v4a1.8 1.8 0 003.5.6V8.5L18 5.5"/><path d="M6.5 8.5h4"/>',
        'tag'      => '<path d="M20.6 13.4l-7.1 7.1a2 2 0 01-2.8 0l-7.3-7.3A2 2 0 013 11.8V4.8A1.8 1.8 0 014.8 3h7a2 2 0 011.4.6l7.4 7.4a2 2 0 010 2.4z"/><circle cx="7.8" cy="7.8" r="1.4"/>',
        'percent'  => '<path d="M19 5L5 19"/><circle cx="7.5" cy="7.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
        'shield'   => '<path d="M12 22s8-3.5 8-10V5.5l-8-3-8 3V12c0 6.5 8 10 8 10z"/><path d="M9.2 11.8l2 2 3.6-3.8"/>',
        'lock'     => '<rect x="4.5" y="10.5" width="15" height="10.5" rx="2.2"/><path d="M8 10.5V7.2a4 4 0 018 0v3.3"/>',
        'refresh'  => '<path d="M21 12a9 9 0 01-15.2 6.5L3 16"/><path d="M3 12a9 9 0 0115.2-6.5L21 8"/><path d="M21 3.5V8h-4.5M3 20.5V16h4.5"/>',
        'check'    => '<path d="M20 6.5L9.5 17 4 11.5"/>',
        'checkbig' => '<circle cx="12" cy="12" r="9.2"/><path d="M8.2 12.3l2.6 2.6 5-5.2"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'back'     => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'doc'      => '<path d="M14 2.5H7A2 2 0 005 4.5v15a2 2 0 002 2h10a2 2 0 002-2V7.5z"/><path d="M14 2.5v5h5"/><path d="M8.5 13h7M8.5 17h5"/>',
        'money'    => '<rect x="2.5" y="6" width="19" height="12" rx="2.5"/><circle cx="12" cy="12" r="2.6"/><path d="M6 10v4M18 10v4"/>',
        'pin'      => '<path d="M20 10.5c0 6-8 11.5-8 11.5s-8-5.5-8-11.5a8 8 0 1116 0z"/><circle cx="12" cy="10.2" r="2.8"/>',
        'line'     => '<path d="M21 10.2c0-4-4-7.2-9-7.2s-9 3.2-9 7.2c0 3.6 3.2 6.6 7.5 7.1.3.1.7.2.8.5.1.3.1.6 0 .9l-.2 1c0 .3-.3 1.2 1 .6 1.3-.5 6.8-4 9.3-6.9A6.4 6.4 0 0021 10.2z"/>',
        'mail'     => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M3 7l9 6 9-6"/>',
        'star'     => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'clipboard'=> '<rect x="5" y="4.5" width="14" height="17" rx="2"/><path d="M9 4.5a2 2 0 012-2h2a2 2 0 012 2"/><path d="M8.5 11h7M8.5 15h4.5"/>',
        'upload'   => '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><path d="M7.5 8.5L12 4l4.5 4.5M12 4v12"/>',
        'wrench'   => '<path d="M14.5 6.5a4.5 4.5 0 006 5.9L14 19l-3-3 6.6-6.5a4.5 4.5 0 01-3.1-3z"/><path d="M8 13l-4.5 4.5a2.1 2.1 0 003 3L11 16"/>',
        'camera'   => '<path d="M3 8.5A2 2 0 015 6.5h1.7a1 1 0 00.83-.45l.94-1.4A1 1 0 019.3 4.2h5.4a1 1 0 01.83.45l.94 1.4a1 1 0 00.83.45H19a2 2 0 012 2V17a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><circle cx="12" cy="12.6" r="3.4"/>',
        'sign'     => '<path d="M3 19.5c2.2 0 2.2-2.6 3.6-8.3C7.6 7 8.6 4.5 10 4.5c1.2 0 1.4 1.3.6 4.2-.9 3.2-2.4 6.6-2.4 8.3 0 1 .5 1.5 1.2 1.5 1.6 0 3-3 4.4-3 .9 0 1.2.7 1.2 1.4 0 .8.5 1.3 1.3 1.3H21"/>',
        'compare'  => '<path d="M12 3v18M7 7H4.5A1.5 1.5 0 003 8.5v6A1.5 1.5 0 004.5 16H7M17 7h2.5A1.5 1.5 0 0121 8.5v6a1.5 1.5 0 01-1.5 1.5H17"/>',
        'whatsapp' => '<path d="M12 3a9 9 0 00-7.7 13.7L3 21l4.4-1.2A9 9 0 1012 3z"/>',
    ];
    return $o . ($paths[$name] ?? '') . '</svg>';
}

/**
 * โลโก้ thonglorcarrent
 *   mode 'mark'  = สัญลักษณ์รถ+ถนน (ใช้คู่กับข้อความ)
 *   mode 'full'  = โลโก้เต็มพร้อม wordmark (ใช้ที่ต้องการโลโก้เดี่ยว)
 * ไฟล์อยู่ที่ assets/img/ — เปลี่ยนโลโก้ = แทนที่ไฟล์เหล่านี้ได้เลย
 */
function brand_logo(string $mode = 'lockup', string $cls = ''): string
{
    // mode: lockup (แนวนอน — ใช้ในแถบหัวเว็บ) | full (แนวตั้งพร้อมสโลแกน) | mark (สัญลักษณ์อย่างเดียว)
    $map = [
        'lockup' => ['logo-lockup', 760, 84],
        'full'   => ['logo-full',   900, 371],
        'mark'   => ['logo-mark',   520, 122],
    ];
    [$file, $w, $h] = $map[$mode] ?? $map['lockup'];
    $c = $cls ? ' class="' . e($cls) . '"' : '';
    return '<img' . $c . ' src="' . e(url("assets/img/$file.webp")) . '?v=' . APP_VERSION . '"'
         . ' alt="' . e(setting('site_name', 'thonglorcarrent')) . '"'
         . ' width="' . $w . '" height="' . $h . '" decoding="async">';
}
