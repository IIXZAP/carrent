/**
 * Tailwind CSS — ตั้งค่าธีมของ thonglorcarrent
 *
 * สีแบรนด์ (brand / accent / dark) อ่านจากตัวแปร CSS ที่ layout ฉีดให้จากหน้า หลังบ้าน → ตั้งค่า
 * (ดู brand_style() ใน app/Helpers/functions.php) จึงเปลี่ยนสีทั้งเว็บได้โดยไม่ต้อง build ใหม่
 *
 * build:  npm install && npm run build   (ผลลัพธ์ assets/css/app.css ถูก commit ไว้แล้ว
 *         โฮสต์ cPanel จึงไม่ต้องมี Node.js)
 */
const rgb = (name) => `rgb(var(--c-${name}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './app/Views/**/*.php',
    './app/Controllers/**/*.php',
    './app/Services/**/*.php',
    './app/Helpers/**/*.php',
    './assets/js/**/*.js',
  ],
  // คลาสที่ประกอบชื่อจากตัวแปร PHP (เช่น 'badge-' . $tone) ต้อง safelist ไว้ ไม่งั้นตัวสแกนมองไม่เห็น
  safelist: [
    { pattern: /^(badge|alert)-(ok|warn|danger|info|primary|muted)$/ },
    { pattern: /^stat-(ok|warn|danger|brand|accent|none)$/ },
  ],
  theme: {
    screens: { sm: '640px', md: '768px', lg: '960px', xl: '1280px' },
    extend: {
      colors: {
        brand: { DEFAULT: rgb('brand'), 700: rgb('brand-700'), 100: rgb('brand-100'), 50: rgb('brand-50') },
        accent: { DEFAULT: rgb('accent'), 600: rgb('accent-600'), 100: rgb('accent-100'), 50: rgb('accent-50') },
        dark: rgb('dark'),
        ink: { DEFAULT: '#1e2732', 2: '#5c6875' },
        muted: '#8b96a3',
        line: { DEFAULT: '#e3e7ec', 2: '#eef0f4' },
        surface: '#f5f7f9',
        ok: { DEFAULT: '#0f7a4d', bg: '#e7f6ef' },
        warn: { DEFAULT: '#9a6300', bg: '#fdf2dd' },
        danger: { DEFAULT: '#c0322a', bg: '#fdeceb' },
        info: { DEFAULT: '#15557f', bg: '#e8f2fa' },
      },
      fontFamily: {
        sans: ['Sarabun', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
      },
      boxShadow: {
        soft: '0 1px 2px rgba(22,33,46,.06)',
        card: '0 2px 8px rgba(22,33,46,.06), 0 12px 32px rgba(22,33,46,.07)',
        lift: '0 8px 20px rgba(22,33,46,.10), 0 24px 56px rgba(22,33,46,.10)',
      },
      maxWidth: { wrap: '1240px' },
    },
  },
  plugins: [],
};
