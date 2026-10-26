module.exports = {
  content: ['./app/Views/**/*.php'],
  safelist: ['badge-available', 'badge-maintenance', 'badge-inactive', 'alert-success', 'alert-error'],
  theme: {
    extend: {
      colors: {
        brand: { 50: '#eef6ff', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8' },
      },
      fontFamily: { sans: ['"Noto Sans Thai"', 'system-ui', 'sans-serif'] },
    },
  },
};
